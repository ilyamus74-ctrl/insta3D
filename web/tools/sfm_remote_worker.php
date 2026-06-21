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

const SFM_REMOTE_BASE = '/home/makler/web/remote_station';
const SFM_REMOTE_CONF = '/home/makler/web/remote_station/stations.conf';
const SFM_REMOTE_OUTPUT = '/home/makler/web/remote_station/output';
const SFM_REMOTE_STORAGE_OUTPUT = '/home/makler_storage/output';
const AUTO_SFM_EXPORT_MODELS = [0, 1];
const AUTO_SFM_DENSE_AFTER_SPARSE = false;

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
    ];
    foreach ($cols as $col => $sql) {
        $res = $db->query("SHOW COLUMNS FROM sfm_remote_jobs LIKE '" . $db->real_escape_string($col) . "'");
        $exists = $res && $res->num_rows > 0;
        if ($res) { $res->close(); }
        if (!$exists) { $db->query($sql); }
    }
}

function sfm_job_id(mysqli $db): int
{
    do {
        $id = random_int(10000, 999999999);
        $st = $db->prepare('SELECT id FROM sfm_remote_jobs WHERE remote_job_id=? LIMIT 1');
        if (!$st) {
            return $id;
        }
        $st->bind_param('i', $id);
        $st->execute();
        $exists = $st->get_result()->fetch_assoc();
        $st->close();
    } while ($exists);
    return $id;
}

function remote_output_dir(int $remoteJobId): string
{
    return rtrim(SFM_REMOTE_OUTPUT, '/') . '/job_' . $remoteJobId;
}

function auto_chain_after_done(mysqli $db, array $job): void
{
    $type = (string)$job['job_type'];
    $remote = (int)$job['remote_job_id'];
    $orderId = (int)$job['order_id'];
    $sessionId = (int)$job['capture_session_id'];
    if ($remote <= 0 || $orderId <= 0 || $sessionId <= 0) {
        return;
    }

    if ($type === 'EXTRACT_FRAMES') {
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
        $rid = sfm_job_id($db);
        $input = frames_path_for_parent($remote);
        $out = remote_output_dir($rid);
        $result = $out . '/result.json';
        $log = $out . '/logs';
        $jt = 'COLMAP_SPARSE';
        $msg = 'Auto queued after extract frames';
        $st = $db->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,job_type,remote_job_id,parent_remote_job_id,input_path,output_path,status,progress_percent,message,result_json_path,log_path) VALUES (?,?,?,?,?,?,?,'QUEUED',0,?,?,?)");
        if ($st) {
            $st->bind_param('iisiisssss', $orderId, $sessionId, $jt, $rid, $remote, $input, $out, $msg, $result, $log);
            $st->execute();
            $st->close();
            worker_log("auto queued COLMAP_SPARSE parent={$remote} remote_job_id={$rid}");
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

function safe_session_video_path(mysqli $db, array $job): string
{
    $input = (string)($job['input_path'] ?? '');
    $orderId = (int)$job['order_id'];
    $sessionId = (int)$job['capture_session_id'];
    $st = $db->prepare('SELECT app_session_uuid FROM capture_sessions WHERE id=? AND order_id=? AND deleted_at IS NULL LIMIT 1');
    if (!$st) {
        throw new RuntimeException('prepare session lookup failed: ' . $db->error);
    }
    $st->bind_param('ii', $sessionId, $orderId);
    $st->execute();
    $session = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$session) {
        throw new RuntimeException('capture session not found');
    }
    $safeUuid = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)$session['app_session_uuid']);
    $base = realpath(rtrim(APP_STORAGE_DIR, '/') . '/orders/' . $orderId . '/sessions/' . $safeUuid . '/videos');
    $real = realpath($input);
    if ($base === false || $real === false || !is_file($real) || strpos($real, $base . '/') !== 0) {
        throw new RuntimeException('input_path is outside allowed session videos directory');
    }
    return $real;
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

