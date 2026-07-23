<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$connectCandidates = ['/home/makler/web/configs/connectDB.php', __DIR__ . '/../configs/connectDB.php'];
foreach ($connectCandidates as $connectFile) {
    if (is_file($connectFile)) {
        require_once $connectFile;
        break;
    }
}
$appCandidates = ['/home/makler/web/configs/app.php', __DIR__ . '/../configs/app.php'];
foreach ($appCandidates as $appFile) {
    if (is_file($appFile)) {
        require_once $appFile;
        break;
    }
}
if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) {
    fwrite(STDERR, "ERROR: failed to initialize mysqli via connectDB.php\n");
    exit(1);
}
if (!defined('APP_STORAGE_DIR')) {
    define('APP_STORAGE_DIR', __DIR__ . '/../storage');
}
require_once dirname(__DIR__) . '/remote_station/sfm_pipeline.php';
require_once dirname(__DIR__) . '/remote_station/sfm_cleanup.php';
require_once dirname(__DIR__) . '/libs/sfm_settings_lib.php';
require_once dirname(__DIR__) . '/libs/source_storage_lib.php';
require_once dirname(__DIR__) . '/libs/sfm_remote_job_lib.php';
require_once dirname(__DIR__) . '/libs/auto_photo_prepare_lib.php';
require_once dirname(__DIR__) . '/libs/auto_photo_sparse_lib.php';
require_once dirname(__DIR__) . '/libs/auto_photo_sparse_chain_lib.php';
require_once __DIR__
    . '/../libs/auto_photo_export_worker_lib.php';
require_once __DIR__ . '/sfm_dense_merge_contract.php';

const SFM_REMOTE_BASE = '/home/makler/web/remote_station';
const SFM_REMOTE_CONF = '/home/makler/web/remote_station/stations.conf';
const SFM_REMOTE_OUTPUT = '/home/makler/web/remote_station/output';
const SFM_REMOTE_STORAGE_OUTPUT = '/home/makler_storage/output';
const AUTO_SFM_EXPORT_MODELS = [0, 1];
const AUTO_SFM_DENSE_AFTER_SPARSE = false;
const MIN_REGISTERED_IMAGES_PREVIEW = 10;
const MIN_REGISTERED_IMAGES_HQ = 20;
define('SFM_DENSE_STALE_TIMEOUT_SECONDS', max(60, (int)(getenv('SFM_DENSE_STALE_TIMEOUT_SECONDS') ?: 900)));



