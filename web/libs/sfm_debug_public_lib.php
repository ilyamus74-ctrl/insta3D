<?php
declare(strict_types=1);

const SFM_DEBUG_PUBLIC_DEFAULT_OPTIONS = [
    'source_video'=>true,'selected_frames'=>true,'candidate_frames'=>false,
    'point_clouds'=>true,'meshes'=>true,'logs'=>true,'imu'=>true,
];
const SFM_DEBUG_PUBLIC_ARTIFACT_TYPES = [
    'source_video','camera_info','manifest','imu','selected_frames','quality_summary','frame_quality','rejected_frames',
    'sparse_diagnostics','camera_trajectory','world_alignment','world_alignment_override','sparse_ply','dense_ply','mesh_ply','mesh_stats','pipeline_result','pipeline_log','full_log',
    'selected_frame_preview','candidate_frame_preview','debug_bundle'
];

function sfm_debug_public_ensure_schema(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS sfm_debug_public_links (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL UNIQUE,
        order_id BIGINT UNSIGNED NOT NULL,
        capture_session_id BIGINT UNSIGNED NOT NULL,
        created_by BIGINT UNSIGNED NOT NULL,
        created_at DATETIME(6) NOT NULL,
        expires_at DATETIME(6) NULL,
        revoked_at DATETIME(6) NULL,
        last_accessed_at DATETIME(6) NULL,
        access_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        options_json JSON NULL,
        INDEX idx_session (capture_session_id),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function sfm_debug_public_safe_uuid(string $uuid): string { $s=preg_replace('/[^a-zA-Z0-9._-]+/','_', $uuid); return $s!==''?$s:'session'; }
function sfm_debug_public_hash(string $token): string { return hash('sha256', trim($token)); }
function sfm_debug_public_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; media-src 'self'; style-src 'self' 'unsafe-inline' https://unpkg.com; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://unpkg.com; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
}
function sfm_debug_public_fail(int $code): void { http_response_code($code); echo $code===410?'Gone':'Not Found'; exit; }
function sfm_debug_public_validate(mysqli $db, string $token, bool $touch=true): array {
    sfm_debug_public_ensure_schema($db);
    if(!preg_match('/^[a-f0-9]{64}$/i',$token)) sfm_debug_public_fail(404);
    $hash=sfm_debug_public_hash($token);
    $st=$db->prepare('SELECT l.*, cs.app_session_uuid, cs.created_at AS session_created_at, o.status AS order_status FROM sfm_debug_public_links l JOIN capture_sessions cs ON cs.id=l.capture_session_id JOIN tour_orders o ON o.id=l.order_id WHERE l.token_hash=? AND cs.deleted_at IS NULL LIMIT 1');
    if(!$st) sfm_debug_public_fail(404);
    $st->bind_param('s',$hash); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close();
    if(!$row) sfm_debug_public_fail(404);
    if(!empty($row['revoked_at'])) sfm_debug_public_fail(410);
    if(!empty($row['expires_at']) && strtotime((string)$row['expires_at']) <= time()) sfm_debug_public_fail(410);
    if($touch){ $id=(int)$row['id']; $u=$db->prepare('UPDATE sfm_debug_public_links SET last_accessed_at=NOW(6), access_count=access_count+1 WHERE id=?'); if($u){$u->bind_param('i',$id);$u->execute();$u->close();} if(function_exists('audit_log')) @audit_log(0,'DEBUG_PUBLIC_LINK_OPENED','CAPTURE_SESSION',(int)$row['capture_session_id'],'Debug public link opened',['link_id'=>$id]); }
    $row['options']=json_decode((string)($row['options_json'] ?? '{}'), true) ?: [];
    return $row;
}
function sfm_debug_public_rate_limit(string $token): void {
    $ip=(string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'); $key=sys_get_temp_dir().'/sfm_debug_rate_'.sha1($token.'|'.$ip).'.json'; $now=time(); $hits=[];
    if(is_file($key)){ $hits=json_decode((string)file_get_contents($key),true) ?: []; }
    $hits=array_values(array_filter($hits, fn($t)=>is_int($t)&&$t>$now-60));
    if(count($hits)>=120){ http_response_code(429); exit('Too Many Requests'); }
    $hits[]=$now; @file_put_contents($key,json_encode($hits),LOCK_EX);
}
function sfm_debug_public_session_base(array $link): string { return '/home/makler/web/storage/orders/'.(int)$link['order_id'].'/sessions/'.sfm_debug_public_safe_uuid((string)$link['app_session_uuid']); }
function sfm_debug_public_remote_base(int $remoteJobId): string { return '/home/makler/web/remote_station/output/job_'.$remoteJobId; }
function sfm_debug_public_inside(string $path, array $bases): ?string {
    $real=realpath($path); if($real===false || !is_file($real) || is_link($path)) return null;
    foreach($bases as $base){ $rb=realpath($base); if($rb!==false){ $rb=rtrim($rb,DIRECTORY_SEPARATOR); if($real===$rb || str_starts_with($real,$rb.DIRECTORY_SEPARATOR)) return $real; } }
    return null;
}

function sfm_debug_public_selected_sparse_model(array $run, string $sparseBase): int {
    foreach (['sparse_model_id','selected_model_id'] as $k) { if (isset($run[$k]) && is_numeric($run[$k]) && (int)$run[$k] >= 0) return (int)$run[$k]; }
    $sparseDir = $sparseBase . '/sparse'; $best = -1; $bestImages = -1;
    if (is_dir($sparseDir)) {
        foreach (scandir($sparseDir) ?: [] as $name) {
            if (!preg_match('/^\d+$/', $name)) continue;
            $diag = $sparseDir . '/' . $name . '/sparse_diagnostics.json'; $images = 0;
            if (is_file($diag)) { $d=json_decode((string)file_get_contents($diag), true) ?: []; $images=(int)($d['registered_images'] ?? 0); }
            if ($images > $bestImages) { $bestImages=$images; $best=(int)$name; }
        }
    }
    return $best >= 0 ? $best : 0;
}
function sfm_debug_public_run(mysqli $db, array $link, int $pipelineRunId): ?array {
    if($pipelineRunId<=0) return null;
    $st=$db->prepare('SELECT * FROM sfm_pipeline_runs WHERE id=? AND order_id=? AND capture_session_id=? LIMIT 1'); if(!$st) return null;
    $oid=(int)$link['order_id']; $sid=(int)$link['capture_session_id']; $st->bind_param('iii',$pipelineRunId,$oid,$sid); $st->execute(); $r=$st->get_result()->fetch_assoc()?:null; $st->close(); return $r;
}
function sfm_debug_public_jobs(mysqli $db, array $link, int $pipelineRunId=0): array {
    $jobs=[]; $sid=(int)$link['capture_session_id'];
    if($pipelineRunId>0){ $st=$db->prepare('SELECT * FROM sfm_remote_jobs WHERE capture_session_id=? AND pipeline_run_id=? ORDER BY created_at DESC,id DESC'); if($st){$st->bind_param('ii',$sid,$pipelineRunId);$st->execute();$rs=$st->get_result();while($j=$rs->fetch_assoc())$jobs[]=$j;$st->close();} }
    return $jobs;
}
function sfm_debug_public_artifact_path(mysqli $db, array $link, int $pipelineRunId, string $type, string $fileId=''): ?array {
    if(!in_array($type,SFM_DEBUG_PUBLIC_ARTIFACT_TYPES,true)) return null;
    $sessionBase=sfm_debug_public_session_base($link); $bases=[$sessionBase]; $path=''; $download=true; $mime='application/octet-stream';
    if(in_array($type,['source_video','camera_info','manifest','imu'],true)){
        $name=basename($fileId); if($name==='') return null; $videoDir=$sessionBase.'/videos';
        if($type==='source_video'){ $path=$videoDir.'/'.$name; $mime='video/mp4'; }
else {
    $filenameStem = preg_replace('/\.mp4$/i', '', $name);
    $baseStem = preg_replace('/_video$/i', '', $filenameStem);

    $suffix = [
        'camera_info' => '_camera_info.json',
        'manifest'    => '_manifest.json',
        'imu'         => '_imu.jsonl',
    ][$type];

    foreach (array_unique([$baseStem, $filenameStem]) as $stem) {
        $candidate = $videoDir . '/' . $stem . $suffix;

        if (is_file($candidate)) {
            $path = $candidate;
            break;
        }
    }

    $mime = $type === 'imu'
        ? 'text/plain; charset=utf-8'
        : 'application/json';
}

    } else {
        $run=sfm_debug_public_run($db,$link,$pipelineRunId); if(!$run) return null;
        $jobs=sfm_debug_public_jobs($db,$link,$pipelineRunId); $sparse=$recon=$mesh=$extract=null; foreach($jobs as $j){ $jt=(string)$j['job_type']; if($jt==='COLMAP_SPARSE')$sparse=$j; elseif(in_array($jt,['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'],true))$recon=$j; elseif($jt==='COLMAP_MESH')$mesh=$j; elseif($jt==='EXTRACT_FRAMES')$extract=$j; }
        foreach([$sparse,$recon,$mesh,$extract] as $j){ if($j) $bases[]=sfm_debug_public_remote_base((int)$j['remote_job_id']); }
        $model=0;
        $map=[];
        if($extract){ $eb=sfm_debug_public_remote_base((int)$extract['remote_job_id']); $map += ['selected_frames'=>$eb.'/selected_frames.json','quality_summary'=>$eb.'/quality_summary.json','frame_quality'=>$eb.'/frame_quality.json','rejected_frames'=>$eb.'/rejected_frames.json','full_log'=>(string)($extract['log_path'] ?? '')]; }
        if($sparse){ $sb=sfm_debug_public_remote_base((int)$sparse['remote_job_id']); $model=sfm_debug_public_selected_sparse_model($run,$sb.'/colmap'); $map += ['sparse_diagnostics'=>$sb.'/colmap/sparse/'.$model.'/sparse_diagnostics.json','camera_trajectory'=>$sb.'/colmap/sparse/'.$model.'/camera_trajectory.json','world_alignment'=>$sb.'/colmap/sparse/'.$model.'/world_alignment.json','world_alignment_override'=>$sb.'/colmap/sparse/'.$model.'/world_alignment_override.json','sparse_ply'=>$sb.'/colmap/sparse/'.$model.'/model.ply']; }
        if($recon){ $rb=sfm_debug_public_remote_base((int)$recon['remote_job_id']); $map += ['dense_ply'=>$rb.'/merged/merged_fused.ply']; }
        if($mesh){ $mb=sfm_debug_public_remote_base((int)$mesh['remote_job_id']); $map += ['mesh_ply'=>$mb.'/mesh/mesh_final.ply','mesh_stats'=>$mb.'/mesh/mesh_stats.json']; }
        $map['pipeline_result']=(string)($run['output_result_json_path'] ?? ''); $map['pipeline_log']=(string)($run['unified_log_path'] ?? '');
        $path=$map[$type] ?? ''; $mime=str_ends_with($type,'ply')?'model/ply':(str_contains($type,'log')?'text/plain; charset=utf-8':'application/json');
    }
    $real=sfm_debug_public_inside($path,$bases); if(!$real) return null;
    return ['path'=>$real,'mime'=>$mime,'download'=>$download,'name'=>basename($real)];
}