function launch_job(mysqli $db, array $job): void
{
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
        $target = (int)($params['target_images_per_chunk'] ?? ($mode === 'hq' ? 60 : 50));
        $max = (int)($params['max_images_per_chunk'] ?? ($mode === 'hq' ? 100 : 80));
        $overlap = (int)($params['overlap_images'] ?? ($mode === 'hq' ? 20 : 15));
        $reserve = (int)($params['ram_reserve_mb'] ?? 3000);
        $outDir = remote_output_dir($remoteJobId);
        if (!is_dir($outDir)) { @mkdir($outDir, 0775, true); }
        [$code, $output, $cmd] = run_command([SFM_REMOTE_BASE . '/run_colmap_chunk_plan_job.sh', SFM_REMOTE_CONF, (string)$remoteJobId, (string)$sparse, (string)$model, $mode, (string)$target, (string)$max, (string)$overlap, (string)$reserve, SFM_REMOTE_OUTPUT]);
        if ($code !== 0) { set_job($db, $id, 'ERROR', 0, format_command_failure($cmd, $code, $output)); return; }
        $plan = json_decode((string)@file_get_contents($outDir . '/chunk_plan.json'), true) ?: [];
        $chunkCount = count($plan['chunks'] ?? []);
        $st = $db->prepare('UPDATE sfm_remote_jobs SET chunk_count=?, message=?, updated_at=NOW(6) WHERE id=?');
        if ($st) { $msg = 'Chunk plan ready: ' . $chunkCount . ' chunks'; $st->bind_param('isi', $chunkCount, $msg, $id); $st->execute(); $st->close(); }
        set_job($db, $id, 'RUNNING', 5, 'Chunk plan ready: ' . $chunkCount . ' chunks');
        return;
    }
    if ($type === 'EXTRACT_FRAMES') {
        $input = safe_session_video_path($db, $job);
        $args = [SFM_REMOTE_BASE . '/run_extract_frames_job.sh', SFM_REMOTE_CONF, (string)$remoteJobId, $input];
    } elseif ($type === 'COLMAP_SPARSE') {
        $parent = (int)($job['parent_remote_job_id'] ?? 0);
        $args = [SFM_REMOTE_BASE . '/run_colmap_sparse_job.sh', SFM_REMOTE_CONF, (string)$remoteJobId, frames_path_for_parent($parent)];
    } elseif ($type === 'EXPORT_PLY') {
        $parent = (int)($job['parent_remote_job_id'] ?? $remoteJobId);
        $modelId = model_id_from_job($job);
        $args = [SFM_REMOTE_BASE . '/export_sparse_ply.sh', SFM_REMOTE_CONF, (string)$parent, (string)$modelId, SFM_REMOTE_OUTPUT];
    } elseif ($type === 'COLMAP_DENSE_CHUNK') {
        $parentJobId = (int)($job['parent_remote_job_id'] ?? 0);
        $params = json_decode((string)($job['parameters_json'] ?? '{}'), true) ?: [];
        $args = [SFM_REMOTE_BASE . '/run_colmap_dense_chunk_job.sh', SFM_REMOTE_CONF, (string)$remoteJobId, (string)$parentJobId, (string)($params['sparse_job_id'] ?? 0), (string)($params['model_id'] ?? 0), (string)($job['chunk_index'] ?? 0), (string)($params['image_list_path'] ?? ''), (string)($job['reconstruction_mode'] ?? 'preview')];
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
    }
    if ($code !== 0) {
        set_job($db, $id, 'ERROR', (int)($job['progress_percent'] ?? 0), format_command_failure($cmd, $code, $output));
        worker_log("ERROR launch {$type} id={$id} remote={$remoteJobId} exit={$code}");
        return;
    }
    if ($type === 'EXPORT_PLY') {
        write_job_text_log($remoteJobId, 'export_ply_stdout_stderr.log', $output);
        $parent = (int)($job['parent_remote_job_id'] ?? $remoteJobId);
        $modelId = model_id_from_job($job);
        set_job($db, $id, 'DONE', 100, 'PLY exported: job_' . $parent . '/colmap/sparse/' . $modelId . '/model.ply');
    } elseif ($type === 'COLMAP_DENSE_CHUNK') {
        set_job($db, $id, 'DONE', 100, 'dense chunk done');
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
        $pid=(int)$p['id']; $parentRemote=(int)$p['remote_job_id']; $mode=(string)($p['reconstruction_mode'] ?: (str_contains($p['job_type'],'HQ')?'hq':'preview'));
        $params=json_decode((string)($p['parameters_json'] ?? '{}'), true) ?: []; $sparse=(int)($params['sparse_job_id'] ?? $p['parent_remote_job_id'] ?? 0); $model=(int)($params['model_id'] ?? 0);
        $planPath=remote_output_dir($parentRemote).'/chunk_plan.json';
        if (!is_file($planPath)) { set_job($db,$pid,'PLANNING',3,'Waiting for chunk plan generated on station'); continue; }
        $plan=json_decode((string)file_get_contents($planPath), true); if(!is_array($plan)){ set_job($db,$pid,'ERROR',3,'Invalid chunk_plan.json'); continue; }
        $chunks=$plan['chunks'] ?? []; $total=count($chunks); if($total===0){ set_job($db,$pid,'ERROR',0,'No chunks in plan'); continue; }
        $st=$db->prepare("SELECT COUNT(*) c FROM sfm_remote_jobs WHERE parent_remote_job_id=? AND job_type='COLMAP_DENSE_CHUNK' AND status='DONE'"); $st->bind_param('i',$parentRemote); $st->execute(); $done=(int)$st->get_result()->fetch_assoc()['c']; $st->close();
        $st=$db->prepare("SELECT COUNT(*) c FROM sfm_remote_jobs WHERE parent_remote_job_id=? AND job_type='COLMAP_DENSE_CHUNK' AND status IN ('QUEUED','RUNNING')"); $st->bind_param('i',$parentRemote); $st->execute(); $active=(int)$st->get_result()->fetch_assoc()['c']; $st->close();
        $st=$db->prepare("SELECT f.* FROM sfm_remote_jobs f WHERE f.parent_remote_job_id=? AND f.job_type='COLMAP_DENSE_CHUNK' AND f.status='ERROR' AND NOT EXISTS (SELECT 1 FROM sfm_remote_jobs d WHERE d.parent_remote_job_id=f.parent_remote_job_id AND d.job_type='COLMAP_DENSE_CHUNK' AND d.chunk_index=f.chunk_index AND d.status='DONE') ORDER BY f.updated_at DESC LIMIT 1"); $st->bind_param('i',$parentRemote); $st->execute(); $failed=$st->get_result()->fetch_assoc(); $st->close();
        if($failed && (int)($failed['retry_count'] ?? 0) <= 0){
            $failedParams=json_decode((string)($failed['parameters_json'] ?? '{}'), true) ?: []; $failedIdx=(int)($failed['chunk_index'] ?? 0);
            $src=(string)($failedParams['image_list_path'] ?? ($chunks[$failedIdx]['image_list_path'] ?? '')); $retryList=preg_replace('/\.txt$/','_retry1.txt',$src);
            $lines=is_file($src)?array_values(array_filter(array_map('trim', file($src)))):[]; $keep=max(20,(int)floor(count($lines)*0.75)); if(count($lines)>0){ file_put_contents($retryList, implode("\n", array_slice($lines,0,min($keep,count($lines)))) . "\n"); }
            $oldId=(int)$failed['id']; $db->query('UPDATE sfm_remote_jobs SET retry_count=1 WHERE id=' . $oldId);
            $rid=sfm_job_id($db); $jt='COLMAP_DENSE_CHUNK'; $msg='retry chunk queued after OOM/error'; $pj=json_encode(['sparse_job_id'=>$sparse,'model_id'=>$model,'image_list_path'=>$retryList ?: $src], JSON_UNESCAPED_SLASHES);
            $st=$db->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,job_type,remote_job_id,parent_remote_job_id,status,progress_percent,message,reconstruction_mode,chunk_index,chunk_count,retry_count,parameters_json) VALUES (?,?,?,?,?,'QUEUED',0,?,?,?,?,?,?)"); $retry=1; $st->bind_param('iisiissiisis',(int)$p['order_id'],(int)$p['capture_session_id'],$jt,$rid,$parentRemote,$msg,$mode,$failedIdx,$total,$retry,$pj); $st->execute(); $st->close();
            set_job($db,$pid,'RUNNING_CHUNKS',(int)(5+($done/$total)*85),"Retry queued for chunk {$failedIdx}"); continue;
        } elseif($failed) { set_job($db,$pid,'ERROR',(int)(5+($done/$total)*85),'Chunk failed after retry; merge skipped'); continue; }
        if($done >= $total){
            $cmd=[SFM_REMOTE_BASE.'/run_colmap_dense_merge_job.sh', SFM_REMOTE_CONF, (string)$parentRemote, $mode, SFM_REMOTE_OUTPUT]; [$code,$output,$c]=run_command($cmd);
            set_job($db,$pid,$code===0?'DONE':'ERROR',$code===0?100:95,$output!==''?$output:'merge done'); continue;
        }
        if($active===0){ $next=$chunks[$done]; $rid=sfm_job_id($db); $jt='COLMAP_DENSE_CHUNK'; $msg='chunk queued'; $pj=json_encode(['sparse_job_id'=>$sparse,'model_id'=>$model,'image_list_path'=>$next['image_list_path']], JSON_UNESCAPED_SLASHES); $idx=(int)$next['chunk_id']; $st=$db->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,job_type,remote_job_id,parent_remote_job_id,status,progress_percent,message,reconstruction_mode,chunk_index,chunk_count,parameters_json) VALUES (?,?,?,?,?,'QUEUED',0,?,?,?,?,?)"); $st->bind_param('iisiissiis',(int)$p['order_id'],(int)$p['capture_session_id'],$jt,$rid,$parentRemote,$msg,$mode,$idx,$total,$pj); $st->execute(); $st->close(); }
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
        if ($remoteStatus === 'DONE') {
            worker_log("running fetch command for job id={$id} type={$type} remote_job_id={$remote}");
            try {
                [$fetchCode, $fetchOut, $fetchCmd] = run_command([
                    SFM_REMOTE_BASE . '/fetch_job_result.sh',
                    SFM_REMOTE_CONF,
                    (string)$remote,
                    SFM_REMOTE_OUTPUT,
                ]);
                $fetchMessage = $fetchCode === 0 ? ($fetchOut !== '' ? $fetchOut : $message) : format_command_failure($fetchCmd, $fetchCode, $fetchOut);
            } catch (Throwable $e) {
                $fetchCode = 1;
                $fetchMessage = $e->getMessage();
                worker_log("ERROR fetch id={$id} remote={$remote}: " . $e->getMessage());
            }
            if ($fetchCode === 0) {
                set_job($db, $id, 'DONE', 100, $fetchMessage);
                auto_chain_after_done($db, $job);
            } else {
                set_job($db, $id, 'ERROR', $progress, $fetchMessage);
            }
        } elseif ($remoteStatus === 'ERROR' || $remoteStatus === 'FAILED') {
            set_job($db, $id, 'ERROR', $progress, $message);
        } else {
            set_job($db, $id, 'RUNNING', $progress, $message);
        }
    }
    $res->close();
}

ensure_sfm_remote_jobs_table($dbcnx);
ensure_sfm_remote_jobs_chunk_columns($dbcnx);
worker_log('MaklerTour SfM remote worker started');
worker_log('SFM_REMOTE_BASE=' . SFM_REMOTE_BASE);
worker_log('SFM_REMOTE_CONF=' . SFM_REMOTE_CONF);
worker_log('SFM_REMOTE_OUTPUT=' . SFM_REMOTE_OUTPUT);
while (true) {
    try {
        sync_running_jobs($dbcnx);
        orchestrate_reconstruction_parents($dbcnx);
        $job = claim_next_job($dbcnx);
        if ($job) {
            launch_job($dbcnx, $job);
        }
    } catch (Throwable $e) {
        worker_log('ERROR ' . $e->getMessage());
    }
    sleep(2);
}