function sfm_worker_generated_merges_ensure_schema(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS sfm_generated_model_merges (id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, capture_session_id BIGINT NULL, created_by_user_id BIGINT NULL, status VARCHAR(32) NOT NULL DEFAULT 'DONE', merge_type VARCHAR(64) NOT NULL, source_jobs_json JSON NULL, output_path TEXT NOT NULL, result_json_path TEXT NULL, total_points BIGINT NOT NULL DEFAULT 0, message TEXT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), KEY idx_sfm_generated_model_merges_order (order_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function sfm_auto_component_selection(array $components, array $params): array {
    $minImages=max(1,(int)($params['component_min_registered_images'] ?? 10)); $minPoints=max(0,(int)($params['component_min_sparse_points'] ?? 1000)); $max=max(1,min(50,(int)($params['component_max_count'] ?? 12))); $models=[];
    foreach(($components['models'] ?? []) as $c){ $mid=(int)($c['model_id'] ?? -1); if($mid<0)continue; $ri=(int)($c['registered_images'] ?? 0); $pts=(int)($c['points3D_count'] ?? ($c['points'] ?? 0)); if($ri >= $minImages && $pts >= $minPoints){ $c['model_id']=$mid; $c['registered_images']=$ri; $c['points3D_count']=$pts; $models[]=$c; } }
    usort($models, fn($a,$b)=>((int)$b['registered_images'] <=> (int)$a['registered_images']) ?: ((int)$b['points3D_count'] <=> (int)$a['points3D_count']));
    return array_slice($models,0,$max);
}
function sfm_dense_preview_exists(mysqli $db,int $pipelineRunId,int $sparseRemote,int $modelId): bool {
    $st=$db->prepare("SELECT parameters_json,status FROM sfm_remote_jobs WHERE pipeline_run_id=? AND parent_remote_job_id=? AND job_type='COLMAP_RECONSTRUCTION_PREVIEW' AND status IN ('QUEUED','RUNNING','PLANNING','RUNNING_CHUNKS','MERGING','DONE')"); if(!$st)return false; $st->bind_param('ii',$pipelineRunId,$sparseRemote); $st->execute(); $rs=$st->get_result(); while($j=$rs->fetch_assoc()){ $p=json_decode((string)($j['parameters_json']??'{}'),true)?:[]; if((int)($p['model_id']??-1)===$modelId){$st->close(); return true;} } $st->close(); return false;
}
function sfm_auto_components_update(mysqli $db, int $pipelineRunId, array $params, array $auto): void {
    $params['auto_components']=array_replace($params['auto_components'] ?? [], $auto);
    $json=json_encode($params, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $st=$db->prepare('UPDATE sfm_pipeline_runs SET parameters_json=? WHERE id=?');
    if($st){$st->bind_param('si',$json,$pipelineRunId);$st->execute();$st->close();}
}
function sfm_auto_components_maybe_merge(mysqli $db,int $pipelineRunId): void {
    $st=$db->prepare('SELECT parameters_json,order_id,capture_session_id FROM sfm_pipeline_runs WHERE id=? LIMIT 1'); if(!$st)return; $st->bind_param('i',$pipelineRunId); $st->execute(); $run=$st->get_result()->fetch_assoc() ?: []; $st->close();
    $rp=json_decode((string)($run['parameters_json'] ?? '{}'),true)?:[]; if(empty($rp['auto_process_all_components']) || empty($rp['auto_aligned_merge']))return;
    $auto=is_array($rp['auto_components'] ?? null) ? $rp['auto_components'] : [];
    $sparse=(int)($auto['sparse_remote_job_id'] ?? 0); $selected=array_values(array_unique(array_map('intval', is_array($auto['selected_model_ids'] ?? null) ? $auto['selected_model_ids'] : []))); if($sparse<=0 || !$selected)return;
    sfm_worker_generated_merges_ensure_schema($db);
    $message='Auto aligned merge from sparse components pipeline_run_id='.$pipelineRunId.' sparse_remote_job_id='.$sparse;
    $st=$db->prepare("SELECT id FROM sfm_generated_model_merges WHERE order_id=? AND merge_type='aligned_shared_images_dense_ply' AND message=? ORDER BY id DESC LIMIT 1"); if($st){$oid=(int)$run['order_id']; $st->bind_param('is',$oid,$message); $st->execute(); $exists=$st->get_result()->fetch_assoc(); $st->close(); if($exists)return;}

    $st=$db->prepare("SELECT * FROM sfm_remote_jobs WHERE pipeline_run_id=? AND parent_remote_job_id=? AND job_type='COLMAP_RECONSTRUCTION_PREVIEW' AND status IN ('QUEUED','RUNNING','PLANNING','RUNNING_CHUNKS','MERGING','DONE')"); if(!$st)return; $st->bind_param('ii',$pipelineRunId,$sparse); $st->execute(); $rs=$st->get_result(); $jobsByModel=[]; while($j=$rs->fetch_assoc()){ $p=json_decode((string)($j['parameters_json']??'{}'),true)?:[]; $mid=(int)($p['model_id']??-1); if(in_array($mid,$selected,true) && !isset($jobsByModel[$mid])){$jobsByModel[$mid]=$j;} } $st->close();
    $minDensePoints=1000; $src=[]; $ready=[]; $waiting=[]; $excluded=[];
    foreach($selected as $mid){
        if(!isset($jobsByModel[$mid])){ $waiting[]=$mid; continue; }
        $j=$jobsByModel[$mid]; $status=strtoupper((string)($j['status'] ?? ''));
        if($status!=='DONE'){ $waiting[]=$mid; continue; }
        $path=remote_output_dir((int)$j['remote_job_id']).'/merged/merged_fused.ply'; $pi=ply_header_info($path); $vertices=(int)($pi['vertices'] ?? 0);
        if(!$pi['ok'] || $vertices<$minDensePoints){ $excluded[]=['model_id'=>$mid,'remote_job_id'=>(int)$j['remote_job_id'],'points'=>$vertices,'min_points'=>$minDensePoints]; continue; }
        $ready[]=$mid; $src[]=['db_job_id'=>(int)$j['id'],'remote_job_id'=>(int)$j['remote_job_id'],'model_id'=>$mid,'mode'=>'preview','points'=>$vertices,'path'=>$path,'capture_session_id'=>(int)$j['capture_session_id'],'sparse_remote_job_id'=>$sparse];
    }
    $auto['previews_done']=count($ready)+count($excluded); $auto['ready_models']=$ready; $auto['waiting_models']=$waiting; $auto['excluded_tiny_models']=$excluded; $auto['aligned_merge']='not started'; $auto['combined_model_available']=false; sfm_auto_components_update($db,$pipelineRunId,$rp,$auto);
    if($waiting){ pipeline_log($pipelineRunId,'INFO','AUTO_COMPONENTS','previews done '.$auto['previews_done'].'/'.count($selected).'; waiting_models='.implode(',',$waiting)); return; }
    if(!$src){ pipeline_log($pipelineRunId,'WARNING','AUTO_COMPONENTS','aligned merge skipped: no ready dense sources after tiny exclusion'); return; }

    $dir=SFM_REMOTE_OUTPUT.'/merged_order_'.(int)$run['order_id'].'_aligned_auto_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)); if(!mkdir($dir,0775,true)&&!is_dir($dir)){ $auto['aligned_merge']='error'; $auto['last_error']='Cannot create aligned merge output directory'; sfm_auto_components_update($db,$pipelineRunId,$rp,$auto); pipeline_log($pipelineRunId,'ERROR','AUTO_COMPONENTS','aligned merge error: cannot create output dir'); return; }
    $poses=$dir.'/sparse_component_poses.json'; $spec=$dir.'/aligned_merge_spec.json'; $ply=$dir.'/aligned_merged_dense_cloud.ply'; $json=$dir.'/merge_result.json'; file_put_contents($spec,json_encode(['sources'=>$src,'result_json'=>$json,'excluded_tiny_models'=>$excluded],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); $anchor=(int)($ready[0] ?? $selected[0]); $script=SFM_REMOTE_BASE.'/scripts/sparse_component_pose_export.py';
    $auto['aligned_merge']='running'; sfm_auto_components_update($db,$pipelineRunId,$rp,$auto);
    $cmd='python3 '.escapeshellarg($script).' --sparse-dir '.escapeshellarg(remote_output_dir($sparse).'/colmap/sparse').' --output-json '.escapeshellarg($poses).' --merge-spec-json '.escapeshellarg($spec).' --output-ply '.escapeshellarg($ply).($anchor>=0?' --anchor-model-id '.$anchor:'').' 2>&1'; pipeline_log($pipelineRunId,'INFO','AUTO_COMPONENTS','aligned merge running'); exec($cmd,$out,$code); if($code!==0){ $err=substr(implode("\n",$out),0,1000); $auto['aligned_merge']='error'; $auto['combined_model_available']=false; $auto['last_error']=$err; sfm_auto_components_update($db,$pipelineRunId,$rp,$auto); pipeline_log($pipelineRunId,'ERROR','AUTO_COMPONENTS','aligned merge error: '.str_replace("\n",' | ',$err)); return; }
    $payload=json_decode((string)@file_get_contents($json),true)?:[];
    $included=is_array($payload['included'] ?? null) ? $payload['included'] : [];
    $scriptExcluded=is_array($payload['excluded'] ?? null) ? $payload['excluded'] : [];
    $includedCount=count($included); $selectedCount=count($selected);
    $total=(int)($payload['total_points']??ply_vertex_count($ply)??0);
    $sourcePoints=array_map(static fn($s)=>(int)($s['points'] ?? 0), $src);
    $maxSourcePoints=$sourcePoints ? max($sourcePoints) : 0; $sumSourcePoints=array_sum($sourcePoints);
    $payload=array_merge(['pipeline_run_id'=>$pipelineRunId,'order_id'=>(int)$run['order_id'],'sparse_remote_job_id'=>$sparse,'merge_type'=>'aligned_shared_images_dense_ply','aligned'=>true,'included'=>[],'excluded'=>[],'excluded_tiny_models'=>$excluded,'max_source_points'=>$maxSourcePoints,'sum_source_points'=>$sumSourcePoints],$payload);
    $payload['included']=$included; $payload['excluded']=$scriptExcluded; $payload['total_points']=$total; $payload['max_source_points']=$maxSourcePoints; $payload['sum_source_points']=$sumSourcePoints;
    file_put_contents($json,json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    if($selectedCount>1 && ($includedCount<2 || $total <= $maxSourcePoints)){
        $msg='Auto aligned merge included only '.$includedCount.'/'.$selectedCount.' models; disconnected components cannot be aligned.';
        if($includedCount>=2 && $total <= $maxSourcePoints){ $msg.=' Output point count '.$total.' is not greater than max source '.$maxSourcePoints.'.'; }
        $auto['aligned_merge']='anchor_only'; $auto['combined_model_available']=false; $auto['last_error']=$msg; $auto['previews_done']=count($ready)+count($excluded); $auto['ready_models']=$ready; $auto['waiting_models']=[]; $auto['excluded_tiny_models']=$excluded; sfm_auto_components_update($db,$pipelineRunId,$rp,$auto); pipeline_log($pipelineRunId,'WARNING','AUTO_COMPONENTS',$msg); return;
    }
    $sj=json_encode($payload['source_jobs']??$src,JSON_UNESCAPED_SLASHES); $mt='aligned_shared_images_dense_ply'; $oid=(int)$run['order_id']; $sid=(int)$run['capture_session_id']; $st=$db->prepare('INSERT INTO sfm_generated_model_merges (order_id,capture_session_id,created_by_user_id,status,merge_type,source_jobs_json,output_path,result_json_path,total_points,message) VALUES (?,?,0,\'DONE\',?,?,?,?,?,?)'); if($st){$st->bind_param('iissssis',$oid,$sid,$mt,$sj,$ply,$json,$total,$message); $st->execute(); $st->close();}
    $auto['aligned_merge']='done'; $auto['combined_model_available']=true; $auto['previews_done']=count($ready)+count($excluded); $auto['ready_models']=$ready; $auto['waiting_models']=[]; $auto['excluded_tiny_models']=$excluded; unset($auto['last_error']); sfm_auto_components_update($db,$pipelineRunId,$rp,$auto); pipeline_log($pipelineRunId,'INFO','AUTO_COMPONENTS','aligned merge done; combined model available: yes; previews done '.$auto['previews_done'].'/'.count($selected));
}

function ply_vertex_count(string $path): ?int
{
    if (!is_file($path)) { return null; }
    $fh = @fopen($path, 'rb');
    if (!$fh) { return null; }
    $count = null;
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if (preg_match('/^element\s+vertex\s+(\d+)$/', $line, $m)) { $count = (int)$m[1]; }
        if ($line === 'end_header') { break; }
    }
    fclose($fh);
    return $count;
}

function ply_header_info(string $path): array
{
    $info = ['ok' => false, 'vertices' => 0, 'faces' => 0, 'normals' => false];
    if (!is_file($path) || !is_readable($path) || filesize($path) <= 100) { return $info; }
    $fh = @fopen($path, 'rb');
    if (!$fh) { return $info; }
    if (fread($fh, 3) !== 'ply') { fclose($fh); return $info; }
    rewind($fh);
    $props = [];
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if (preg_match('/^element\s+vertex\s+(\d+)$/', $line, $m)) { $info['vertices'] = (int)$m[1]; }
        if (preg_match('/^element\s+face\s+(\d+)$/', $line, $m)) { $info['faces'] = (int)$m[1]; }
        if (preg_match('/^property\s+\S+\s+(nx|ny|nz)$/', $line, $m)) { $props[] = $m[1]; }
        if ($line === 'end_header') { $info['ok'] = true; break; }
    }
    fclose($fh);
    $info['normals'] = in_array('nx', $props, true) && in_array('ny', $props, true) && in_array('nz', $props, true);
    return $info;
}

function chunk_result_vertices(int $parentRemote, int $chunkIndex): int
{
    $result = remote_output_dir($parentRemote) . '/chunks/chunk_' . $chunkIndex . '/result.json';
    $data = is_file($result) ? (json_decode((string)file_get_contents($result), true) ?: []) : [];
    if (isset($data['fused_vertices'])) { return (int)$data['fused_vertices']; }
    $ply = remote_output_dir($parentRemote) . '/chunks/chunk_' . $chunkIndex . '/fused.ply';
    return (int)(ply_vertex_count($ply) ?? 0);
}

function worker_log(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
}

function ensure_sfm_remote_jobs_table(mysqli $db): void
{
    $sql = "CREATE TABLE IF NOT EXISTS sfm_remote_jobs (id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, capture_session_id BIGINT NOT NULL, job_type VARCHAR(64) NOT NULL, remote_job_id INT NOT NULL, parent_remote_job_id INT NULL, input_path TEXT NULL, output_path TEXT NULL, status VARCHAR(32) NOT NULL DEFAULT 'QUEUED', progress_percent INT DEFAULT 0, message TEXT NULL, result_json_path TEXT NULL, log_path TEXT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), KEY idx_sfm_remote_jobs_order_session (order_id, capture_session_id), KEY idx_sfm_remote_jobs_remote (remote_job_id), KEY idx_sfm_remote_jobs_status_updated (status, updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$db->query($sql)) {
        throw new RuntimeException('failed to ensure sfm_remote_jobs: ' . $db->error);
    }
}

function ensure_sfm_remote_jobs_chunk_columns(mysqli $db): void
{
    $cols = [
        'reconstruction_mode' => "ALTER TABLE sfm_remote_jobs ADD COLUMN reconstruction_mode VARCHAR(20) NULL",
        'chunk_index' => "ALTER TABLE sfm_remote_jobs ADD COLUMN chunk_index INT NULL",
        'chunk_count' => "ALTER TABLE sfm_remote_jobs ADD COLUMN chunk_count INT NULL",
        'retry_count' => "ALTER TABLE sfm_remote_jobs ADD COLUMN retry_count INT NOT NULL DEFAULT 0",
        'parameters_json' => "ALTER TABLE sfm_remote_jobs ADD COLUMN parameters_json LONGTEXT NULL",
        'cancel_requested_at' => "ALTER TABLE sfm_remote_jobs ADD COLUMN cancel_requested_at DATETIME(6) NULL",
        'cancelled_at' => "ALTER TABLE sfm_remote_jobs ADD COLUMN cancelled_at DATETIME(6) NULL",
    ];
    foreach ($cols as $col => $sql) {
        $res = $db->query("SHOW COLUMNS FROM sfm_remote_jobs LIKE '" . $db->real_escape_string($col) . "'");
        $exists = $res && $res->num_rows > 0;
        if ($res) { $res->close(); }
        if (!$exists) { $db->query($sql); }
    }
}

function remote_output_dir(int $remoteJobId): string
{
    return rtrim(SFM_REMOTE_OUTPUT, '/') . '/job_' . $remoteJobId;
}

function pipeline_log_extract_quality_summary(mysqli $db, int $pipelineRunId, string $jobOutputDir): void
{
    $path = rtrim($jobOutputDir, '/') . '/quality/quality_summary.json';
    if (!is_file($path)) {
        pipeline_log($pipelineRunId, 'WARNING', 'FRAME_SELECTION', 'quality_summary.json not found');
        return;
    }
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        pipeline_log($pipelineRunId, 'WARNING', 'FRAME_SELECTION', 'Invalid quality_summary.json');
        return;
    }
    $coverage = is_array($data['coverage'] ?? null) ? $data['coverage'] : [];
    pipeline_log($pipelineRunId, 'INFO', 'EXTRACT_FRAMES', sprintf('Video duration=%s source_fps=%s', $data['video_duration_sec'] ?? 0, $data['source_fps'] ?? 0));
    pipeline_log($pipelineRunId, 'INFO', 'EXTRACT_FRAMES', sprintf('Sampling mode=%s target=%d candidates=%d', $data['sampling_mode'] ?? 'unknown', (int)($data['target_frames'] ?? 0), (int)($data['candidate_frames'] ?? 0)));
    pipeline_log($pipelineRunId, 'INFO', 'FRAME_QUALITY', sprintf('Blur rejected=%d dark=%d overexposed=%d duplicates=%d fallback=%d', (int)($data['rejected_blur'] ?? 0), (int)($data['rejected_dark'] ?? 0), (int)($data['rejected_overexposed'] ?? 0), (int)($data['rejected_duplicate'] ?? 0), (int)($data['fallback_frames'] ?? 0)));
    pipeline_log($pipelineRunId, 'INFO', 'FRAME_SELECTION', sprintf('Selected=%d coverage=%s%% max_gap=%s sec', (int)($data['selected_frames'] ?? 0), $coverage['coverage_percent'] ?? 0, $coverage['maximum_gap_sec'] ?? 0));
    pipeline_log($pipelineRunId, 'INFO', 'FRAME_SELECTION', sprintf('First timestamp=%s last timestamp=%s', $coverage['first_timestamp_sec'] ?? 0, $coverage['last_timestamp_sec'] ?? 0));

    $actual = [
        'video_duration_sec' => $data['video_duration_sec'] ?? null,
        'source_fps' => $data['source_fps'] ?? null,
        'candidate_frames' => $data['candidate_frames'] ?? null,
        'extracted_frames' => $data['extracted_frames'] ?? ($data['selected_frames'] ?? null),
        'selected_frames' => $data['selected_frames'] ?? null,
        'coverage_percent' => $coverage['coverage_percent'] ?? null,
        'first_timestamp_sec' => $coverage['first_timestamp_sec'] ?? null,
        'last_timestamp_sec' => $coverage['last_timestamp_sec'] ?? null,
    ];
    $actual = array_filter($actual, static fn($v) => $v !== null);
    $res = $db->query('SELECT parameters_json FROM sfm_pipeline_runs WHERE id=' . (int)$pipelineRunId . ' LIMIT 1');
    $params = $res ? sfm_json_array((string)($res->fetch_assoc()['parameters_json'] ?? '{}')) : [];
    if ($res) { $res->close(); }
    $params['actual_statistics'] = array_replace($params['actual_statistics'] ?? [], $actual);
    $json = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $selected = (int)($data['selected_frames'] ?? 0);
    $extracted = (int)($actual['extracted_frames'] ?? $selected);
    $st = $db->prepare('UPDATE sfm_pipeline_runs SET parameters_json=?, extracted_frames=? WHERE id=?');
    if ($st) { $st->bind_param('sii', $json, $extracted, $pipelineRunId); $st->execute(); $st->close(); }
}


function pipeline_log_camera_metadata(mysqli $db, int $pipelineRunId, string $jobOutputDir): void
{
    $path = rtrim($jobOutputDir, '/') . '/camera_metadata.json';
    if (!is_file($path)) {
        pipeline_log($pipelineRunId, 'INFO', 'CAMERA_METADATA', 'No camera metadata sidecar found');
        return;
    }
    $meta = sfm_json_array((string)file_get_contents($path));
    if (!$meta) {
        pipeline_log($pipelineRunId, 'WARNING', 'CAMERA_METADATA', 'Invalid camera_metadata.json');
        return;
    }
    $lens = (string)($meta['lens_label'] ?? 'unknown');
    $fov = (string)($meta['approximate_fov_deg'] ?? 'unknown');
    $focal = (string)($meta['focal_length_mm'] ?? 'unknown');
    $res = is_array($meta['resolution'] ?? null) && count($meta['resolution']) >= 2 ? ((string)$meta['resolution'][0] . 'x' . (string)$meta['resolution'][1]) : 'unknown';
    pipeline_log($pipelineRunId, 'INFO', 'CAMERA_METADATA', 'Camera lens: ' . $lens);
    pipeline_log($pipelineRunId, 'INFO', 'CAMERA_METADATA', 'FOV: ' . $fov);
    pipeline_log($pipelineRunId, 'INFO', 'CAMERA_METADATA', 'focal_length_mm: ' . $focal);
    pipeline_log($pipelineRunId, 'INFO', 'CAMERA_METADATA', 'resolution/fps: ' . $res . '/' . (string)($meta['fps'] ?? 'unknown'));
    if (!empty($meta['stabilization_mode'])) { pipeline_log($pipelineRunId, 'INFO', 'CAMERA_METADATA', 'stabilization: ' . (string)$meta['stabilization_mode']); }
    foreach (($meta['warnings'] ?? []) as $warning) { pipeline_log($pipelineRunId, 'WARNING', 'CAMERA_METADATA', (string)$warning); }
    $resq = $db->query('SELECT parameters_json FROM sfm_pipeline_runs WHERE id=' . (int)$pipelineRunId . ' LIMIT 1');
    $params = $resq ? sfm_json_array((string)($resq->fetch_assoc()['parameters_json'] ?? '{}')) : [];
    if ($resq) { $resq->close(); }
    $params['camera_metadata'] = $meta;
    $json = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $st = $db->prepare('UPDATE sfm_pipeline_runs SET parameters_json=? WHERE id=?');
    if ($st) { $st->bind_param('si', $json, $pipelineRunId); $st->execute(); $st->close(); }
}

function auto_chain_after_done(mysqli $db, array $job): void
{
    $type = (string)$job['job_type'];
    if (auto_photo_sparse_is_standalone_job($job)) {
        worker_log('AUTO-B03 standalone COLMAP_SPARSE completed; automatic export/dense chain skipped');
        return;
    }
    $remote = (int)$job['remote_job_id'];
    $orderId = (int)$job['order_id'];
    $sessionId = (int)$job['capture_session_id'];
    if ($remote <= 0 || $orderId <= 0 || $sessionId <= 0) {
        return;
    }
    if ($type === AUTO_PHOTO_PREPARE_JOB_TYPE) {
        try {
            $result = auto_photo_sparse_chain_enqueue_from_prepare($db, (int) ($job['id'] ?? 0));
            worker_log($result['duplicate']
                ? 'AUTO-B03.1 standalone sparse already exists prepare_db_job_id=' . (int) $result['prepare_db_job_id']
                : 'AUTO-B03.1 standalone sparse queued prepare_db_job_id=' . (int) $result['prepare_db_job_id'] . ' sparse_remote_job_id=' . (int) $result['sparse_remote_job_id']);
        } catch (Throwable $e) {
            worker_log('ERROR AUTO-B03.1 standalone sparse enqueue failed prepare_db_job_id=' . (int) ($job['id'] ?? 0) . ' code=' . $e->getMessage());
        }
        return;
    }

    if ($type === 'EXTRACT_FRAMES') {
        $pipelineRunId = pipeline_run_for_job($job);
        $st = $db->prepare("SELECT id FROM sfm_remote_jobs WHERE job_type='COLMAP_SPARSE' AND parent_remote_job_id=? LIMIT 1");
        if (!$st) {
            return;
        }
        $st->bind_param('i', $remote);
        $st->execute();
        $exists = $st->get_result()->fetch_assoc();
        $st->close();
        if ($exists) {
            return;
        }
        if ($pipelineRunId <= 0) {
            worker_log("EXTRACT_FRAMES parent={$remote} completed as upload preparation/diagnostics; not auto-queueing COLMAP_SPARSE");
            return;
        }
        pipeline_log_extract_quality_summary($db, $pipelineRunId, remote_output_dir($remote)); pipeline_log_camera_metadata($db, $pipelineRunId, remote_output_dir($remote)); $er=sfm_json_array((string)@file_get_contents(remote_output_dir($remote).'/result.json')); $frames=(int)($er['frames'] ?? 0); $extra=$frames>0?['extracted_frames'=>$frames]:[]; sfm_pipeline_update($db,$pipelineRunId,'RUNNING','SPARSE',15,'Sparse reconstruction queued',$extra); pipeline_log($pipelineRunId,'INFO','EXTRACT_FRAMES','Done'); pipeline_log($pipelineRunId,'INFO','SPARSE','Started');
        $rid = sfm_job_id($db);
        $input = frames_path_for_parent($remote);
        $out = remote_output_dir($rid);
        $result = $out . '/result.json';
        $log = $out . '/logs';
        $jt = 'COLMAP_SPARSE';
        $msg = 'Auto queued after extract frames';
        $st = $db->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,pipeline_run_id,job_type,remote_job_id,parent_remote_job_id,input_path,output_path,status,progress_percent,message,result_json_path,log_path,parameters_json) VALUES (?,?,?,?,?,?,?,?,'QUEUED',0,?,?,?,?)");
        if ($st) {
            $childParams=json_encode(['pipeline_run_id'=>$pipelineRunId,'settings'=>worker_run_parameters($db,$job),'imu_jsonl_path'=>remote_output_dir($remote).'/scan_imu.jsonl'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $st->bind_param('iiisiissssss', $orderId, $sessionId, $pipelineRunId, $jt, $rid, $remote, $input, $out, $msg, $result, $log, $childParams);
            $st->execute();
            $st->close();
            worker_log("queued official COLMAP_SPARSE pipeline_run_id={$pipelineRunId} parent={$remote} remote_job_id={$rid}");
        }
        return;
    }

    if ($type === 'COLMAP_SPARSE' && pipeline_run_for_job($job) > 0) {
        $pipelineRunId=pipeline_run_for_job($job); $preset=json_decode((string)($job['parameters_json'] ?? '{}'), true) ?: [];
        $st=$db->prepare('SELECT parameters_json,pipeline_mode FROM sfm_pipeline_runs WHERE id=? LIMIT 1'); $st->bind_param('i',$pipelineRunId); $st->execute(); $run=$st->get_result()->fetch_assoc() ?: []; $st->close();
        $runParams=json_decode((string)($run['parameters_json'] ?? '{}'), true) ?: []; $mode=(string)($run['pipeline_mode'] ?? ($runParams['pipeline_mode'] ?? 'preview'));
        $preset=sfm_pipeline_preset($mode); $runSettings=worker_run_parameters($db,$job); $dense=$runSettings['dense'] ?? [];
        $best=sfm_pipeline_best_sparse_model_worker($remote);
        $componentsPath=remote_output_dir($remote).'/colmap/sparse_components.json'; if(!is_file($componentsPath)){$componentsPath=remote_output_dir($remote).'/sparse_components.json';} $components=is_file($componentsPath)?(json_decode((string)file_get_contents($componentsPath),true)?:[]):[]; $logModels=[]; foreach(($components['models'] ?? []) as $component){ if(isset($component['model_id'])){$logModels[]=(int)$component['model_id'];} } if(!$logModels){$logModels=range(0,20);} foreach(array_values(array_unique($logModels)) as $mid){ $ms=sfm_sparse_stats_worker($remote,$mid); if($ms['registered_images']>0 || $ms['points']>0){ pipeline_log($pipelineRunId,'INFO','SPARSE','Model '.$mid.': images='.$ms['registered_images'].' points='.$ms['points']); } }
        if((int)$best['registered_images']<5 || (int)$best['points']<=0){ sfm_pipeline_fail($db,$pipelineRunId,'Sparse reconstruction failed: no model has at least 5 registered images and points',['sparse_remote_job_id'=>$remote]); return; }
        pipeline_log($pipelineRunId,'INFO','SPARSE','Selected model '.$best['model_id'].': registered_images='.$best['registered_images'].' points='.$best['points']);
        $rowRes=$db->query('SELECT extracted_frames FROM sfm_pipeline_runs WHERE id='.(int)$pipelineRunId); $row=$rowRes?$rowRes->fetch_assoc():[]; if($rowRes){$rowRes->close();} $ef=(int)($row['extracted_frames'] ?? 0); $extra=['sparse_model_id'=>(int)$best['model_id'],'registered_images'=>(int)$best['registered_images'],'selected_model_id'=>(int)$best['model_id'],'selected_model_points'=>(int)$best['points'],'sparse_points'=>(int)$best['points'],'sparse_models_count'=>(int)($components['models_count'] ?? 0)]; if($ef>0){$extra['registration_ratio']=(string)round(((int)$best['registered_images'])*100/$ef,2);} $extra += sfm_pipeline_integrate_sparse_artifacts($db,$pipelineRunId,$remote,(int)$best['model_id']); $runScope=(string)($runParams['run_scope'] ?? ($run['run_scope'] ?? 'FULL')); if($runScope==='SPARSE_ONLY'){ sfm_pipeline_update($db,$pipelineRunId,'DONE','SPARSE_COMPLETE',100,'Sparse complete',$extra+['completed_stage'=>'SPARSE','run_scope'=>'SPARSE_ONLY']); pipeline_log($pipelineRunId,'INFO','PIPELINE','Sparse-only completed'); return; } sfm_pipeline_update($db,$pipelineRunId,'RUNNING','DENSE_PLAN',35,'Dense chunk planning queued',$extra);
        $selectedComponents=!empty($runParams['auto_process_all_components']) ? sfm_auto_component_selection($components,$runParams) : []; if(!$selectedComponents){ $selectedComponents=[['model_id'=>(int)$best['model_id'],'registered_images'=>(int)$best['registered_images'],'points3D_count'=>(int)$best['points']]]; }
        $selectedIds=array_map(fn($c)=>(int)$c['model_id'],$selectedComponents); if(!in_array((int)$best['model_id'],$selectedIds,true)){ array_unshift($selectedComponents,['model_id'=>(int)$best['model_id'],'registered_images'=>(int)$best['registered_images'],'points3D_count'=>(int)$best['points']]); $selectedIds=array_map(fn($c)=>(int)$c['model_id'],$selectedComponents); }
        if(!empty($runParams['auto_process_all_components'])){ $rp=$runParams; $rp['auto_components']=['sparse_remote_job_id'=>$remote,'sparse_models_detected'=>(int)($components['models_count'] ?? count($components['models'] ?? [])),'selected_useful_models'=>count($selectedIds),'selected_model_ids'=>$selectedIds,'previews_done'=>0,'aligned_merge'=>'not started','combined_model_available'=>false]; $json=json_encode($rp,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); $ust=$db->prepare('UPDATE sfm_pipeline_runs SET parameters_json=? WHERE id=?'); if($ust){$ust->bind_param('si',$json,$pipelineRunId);$ust->execute();$ust->close();} pipeline_log($pipelineRunId,'INFO','AUTO_COMPONENTS','sparse models detected: '.(int)$rp['auto_components']['sparse_models_detected'].'; selected useful models: '.count($selectedIds).'; previews done: 0/'.count($selectedIds).'; aligned merge: not started; combined model available: no'); }
        $settingsHash=substr(hash('sha256', json_encode($runSettings, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),0,16); pipeline_log($pipelineRunId,'INFO','DENSE','Dense source sparse_job_id='.$remote.' settings_hash='.$settingsHash);
        foreach($selectedComponents as $component){ $modelId=(int)$component['model_id']; if(sfm_dense_preview_exists($db,$pipelineRunId,$remote,$modelId)){ continue; } $rid=sfm_job_id($db); $jt='COLMAP_RECONSTRUCTION_PREVIEW'; $out=remote_output_dir($rid).'/merged/merged_fused.ply'; $result=remote_output_dir($rid).'/merged/result.json'; $log=remote_output_dir($rid).'/logs'; $msg=$modelId===(int)$best['model_id']?'pipeline dense reconstruction queued':'auto component preview queued'; $params=json_encode(['sparse_job_id'=>$remote,'sparse_remote_job_id'=>$remote,'model_id'=>$modelId,'auto_process_all_components'=>!empty($runParams['auto_process_all_components']),'settings'=>worker_run_parameters($db,$job),'target_images_per_chunk'=>(int)($dense['target_images_per_chunk'] ?? $preset['target_images_per_chunk']),'max_images_per_chunk'=>(int)($dense['max_images_per_chunk'] ?? $preset['max_images_per_chunk']),'overlap_images'=>(int)($dense['chunk_overlap'] ?? $preset['overlap_images'])]+$preset, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); $st=$db->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,pipeline_run_id,job_type,remote_job_id,parent_remote_job_id,output_path,status,progress_percent,message,result_json_path,log_path,reconstruction_mode,parameters_json) VALUES (?,?,?,?,?,?,?,'QUEUED',0,?,?,?,?,?)"); if($st){ $st->bind_param('iiisiissssss',$orderId,$sessionId,$pipelineRunId,$jt,$rid,$remote,$out,$msg,$result,$log,$mode,$params); $st->execute(); $st->close(); pipeline_log($pipelineRunId,'INFO','DENSE_PLAN','Queued preview for sparse model '.$modelId.' max_image_size='.$preset['max_image_size']); } if(empty($runParams['auto_process_all_components'])){ break; } }
        return;
    }

    if (in_array($type, ['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'], true)) {
        $mode = (string)($job['reconstruction_mode'] ?: (str_contains($type, 'HQ') ? 'hq' : 'preview'));
        $denseMarkers = sfm_json_array((string)($job['parameters_json'] ?? '{}'));
        if (($denseMarkers['standalone_auto_photo_dense'] ?? null) === true && ($denseMarkers['dense_only'] ?? null) === true) {
            worker_log("standalone dense-only reconstruction {$remote}: automatic mesh skipped");
            return;
        }
        $pipelineRunId = pipeline_run_for_job($job);
        if ($pipelineRunId > 0) { sfm_auto_components_maybe_merge($db, $pipelineRunId); }
        $inputPly = remote_output_dir($remote) . '/merged/merged_fused.ply';
        $ply = ply_header_info($inputPly);
        if (!$ply['ok'] || (int)$ply['vertices'] <= 0) {
            worker_log("auto mesh skipped for reconstruction {$remote}: invalid merged PLY");
            return;
        }
        $st = $db->prepare("SELECT id FROM sfm_remote_jobs WHERE parent_remote_job_id=? AND job_type='COLMAP_MESH' AND status IN ('QUEUED','RUNNING','DONE') LIMIT 1");
        if (!$st) { return; }
        $st->bind_param('i', $remote);
        $st->execute();
        $exists = $st->get_result()->fetch_assoc();
        $st->close();
        if ($exists) { return; }
        $rid = sfm_job_id($db);
        $out = remote_output_dir($rid) . '/mesh';
        $result = $out . '/mesh_result.json';
        $log = $out . '/logs';
        $jt = 'COLMAP_MESH';
        $msg = 'Auto queued mesh after dense merge';
        $preset = in_array($mode, ['preview','standard','fullhd'], true) ? sfm_pipeline_preset($mode) : ['mesh_depth'=>($mode === 'hq' ? 9 : 7),'target_faces'=>($mode === 'hq' ? 500000 : 100000)];
        $mesh=(worker_run_parameters($db,$job)['mesh'] ?? []);
        $poissonDepth = (int)($mesh['depth'] ?? $preset['mesh_depth']);
        $targetFaces = (int)($mesh['target_faces'] ?? $preset['target_faces']);
        if($pipelineRunId>0){ pipeline_log($pipelineRunId,'INFO','MESH','Open3D queued depth='.$poissonDepth.' target_faces='.$targetFaces); }
        $pj = json_encode(['input_ply' => $inputPly, 'poisson_depth' => $poissonDepth, 'target_faces' => $targetFaces, 'trim_enabled' => false, 'settings'=>['mesh'=>$mesh]], JSON_UNESCAPED_SLASHES);
        $st = $db->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,pipeline_run_id,job_type,remote_job_id,parent_remote_job_id,input_path,output_path,status,progress_percent,message,result_json_path,log_path,reconstruction_mode,parameters_json) VALUES (?,?,?,?,?,?,?,?,'QUEUED',0,?,?,?,?,?)");
        if ($st) {
            $st->bind_param('iiisiisssssss', $orderId, $sessionId, $pipelineRunId, $jt, $rid, $remote, $inputPly, $out, $msg, $result, $log, $mode, $pj);
            $st->execute();
            $st->close();
            worker_log("auto queued COLMAP_MESH parent={$remote} remote_job_id={$rid}");
        }
        return;
    }

    if ($type === 'COLMAP_SPARSE') {
        $resultPath = remote_output_dir($remote) . '/colmap/result.json';
        if (!is_file($resultPath)) {
            $resultPath = remote_output_dir($remote) . '/result.json';
        }
        $models = 0;
        if (is_file($resultPath)) {
            $data = json_decode((string)file_get_contents($resultPath), true);
            if (is_array($data)) {
                $models = (int)($data['models'] ?? $data['model_count'] ?? $data['models_count'] ?? 0);
            }
        }
        if ($models <= 0) {
            worker_log("auto export skipped for COLMAP {$remote}: models count not found");
            return;
        }
        if (AUTO_SFM_DENSE_AFTER_SPARSE) {
            // Dense reconstruction is intentionally disabled by default; keep this hook for later config enablement.
        }
        $maxModels = min($models, count(AUTO_SFM_EXPORT_MODELS));
        for ($i = 0; $i < $maxModels; $i++) {
            $modelId = (int)AUTO_SFM_EXPORT_MODELS[$i];
            if ($modelId >= $models) {
                continue;
            }
            $st = $db->prepare("SELECT id FROM sfm_remote_jobs WHERE job_type='EXPORT_PLY' AND parent_remote_job_id=? AND output_path LIKE ? LIMIT 1");
            if (!$st) {
                continue;
            }
            $like = '%sparse_' . $modelId . '.ply';
            $st->bind_param('is', $remote, $like);
            $st->execute();
            $exists = $st->get_result()->fetch_assoc();
            $st->close();
            if ($exists) {
                continue;
            }
            $rid = sfm_job_id($db);
            $out = remote_output_dir($remote) . '/sparse_' . $modelId . '.ply';
            $log = remote_output_dir($remote) . '/logs';
            $jt = 'EXPORT_PLY';
            $msg = 'Auto queued after COLMAP sparse';
            $st = $db->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,job_type,remote_job_id,parent_remote_job_id,output_path,status,progress_percent,message,log_path) VALUES (?,?,?,?,?,?,'QUEUED',0,?,?)");
            if ($st) {
                $st->bind_param('iisiisss', $orderId, $sessionId, $jt, $rid, $remote, $out, $msg, $log);
                $st->execute();
                $st->close();
                worker_log("auto queued EXPORT_PLY parent={$remote} model={$modelId} remote_job_id={$rid}");
            }
        }
    }
}


function pipeline_run_for_job(array $job): int { return (int)($job['pipeline_run_id'] ?? 0); }
function pipeline_job_log(mysqli $db, array $job, string $level, string $stage, string $message): void { $pid=pipeline_run_for_job($job); if($pid>0){ pipeline_log($pid,$level,$stage,$message); } }

function sfm_read_uint64_le_worker($fh): ?int
{
    $bytes = fread($fh, 8);

    if ($bytes === false || strlen($bytes) !== 8) {
        return null;
    }

    $parts = unpack('Vlo/Vhi', $bytes);

    if (!is_array($parts)) {
        return null;
    }

    return (int)(
        (int)$parts['lo'] +
        (int)$parts['hi'] * 4294967296
    );
}

function sfm_count_colmap_bin_worker(string $path): int
{
    $fh = @fopen($path, 'rb');

    if (!$fh) {
        return 0;
    }

    $count = sfm_read_uint64_le_worker($fh);
    fclose($fh);

    return max(0, (int)($count ?? 0));
}

function sfm_sparse_stats_worker(
    int $sparseJobId,
    int $modelId
): array {
    $dir = remote_output_dir($sparseJobId) .
        '/colmap/sparse/' .
        $modelId;

    $images = 0;
    $points = 0;

    $imagesTxt = $dir . '/images.txt';

    if (is_file($imagesTxt)) {
        foreach (
            file($imagesTxt, FILE_IGNORE_NEW_LINES) ?: []
            as $line
        ) {
            $line = trim($line);

            if ($line !== '' && $line[0] !== '#') {
                $images++;
            }
        }

        $images = (int)floor($images / 2);
    }

    $pointsTxt = $dir . '/points3D.txt';

    if (is_file($pointsTxt)) {
        foreach (
            file($pointsTxt, FILE_IGNORE_NEW_LINES) ?: []
            as $line
        ) {
            $line = trim($line);

            if ($line !== '' && $line[0] !== '#') {
                $points++;
            }
        }
    }

    if ($images <= 0) {
        $images = sfm_count_colmap_bin_worker(
            $dir . '/images.bin'
        );
    }

    if ($points <= 0) {
        $points = sfm_count_colmap_bin_worker(
            $dir . '/points3D.bin'
        );
    }

    $resultPaths = [
        remote_output_dir($sparseJobId) . '/colmap/result.json',
        remote_output_dir($sparseJobId) . '/result.json',
    ];

    $data = [];

    foreach ($resultPaths as $resultPath) {
        if (!is_file($resultPath)) {
            continue;
        }

        $decoded = json_decode(
            (string)file_get_contents($resultPath),
            true
        );

        if (is_array($decoded)) {
            $data = $decoded;
            break;
        }
    }

    if ($images <= 0) {
        $images = (int)(
            $data['registered_images_by_model'][$modelId]
            ?? $data['models'][$modelId]['registered_images']
            ?? $data['registered_images']
            ?? 0
        );
    }

    if ($points <= 0) {
        $points = (int)(
            $data['points_by_model'][$modelId]
            ?? $data['models'][$modelId]['points']
            ?? $data['models'][$modelId]['points3D']
            ?? $data['points3D']
            ?? $data['points']
            ?? 0
        );
    }

    worker_log(
        'sparse model stats' .
        ' sparse_job_id=' . $sparseJobId .
        ' model_id=' . $modelId .
        ' images=' . $images .
        ' points=' . $points
    );

    return [
        'model_id' => $modelId,
        'registered_images' => $images,
        'points' => $points,
    ];
}
function sfm_pipeline_best_sparse_model_worker(int $sparseJobId): array {
    $componentsPath = remote_output_dir($sparseJobId) . '/colmap/sparse_components.json';
    if (!is_file($componentsPath)) {
        $componentsPath = remote_output_dir($sparseJobId) . '/sparse_components.json';
    }
    if (is_file($componentsPath)) {
        $components = json_decode((string)file_get_contents($componentsPath), true) ?: [];
        $best = null;
        foreach (($components['models'] ?? []) as $m) {
            $candidate = [
                'model_id' => (int)($m['model_id'] ?? 0),
                'registered_images' => (int)($m['registered_images'] ?? 0),
                'points' => (int)($m['points3D_count'] ?? 0),
            ];
            if ($candidate['registered_images'] < 5) { continue; }
            $candidate['score'] = $candidate['registered_images'] * 1000000 + $candidate['points'];
            if ($best === null || $candidate['score'] > $best['score']) { $best = $candidate; }
        }
        if ($best !== null) { return $best; }
    }
    $best=['model_id'=>0,'registered_images'=>0,'points'=>0,'score'=>-1];
    foreach(range(0,20) as $mid){ $st=sfm_sparse_stats_worker($sparseJobId,$mid); if($st['registered_images']<5 || $st['points']<=0){ continue; } $score=$st['registered_images']*1000000+$st['points']; if($score>$best['score']){ $best=$st+['score'=>$score]; } }
    return $best['score']>=0?$best:['model_id'=>0,'registered_images'=>0,'points'=>0,'score'=>0];
}
function sfm_pipeline_fail(mysqli $db, int $pipelineRunId, string $message, array $error=[]): void { pipeline_log($pipelineRunId,'ERROR','PIPELINE',$message); sfm_pipeline_update($db,$pipelineRunId,'ERROR','ERROR',100,$message,['error_json'=>json_encode($error, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]); }

function dense_patchmatch_user_error(string $message): string
{
    preg_match_all('/Missing image or map dependency for image \d+:\s*([^\s]+)/', $message, $matches);
    if (!empty($matches[1])) {
        $names = array_values(array_unique($matches[1]));
        return "PatchMatch failed:\nmissing image/map dependencies:\n" . implode(', ', array_slice($names, 0, 12));
    }
    return mb_substr($message !== '' ? $message : 'PatchMatch failed', 0, 1000);
}

function dense_chunk_stage_label(string $message): string
{
    if (stripos($message, 'fusion') !== false) { return 'Fusion'; }
    if (stripos($message, 'patchmatch') !== false || stripos($message, 'patch match') !== false) { return 'PatchMatch'; }
    if (stripos($message, 'prepar') !== false) { return 'Preparing'; }
    return trim($message) !== '' ? trim($message) : 'Running';
}

function update_parent_pipeline_from_dense_child(mysqli $db, array $job, int $childProgress, string $childMessage, string $remoteStatus): void
{
    if ((string)($job['job_type'] ?? '') !== 'COLMAP_DENSE_CHUNK') { return; }
    $pipelineRunId = pipeline_run_for_job($job);
    if ($pipelineRunId <= 0) { return; }
    $chunkIndex = (int)($job['chunk_index'] ?? 0);
    $total = max(1, (int)($job['chunk_count'] ?? 1));
    $parentRemote = (int)($job['parent_remote_job_id'] ?? 0);
    $done = 0;
    if ($parentRemote > 0) {
        $st = $db->prepare("SELECT COUNT(*) c FROM sfm_remote_jobs WHERE parent_remote_job_id=? AND job_type='COLMAP_DENSE_CHUNK' AND status='DONE'");
        if ($st) {
            $st->bind_param('i', $parentRemote);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            $done = (int)($row['c'] ?? 0);
        }
    }
    $doneBeforeCurrent = min($done, $chunkIndex);
    $boundedChildProgress = max(0, min(100, $childProgress));
    $overall = 40 + (int)floor((($doneBeforeCurrent + ($boundedChildProgress / 100)) / $total) * 40);
    $stageLabel = dense_chunk_stage_label($childMessage);
    $message = 'Dense reconstruction: chunk ' . ($chunkIndex + 1) . ' of ' . $total . ' — ' . $stageLabel . ' ' . $boundedChildProgress . '%';

    $st = $db->prepare('SELECT progress_percent, message FROM sfm_pipeline_runs WHERE id=? LIMIT 1');
    $oldProgress = null;
    $oldMessage = '';
    if ($st) {
        $st->bind_param('i', $pipelineRunId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        $oldProgress = isset($row['progress_percent']) ? (int)$row['progress_percent'] : null;
        $oldMessage = (string)($row['message'] ?? '');
    }
    $oldBucket = $oldProgress === null ? -1 : intdiv($oldProgress, 10);
    $newBucket = intdiv($overall, 10);
    if ($oldBucket !== $newBucket || $oldMessage !== $message || strtoupper($remoteStatus) === 'DONE') {
        pipeline_log($pipelineRunId, 'INFO', 'DENSE_CHUNK', 'chunk=' . ($chunkIndex + 1) . '/' . $total . ' progress=' . $boundedChildProgress . ' stage=' . $stageLabel);
    }
    sfm_pipeline_update($db, $pipelineRunId, 'RUNNING', 'DENSE', $overall, $message);
}



function write_extract_frames_failure_result(int $remoteJobId, string $message, array $extra=[]): void
{
    $dir = remote_output_dir($remoteJobId);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $data = array_replace([
        'status' => 'ERROR',
        'stage' => 'EXTRACT_FRAMES',
        'message' => $message,
        'error' => $message,
    ], $extra);
    @file_put_contents($dir . '/result.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function sfm_ffprobe_source_video(string $path): array
{
    [$code, $output, $cmd] = run_command([
        'ffprobe', '-v', 'error', '-select_streams', 'v:0',
        '-show_entries', 'stream=index,codec_name,codec_type,pix_fmt,width,height,duration:format=format_name,duration',
        '-of', 'json', $path,
    ]);
    $data = json_decode($output, true);
    if ($code !== 0 || !is_array($data)) {
        throw new RuntimeException(format_command_failure($cmd, $code, $output));
    }
    $stream = is_array($data['streams'][0] ?? null) ? $data['streams'][0] : [];
    $format = is_array($data['format'] ?? null) ? $data['format'] : [];
    return [
        'command' => $cmd,
        'raw' => $data,
        'codec' => (string)($stream['codec_name'] ?? ''),
        'pix_fmt' => (string)($stream['pix_fmt'] ?? ''),
        'width' => (int)($stream['width'] ?? 0),
        'height' => (int)($stream['height'] ?? 0),
        'duration' => (string)($format['duration'] ?? ($stream['duration'] ?? '')),
        'format_name' => (string)($format['format_name'] ?? ''),
    ];
}

function sfm_source_video_is_safe_mp4(string $path, array $probe): bool
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $formats = array_filter(array_map('trim', explode(',', strtolower((string)($probe['format_name'] ?? '')))));
    return $ext === 'mp4'
        && in_array('mp4', $formats, true)
        && strtolower((string)($probe['codec'] ?? '')) === 'h264'
        && strtolower((string)($probe['pix_fmt'] ?? '')) === 'yuv420p';
}

function normalize_extract_source_video_if_needed(mysqli $db, int $jobId, int $remoteJobId, int $pipelineRunId, string $input): string
{
    $probe = sfm_ffprobe_source_video($input);
    $resolution = ((int)$probe['width']) . 'x' . ((int)$probe['height']);
    $metaLine = sprintf('Source video codec=%s pix_fmt=%s resolution=%s duration=%s format=%s', $probe['codec'] ?: 'unknown', $probe['pix_fmt'] ?: 'unknown', $resolution, $probe['duration'] ?: 'unknown', $probe['format_name'] ?: 'unknown');
    worker_log('EXTRACT_FRAMES | ' . $metaLine);
    if ($pipelineRunId > 0) { pipeline_log($pipelineRunId, 'INFO', 'EXTRACT_FRAMES', $metaLine); }

    if (sfm_source_video_is_safe_mp4($input, $probe)) {
        if ($pipelineRunId > 0) { pipeline_log($pipelineRunId, 'INFO', 'EXTRACT_FRAMES', 'Source video already safe H.264/yuv420p MP4; normalization skipped'); }
        worker_log('EXTRACT_FRAMES | normalization skipped source already safe');
        return $input;
    }

    $dir = remote_output_dir($remoteJobId) . '/normalized';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create normalized video directory: ' . $dir);
    }
    $normalized = $dir . '/source_safe.mp4';
    $args = ['ffmpeg', '-hide_banner', '-y', '-i', $input, '-map', '0:v:0', '-an', '-sn', '-dn', '-vf', 'fps=30,format=yuv420p', '-c:v', 'libx264', '-crf', '18', '-preset', 'veryfast', '-movflags', '+faststart', $normalized];
    if ($pipelineRunId > 0) { pipeline_log($pipelineRunId, 'INFO', 'EXTRACT_FRAMES', 'Normalizing source video to safe MP4: ' . $normalized); }
    [$code, $output, $cmd] = run_command($args);
    write_job_text_log($remoteJobId, 'normalize_source_video_ffmpeg.log', "Command: {$cmd}\nExit code: {$code}\n\n{$output}\n");
    $exitLine = 'ffmpeg normalization exit_code=' . $code . ' normalized_path=' . $normalized;
    worker_log('EXTRACT_FRAMES | ' . $exitLine);
    if ($pipelineRunId > 0) { pipeline_log($pipelineRunId, 'INFO', 'EXTRACT_FRAMES', $exitLine); }
    if ($code !== 0 || !is_file($normalized) || filesize($normalized) <= 0) {
        throw new RuntimeException(format_command_failure($cmd, $code, $output));
    }
    $normalizedProbe = sfm_ffprobe_source_video($normalized);
    $normLine = sprintf('Normalized video codec=%s pix_fmt=%s resolution=%dx%d duration=%s normalized_path=%s', $normalizedProbe['codec'] ?: 'unknown', $normalizedProbe['pix_fmt'] ?: 'unknown', (int)$normalizedProbe['width'], (int)$normalizedProbe['height'], $normalizedProbe['duration'] ?: 'unknown', $normalized);
    worker_log('EXTRACT_FRAMES | ' . $normLine);
    if ($pipelineRunId > 0) { pipeline_log($pipelineRunId, 'INFO', 'EXTRACT_FRAMES', $normLine); }
    return $normalized;
}

function set_job(mysqli $db, int $id, string $status, int $progress, string $message): void
{
    $message = mb_substr($message, 0, 60000);
    $st = $db->prepare('UPDATE sfm_remote_jobs SET status=?, progress_percent=?, message=?, updated_at=NOW(6) WHERE id=?');
    if (!$st) {
        throw new RuntimeException('prepare update failed: ' . $db->error);
    }
    $st->bind_param('sisi', $status, $progress, $message, $id);
    $st->execute();
    $st->close();
    if (in_array($status, ['ERROR','FAILED','ERROR_EMPTY'], true)) {
        $q=$db->prepare('SELECT pipeline_run_id, job_type, chunk_index FROM sfm_remote_jobs WHERE id=? LIMIT 1');
        if($q){ $q->bind_param('i',$id); $q->execute(); $j=$q->get_result()->fetch_assoc(); $q->close(); $pid=(int)($j['pipeline_run_id'] ?? 0); if($pid>0){ $userMessage = ((string)($j['job_type'] ?? '') === 'COLMAP_DENSE_CHUNK') ? dense_patchmatch_user_error($message) : (((string)($j['job_type'] ?? '') === 'EXTRACT_FRAMES') ? mb_substr($message !== '' ? $message : 'Frame extraction failed', 0, 4000) : 'Pipeline stage failed: '.(string)($j['job_type'] ?? 'job')); sfm_pipeline_fail($db,$pid,$userMessage,['child_job_id'=>$id,'child_job_type'=>$j['job_type'] ?? '', 'technical_message'=>$message]); } }
    }
}

function ensure_executable(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException('remote_station script not found: ' . $path);
    }
    if (!is_executable($path)) {
        throw new RuntimeException('remote_station script is not executable: ' . $path);
    }
}

function format_command_failure(string $cmd, int $code, string $output): string
{
    $message = "Command failed with exit code {$code}\nCommand: {$cmd}";
    if ($output !== '') {
        $message .= "\nOutput:\n" . $output;
    }
    return $message;
}

function ensure_command_available(string $command): void
{
    if ($command === '') {
        throw new RuntimeException('empty command');
    }
    if (str_contains($command, '/')) {
        ensure_executable($command);
        return;
    }
    $resolved = trim((string)shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null'));
    if ($resolved === '' || !is_executable($resolved)) {
        throw new RuntimeException('command not found in PATH: ' . $command);
    }
}

function run_command(array $args): array
{
    if (!$args) {
        throw new RuntimeException('empty command args');
    }
    ensure_command_available((string)$args[0]);
    $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out), $cmd];
}

function write_job_text_log(int $remoteJobId, string $name, string $contents): ?string
{
    if ($remoteJobId <= 0) {
        return null;
    }
    $dir = rtrim(SFM_REMOTE_OUTPUT, '/') . '/job_' . $remoteJobId . '/logs';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    $path = $dir . '/' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
    return @file_put_contents($path, $contents) === false ? null : $path;
}

function path_is_inside_dir(string $realPath, string $realDir): bool
{
    return $realPath === $realDir || str_starts_with($realPath, rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

function sfm_job_video_scan_id(mysqli $db, array $job): int
{
    $params = json_decode((string)($job['parameters_json'] ?? '{}'), true);
    if (is_array($params) && isset($params['video_scan_id'])) {
        return (int)$params['video_scan_id'];
    }
    $pipelineRunId = pipeline_run_for_job($job);
    if ($pipelineRunId > 0) {
        $st = $db->prepare('SELECT video_scan_id FROM sfm_pipeline_runs WHERE id=? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $pipelineRunId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            return (int)($row['video_scan_id'] ?? 0);
        }
    }
    return 0;
}

function safe_session_video_path(mysqli $db, array $job): string
{
    $input = trim((string)($job['input_path'] ?? ''));
    $orderId = (int)$job['order_id'];
    $sessionId = (int)$job['capture_session_id'];
    $videoScanId = sfm_job_video_scan_id($db, $job);
    $logPrefix = 'SOURCE_VIDEO_VALIDATE | job_id=' . (int)($job['id'] ?? 0) . ' order_id=' . $orderId . ' capture_session_id=' . $sessionId . ' video_scan_id=' . $videoScanId;

    $st = $db->prepare('SELECT id, app_session_uuid FROM capture_sessions WHERE id=? AND order_id=? AND deleted_at IS NULL LIMIT 1');
    if (!$st) {
        throw new RuntimeException('prepare session lookup failed: ' . $db->error);
    }
    $st->bind_param('ii', $sessionId, $orderId);
    $st->execute();
    $session = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$session) {
        worker_log($logPrefix . ' validation=FAIL reason=capture_session_not_found input_path=' . $input);
        throw new RuntimeException('Source video path failed safety validation: ' . $input);
    }

    $sessionUuid = (string)$session['app_session_uuid'];
    $allowedDirs = array_values(array_unique([
        capture_session_videos_dir($orderId, $sessionUuid, false, false),
        capture_session_videos_dir($orderId, $sessionUuid, false, true),
        capture_session_videos_dir($orderId, $sessionUuid, true, false),
        capture_session_videos_dir($orderId, $sessionUuid, true, true),
    ]));

    // Hard allow canonical source media dirs used by APP/web uploads.
    // Source media must live outside the web project:
    // /home/storage/orders/<order_id>/sessions/<app_session_uuid>/videos/<file>
    $allowedDirs[] = '/home/storage/orders/' . $orderId . '/sessions/' . $sessionUuid . '/videos';

    // Legacy fallback if old installs stored source media under the project tree.
    $allowedDirs[] = '/home/makler/web/storage/orders/' . $orderId . '/sessions/' . $sessionUuid . '/videos';

    // If input_path itself is already under the exact canonical session videos dir,
    // allow its dirname too. This keeps validation deterministic even if helpers
    // build a different legacy path.
    if ($input !== '') {
        $canonicalPrefix = '/home/storage/orders/' . $orderId . '/sessions/' . $sessionUuid . '/videos/';
        $legacyPrefix = '/home/makler/web/storage/orders/' . $orderId . '/sessions/' . $sessionUuid . '/videos/';
        if (str_starts_with($input, $canonicalPrefix) || str_starts_with($input, $legacyPrefix)) {
            $allowedDirs[] = dirname($input);
        }
    }

    $allowedDirs = array_values(array_unique($allowedDirs));

    $candidates = [];
    if ($input !== '') { $candidates[] = $input; }

    if ($videoScanId > 0) {
        $st = $db->prepare('SELECT id, session_id, filename, storage_path FROM video_scans WHERE id=? AND deleted_at IS NULL LIMIT 1');
        if (!$st) {
            throw new RuntimeException('prepare video scan lookup failed: ' . $db->error);
        }
        $st->bind_param('i', $videoScanId);
        $st->execute();
        $scan = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$scan || (int)$scan['session_id'] !== $sessionId) {
            worker_log($logPrefix . ' validation=FAIL reason=video_scan_session_mismatch input_path=' . $input);
            throw new RuntimeException('Source video path failed safety validation: ' . $input);
        }
        foreach (['storage_path', 'filename'] as $field) {
            $value = trim((string)($scan[$field] ?? ''));
            if ($value === '') { continue; }
            $candidates[] = $value;
            if (!str_starts_with($value, '/')) {
                $relative = ltrim($value, '/');
                $candidates[] = rtrim(source_storage_root(), '/') . '/' . $relative;
                $candidates[] = rtrim(legacy_source_storage_root(), '/') . '/' . $relative;
                if (str_starts_with($relative, 'orders/')) {
                    $candidates[] = dirname(rtrim(source_storage_root(), '/')) . '/' . $relative;
                    $candidates[] = dirname(rtrim(legacy_source_storage_root(), '/')) . '/' . $relative;
                }
                foreach ($allowedDirs as $dir) { $candidates[] = rtrim($dir, '/') . '/' . basename($value); }
            }
        }
    }

    $realAllowedDirs = [];
    foreach ($allowedDirs as $dir) {
        $realDir = realpath($dir);
        if ($realDir !== false && is_dir($realDir)) { $realAllowedDirs[] = $realDir; }
    }
    $realAllowedDirs = array_values(array_unique($realAllowedDirs));
    worker_log($logPrefix . ' input_path=' . $input . ' allowed_base_dirs=' . json_encode($realAllowedDirs, JSON_UNESCAPED_SLASHES));

    foreach (array_values(array_unique($candidates)) as $candidate) {
        $real = realpath($candidate);
        $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        $ok = $real !== false && is_file($real) && is_readable($real) && in_array($ext, ['mp4', 'mov', 'm4v'], true);
        if ($ok) {
            foreach ($realAllowedDirs as $realDir) {
                if (path_is_inside_dir($real, $realDir)) {
                    worker_log($logPrefix . ' candidate=' . $candidate . ' resolved_realpath=' . $real . ' validation=PASS');
                    return $real;
                }
            }
        }
        worker_log($logPrefix . ' candidate=' . $candidate . ' resolved_realpath=' . ($real === false ? 'false' : $real) . ' validation=FAIL');
    }

    throw new RuntimeException('Source video path failed safety validation: ' . $input);
}

/*
function safe_session_imu_path(mysqli $db, array $job): ?string
{
    $video = safe_session_video_path($db, $job);
    $base = dirname($video);
    $orderId=(int)$job['order_id']; $sessionId=(int)$job['capture_session_id'];
    $params=json_decode((string)($job['parameters_json'] ?? '{}'), true);
    $candidates=[];
    if (is_array($params)) {
        if (!empty($params['imu_jsonl_path'])) { $candidates[]=(string)$params['imu_jsonl_path']; }
        if (!empty($params['source_video']['imu_jsonl_path'])) { $candidates[]=(string)$params['source_video']['imu_jsonl_path']; }
    }
    $stem=pathinfo($video, PATHINFO_FILENAME); $base=preg_replace('/_video$/','',$stem);
    foreach (array_unique([$stem.'_imu.jsonl',$base.'_imu.jsonl']) as $name) { $candidates[]=dirname($video).'/'.$name; }
    $st=$db->prepare('SELECT imu_storage_path, imu_path FROM video_scans WHERE session_id=? AND deleted_at IS NULL AND (storage_path=? OR filename=?) LIMIT 1');
    if($st){ $bn=basename($video); $storage=''; $st->bind_param('iss',$sessionId,$storage,$bn); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); if($row){ foreach(['imu_storage_path','imu_path'] as $k){ if(!empty($row[$k])) $candidates[]=rtrim(APP_STORAGE_DIR,'/').'/'.ltrim((string)$row[$k],'/'); } } }
    foreach ($candidates as $c) { $real=realpath($c); $allowed=realpath($base); if($real && $allowed && is_file($real) && strpos($real,$allowed.'/')===0) return $real; }
    return null;
}*/

function safe_session_imu_path(mysqli $db, array $job): ?string
{
    $video = safe_session_video_path($db, $job);
    $videoDir = dirname($video);
    $allowedDir = realpath($videoDir);

    if ($allowedDir === false || !is_dir($allowedDir)) {
        return null;
    }

    $params = json_decode((string)($job['parameters_json'] ?? '{}'), true);
    $candidates = [];

    if (is_array($params)) {
        if (!empty($params['imu_jsonl_path'])) {
            $candidates[] = (string)$params['imu_jsonl_path'];
        }
        if (!empty($params['source_video']['imu_jsonl_path'])) {
            $candidates[] = (string)$params['source_video']['imu_jsonl_path'];
        }
    }

    $stem = pathinfo($video, PATHINFO_FILENAME);
    $baseStem = preg_replace('/_video$/', '', $stem);

    foreach (array_unique([
        $stem . '_imu.jsonl',
        $baseStem . '_imu.jsonl',
    ]) as $name) {
        $candidates[] = $videoDir . '/' . $name;
    }

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if (
            $real !== false &&
            is_file($real) &&
            str_ends_with(strtolower($real), '.jsonl') &&
            ($real === $allowedDir || str_starts_with($real, $allowedDir . DIRECTORY_SEPARATOR))
        ) {
            return $real;
        }
    }

    return null;
}


