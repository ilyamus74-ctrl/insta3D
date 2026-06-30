<?php
declare(strict_types=1);

const SFM_CLEANUP_WEB_OUTPUT_BASE = '/home/makler/web/remote_station/output';
const SFM_CLEANUP_STATION_BASE_DEFAULT = '/home/makler_storage';
const SFM_CLEANUP_ACTIVE_STATUSES = ['RUNNING','QUEUED','STARTED','PROCESSING','ACTIVE','PLANNING','RUNNING_CHUNKS','MERGING','CANCELLING','RESTARTING'];

function sfm_cleanup_is_numeric_id($id): bool { return is_int($id) ? $id > 0 : (is_string($id) && preg_match('/^[1-9][0-9]*$/', $id) === 1); }
function sfm_cleanup_human_bytes(int $bytes): string { $u=['B','KB','MB','GB','TB']; $v=(float)$bytes; $i=0; while($v>=1024 && $i<count($u)-1){$v/=1024;$i++;} return ($i===0?(string)(int)$v:sprintf('%.1f',$v)).' '.$u[$i]; }
function sfm_cleanup_path_size(string $path): int { if(!file_exists($path)&&!is_link($path))return 0; if(is_link($path)||is_file($path))return (int)@filesize($path); $total=0; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS|FilesystemIterator::CURRENT_AS_FILEINFO),RecursiveIteratorIterator::SELF_FIRST); foreach($it as $f){ if($f->isLink())continue; if($f->isFile())$total+=(int)$f->getSize(); } return $total; }
function sfm_cleanup_safe_artifact_path(string $path, int $rid, bool $includeLogs=false): bool { $n=rtrim($path,'/'); $web=SFM_CLEANUP_WEB_OUTPUT_BASE.'/job_'.$rid; $base=SFM_CLEANUP_STATION_BASE_DEFAULT; $exact=[$web,$base.'/input/job_'.$rid,$base.'/output/job_'.$rid,$base.'/logs/job_'.$rid.'.log']; if($includeLogs)$exact[]=$base.'/logs/job_'.$rid.'.nohup.log'; $prefix=[$base.'/incoming/job_'.$rid.'_',$base.'/status/job_'.$rid]; return in_array($n,$exact,true) || str_starts_with($n,$prefix[0]) || str_starts_with($n,$prefix[1]); }
function sfm_cleanup_delete_path(string $path, bool $delete): array { $size=sfm_cleanup_path_size($path); if(!$delete)return ['path'=>$path,'size_bytes'=>$size,'deleted'=>false]; if(is_link($path)||is_file($path)){ if(!@unlink($path))throw new RuntimeException('failed to delete file: '.$path); return ['path'=>$path,'size_bytes'=>$size,'deleted'=>true]; } if(is_dir($path)){ $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS|FilesystemIterator::CURRENT_AS_FILEINFO),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){$p=$f->getPathname(); if($f->isLink()||$f->isFile()){if(!@unlink($p))throw new RuntimeException('failed to delete file: '.$p);} elseif($f->isDir()){if(!@rmdir($p))throw new RuntimeException('failed to delete dir: '.$p);}} if(!@rmdir($path))throw new RuntimeException('failed to delete dir: '.$path); return ['path'=>$path,'size_bytes'=>$size,'deleted'=>true]; } return ['path'=>$path,'size_bytes'=>0,'deleted'=>false]; }
function sfm_cleanup_column_exists(mysqli $db,string $table,string $column): bool {
    if(!preg_match('/^[A-Za-z0-9_]+$/',$table) || !preg_match('/^[A-Za-z0-9_]+$/',$column)){
        error_log('sfm cleanup metadata column check rejected unsafe identifier table='.$table.' column='.$column);
        return false;
    }
    $sql='SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?';
    $st=$db->prepare($sql);
    if(!$st){ error_log('sfm cleanup metadata column check prepare failed: '.$db->error); return false; }
    $st->bind_param('ss',$table,$column);
    if(!$st->execute()){ error_log('sfm cleanup metadata column check execute failed: '.$st->error); $st->close(); return false; }
    $rs=$st->get_result(); $row=$rs?$rs->fetch_assoc():null; $st->close();
    return (int)($row['c'] ?? 0) > 0;
}
function sfm_cleanup_update_metadata_if_available(mysqli $db,int $pipelineRunId,array $result): void {
    try {
        foreach(['artifacts_deleted_at'=>'DATETIME(6) NULL','artifacts_deleted_json'=>'LONGTEXT NULL'] as $c=>$def){
            if(!sfm_cleanup_column_exists($db,'sfm_pipeline_runs',$c)){ @ $db->query('ALTER TABLE sfm_pipeline_runs ADD COLUMN '.$c.' '.$def); }
        }
        if(!sfm_cleanup_column_exists($db,'sfm_pipeline_runs','artifacts_deleted_at')||!sfm_cleanup_column_exists($db,'sfm_pipeline_runs','artifacts_deleted_json'))return;
        $json=json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $st=$db->prepare('UPDATE sfm_pipeline_runs SET artifacts_deleted_at=NOW(6), artifacts_deleted_json=? WHERE id=?');
        if($st){$st->bind_param('si',$json,$pipelineRunId);$st->execute();$st->close();}
    } catch (Throwable $e) {
        error_log('sfm cleanup metadata update skipped for pipeline_run_id='.$pipelineRunId.': '.$e->getMessage());
    }
}
function sfm_cleanup_remote_job_ids(mysqli $db,int $pipelineRunId): array { $jobs=[]; $st=$db->prepare('SELECT r.remote_job_id,r.job_type,r.status,r.parent_remote_job_id,p.capture_session_id,p.video_scan_id,p.pipeline_mode FROM sfm_remote_jobs r LEFT JOIN sfm_pipeline_runs p ON p.id=r.pipeline_run_id WHERE r.pipeline_run_id=? ORDER BY r.id ASC'); if(!$st)throw new RuntimeException('prepare failed: '.$db->error); $st->bind_param('i',$pipelineRunId); $st->execute(); $rs=$st->get_result(); while($row=$rs->fetch_assoc()){ $rid=(string)($row['remote_job_id']??''); if(sfm_cleanup_is_numeric_id($rid)){$row['remote_job_id']=(int)$rid; $jobs[]=$row;} } $st->close(); return $jobs; }
function sfm_cleanup_remote_job_lookup(mysqli $db,array $ids): array { $out=[]; foreach($ids as $id){ $st=$db->prepare('SELECT r.pipeline_run_id,r.remote_job_id,r.job_type,r.status,r.parent_remote_job_id,r.capture_session_id,p.video_scan_id,p.pipeline_mode,p.status AS pipeline_status,p.created_at AS pipeline_created_at FROM sfm_remote_jobs r LEFT JOIN sfm_pipeline_runs p ON p.id=r.pipeline_run_id WHERE r.remote_job_id=? ORDER BY r.id DESC LIMIT 1'); if(!$st)throw new RuntimeException($db->error); $st->bind_param('i',$id); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); $out[$id]=$row ?: ['remote_job_id'=>$id,'orphan'=>true]; } return $out; }
function sfm_cleanup_job_paths(int $rid,bool $includeLogs): array { $paths=[SFM_CLEANUP_WEB_OUTPUT_BASE.'/job_'.$rid,SFM_CLEANUP_STATION_BASE_DEFAULT.'/input/job_'.$rid,SFM_CLEANUP_STATION_BASE_DEFAULT.'/output/job_'.$rid,SFM_CLEANUP_STATION_BASE_DEFAULT.'/logs/job_'.$rid.'.log']; if($includeLogs)$paths[]=SFM_CLEANUP_STATION_BASE_DEFAULT.'/logs/job_'.$rid.'.nohup.log'; foreach([SFM_CLEANUP_STATION_BASE_DEFAULT.'/incoming/job_'.$rid.'_*',SFM_CLEANUP_STATION_BASE_DEFAULT.'/status/job_'.$rid.'*'] as $g){ foreach(glob($g)?:[] as $p)$paths[]=$p; } return array_values(array_unique($paths)); }
function sfm_cleanup_delete_job_artifacts(int $rid,array $options): array { $delete=!empty($options['delete']); $include=!empty($options['include_logs']); $res=['remote_job_id'=>$rid,'paths'=>[],'missing_paths'=>[],'errors'=>[],'reclaimable_bytes'=>0,'freed_bytes'=>0]; foreach(sfm_cleanup_job_paths($rid,$include) as $p){ if(!sfm_cleanup_safe_artifact_path($p,$rid,$include)||str_starts_with($p,'/home/storage/orders/')){$res['errors'][]=['path'=>$p,'message'=>'unsafe path rejected']; continue;} if(!file_exists($p)&&!is_link($p)){$res['missing_paths'][]=$p; $res['paths'][]=['path'=>$p,'missing'=>true,'size_bytes'=>0]; continue;} try{$d=sfm_cleanup_delete_path($p,$delete); $res['paths'][]=$d; if($delete && !empty($d['deleted']))$res['freed_bytes']+=(int)$d['size_bytes']; if(!$delete)$res['reclaimable_bytes']+=(int)$d['size_bytes'];}catch(Throwable $e){$res['errors'][]=['path'=>$p,'message'=>$e->getMessage()];} } return $res; }
function sfm_cleanup_run_protection_reasons(mysqli $db,array $run,array $options=[]): array { $reasons=[]; $status=strtoupper((string)($run['status']??'')); if(in_array($status,SFM_CLEANUP_ACTIVE_STATUSES,true))$reasons[]='active'; $created=strtotime((string)($run['created_at']??'')); if($created && $created>=time()-86400 && empty($options['force_recent']))$reasons[]='recent'; if(empty($options['force_latest'])){ $st=$db->prepare('SELECT MAX(id) id FROM sfm_pipeline_runs WHERE capture_session_id=? AND video_scan_id=? AND pipeline_mode=?'); if($st){$sid=(int)$run['capture_session_id'];$vid=(int)$run['video_scan_id'];$mode=(string)$run['pipeline_mode'];$st->bind_param('iis',$sid,$vid,$mode);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close(); if((int)($r['id']??0)===(int)$run['id'])$reasons[]='latest_for_video_mode';} } return array_values(array_unique($reasons)); }
function sfm_cleanup_select_runs(mysqli $db,array $options): array { $where=[];$types='';$params=[]; if(!empty($options['pipeline_run_id'])){$where[]='id=?';$types.='i';$params[]=(int)$options['pipeline_run_id'];} if(!empty($options['older_than'])){$where[]='created_at < ?';$types.='s';$params[]=(string)$options['older_than'].' 00:00:00';} if(!empty($options['video_scan_id'])){$where[]='CAST(video_scan_id AS CHAR)=?';$types.='s';$params[]=(string)$options['video_scan_id'];} if(!empty($options['mode'])){$where[]='pipeline_mode=?';$types.='s';$params[]=(string)$options['mode'];} $sql='SELECT id,capture_session_id,video_scan_id,pipeline_mode,status,created_at FROM sfm_pipeline_runs'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id ASC'; $st=$db->prepare($sql); if(!$st)throw new RuntimeException($db->error); if($types!=='')$st->bind_param($types,...$params); $st->execute(); $rs=$st->get_result(); $rows=[]; while($r=$rs->fetch_assoc()){ $r['protection_reasons']=sfm_cleanup_run_protection_reasons($db,$r,$options); $r['protected']=!empty($r['protection_reasons']); $rows[]=$r;} $st->close(); return $rows; }
function sfm_cleanup_pipeline_run_artifacts(mysqli $db,int $pipelineRunId,array $options=[]): array { $delete=!empty($options['delete']); $result=['pipeline_run_id'=>$pipelineRunId,'delete'=>$delete,'include_logs'=>!empty($options['include_logs']),'jobs'=>[],'deleted_paths'=>[],'missing_paths'=>[],'errors'=>[],'reclaimable_bytes'=>0,'freed_bytes'=>0]; foreach(sfm_cleanup_remote_job_ids($db,$pipelineRunId) as $job){ $rid=(int)$job['remote_job_id']; $jr=sfm_cleanup_delete_job_artifacts($rid,$options); $entry=$job+['paths'=>$jr['paths']]; $result['jobs'][]=$entry; foreach($jr['paths'] as $p){ if(empty($p['missing']))$result['deleted_paths'][]=$p['path']; } foreach($jr['missing_paths'] as $p)$result['missing_paths'][]=$p; foreach($jr['errors'] as $e)$result['errors'][]=$e; $result['reclaimable_bytes']+=(int)$jr['reclaimable_bytes']; $result['freed_bytes']+=(int)$jr['freed_bytes']; } if($delete)sfm_cleanup_update_metadata_if_available($db,$pipelineRunId,$result); return $result; }
function sfm_cleanup_discover_orphan_job_ids(string $olderThan): array { $cut=strtotime($olderThan.' 00:00:00'); $ids=[]; foreach([SFM_CLEANUP_WEB_OUTPUT_BASE.'/job_*',SFM_CLEANUP_STATION_BASE_DEFAULT.'/input/job_*',SFM_CLEANUP_STATION_BASE_DEFAULT.'/output/job_*',SFM_CLEANUP_STATION_BASE_DEFAULT.'/incoming/job_*',SFM_CLEANUP_STATION_BASE_DEFAULT.'/status/job_*'] as $g){ foreach(glob($g)?:[] as $p){ if(@filemtime($p)!==false && @filemtime($p)>=$cut)continue; if(preg_match('/job_([1-9][0-9]*)/',$p,$m))$ids[(int)$m[1]]=true; } } return array_keys($ids); }
?>
<?php
function sfm_cleanup_order_session_source_base(int $orderId, int $sessionId): ?string
{
    if ($orderId <= 0 || $sessionId <= 0) { return null; }
    $root = '/home/storage/orders/' . $orderId . '/sessions';
    foreach (glob($root . '/' . $sessionId . '_' . $orderId . '*') ?: [] as $dir) {
        $real = realpath($dir);
        $rootReal = realpath($root);
        if ($real && $rootReal && is_dir($real) && str_starts_with($real, rtrim($rootReal, '/') . '/')) { return $real; }
    }
    return null;
}
function sfm_cleanup_source_media_paths(int $orderId, int $sessionId, ?int $videoScanId = null): array
{
    $base = sfm_cleanup_order_session_source_base($orderId, $sessionId);
    if (!$base) { return []; }
    $patterns = $videoScanId && $videoScanId > 0
        ? ['/videos/' . $videoScanId . '_video.mp4','/videos/' . $videoScanId . '_imu.jsonl','/videos/' . $videoScanId . '_camera_info.json','/videos/' . $videoScanId . '_manifest.json']
        : ['/videos/*_video.mp4','/videos/*_imu.jsonl','/videos/*_camera_info.json','/videos/*_manifest.json','/photos/*','/photo_originals/*','/previews/*'];
    $paths = [];
    foreach ($patterns as $pat) { foreach (glob($base . $pat) ?: [] as $p) { $real = realpath($p); if ($real && str_starts_with($real, $base . '/')) { $paths[] = $real; } } }
    return array_values(array_unique($paths));
}
function sfm_cleanup_delete_project_session_artifacts_and_media(mysqli $db, int $orderId, int $sessionId, ?int $videoScanId, bool $confirmed, bool $delete): array
{
    if (!$confirmed) { throw new RuntimeException('Source media cleanup requires explicit confirmation.'); }
    $result = ['order_id'=>$orderId,'capture_session_id'=>$sessionId,'video_scan_id'=>$videoScanId,'source_paths'=>[],'artifact_results'=>[],'errors'=>[],'freed_bytes'=>0,'reclaimable_bytes'=>0,'delete'=>$delete];
    $where = 'order_id=? AND capture_session_id=?'; $types='ii'; $params=[$orderId,$sessionId];
    if ($videoScanId !== null && $videoScanId > 0) { $where .= ' AND video_scan_id=?'; $types.='i'; $params[]=$videoScanId; }
    $st=$db->prepare('SELECT id FROM sfm_pipeline_runs WHERE '.$where); if($st){$st->bind_param($types,...$params);$st->execute();$rs=$st->get_result();while($r=$rs->fetch_assoc()){ $res=sfm_cleanup_pipeline_run_artifacts($db,(int)$r['id'],['delete'=>$delete,'include_logs'=>false,'force_recent'=>true,'force_latest'=>true]); $result['artifact_results'][]=$res; $result['freed_bytes']+=(int)($res['freed_bytes']??0); $result['reclaimable_bytes']+=(int)($res['reclaimable_bytes']??0); foreach($res['errors']??[] as $e)$result['errors'][]=$e; } $st->close(); }
    foreach (sfm_cleanup_source_media_paths($orderId, $sessionId, $videoScanId) as $path) { try { $d=sfm_cleanup_delete_path($path,$delete); $result['source_paths'][]=$d; if($delete && !empty($d['deleted']))$result['freed_bytes']+=(int)$d['size_bytes']; if(!$delete)$result['reclaimable_bytes']+=(int)$d['size_bytes']; } catch(Throwable $e) { $result['errors'][]=['path'=>$path,'message'=>$e->getMessage()]; } }
    return $result;
}
?>