function safe_session_metadata_path(mysqli $db, array $job, string $suffix): ?string
{
    $video = safe_session_video_path($db, $job);
    $videoDir = dirname($video);
    $allowedDir = realpath($videoDir);
    if ($allowedDir === false || !is_dir($allowedDir)) { return null; }
    $params = json_decode((string)($job['parameters_json'] ?? '{}'), true);
    $key = $suffix === '_camera_info.json' ? 'camera_info_path' : 'manifest_path';
    $candidates = [];
    if (is_array($params) && !empty($params['source_video'][$key])) { $candidates[] = (string)$params['source_video'][$key]; }
    $stem = pathinfo($video, PATHINFO_FILENAME);
    $baseStem = preg_replace('/_video$/', '', $stem);
    foreach (array_unique([$stem . $suffix, $baseStem . $suffix]) as $name) { $candidates[] = $videoDir . '/' . $name; }
    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real !== false && is_file($real) && str_starts_with($real, $allowedDir . DIRECTORY_SEPARATOR)) { return $real; }
    }
    return null;
}

function frames_path_for_parent(int $parentRemoteJobId): string
{
    if ($parentRemoteJobId <= 0) {
        throw new RuntimeException('missing parent_remote_job_id');
    }
    return SFM_REMOTE_STORAGE_OUTPUT . '/job_' . $parentRemoteJobId . '/frames';
}

function model_id_from_job(array $job): int
{
    $out = (string)($job['output_path'] ?? '');
    if (preg_match('/sparse_(\d+)\.ply$/', $out, $m)) {
        return (int)$m[1];
    }
    if (preg_match('/dense_model_(\d+)\.ply$/', $out, $m)) {
        return (int)$m[1];
    }
    if (preg_match('#/sparse/(\d+)/model\.ply#', $out, $m)) {
        return (int)$m[1];
    }
    if (preg_match('/model[_-]?(\d+)/', $out, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function claim_next_job(mysqli $db): ?array
{
    $db->begin_transaction();
    try {
        $res = $db->query("SELECT * FROM sfm_remote_jobs WHERE status='QUEUED' ORDER BY created_at ASC, id ASC LIMIT 1 FOR UPDATE");
        $job = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->close();
        }
        if (!$job) {
            $db->commit();
            return null;
        }
        $id = (int)$job['id'];
        $st = $db->prepare("UPDATE sfm_remote_jobs SET status='RUNNING', message='Worker picked up job', updated_at=NOW(6) WHERE id=? AND status='QUEUED'");
        if (!$st) {
            throw new RuntimeException('prepare claim failed: ' . $db->error);
        }
        $st->bind_param('i', $id);
        $st->execute();
        $ok = $st->affected_rows === 1;
        $st->close();
        $db->commit();
        return $ok ? $job : null;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function worker_run_parameters(mysqli $db,array $job): array { $pid=pipeline_run_for_job($job); if($pid>0){ $st=$db->prepare('SELECT parameters_json,pipeline_mode FROM sfm_pipeline_runs WHERE id=? LIMIT 1'); if($st){$st->bind_param('i',$pid);$st->execute();$run=$st->get_result()->fetch_assoc() ?: [];$st->close(); $all=sfm_json_array((string)($run['parameters_json'] ?? '{}')); $mode=(string)($run['pipeline_mode'] ?? ($all['pipeline_mode'] ?? ($job['reconstruction_mode'] ?? 'preview'))); if(isset($all['mode_parameters']) && is_array($all['mode_parameters'])){return $all['mode_parameters'];} if(isset($all[$mode])){return sfm_mode_parameters(sfm_merge_settings(sfm_system_defaults(),[],[],$all),$mode);} }} $jp=sfm_json_array((string)($job['parameters_json'] ?? '{}')); return $jp['settings'] ?? []; }
function launch_job(mysqli $db, array $job): void
{
    $launchTemp = null;
    $photoExportPlan = null;
    $id = (int)$job['id'];
    $remoteJobId = (int)$job['remote_job_id'];
    $type = (string)$job['job_type'];
    if ($remoteJobId <= 0) {
        throw new RuntimeException('bad remote_job_id');
    }
    if ($type === 'COLMAP_RECONSTRUCTION_PREVIEW' || $type === 'COLMAP_RECONSTRUCTION_HQ') {
        $mode = (string)($job['reconstruction_mode'] ?: (str_contains($type, 'HQ') ? 'hq' : 'preview'));
        $params = json_decode((string)($job['parameters_json'] ?? '{}'), true) ?: [];
        $sparse = (int)($params['sparse_job_id'] ?? $job['parent_remote_job_id'] ?? 0);
        $model = (int)($params['model_id'] ?? 0);
        $runSettings=worker_run_parameters($db,$job); $dense=$runSettings['dense'] ?? [];
        $target = (int)($dense['target_images_per_chunk'] ?? $params['target_images_per_chunk'] ?? ($mode === 'hq' ? 60 : 50));
        $max = (int)($dense['max_images_per_chunk'] ?? $params['max_images_per_chunk'] ?? ($mode === 'hq' ? 100 : 80));
        $overlap = (int)($dense['chunk_overlap'] ?? $params['overlap_images'] ?? ($mode === 'hq' ? 20 : 15));
        $reserve = (int)($params['ram_reserve_mb'] ?? 3000);
        $outDir = remote_output_dir($remoteJobId);
        if (!is_dir($outDir)) { @mkdir($outDir, 0775, true); }
        [$code, $output, $cmd] = run_command([SFM_REMOTE_BASE . '/run_colmap_chunk_plan_job.sh', SFM_REMOTE_CONF, (string)$remoteJobId, (string)$sparse, (string)$model, $mode, (string)$target, (string)$max, (string)$overlap, (string)$reserve, SFM_REMOTE_OUTPUT]);
        if ($code !== 0) { set_job($db, $id, 'ERROR', 0, format_command_failure($cmd, $code, $output)); return; }
        $plan = json_decode((string)@file_get_contents($outDir . '/chunk_plan.json'), true) ?: [];
        $registered=(int)($plan['registered_images_total'] ?? 0); $min=$mode==='hq'?MIN_REGISTERED_IMAGES_HQ:MIN_REGISTERED_IMAGES_PREVIEW; if($registered < $min){ $label=$mode==='hq'?'high quality':'preview'; set_job($db,$id,'ERROR',3,'Insufficient registered images: '.$registered.'. Minimum for '.$label.' is '.$min.'. Select another sparse model or improve sparse reconstruction.'); return; }
        $chunkCount = count($plan['chunks'] ?? []);
        $st = $db->prepare('UPDATE sfm_remote_jobs SET chunk_count=?, message=?, updated_at=NOW(6) WHERE id=?');
        if ($st) { $msg = 'Chunk plan ready: ' . $chunkCount . ' chunks'; $st->bind_param('isi', $chunkCount, $msg, $id); $st->execute(); $st->close(); }
        set_job($db, $id, 'RUNNING', 5, 'Chunk plan ready: ' . $chunkCount . ' chunks');
        return;
    }
    if ($type === 'EXTRACT_FRAMES') {
        $pipelineRunId = pipeline_run_for_job($job);
        if ($pipelineRunId > 0) { sfm_pipeline_update($db,$pipelineRunId,'RUNNING','EXTRACT_FRAMES',1,'Extracting and selecting frames'); }
        try {
            $input = safe_session_video_path($db, $job);
        } catch (Throwable $e) {
            $msg = 'Source video validation failed before remote launch: ' . $e->getMessage();
            worker_log('ERROR ' . $msg);
            set_job($db, $id, 'ERROR', 0, $msg);
            if ($pipelineRunId > 0) {
                sfm_pipeline_fail($db, $pipelineRunId, $msg, [
                    'remote_job_id' => $remoteJobId,
                    'job_type' => $type,
                ]);
            }
            return;
        }
        try {
            $input = normalize_extract_source_video_if_needed($db, $id, $remoteJobId, $pipelineRunId, $input);
        } catch (Throwable $e) {
            $msg = 'Source video normalization failed before frame extraction: ' . $e->getMessage();
            worker_log('ERROR ' . $msg);
            if ($pipelineRunId > 0) { pipeline_log($pipelineRunId, 'ERROR', 'EXTRACT_FRAMES', $msg); }
            write_extract_frames_failure_result($remoteJobId, $msg, ['normalized_path' => remote_output_dir($remoteJobId) . '/normalized/source_safe.mp4']);
            set_job($db, $id, 'ERROR', 0, $msg);
            return;
        }
        $rs=worker_run_parameters($db,$job); $ex=$rs['extract'] ?? []; $imuCfg=$rs['imu_frame_selection'] ?? []; $params=json_decode((string)($job['parameters_json'] ?? '{}'), true) ?: []; $localImu=safe_session_imu_path($db,$job); $localCameraInfo=safe_session_metadata_path($db,$job,'_camera_info.json'); $localManifest=safe_session_metadata_path($db,$job,'_manifest.json'); $sourceVideo=$params['source_video'] ?? []; $extractPayload=['extract'=>$ex,'imu_frame_selection'=>$imuCfg]; if(!is_array($sourceVideo)){$sourceVideo=[];} if($localCameraInfo){$sourceVideo['camera_info_path']=$localCameraInfo; worker_log('CAMERA_METADATA | camera_info sidecar found: '.$localCameraInfo);} if($localManifest){$sourceVideo['manifest_path']=$localManifest; worker_log('CAMERA_METADATA | manifest sidecar found: '.$localManifest);} if($sourceVideo){$extractPayload['source_video']=$sourceVideo;} if($localImu){$extractPayload['imu_jsonl_path']=$localImu; worker_log('IMU | Source sidecar found: '.$localImu);} else { worker_log('IMU | No source IMU sidecar found for video '.basename($input)); } $extractJson=json_encode($extractPayload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); $args = [SFM_REMOTE_BASE . '/run_extract_frames_job.sh', SFM_REMOTE_CONF, (string)$remoteJobId, $input, (string)($ex['fps'] ?? ''), (string)($ex['max_frames'] ?? ''), (string)($ex['scale_width'] ?? ''), (string)($ex['jpeg_quality'] ?? ''), $extractJson, (string)($localImu ?? '')];
    } elseif ($type === AUTO_PHOTO_PREPARE_JOB_TYPE) {
        $params=json_decode((string)($job['parameters_json'] ?? '{}'), true) ?: [];
        $bundleId=(int)($params['capture_bundle_id'] ?? 0);
        if($bundleId<=0){ set_job($db,$id,'ERROR',0,'capture_bundle_id_missing'); return; }
        $q=$db->prepare('SELECT * FROM capture_bundles WHERE id=? LIMIT 1'); if(!$q){set_job($db,$id,'ERROR',0,'capture_bundle_query_failed');return;} $q->bind_param('i',$bundleId); $q->execute(); $row=$q->get_result()->fetch_assoc(); $q->close();
        try { if(!$row) throw new RuntimeException('capture_bundle_missing'); $plan=auto_photo_prepare_plan($row); } catch(Throwable $e) { set_job($db,$id,'ERROR',0,'source_validation_failed: '.$e->getMessage()); return; }
        if((string)($params['app_bundle_uuid']??'') !== $plan['app_bundle_uuid'] || (int)($params['input_images']??0) !== count($plan['frames'])) { set_job($db,$id,'ERROR',0,'parameters_source_mismatch'); return; }
        $manifest=['capture_bundle_id'=>$bundleId,'app_bundle_uuid'=>$plan['app_bundle_uuid'],'frames'=>$plan['frames'],'sidecars'=>$plan['sidecars']];
        $tmp=tempnam(sys_get_temp_dir(),'auto_photo_prepare_'); if($tmp===false){set_job($db,$id,'ERROR',0,'prepare_manifest_create_failed');return;} $launchTemp=$tmp; if(file_put_contents($tmp,json_encode($manifest,JSON_UNESCAPED_SLASHES))===false){@unlink($tmp);set_job($db,$id,'ERROR',0,'prepare_manifest_write_failed');return;}
        $args=[SFM_REMOTE_BASE.'/run_auto_photo_prepare_job.sh',SFM_REMOTE_CONF,(string)$remoteJobId,$plan['photos_dir'],$tmp];
    } elseif ($type === 'COLMAP_SPARSE') {
        $parent = (int)($job['parent_remote_job_id'] ?? 0);
        $rs=worker_run_parameters($db,$job); $sp=$rs['sparse'] ?? []; $pipelineRunId=pipeline_run_for_job($job); $run=[]; $settingsHash=substr(hash('sha256', json_encode($rs, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),0,16); $mode=(string)($job['reconstruction_mode'] ?? 'preview');
        if($pipelineRunId>0){ $st=$db->prepare('SELECT pipeline_mode,video_scan_id FROM sfm_pipeline_runs WHERE id=? LIMIT 1'); if($st){$st->bind_param('i',$pipelineRunId);$st->execute();$run=$st->get_result()->fetch_assoc() ?: [];$st->close(); $mode=(string)($run['pipeline_mode'] ?? $mode); pipeline_log($pipelineRunId,'INFO','SPARSE',sprintf('Sparse settings: mode=%s matcher=%s overlap=%d loop_detection=%d source=ui_snapshot',$mode,(string)($sp['matcher'] ?? 'sequential'),(int)($sp['sequential_overlap'] ?? 60),!empty($sp['loop_detection'])?1:0)); } }
        $stationParams=json_encode(['pipeline_run_id'=>$pipelineRunId,'video_scan_id'=>(int)($run['video_scan_id'] ?? 0),'render_mode'=>$mode,'settings_source'=>'ui_snapshot','settings_hash'=>$settingsHash,'settings'=>$rs], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $args = [SFM_REMOTE_BASE . '/run_colmap_sparse_job.sh', SFM_REMOTE_CONF, (string)$remoteJobId, frames_path_for_parent($parent), (string)($sp['matcher'] ?? 'sequential'), (string)($sp['sequential_overlap'] ?? 60), !empty($sp['loop_detection'])?'1':'0', $stationParams];
    } elseif ($type === 'EXPORT_PLY') {
        try {
            $photoExportPlan = auto_photo_export_worker_plan(
                $job,
                SFM_REMOTE_OUTPUT,
                SFM_REMOTE_BASE . '/export_sparse_ply.sh',
                SFM_REMOTE_CONF
            );
        } catch (Throwable $e) {
            set_job(
                $db,
                $id,
                'ERROR',
                0,
                $e->getMessage()
            );

            worker_log(
                'ERROR photo EXPORT_PLY validation: '
                . $e->getMessage()
            );

            return;
        }

        if ($photoExportPlan['is_photo_export'] === true) {
            try {
                auto_photo_export_worker_prepare_paths(
                    $photoExportPlan,
                    SFM_REMOTE_OUTPUT
                );
            } catch (Throwable $e) {
                set_job(
                    $db,
                    $id,
                    'ERROR',
                    0,
                    $e->getMessage()
                );

                worker_log(
                    'ERROR photo EXPORT_PLY path preparation: '
                    . $e->getMessage()
                );

                return;
            }

            $args = $photoExportPlan['args'];
        } else {
            $parent = (int)($job['parent_remote_job_id'] ?? $remoteJobId);
            $modelId = model_id_from_job($job);
            $args = [SFM_REMOTE_BASE . '/export_sparse_ply.sh', SFM_REMOTE_CONF, (string)$parent, (string)$modelId, SFM_REMOTE_OUTPUT];
        }
    } elseif ($type === 'COLMAP_DENSE_CHUNK') {
        $parentJobId = (int)($job['parent_remote_job_id'] ?? 0);
        $params = json_decode((string)($job['parameters_json'] ?? '{}'), true) ?: [];
        $settings=$params['settings'] ?? worker_run_parameters($db,$job); $dense=$settings['dense'] ?? []; $args = [SFM_REMOTE_BASE . '/run_colmap_dense_chunk_job.sh', SFM_REMOTE_CONF, (string)$remoteJobId, (string)$parentJobId, (string)($params['sparse_job_id'] ?? 0), (string)($params['model_id'] ?? 0), (string)($job['chunk_index'] ?? 0), (string)($params['image_list_path'] ?? ''), (string)($job['reconstruction_mode'] ?? 'preview'), (string)($dense['max_image_size'] ?? ''), (string)($dense['num_src_images'] ?? '')];
    } elseif ($type === 'COLMAP_MESH') {
        $parent = (int)($job['parent_remote_job_id'] ?? 0);
        $mode = (string)($job['reconstruction_mode'] ?: 'preview');
        $params=json_decode((string)($job['parameters_json'] ?? '{}'), true) ?: []; $mesh=($params['settings']['mesh'] ?? (worker_run_parameters($db,$job)['mesh'] ?? [])); if(isset($params['poisson_depth']) && !isset($mesh['depth'])){$mesh['depth']=$params['poisson_depth'];} if(isset($params['target_faces']) && !isset($mesh['target_faces'])){$mesh['target_faces']=$params['target_faces'];} if(isset($params['density_quantile']) && !isset($mesh['density_quantile'])){$mesh['density_quantile']=$params['density_quantile'];} if(isset($params['mesh_engine']) && !isset($mesh['engine'])){$mesh['engine']=$params['mesh_engine'];} $args = [SFM_REMOTE_BASE . '/run_colmap_mesh_job.sh', SFM_REMOTE_CONF, (string)$remoteJobId, (string)$parent, $mode, (string)($mesh['engine'] ?? ''), (string)($mesh['depth'] ?? ''), (string)($mesh['target_faces'] ?? ''), (string)($mesh['density_quantile'] ?? ''), json_encode($mesh, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)];
    } elseif ($type === 'MAKLERTOUR_SYNCED_DENSE') {
        $input=(string)($job['input_path'] ?? '');
        if($input==='' || !is_file($input)){ set_job($db,$id,'ERROR',0,'Capture bundle input file not found'); return; }
        $params=json_decode((string)($job['parameters_json'] ?? '{}'), true) ?: [];
        $maxPairs=(int)($params['max_pairs'] ?? 40); $numDisp=(int)($params['num_disparities'] ?? 128); $block=(int)($params['block_size'] ?? 7);
        $args=[SFM_REMOTE_BASE.'/run_maklertour_synced_dense_job.sh', SFM_REMOTE_CONF, (string)$remoteJobId, $input, (string)$maxPairs, (string)$numDisp, (string)$block];
    } elseif ($type === 'COLMAP_DENSE') {
        $parent = (int)($job['parent_remote_job_id'] ?? 0);
        if ($parent <= 0) {
            throw new RuntimeException('missing COLMAP_SPARSE parent_remote_job_id');
        }
        $modelId = model_id_from_job($job);
        $args = [SFM_REMOTE_BASE . '/run_colmap_dense_job.sh', SFM_REMOTE_CONF, (string)$remoteJobId, (string)$parent, (string)$modelId];
    } else {
        $message = 'unknown job_type: ' . $type;
        set_job($db, $id, 'ERROR', (int)($job['progress_percent'] ?? 0), $message);
        throw new RuntimeException($message);
    }

    worker_log("running command for job id={$id} type={$type} remote_job_id={$remoteJobId}");
    try {
        [$code, $output, $cmd] = run_command($args);
    } catch (Throwable $e) {
        set_job($db, $id, 'ERROR', (int)($job['progress_percent'] ?? 0), $e->getMessage());
        worker_log("ERROR launch {$type} id={$id} remote={$remoteJobId}: " . $e->getMessage());
        return;
    } finally { if ($launchTemp !== null) @unlink($launchTemp); }
    if (
        $type === 'EXPORT_PLY'
        && is_array($photoExportPlan)
        && ($photoExportPlan['is_photo_export'] ?? false) === true
    ) {
        write_job_text_log(
            $remoteJobId,
            'export_ply_stdout_stderr.log',
            $output
        );

        $completion = auto_photo_export_worker_completion(
            $photoExportPlan,
            $code
        );

        set_job(
            $db,
            $id,
            $completion['status'],
            $completion['progress'],
            $completion['message']
        );

        if ($completion['status'] === 'ERROR') {
            worker_log(
                'ERROR photo EXPORT_PLY id='
                . $id
                . ' remote='
                . $remoteJobId
                . ': '
                . $completion['message']
            );
        } else {
            worker_log(
                'DONE photo EXPORT_PLY id='
                . $id
                . ' remote='
                . $remoteJobId
                . ': '
                . $completion['message']
            );
        }

        return;
    }
    if ($code !== 0) {
        $failure = format_command_failure($cmd, $code, $output);
        if ($type === 'EXTRACT_FRAMES') {
            write_extract_frames_failure_result($remoteJobId, $failure);
        }
        set_job($db, $id, 'ERROR', (int)($job['progress_percent'] ?? 0), $failure);
        worker_log("ERROR launch {$type} id={$id} remote={$remoteJobId}: " . $failure);
        return;
    }
    if ($type === 'EXPORT_PLY') {
        write_job_text_log($remoteJobId, 'export_ply_stdout_stderr.log', $output);
        $parent = (int)($job['parent_remote_job_id'] ?? $remoteJobId);
        $modelId = model_id_from_job($job);
        set_job($db, $id, 'DONE', 100, 'PLY exported: job_' . $parent . '/colmap/sparse/' . $modelId . '/model.ply');
    } elseif ($type === 'COLMAP_DENSE_CHUNK') {
        set_job($db, $id, 'RUNNING', 0, 'launched COLMAP_DENSE_CHUNK');
    } elseif ($type === 'MAKLERTOUR_SYNCED_DENSE') {
        set_job($db, $id, 'RUNNING', 0, 'launched MAKLERTOUR_SYNCED_DENSE');
    } elseif ($type === 'COLMAP_DENSE') {
        set_job($db, $id, 'RUNNING', 0, 'dense job launched');
    } else {
        set_job($db, $id, 'RUNNING', 0, $output !== '' ? $output : 'job launched');
    }
    worker_log("launched {$type} id={$id} remote={$remoteJobId}");
}

function orchestrate_reconstruction_parents(mysqli $db): void
{
    $res = $db->query("SELECT * FROM sfm_remote_jobs WHERE job_type IN ('COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ') AND status IN ('RUNNING','PLANNING','RUNNING_CHUNKS','MERGING') ORDER BY updated_at ASC LIMIT 10");
    if (!$res) { return; }
    while ($p = $res->fetch_assoc()) {
        $pid=(int)$p['id']; $pipelineRunId=pipeline_run_for_job($p); $parentRemote=(int)$p['remote_job_id']; $mode=(string)($p['reconstruction_mode'] ?: (str_contains($p['job_type'],'HQ')?'hq':'preview'));
        $params=json_decode((string)($p['parameters_json'] ?? '{}'), true) ?: []; $sparse=(int)($params['sparse_job_id'] ?? $p['parent_remote_job_id'] ?? 0); $model=(int)($params['model_id'] ?? 0);
        $planPath=remote_output_dir($parentRemote).'/chunk_plan.json';
        if (!is_file($planPath)) { set_job($db,$pid,'PLANNING',3,'Waiting for chunk plan generated on station'); continue; }
        $plan=json_decode((string)file_get_contents($planPath), true); if(!is_array($plan)){ set_job($db,$pid,'ERROR',3,'Invalid chunk_plan.json'); continue; }
        $chunks=$plan['chunks'] ?? []; if(($plan['parser_validated'] ?? false) !== true){ set_job($db,$pid,'ERROR',0,'Chunk plan was created by an outdated parser; regenerate parent output'); continue; } $invalidPlanImage=''; foreach($chunks as $chunk){ foreach(($chunk['images'] ?? []) as $imageName){ $imageName=(string)$imageName; if(preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/', $imageName) || !preg_match('/\.(jpg|jpeg|png|webp|tif|tiff|bmp)$/i', $imageName)){ $invalidPlanImage=$imageName; break 2; } } } if($invalidPlanImage !== ''){ set_job($db,$pid,'ERROR',0,'Invalid image name in chunk plan: ' . $invalidPlanImage); continue; } $total=count($chunks); if($total===0){ set_job($db,$pid,'ERROR',0,'No chunks in plan'); continue; }
        $st=$db->prepare("SELECT COUNT(*) c FROM sfm_remote_jobs WHERE parent_remote_job_id=? AND job_type='COLMAP_DENSE_CHUNK' AND status='DONE'");
        $st->bind_param('i',$parentRemote); $st->execute(); $done=(int)$st->get_result()->fetch_assoc()['c']; $st->close();
        $st=$db->prepare("SELECT COUNT(*) c FROM sfm_remote_jobs WHERE parent_remote_job_id=? AND job_type='COLMAP_DENSE_CHUNK' AND status IN ('QUEUED','RUNNING')");
        $st->bind_param('i',$parentRemote); $st->execute(); $active=(int)$st->get_result()->fetch_assoc()['c']; $st->close();
        $st=$db->prepare("SELECT f.* FROM sfm_remote_jobs f WHERE f.parent_remote_job_id=? AND f.job_type='COLMAP_DENSE_CHUNK' AND f.status IN ('ERROR','ERROR_EMPTY') AND NOT EXISTS (SELECT 1 FROM sfm_remote_jobs d WHERE d.parent_remote_job_id=f.parent_remote_job_id AND d.job_type='COLMAP_DENSE_CHUNK' AND d.chunk_index=f.chunk_index AND d.status='DONE') ORDER BY f.updated_at DESC LIMIT 1");
        $st->bind_param('i',$parentRemote); $st->execute(); $failed=$st->get_result()->fetch_assoc(); $st->close();
        if($failed && (int)($failed['retry_count'] ?? 0) <= 0){
            $failedParams=json_decode((string)($failed['parameters_json'] ?? '{}'), true) ?: []; $failedIdx=(int)($failed['chunk_index'] ?? 0);
            $src=(string)($failedParams['image_list_path'] ?? ($chunks[$failedIdx]['image_list_path'] ?? '')); $retryList=preg_replace('/\.txt$/','_retry1.txt',$src);
            if ($retryList === null || $retryList === '') { $retryList = $src . '_retry1.txt'; }
            $retryCmd=[SFM_REMOTE_BASE.'/create_retry_image_list.sh', SFM_REMOTE_CONF, $src, $retryList, '0.75', '3']; [$retryCode,$retryOutput,$retryShellCmd]=run_command($retryCmd);
            if($retryCode !== 0){ $err='Failed to create remote retry image list: '.format_command_failure($retryShellCmd,$retryCode,$retryOutput); worker_log("ERROR {$err}; parent_remote_job_id={$parentRemote} chunk_index={$failedIdx} model_id={$model}"); set_job($db,$pid,'ERROR',(int)(5+($done/$total)*85),$err); continue; }
            worker_log("created retry image list for parent_remote_job_id={$parentRemote} chunk_index={$failedIdx}: " . $retryOutput);
            $oldId=(int)$failed['id']; $db->query('UPDATE sfm_remote_jobs SET retry_count=1 WHERE id=' . $oldId);
            $rid=sfm_job_id($db); $jt='COLMAP_DENSE_CHUNK'; $msg='retry chunk queued after OOM/error'; $pj=json_encode(['sparse_job_id'=>$sparse,'model_id'=>$model,'image_list_path'=>$retryList,'settings'=>($params['settings'] ?? worker_run_parameters($db,$p))], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $st=$db->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,pipeline_run_id,job_type,remote_job_id,parent_remote_job_id,status,progress_percent,message,reconstruction_mode,chunk_index,chunk_count,retry_count,parameters_json) VALUES (?,?,?,?,?,?,'QUEUED',0,?,?,?,?,?,?)"); $retry=1; $orderId=(int)$p['order_id']; $sessionId=(int)$p['capture_session_id'];
            if(!$st){ $err='SQL prepare failed while queuing retry dense chunk: '.$db->error; worker_log("ERROR {$err}; parent_remote_job_id={$parentRemote} chunk_index={$failedIdx} model_id={$model}"); set_job($db,$pid,'ERROR',(int)(5+($done/$total)*85),$err); continue; }
            if(!$st->bind_param('iiisiissiiis',$orderId,$sessionId,$pipelineRunId,$jt,$rid,$parentRemote,$msg,$mode,$failedIdx,$total,$retry,$pj)){ $err='SQL bind_param failed while queuing retry dense chunk: '.$st->error; worker_log("ERROR {$err}; parent_remote_job_id={$parentRemote} chunk_index={$failedIdx} model_id={$model}"); $st->close(); set_job($db,$pid,'ERROR',(int)(5+($done/$total)*85),$err); continue; }
            if(!$st->execute()){ $err='SQL execute failed while queuing retry dense chunk: '.$st->error; worker_log("ERROR {$err}; parent_remote_job_id={$parentRemote} chunk_index={$failedIdx} model_id={$model}"); $st->close(); set_job($db,$pid,'ERROR',(int)(5+($done/$total)*85),$err); continue; }
            $st->close(); if($pipelineRunId>0){ pipeline_log($pipelineRunId,'WARNING','DENSE_CHUNK','chunk=' . ($failedIdx+1) . '/' . $total . ' retry queued with 75% images'); sfm_pipeline_update($db,$pipelineRunId,'RUNNING','DENSE',sfm_pipeline_progress('DENSE',$done,$total),'Dense reconstruction: retry chunk '.($failedIdx+1).' of '.$total); } set_job($db,$pid,'RUNNING_CHUNKS',(int)(5+($done/$total)*85),"Retry queued for chunk {$failedIdx}"); continue;
        } elseif($failed) { set_job($db,$pid,'ERROR',(int)(5+($done/$total)*85),'Chunk failed after retry; merge skipped'); continue; }
        if($done >= $total){
            $verticesTotal=0; for($i=0;$i<$total;$i++){ $verticesTotal+=chunk_result_vertices($parentRemote,$i); } if($verticesTotal<=0){ set_job($db,$pid,'ERROR',95,'Dense fusion produced zero vertices'); continue; }
            try {
                $mergeMode = resolve_dense_merge_mode((string)$p['job_type']);
                $parentOutputDir = remote_output_dir($parentRemote);
                $outputPly = $parentOutputDir . '/merged/merged_fused.ply';
                $inputPlyFiles = dense_merge_input_ply_files($parentOutputDir, $total);
                $inputPlySizes = dense_merge_input_ply_sizes($inputPlyFiles);
            } catch (Throwable $e) {
                set_job($db, $pid, 'ERROR', 95, $e->getMessage());
                worker_log("ERROR dense merge {$e->getMessage()}; child_job_id={$pid} parent_remote_job_id={$parentRemote}");
                continue;
            }
            worker_log(
                'starting dense merge: ' . json_encode([
                    'parent_job_id' => $parentRemote,
                    'child_job_id' => $pid,
                    'child_job_type' => (string)$p['job_type'],
                    'merge_mode' => $mergeMode,
                    'parent_output_dir' => $parentOutputDir,
                    'output_ply' => $outputPly,
                    'input_ply' => $inputPlyFiles,
                    'input_ply_sizes' => $inputPlySizes,
                ], JSON_UNESCAPED_SLASHES)
            );
            $cmd=[SFM_REMOTE_BASE.'/run_colmap_dense_merge_job.sh', SFM_REMOTE_CONF, (string)$parentRemote, $mergeMode, SFM_REMOTE_OUTPUT]; [$code,$output,$c]=run_command($cmd);
            $mergeLog='/home/makler_storage/logs/job_'.$parentRemote.'_merge.log'; @mkdir(dirname($mergeLog),0775,true); @file_put_contents($mergeLog, '['.date('c').'] '.$c."\nexit_code=".$code."\n".$output."\n");
            if ($code === 0) {
                $message = $output !== ''
                    ? $output
                    : 'Merge completed';

                if($pipelineRunId>0){ pipeline_log($pipelineRunId,'INFO','MERGE','Done vertices='.$verticesTotal); sfm_pipeline_update($db,$pipelineRunId,'RUNNING','MESH',88,'Mesh generation queued',['dense_points'=>$verticesTotal]); }
                set_job(
    $db,
    $pid,
    'DONE',
    100,
    $message
);

$completedParent = $p;
$completedParent['status'] = 'DONE';
$completedParent['progress_percent'] = 100;
$completedParent['message'] = $message;

auto_chain_after_done(
    $db,
    $completedParent
);
            } else {
                $message = $output !== ''
                    ? $output
                    : 'Merge failed with exit code ' . $code;

                set_job(
                    $db,
                    $pid,
                    'ERROR',
                    95,
                    $message
                );
            }
            continue;
        }
        if($pipelineRunId>0){ sfm_pipeline_update($db,$pipelineRunId,'RUNNING','DENSE',sfm_pipeline_progress('DENSE',$done,$total),'Dense reconstruction: chunk '.min($done+1,$total).' of '.$total); }
        if($active===0){ $next=$chunks[$done]; if($pipelineRunId>0){ pipeline_log($pipelineRunId,'INFO','DENSE_CHUNK','chunk=' . (((int)$next['chunk_id'])+1) . '/' . $total . ' started'); } $rid=sfm_job_id($db); $jt='COLMAP_DENSE_CHUNK'; $msg='chunk queued'; $pj=json_encode(['sparse_job_id'=>$sparse,'model_id'=>$model,'image_list_path'=>$next['image_list_path'],'settings'=>($params['settings'] ?? worker_run_parameters($db,$p))], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); $idx=(int)$next['chunk_id']; $orderId=(int)$p['order_id']; $sessionId=(int)$p['capture_session_id']; $st=$db->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,pipeline_run_id,job_type,remote_job_id,parent_remote_job_id,status,progress_percent,message,reconstruction_mode,chunk_index,chunk_count,parameters_json) VALUES (?,?,?,?,?,?,'QUEUED',0,?,?,?,?,?)"); $st->bind_param('iiisiissiis',$orderId,$sessionId,$pipelineRunId,$jt,$rid,$parentRemote,$msg,$mode,$idx,$total,$pj); $st->execute(); $st->close(); }
        set_job($db,$pid,'RUNNING_CHUNKS',(int)(5+($done/$total)*85),"Chunks {$done}/{$total} done");
    }
    $res->close();
}

function sync_running_jobs(mysqli $db): void
{
    $res = $db->query("SELECT * FROM sfm_remote_jobs WHERE status='RUNNING' ORDER BY updated_at ASC LIMIT 20");
    if (!$res) {
        return;
    }
    while ($job = $res->fetch_assoc()) {
        $id = (int)$job['id'];
        $remote = (int)$job['remote_job_id'];
        $type = (string)$job['job_type'];
        worker_log("running status command for job id={$id} type={$type} remote_job_id={$remote}");
        try {
            [$code, $raw, $cmd] = run_command([SFM_REMOTE_BASE . '/get_station_status.sh', SFM_REMOTE_CONF, (string)$remote]);
        } catch (Throwable $e) {
            set_job($db, $id, 'ERROR', (int)($job['progress_percent'] ?? 0), $e->getMessage());
            worker_log("ERROR status id={$id} remote={$remote}: " . $e->getMessage());
            continue;
        }
        $json = json_decode($raw, true);
        if ($code !== 0 || !is_array($json)) {
            $message = $code !== 0 ? format_command_failure($cmd, $code, $raw) : ($raw !== '' ? "Invalid status response from command: {$cmd}\nOutput:\n{$raw}" : "Invalid status response from command: {$cmd}");
            set_job($db, $id, 'RUNNING', (int)($job['progress_percent'] ?? 0), $message);
            continue;
        }
        $remoteStatus = strtoupper((string)($json['status'] ?? 'RUNNING'));
        $progress = (int)($json['progress_percent'] ?? $json['progress'] ?? $job['progress_percent'] ?? 0);
        $message = (string)($json['message'] ?? $raw);
        if ($type === 'COLMAP_DENSE_CHUNK' && $remoteStatus === 'RUNNING') {
            $statusAge = null;
            if (!empty($json['updated_at'])) {
                $ts = strtotime((string)$json['updated_at']);
                if ($ts !== false) { $statusAge = time() - $ts; }
            }
            if ($statusAge !== null && $statusAge > SFM_DENSE_STALE_TIMEOUT_SECONDS
                && $progress === (int)($job['progress_percent'] ?? -1)
                && $message === (string)($job['message'] ?? '')
            ) {
                try {
                    [$hCode, $hRaw, $hCmd] = run_command([SFM_REMOTE_BASE . '/get_remote_job_health.sh', SFM_REMOTE_CONF, (string)$remote, (string)($job['parent_remote_job_id'] ?? 0)]);
                    $health = $hCode === 0 ? (json_decode($hRaw, true) ?: []) : [];
                } catch (Throwable $e) {
                    $health = [];
                }
                $deadOrAborted = (($health['process_present'] ?? true) === false && ($health['container_present'] ?? true) === false) || (($health['log_has_sigabrt'] ?? false) === true);
                if ($deadOrAborted) {
                    $staleMessage = 'Dense chunk stale: remote status not updated for ' . $statusAge . 's; process/container absent or PatchMatch log shows SIGABRT';
                    pipeline_job_log($db, $job, 'ERROR', 'DENSE_CHUNK', $staleMessage);
                    set_job($db, $id, 'ERROR', 0, $staleMessage);
                    continue;
                }
            }
        }
        if ($type === 'COLMAP_MESH' && $remoteStatus === 'RUNNING') {
            $pidRun = pipeline_run_for_job($job);
            if ($pidRun > 0) {
                $meshPipelineProgress = min(99, 88 + (int)floor(max(0, min(100, $progress)) * 11 / 100));
                sfm_pipeline_update($db, $pidRun, 'RUNNING', 'MESH', $meshPipelineProgress, $message !== '' ? $message : 'Mesh generation running');
            }
        }
        if ($remoteStatus === 'DONE') {
            if ($type === 'MAKLERTOUR_SYNCED_DENSE') {
                try { [$fetchCode,$fetchOut,$fetchCmd]=run_command([SFM_REMOTE_BASE.'/fetch_job_result.sh', SFM_REMOTE_CONF, (string)$remote, SFM_REMOTE_OUTPUT]); }
                catch(Throwable $e){ set_job($db,$id,'ERROR',$progress,'Remote synced dense DONE but fetch failed: '.$e->getMessage()); continue; }
                if($fetchCode!==0){ set_job($db,$id,'ERROR',$progress,'Remote synced dense DONE but fetch failed: '.format_command_failure($fetchCmd,$fetchCode,$fetchOut)); continue; }
                $result=remote_output_dir($remote).'/result.json';
                $jpg=remote_output_dir($remote).'/dense/contact_dense_depth.jpg';
                if(!is_file($result)){ set_job($db,$id,'ERROR',$progress,'Remote synced dense result.json missing after fetch'); continue; }
                set_job($db,$id,'DONE',100,'Synced stereo dense completed');
                continue;
            }
            if ($type === 'COLMAP_MESH') {
                worker_log("running mesh fetch command for job id={$id} type={$type} remote_job_id={$remote}");
                try {
                    [$fetchCode, $fetchOut, $fetchCmd] = run_command([
                        SFM_REMOTE_BASE . '/fetch_job_result.sh',
                        SFM_REMOTE_CONF,
                        (string)$remote,
                        SFM_REMOTE_OUTPUT,
                    ]);
                } catch (Throwable $e) {
                    set_job($db, $id, 'ERROR', $progress, 'Remote mesh DONE but fetch_job_result.sh failed: ' . $e->getMessage());
                    worker_log("ERROR mesh fetch id={$id} remote={$remote}: " . $e->getMessage());
                    continue;
                }
                if ($fetchCode !== 0) {
                    set_job($db, $id, 'ERROR', $progress, 'Remote mesh DONE but fetch_job_result.sh failed: ' . format_command_failure($fetchCmd, $fetchCode, $fetchOut));
                    continue;
                }

                $localDir = remote_output_dir($remote);
                $resultPath = $localDir . '/mesh/mesh_result.json';
                $localMesh = remote_output_dir($remote) . '/mesh/mesh_final.ply';
                if (!is_file($resultPath) || !is_readable($resultPath)) {
                    set_job($db, $id, 'ERROR', $progress, 'Remote mesh DONE but mesh_result.json is missing after fetch');
                    continue;
                }
                $meshInfo = ply_header_info($localMesh);
                if (!$meshInfo['ok'] || (int)$meshInfo['vertices'] <= 0 || (int)$meshInfo['faces'] <= 0) {
                    set_job($db, $id, 'ERROR', $progress, 'Remote mesh DONE but mesh_final.ply is invalid or missing');
                    continue;
                }
                $rd = json_decode((string)file_get_contents($resultPath), true) ?: [];
                $engine = strtoupper((string)($rd['engine'] ?? 'COLMAP')) === 'OPEN3D' ? 'Open3D' : 'COLMAP';
                $pidRun=pipeline_run_for_job($job); if($pidRun>0){ $pdir=sfm_pipeline_output_dir($pidRun); @mkdir($pdir,0775,true); @copy(remote_output_dir($remote).'/mesh/mesh_final.ply',$pdir.'/mesh.ply'); $parent=(int)($job['parent_remote_job_id'] ?? 0); @copy(remote_output_dir($parent).'/merged/merged_fused.ply',$pdir.'/point_cloud.ply'); $resultPath=$pdir.'/pipeline_result.json'; $runRes=$db->query('SELECT * FROM sfm_pipeline_runs WHERE id='.(int)$pidRun); $runRow=$runRes?$runRes->fetch_assoc():[]; if($runRes){$runRes->close();} $resultData=['status'=>'DONE','pipeline_run_id'=>$pidRun,'pipeline_mode'=>$runRow['pipeline_mode'] ?? '', 'label'=>sfm_pipeline_preset((string)($runRow['pipeline_mode'] ?? 'preview'))['label'],'max_image_size'=>(int)($runRow['max_image_size'] ?? 0),'video_scan_id'=>(int)($runRow['video_scan_id'] ?? 0),'source_video_filename'=>(string)((json_decode((string)($runRow['parameters_json'] ?? '{}'),true)['source_video']['filename'] ?? '')),'source_video_duration_sec'=>(float)((json_decode((string)($runRow['parameters_json'] ?? '{}'),true)['source_video']['duration_sec'] ?? 0)),'sparse_model_id'=>(int)($runRow['sparse_model_id'] ?? 0),'registered_images'=>(int)($runRow['registered_images'] ?? 0),'sparse_points'=>(int)($runRow['sparse_points'] ?? 0),'dense_points'=>(int)($runRow['dense_points'] ?? 0),'mesh_vertices'=>(int)$meshInfo['vertices'],'mesh_faces'=>(int)$meshInfo['faces'],'point_cloud_path'=>$pdir.'/point_cloud.ply','mesh_path'=>$pdir.'/mesh.ply']; @file_put_contents($resultPath,json_encode($resultData,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); $meshStatsPath=remote_output_dir($remote).'/mesh/mesh_stats.json'; $meshStats=is_file($meshStatsPath)?(json_decode((string)file_get_contents($meshStatsPath),true)?:[]):[]; if($meshStats){ pipeline_log($pidRun,'INFO','MESH','Raw Poisson faces='.(int)($meshStats['raw_poisson_faces']??($meshStats['poisson_faces_before_filter']??0))); pipeline_log($pidRun,'INFO','MESH','Density filtered faces='.(int)($meshStats['density_filtered_faces']??0)); pipeline_log($pidRun,'INFO','MESH','Long-edge filtered faces='.(int)($meshStats['edge_filtered_faces']??0)); pipeline_log($pidRun,'INFO','MESH','Component filtered faces='.(int)($meshStats['component_filtered_faces']??0)); pipeline_log($pidRun,'INFO','MESH','Component fallback used='.(empty($meshStats['component_filter_fallback_used'])?'false':'true')); pipeline_log($pidRun,'INFO','MESH','Final stage='.(string)($meshStats['selected_final_stage']??'')); } pipeline_log($pidRun,'INFO','MESH','Done vertices='.(int)$meshInfo['vertices'].' faces='.(int)$meshInfo['faces']); pipeline_log($pidRun,'INFO','PIPELINE','Completed'); sfm_pipeline_update($db,$pidRun,'DONE','DONE',100,'Result ready',['mesh_vertices'=>(int)$meshInfo['vertices'],'mesh_faces'=>(int)$meshInfo['faces'],'output_point_cloud_path'=>$pdir.'/point_cloud.ply','output_mesh_path'=>$pdir.'/mesh.ply','output_result_json_path'=>$resultPath]); try { sfm_remote_cleanup_maybe_schedule($db,$pidRun); } catch (Throwable $cleanupError) { worker_log('WARNING cleanup schedule failed pipeline_run_id='.$pidRun.': '.$cleanupError->getMessage()); } } set_job($db, $id, 'DONE', 100, 'Mesh completed');
                auto_chain_after_done($db, $job);
                continue;
            }
$fetchRemote = $remote;

if ($type === 'COLMAP_DENSE_CHUNK') {
    $fetchRemote = (int)($job['parent_remote_job_id'] ?? 0);

    if ($fetchRemote <= 0) {
        set_job(
            $db,
            $id,
            'ERROR',
            $progress,
            'Dense chunk has no parent_remote_job_id for result fetch'
        );
        continue;
    }
}

worker_log(
    "running fetch command for job id={$id}" .
    " type={$type}" .
    " remote_job_id={$remote}" .
    " fetch_remote_job_id={$fetchRemote}"
);

try {
    [$fetchCode, $fetchOut, $fetchCmd] = run_command([
        SFM_REMOTE_BASE . '/fetch_job_result.sh',
        SFM_REMOTE_CONF,
        (string)$fetchRemote,
        SFM_REMOTE_OUTPUT,
    ]);
                $fetchMessage = $fetchCode === 0 ? ($fetchOut !== '' ? $fetchOut : $message) : format_command_failure($fetchCmd, $fetchCode, $fetchOut);
            } catch (Throwable $e) {
                $fetchCode = 1;
                $fetchMessage = $e->getMessage();
                worker_log("ERROR fetch id={$id} remote={$remote}: " . $e->getMessage());
            }
            if ($fetchCode === 0) {
                if ($type === AUTO_PHOTO_PREPARE_JOB_TYPE) {
                    $resultPath=remote_output_dir($remote).'/result.json'; $prepared=json_decode((string)@file_get_contents($resultPath),true);
                    $pp=json_decode((string)($job['parameters_json']??'{}'),true)?:[]; $bools=['camera_metadata_present','scan_imu_present','photos_metadata_present','manifest_present','bundle_manifest_present','idempotent'];
                    $valid=is_array($prepared) && ($prepared['schema_version']??null)===1 && ($prepared['job_type']??'')===AUTO_PHOTO_PREPARE_JOB_TYPE && (int)($prepared['remote_job_id']??0)===$remote && (int)($prepared['capture_bundle_id']??0)===(int)($pp['capture_bundle_id']??0) && ($prepared['app_bundle_uuid']??'')===($pp['app_bundle_uuid']??'') && ($prepared['status']??'')==='DONE' && (int)($prepared['frames_count']??-1)===(int)($pp['input_images']??-2) && ($prepared['frames_directory']??'')==='frames' && is_array($prepared['warnings']??null);
                    foreach($bools as $k)$valid=$valid&&is_bool($prepared[$k]??null);
                    if(!$valid){set_job($db,$id,'ERROR',$progress,'prepare_result_invalid_after_fetch');continue;}
                    set_job($db,$id,'DONE',100,$fetchMessage); auto_chain_after_done($db,$job); continue;
                }
                if ($type === 'COLMAP_DENSE_CHUNK') {
                    $vertices = chunk_result_vertices((int)($job['parent_remote_job_id'] ?? 0), (int)($job['chunk_index'] ?? 0));
                    if ($vertices <= 0) { set_job($db, $id, 'ERROR_EMPTY', 100, 'Dense fusion produced zero vertices'); continue; }
                    $pidRun = pipeline_run_for_job($job);
                    if ($pidRun > 0) { pipeline_log($pidRun, 'INFO', 'DENSE_CHUNK', 'chunk=' . (((int)($job['chunk_index'] ?? 0)) + 1) . '/' . max(1, (int)($job['chunk_count'] ?? 1)) . ' done vertices=' . $vertices); }
                }
                set_job($db, $id, 'DONE', 100, $fetchMessage);
                auto_chain_after_done($db, $job);
            } else {
                set_job($db, $id, 'ERROR', $progress, $fetchMessage);
            }
    } elseif (
         $remoteStatus === 'FAILED'
         || str_starts_with($remoteStatus, 'ERROR')
    ) {
         set_job($db, $id, 'ERROR', $progress, $message);
    } else {
            if ($type === 'COLMAP_DENSE_CHUNK') {
                update_parent_pipeline_from_dense_child($db, $job, $progress, $message, $remoteStatus);
            }
            set_job($db, $id, 'RUNNING', $progress, $message);
        }
    }
    $res->close();
}

function process_cancel_requests(mysqli $db): void
{
    $res = $db->query("SELECT * FROM sfm_remote_jobs WHERE status='CANCELLING' OR cancel_requested_at IS NOT NULL ORDER BY updated_at ASC LIMIT 10");
    if (!$res) { return; }
    while ($job = $res->fetch_assoc()) {
        $id = (int)$job['id'];
        $rid = (int)($job['remote_job_id'] ?? 0);
        $parent = (int)($job['parent_remote_job_id'] ?? 0);
        if ($parent <= 0) { $parent = $rid; }
        try {
            [$code, $output, $cmd] = run_command([SFM_REMOTE_BASE . '/cancel_remote_job.sh', SFM_REMOTE_CONF, (string)$rid, (string)$parent]);
            $json = json_decode($output, true);
            $ok = $code === 0 && is_array($json) && !empty($json['cancelled']);
            if ($ok) {
                $st=$db->prepare("UPDATE sfm_remote_jobs SET status='CANCELLED', progress_percent=100, message='Cancelled by worker', cancelled_at=NOW(6), updated_at=NOW(6) WHERE id=?");
                if($st){$st->bind_param('i',$id);$st->execute();$st->close();}
            } else {
                $msg = 'Cancellation failed: ' . ($output !== '' ? mb_substr($output, 0, 1000) : ('exit code '.$code));
                $st=$db->prepare("UPDATE sfm_remote_jobs SET status='CANCEL_ERROR', message=?, updated_at=NOW(6) WHERE id=?");
                if($st){$st->bind_param('si',$msg,$id);$st->execute();$st->close();}
            }
            $pid = pipeline_run_for_job($job);
            if ($pid > 0) {
                $active = $db->query("SELECT COUNT(*) AS c FROM sfm_remote_jobs WHERE pipeline_run_id=".(int)$pid." AND status IN ('QUEUED','RUNNING','RUNNING_CHUNKS','PLANNING','MERGING','CANCELLING')");
                $cnt = $active ? (int)($active->fetch_assoc()['c'] ?? 0) : 0; if($active){$active->close();}
                if ($cnt === 0) {
                    $err = $db->query("SELECT COUNT(*) AS c FROM sfm_remote_jobs WHERE pipeline_run_id=".(int)$pid." AND status='CANCEL_ERROR'");
                    $ec = $err ? (int)($err->fetch_assoc()['c'] ?? 0) : 0; if($err){$err->close();}
                    if ($ec > 0) { sfm_pipeline_update($db,$pid,'ERROR','CANCELLING',0,'Cancellation failed'); }
                    else { sfm_pipeline_update($db,$pid,'CANCELLED','CANCELLED',100,'Cancelled by worker'); try { sfm_remote_cleanup_maybe_schedule($db,$pid); } catch (Throwable $cleanupError) { worker_log('WARNING cleanup schedule failed pipeline_run_id='.$pid.': '.$cleanupError->getMessage()); } }
                }
            }
        } catch (Throwable $e) {
            $msg='Cancellation failed: '.$e->getMessage();
            $st=$db->prepare("UPDATE sfm_remote_jobs SET status='CANCEL_ERROR', message=?, updated_at=NOW(6) WHERE id=?");
            if($st){$st->bind_param('si',$msg,$id);$st->execute();$st->close();}
        }
    }
    $res->close();
}


function schedule_terminal_remote_cleanups(mysqli $db): void
{
    sfm_remote_cleanup_require_schema($db);
    $res = $db->query("SELECT p.id FROM sfm_pipeline_runs p WHERE p.status IN ('DONE','FAILED','CANCELLED','ERROR') AND NOT EXISTS (SELECT 1 FROM sfm_remote_jobs r WHERE r.pipeline_run_id=p.id AND r.status='CANCEL_ERROR') AND NOT EXISTS (SELECT 1 FROM sfm_remote_cleanup_runs c WHERE c.pipeline_run_id=p.id AND c.remote_cleanup_status IN ('PENDING','RUNNING','ERROR','DONE','SKIPPED')) ORDER BY p.id ASC LIMIT 20");
    if ($res) { while ($row = $res->fetch_assoc()) { $pid = (int)($row['id'] ?? 0); if ($pid > 0) { sfm_remote_cleanup_maybe_schedule($db, $pid); } } $res->close(); }
    $jobs = $db->query("SELECT r.remote_job_id FROM sfm_remote_jobs r WHERE r.pipeline_run_id IS NULL AND r.status IN ('DONE','ERROR','ERROR_EMPTY','ERROR_EMPTY_MESH','ERROR_OOM','ERROR_STALE','FAILED','CANCELLED') AND (r.job_type IN ('MAKLERTOUR_SYNCED_DENSE','EXPORT_PLY') OR (r.job_type IN ('EXTRACT_FRAMES','COLMAP_SPARSE','COLMAP_DENSE','COLMAP_DENSE_CHUNK') AND r.parameters_json IS NOT NULL AND JSON_VALID(r.parameters_json) AND JSON_UNQUOTE(JSON_EXTRACT(r.parameters_json,'$.cleanup_scope'))='standalone')) AND NOT EXISTS (SELECT 1 FROM sfm_remote_cleanup_runs c WHERE c.remote_job_id=r.remote_job_id AND c.remote_cleanup_status IN ('PENDING','RUNNING','ERROR','DONE','SKIPPED')) ORDER BY r.updated_at ASC LIMIT 20");
    if ($jobs) { while ($row = $jobs->fetch_assoc()) { $rid = (int)($row['remote_job_id'] ?? 0); if ($rid > 0) { sfm_remote_cleanup_maybe_schedule_remote_job($db, $rid); } } $jobs->close(); }
}

function reconcile_pipeline_runs(mysqli $db): void
{
    $res=$db->query("SELECT * FROM sfm_pipeline_runs WHERE status IN ('QUEUED','RUNNING')");
    if(!$res){ return; }
    while($run=$res->fetch_assoc()){
        $pid=(int)$run['id']; $root=(int)($run['root_remote_job_id'] ?? 0); $orderId=(int)$run['order_id']; $sessionId=(int)$run['capture_session_id']; $started=(string)($run['started_at'] ?? '1970-01-01');
        if($pid<=0 || $root<=0){ continue; }
        $st=$db->prepare("SELECT * FROM sfm_remote_jobs WHERE pipeline_run_id=? AND job_type='EXTRACT_FRAMES' AND status='DONE' ORDER BY id DESC LIMIT 1"); if(!$st){continue;} $st->bind_param('i',$pid); $st->execute(); $extract=$st->get_result()->fetch_assoc(); $st->close();
        if($extract){
            $remote=(int)$extract['remote_job_id'];
            $st=$db->prepare("SELECT * FROM sfm_remote_jobs WHERE pipeline_run_id=? AND job_type='COLMAP_SPARSE' LIMIT 1"); $st->bind_param('i',$pid); $st->execute(); $sparse=$st->get_result()->fetch_assoc(); $st->close();
            if(!$sparse){
                $st=$db->prepare("SELECT * FROM sfm_remote_jobs WHERE pipeline_run_id IS NULL AND job_type='COLMAP_SPARSE' AND parent_remote_job_id=? AND order_id=? AND capture_session_id=? AND created_at>=? LIMIT 1");
                if($st){ $st->bind_param('iiis',$root,$orderId,$sessionId,$started); $st->execute(); $orphan=$st->get_result()->fetch_assoc(); $st->close(); if($orphan){ $oid=(int)$orphan['id']; $u=$db->prepare('UPDATE sfm_remote_jobs SET pipeline_run_id=? WHERE id=?'); if($u){$u->bind_param('ii',$pid,$oid);$u->execute();$u->close(); pipeline_log($pid,'INFO','SPARSE','Recovered orphan COLMAP_SPARSE job '.(int)$orphan['remote_job_id']);} continue; } }
                auto_chain_after_done($db,$extract);
            }
        }
        $st=$db->prepare("SELECT * FROM sfm_remote_jobs WHERE pipeline_run_id=? AND job_type='COLMAP_SPARSE' AND status='DONE' ORDER BY id DESC LIMIT 1"); if(!$st){continue;} $st->bind_param('i',$pid); $st->execute(); $sparseDone=$st->get_result()->fetch_assoc(); $st->close();
        if($sparseDone){
            $remote=(int)$sparseDone['remote_job_id'];
            $st=$db->prepare("SELECT id FROM sfm_remote_jobs WHERE pipeline_run_id=? AND job_type='COLMAP_RECONSTRUCTION_PREVIEW' LIMIT 1"); $st->bind_param('i',$pid); $st->execute(); $dense=$st->get_result()->fetch_assoc(); $st->close();
            if(!$dense){ auto_chain_after_done($db,$sparseDone); }
        }
    }
    $res->close();
}

ensure_sfm_remote_jobs_table($dbcnx);
ensure_sfm_remote_jobs_chunk_columns($dbcnx);
ensure_sfm_pipeline_tables($dbcnx);
if (in_array('--cleanup-worker', $argv, true)) {
    sfm_remote_cleanup_require_schema($dbcnx);
    worker_log('MaklerTour SfM remote cleanup worker started');
    while (true) {
        try { schedule_terminal_remote_cleanups($dbcnx); sfm_remote_cleanup_worker_tick($dbcnx, 1); }
        catch (Throwable $e) { worker_log('ERROR cleanup ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()); }
        sleep(10);
    }
}
worker_log('MaklerTour SfM remote worker started');
worker_log('SFM_REMOTE_BASE=' . SFM_REMOTE_BASE);
worker_log('SFM_REMOTE_CONF=' . SFM_REMOTE_CONF);
worker_log('SFM_REMOTE_OUTPUT=' . SFM_REMOTE_OUTPUT);
while (true) {
    try {
        process_cancel_requests($dbcnx);
        sync_running_jobs($dbcnx);
        reconcile_pipeline_runs($dbcnx);
        orchestrate_reconstruction_parents($dbcnx);
        $job = claim_next_job($dbcnx);
        if ($job) {
            try {
                launch_job($dbcnx, $job);
            } catch (Throwable $e) {
                $id = (int)($job['id'] ?? 0);
                if ($id > 0) {
                    set_job($dbcnx, $id, 'ERROR', (int)($job['progress_percent'] ?? 0), $e->getMessage());
                }
                worker_log('ERROR launch job id=' . $id . ' type=' . (string)($job['job_type'] ?? '') . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }
        }
    } catch (Throwable $e) {
        worker_log('ERROR ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
    sleep(2);
}
