<?php
declare(strict_types=1);

const SFM_CLEANUP_WEB_OUTPUT_BASE = '/home/makler/web/remote_station/output';
const SFM_CLEANUP_STATION_BASE_DEFAULT = '/home/makler_storage';
const SFM_CLEANUP_ACTIVE_STATUSES = ['RUNNING','QUEUED','STARTED','PROCESSING','ACTIVE','PLANNING','RUNNING_CHUNKS','MERGING','CANCELLING','RESTARTING'];


const SFM_REMOTE_CLEANUP_PIPELINE_TERMINAL_STATUSES = ['DONE','FAILED','CANCELLED','ERROR'];
function sfm_remote_job_terminal_statuses(): array { return ['DONE','ERROR','ERROR_EMPTY','ERROR_EMPTY_MESH','ERROR_OOM','ERROR_STALE','FAILED','CANCELLED']; }
function sfm_remote_cleanup_delay_seconds(string $pipelineStatus): int { return strtoupper($pipelineStatus)==='DONE' ? max(0,(int)(getenv('SFM_REMOTE_CLEANUP_DELAY_SECONDS') ?: 3600)) : max(0,(int)(getenv('SFM_REMOTE_FAILED_CLEANUP_DELAY_SECONDS') ?: 86400)); }
function sfm_remote_cleanup_require_schema(mysqli $db): void {
    $required=['pipeline_run_id','remote_job_id','remote_cleanup_status','remote_cleanup_started_at','remote_cleanup_finished_at','remote_cleanup_freed_bytes','remote_cleanup_result_json','remote_cleanup_last_error','remote_cleanup_attempts','next_attempt_at'];
    $st=$db->prepare("SELECT column_name,is_nullable FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sfm_remote_cleanup_runs'");
    if(!$st){ throw new RuntimeException('failed to inspect sfm_remote_cleanup_runs schema: '.$db->error); }
    if(!$st->execute()){ $err=$st->error; $st->close(); throw new RuntimeException('failed to inspect sfm_remote_cleanup_runs schema: '.$err); }
    $rs=$st->get_result(); $cols=[]; while($row=$rs->fetch_assoc()){$cols[(string)$row['column_name']]=strtoupper((string)$row['is_nullable']);} $st->close();
    if(!$cols){ throw new RuntimeException('sfm_remote_cleanup_runs table is missing; apply web/migrations/20260714_sfm_remote_cleanup_runs.sql and web/migrations/20260714_sfm_remote_cleanup_runs_v2.sql before starting cleanup worker'); }
    $missing=[]; foreach($required as $c){ if(!array_key_exists($c,$cols))$missing[]=$c; }
    if($missing){ throw new RuntimeException('sfm_remote_cleanup_runs schema is incomplete; missing columns: '.implode(',',$missing)); }
    foreach(['pipeline_run_id','remote_job_id'] as $ownerCol){ if(($cols[$ownerCol] ?? '')!=='YES')throw new RuntimeException('sfm_remote_cleanup_runs schema is incomplete; '.$ownerCol.' must be nullable for exactly-one-owner cleanup rows'); }
    $idx=$db->prepare("SELECT index_name,non_unique,GROUP_CONCAT(column_name ORDER BY seq_in_index) cols FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='sfm_remote_cleanup_runs' GROUP BY index_name,non_unique");
    if(!$idx){ throw new RuntimeException('failed to inspect sfm_remote_cleanup_runs indexes: '.$db->error); }
    if(!$idx->execute()){ $err=$idx->error; $idx->close(); throw new RuntimeException('failed to inspect sfm_remote_cleanup_runs indexes: '.$err); }
    $rs=$idx->get_result(); $unique=[]; $hasStatusNext=false; while($row=$rs->fetch_assoc()){ if((int)$row['non_unique']===0)$unique[(string)$row['cols']]=true; if((string)$row['cols']==='remote_cleanup_status,next_attempt_at')$hasStatusNext=true; } $idx->close();
    if(empty($unique['pipeline_run_id']) || empty($unique['remote_job_id']))throw new RuntimeException('sfm_remote_cleanup_runs schema is incomplete; unique indexes on pipeline_run_id and remote_job_id are required');
    if(!$hasStatusNext)throw new RuntimeException('sfm_remote_cleanup_runs schema is incomplete; status/next_attempt_at index is required');
}
function sfm_remote_cleanup_validate_owner_fields(?int $pipelineRunId, ?int $remoteJobId): void {
    $hasPipeline=$pipelineRunId!==null && $pipelineRunId>0; $hasRemote=$remoteJobId!==null && $remoteJobId>0;
    if($hasPipeline===$hasRemote)throw new InvalidArgumentException('cleanup row must have exactly one owner');
}
function sfm_remote_cleanup_maybe_schedule(mysqli $db,int $pipelineRunId): void {
    if($pipelineRunId<=0)return; sfm_remote_cleanup_require_schema($db); sfm_remote_cleanup_validate_owner_fields($pipelineRunId,null);
    $st=$db->prepare('SELECT status,finished_at FROM sfm_pipeline_runs WHERE id=? LIMIT 1'); if(!$st)throw new RuntimeException('prepare cleanup pipeline schedule failed: '.$db->error);
    $st->bind_param('i',$pipelineRunId); if(!$st->execute()){ $err=$st->error; $st->close(); throw new RuntimeException('execute cleanup pipeline schedule failed: '.$err); } $run=$st->get_result()->fetch_assoc(); $st->close(); if(!$run)return;
    $status=strtoupper((string)$run['status']); if(!in_array($status,SFM_REMOTE_CLEANUP_PIPELINE_TERMINAL_STATUSES,true))return;
    $delay=sfm_remote_cleanup_delay_seconds($status); $finished=$run['finished_at'] ?? null;
    $sql="INSERT IGNORE INTO sfm_remote_cleanup_runs (pipeline_run_id,remote_cleanup_status,next_attempt_at) VALUES (?,'PENDING',DATE_ADD(COALESCE(?,NOW(6)), INTERVAL ? SECOND))";
    $q=$db->prepare($sql); if(!$q)throw new RuntimeException('prepare cleanup pipeline insert failed: '.$db->error); $q->bind_param('isi',$pipelineRunId,$finished,$delay); if(!$q->execute()){ $err=$q->error; $q->close(); throw new RuntimeException('execute cleanup pipeline insert failed: '.$err); } $q->close();
}
function sfm_remote_cleanup_job_attached_to_pipeline(mysqli $db,int $remoteJobId): bool {
    $st=$db->prepare('SELECT pipeline_run_id FROM sfm_remote_jobs WHERE remote_job_id=? AND pipeline_run_id IS NOT NULL ORDER BY id DESC LIMIT 1'); if(!$st)throw new RuntimeException('prepare attached pipeline check failed: '.$db->error); $st->bind_param('i',$remoteJobId); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); return !empty($row);
}
function sfm_remote_cleanup_is_true_standalone_job(mysqli $db,array $job): bool {
    $rid=(int)($job['remote_job_id'] ?? 0); if($rid<=0 || !empty($job['pipeline_run_id']))return false;
    $type=(string)($job['job_type'] ?? ''); $params=json_decode((string)($job['parameters_json'] ?? ''),true); $scope=is_array($params)?(string)($params['cleanup_scope'] ?? ''):'';
    $alwaysStandalone=['MAKLERTOUR_SYNCED_DENSE','EXPORT_PLY'];
    $st=$db->prepare("SELECT COUNT(*) c FROM sfm_pipeline_runs WHERE root_remote_job_id=? AND status NOT IN ('DONE','FAILED','CANCELLED','ERROR')");
    if($st){$st->bind_param('i',$rid);$st->execute(); if((int)($st->get_result()->fetch_assoc()['c']??0)>0){$st->close(); return false;} $st->close();}
    $parent=(int)($job['parent_remote_job_id'] ?? 0);
    if($parent>0){
        $st=$db->prepare("SELECT COUNT(*) c FROM sfm_remote_jobs r JOIN sfm_pipeline_runs p ON p.id=r.pipeline_run_id WHERE r.remote_job_id=? AND p.status NOT IN ('DONE','FAILED','CANCELLED','ERROR')");
        if($st){$st->bind_param('i',$parent);$st->execute(); if((int)($st->get_result()->fetch_assoc()['c']??0)>0){$st->close(); return false;} $st->close();}
    }
    $active="'".implode("','",array_map(static fn($s)=>str_replace("'","''",$s),SFM_CLEANUP_ACTIVE_STATUSES))."'";
    $st=$db->prepare('SELECT COUNT(*) c FROM sfm_remote_jobs WHERE parent_remote_job_id=? AND pipeline_run_id IS NOT NULL AND status IN ('.$active.')');
    if($st){$st->bind_param('i',$rid);$st->execute(); if((int)($st->get_result()->fetch_assoc()['c']??0)>0){$st->close(); return false;} $st->close();}
    if(sfm_remote_cleanup_dependency_count($db,0,$rid)>0)return false;
    if(in_array($type,$alwaysStandalone,true))return true;
    return $scope==='standalone' && in_array($type,['EXTRACT_FRAMES','COLMAP_SPARSE','COLMAP_DENSE','COLMAP_DENSE_CHUNK'],true) && !sfm_remote_cleanup_job_attached_to_pipeline($db,$rid);
}
function sfm_remote_cleanup_maybe_schedule_remote_job(mysqli $db,int $remoteJobId): void {
    if($remoteJobId<=0)return; sfm_remote_cleanup_require_schema($db); sfm_remote_cleanup_validate_owner_fields(null,$remoteJobId);
    $st=$db->prepare('SELECT remote_job_id,pipeline_run_id,job_type,status,updated_at,parent_remote_job_id,parameters_json FROM sfm_remote_jobs WHERE remote_job_id=? ORDER BY id DESC LIMIT 1'); if(!$st)throw new RuntimeException('prepare cleanup standalone schedule failed: '.$db->error);
    $st->bind_param('i',$remoteJobId); if(!$st->execute()){ $err=$st->error; $st->close(); throw new RuntimeException('execute cleanup standalone schedule failed: '.$err); } $job=$st->get_result()->fetch_assoc(); $st->close(); if(!$job)return;
    if(!empty($job['pipeline_run_id']))return; if(!sfm_remote_cleanup_is_true_standalone_job($db,$job))return;
    $status=strtoupper((string)$job['status']); if(!in_array($status,sfm_remote_job_terminal_statuses(),true))return;
    $delay=sfm_remote_cleanup_delay_seconds($status==='DONE'?'DONE':'ERROR'); $updated=$job['updated_at'] ?? null;
    $sql="INSERT IGNORE INTO sfm_remote_cleanup_runs (remote_job_id,remote_cleanup_status,next_attempt_at) VALUES (?,'PENDING',DATE_ADD(COALESCE(?,NOW(6)), INTERVAL ? SECOND))";
    $q=$db->prepare($sql); if(!$q)throw new RuntimeException('prepare cleanup standalone insert failed: '.$db->error); $q->bind_param('isi',$remoteJobId,$updated,$delay); if(!$q->execute()){ $err=$q->error; $q->close(); throw new RuntimeException('execute cleanup standalone insert failed: '.$err); } $q->close();
}
function sfm_remote_cleanup_active_jobs_count(mysqli $db,int $pipelineRunId): int { $escaped=[]; foreach(SFM_CLEANUP_ACTIVE_STATUSES as $s){$escaped[]=$db->real_escape_string($s);} $list="'".implode("','",$escaped)."'"; $r=$db->query('SELECT COUNT(*) c FROM sfm_remote_jobs WHERE pipeline_run_id='.(int)$pipelineRunId.' AND status IN ('.$list.')'); $row=$r?$r->fetch_assoc():[]; if($r)$r->close(); return (int)($row['c']??0); }
function sfm_remote_cleanup_result_json_path(array $job): ?string {
    $rid=(int)($job['remote_job_id'] ?? 0); $type=(string)($job['job_type'] ?? ''); $base=SFM_CLEANUP_WEB_OUTPUT_BASE.'/job_'.$rid;
    $candidates=[]; $declared=(string)($job['result_json_path'] ?? ''); if($declared!=='')$candidates[]=$declared;
    if($type==='COLMAP_MESH')$candidates[]=$base.'/mesh/mesh_result.json';
    elseif(in_array($type,['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ','COLMAP_DENSE'],true))$candidates[]=$base.'/merged/result.json';
    elseif($type==='COLMAP_SPARSE'){$candidates[]=$base.'/colmap/result.json';$candidates[]=$base.'/result.json';}
    elseif($type==='COLMAP_DENSE_CHUNK') { $owner=(int)($job['parent_remote_job_id'] ?? 0); if($owner>0 && ($job['chunk_index'] ?? null)!==null)$candidates[]=SFM_CLEANUP_WEB_OUTPUT_BASE.'/job_'.$owner.'/chunks/chunk_'.(int)$job['chunk_index'].'/result.json'; }
    else $candidates[]=$base.'/result.json';
    foreach(array_values(array_unique($candidates)) as $path){ if($path!=='' && is_file($path) && filesize($path)>0)return $path; }
    return null;
}
function sfm_remote_cleanup_artifact_contract(array $job): array {
    $rid=(int)($job['remote_job_id'] ?? 0); $type=(string)($job['job_type'] ?? ''); $base=SFM_CLEANUP_WEB_OUTPUT_BASE.'/job_'.$rid; $owner=$rid; $paths=[];
    if($type==='EXTRACT_FRAMES'){$paths=[$base.'/result.json',$base.'/frames_manifest.json',$base.'/frames/manifest.json'];}
    elseif($type==='COLMAP_SPARSE'){$paths=[$base.'/colmap/sparse_components.json',$base.'/sparse_components.json',$base.'/colmap/sparse/0'];}
    elseif(in_array($type,['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ','RECONSTRUCTION'],true)){$paths=[$base.'/merged/merged_fused.ply'];}
    elseif(in_array($type,['COLMAP_MESH','MESH'],true)){$paths=[$base.'/mesh/mesh_final.ply'];}
    elseif($type==='COLMAP_DENSE'){$paths=[$base.'/dense_model_0.ply',$base.'/dense_model_1.ply',$base.'/merged/merged_fused.ply'];}
    elseif($type==='COLMAP_DENSE_CHUNK') { $owner=(int)($job['parent_remote_job_id'] ?? 0); if(($job['chunk_index'] ?? null)===null){return ['owner_remote_job_id'=>$owner,'paths'=>[],'missing_contract'=>'chunk_index'];} $chunk=(int)$job['chunk_index']; $base=$owner>0?SFM_CLEANUP_WEB_OUTPUT_BASE.'/job_'.$owner:$base; $paths=[$base.'/chunks/chunk_'.$chunk.'/fused.ply',$base.'/chunks/chunk_'.$chunk.'/result.json']; }
    elseif($type==='EXPORT_PLY') { $parent=(int)($job['parent_remote_job_id'] ?? 0); $base=$parent>0?SFM_CLEANUP_WEB_OUTPUT_BASE.'/job_'.$parent:$base; $paths=[(string)($job['output_path'] ?? ''),$base.'/sparse_'.(int)($job['model_id'] ?? 0).'.ply']; }
    elseif($type==='MAKLERTOUR_SYNCED_DENSE'){$paths=[$base.'/dense/contact_dense_depth.jpg',$base.'/dense/dense_depth_debug.json',$base.'/dense/dense_depth_summary.csv'];}
    else {$paths=[$base.'/result.json'];}
    return ['owner_remote_job_id'=>$owner,'paths'=>array_values(array_filter(array_unique($paths)))];
}
function sfm_remote_cleanup_first_existing_artifact(array $job): ?string { foreach(sfm_remote_cleanup_artifact_contract($job)['paths'] as $path){ if($path!=='' && (is_file($path) || is_dir($path)) && (is_dir($path) || filesize($path)>0)) return $path; } return null; }
function sfm_remote_cleanup_dependency_count(mysqli $db,int $pipelineRunId,int $remoteJobId): int {
    $active="'".implode("','",array_map(static fn($s)=>str_replace("'","''",$s),SFM_CLEANUP_ACTIVE_STATUSES))."'"; $count=0;
    $st=$db->prepare('SELECT COUNT(*) c FROM sfm_remote_jobs WHERE parent_remote_job_id=? AND status IN ('. $active .')'); if($st){$st->bind_param('i',$remoteJobId);$st->execute();$count+=(int)($st->get_result()->fetch_assoc()['c']??0);$st->close();}
    $fields=['$.sparse_remote_job_id','$.sparse_job_id','$.parent_remote_job_id','$.source_remote_job_id','$.dense_remote_job_id'];
    $ors=[]; foreach($fields as $f){$ors[]='CAST(JSON_UNQUOTE(JSON_EXTRACT(parameters_json,\''.$f.'\')) AS UNSIGNED)=?';}
    $sql='SELECT COUNT(*) c FROM sfm_remote_jobs WHERE (pipeline_run_id IS NULL OR pipeline_run_id<>?) AND status IN ('.$active.') AND parameters_json IS NOT NULL AND JSON_VALID(parameters_json) AND ('.implode(' OR ',$ors).')';
    $st=$db->prepare($sql); if($st){$types='i'.str_repeat('i',count($fields)); $params=array_merge([$pipelineRunId],array_fill(0,count($fields),$remoteJobId)); $st->bind_param($types,...$params);$st->execute();$count+=(int)($st->get_result()->fetch_assoc()['c']??0);$st->close();}
    return $count;
}
function sfm_remote_cleanup_verify_jobs(mysqli $db,int $pipelineRunId): array {
    $errors=[]; $ids=[]; $diagnostics=[]; $jobs=sfm_cleanup_remote_job_ids($db,$pipelineRunId);
    foreach($jobs as $job){
        $rid=(int)$job['remote_job_id']; $type=(string)$job['job_type']; $status=strtoupper((string)$job['status']); $contract=sfm_remote_cleanup_artifact_contract($job); $path=null; $eligible=false; $depCount=0;
        if($rid<=0){$errors[]='bad remote_job_id';}
        elseif(!in_array($status,sfm_remote_job_terminal_statuses(),true)){$errors[]='remote_job_id='.$rid.' not terminal: '.$status;}
        else { $depCount=sfm_remote_cleanup_dependency_count($db,$pipelineRunId,$rid); if($depCount>0){$errors[]='remote_job_id='.$rid.' has active dependencies';} }
        if(!$errors || !str_contains(end($errors) ?: '', 'remote_job_id='.$rid)){
            if($status==='DONE'){
                if(!empty($contract['missing_contract'])){$errors[]='remote_job_id='.$rid.' missing '.$contract['missing_contract'];}
                if($type==='COLMAP_DENSE_CHUNK'){
                    $missing=[]; foreach($contract['paths'] as $requiredPath){ if($requiredPath==='' || !is_file($requiredPath) || filesize($requiredPath)<=0){$missing[]=$requiredPath;} }
                    $path=$contract['paths'][0] ?? null;
                    if($missing){$errors[]='remote_job_id='.$rid.' missing dense chunk required artifacts: '.implode(',',$missing);}
                    else {$eligible=true; $ids[]=$rid;}
                } else {
                    $path=sfm_remote_cleanup_first_existing_artifact($job); $result=sfm_remote_cleanup_result_json_path($job);
                    if(!is_dir(SFM_CLEANUP_WEB_OUTPUT_BASE.'/job_'.$rid)){$errors[]='remote_job_id='.$rid.' local output dir missing';}
                    elseif($result===null && !in_array($type,['EXPORT_PLY'],true)){$errors[]='remote_job_id='.$rid.' result JSON missing/empty';}
                    elseif($path===null){$errors[]='remote_job_id='.$rid.' required local artifact missing/empty';}
                    else {$eligible=true; $ids[]=$rid;}
                }
            } else { $eligible=$depCount===0; if($eligible)$ids[]=$rid; }
        }
        $diagnostics[]=['pipeline_run_id'=>$pipelineRunId,'remote_job_id'=>$rid,'job_type'=>$type,'status'=>$status,'effective_artifact_owner_id'=>(int)$contract['owner_remote_job_id'],'local_verification_path'=>$path,'dependency_status'=>$depCount>0?'blocked':'clear','cleanup_eligibility'=>$eligible?'eligible':'blocked'];
    }
    return ['ok'=>empty($errors),'errors'=>$errors,'remote_job_ids'=>array_values(array_unique($ids)),'jobs'=>$jobs,'diagnostics'=>$diagnostics];
}
function sfm_remote_cleanup_run_station_script(array $remoteJobIds,bool $dryRun): array {
    $script=__DIR__.'/cleanup_station_artifacts.sh';
    $args=array_merge([$script,__DIR__.'/stations.conf',$dryRun?'--dry-run':'--delete','--no-logs'],array_map('strval',$remoteJobIds));
    $cmd=implode(' ',array_map('escapeshellarg',$args)).' 2>&1'; exec($cmd,$out,$code);
    return sfm_remote_cleanup_validate_station_response($code,implode("\n",$out),$remoteJobIds,$dryRun);
}
function sfm_remote_cleanup_validate_station_response(int $code,string $text,array $remoteJobIds,bool $dryRun): array {
    if($code!==0)throw new RuntimeException('station cleanup failed: '.$text);
    $json=json_decode($text,true); if(!is_array($json))throw new RuntimeException('station cleanup returned invalid JSON: '.$text);
    if(!empty($json['errors']))throw new RuntimeException('station cleanup errors: '.json_encode($json['errors'],JSON_UNESCAPED_SLASHES));
    $seen=[]; foreach(($json['paths'] ?? []) as $p){
        if(isset($p['remote_job_id']))$seen[(int)$p['remote_job_id']]=true;
        if(!$dryRun && empty($p['missing']) && empty($p['deleted']))throw new RuntimeException('station cleanup incomplete path: '.(string)($p['path'] ?? ''));
    }
    foreach($remoteJobIds as $rid){ if(!isset($seen[(int)$rid]))throw new RuntimeException('station cleanup returned no paths for remote_job_id='.(int)$rid); }
    $json['freed_bytes']=(int)($json['freed_bytes'] ?? 0);
    return $json;
}
function sfm_remote_cleanup_remote_lock_name(int $remoteJobId): string { return 'sfm_remote_job_cleanup:'.$remoteJobId; }
function sfm_remote_cleanup_lock_remote_ids(mysqli $db,array $remoteJobIds): array {
    $ids=array_values(array_unique(array_map('intval',$remoteJobIds))); sort($ids,SORT_NUMERIC); $locks=[];
    foreach($ids as $rid){ if($rid<=0)continue; $name=sfm_remote_cleanup_remote_lock_name($rid); if(!sfm_remote_cleanup_mysql_lock($db,$name)){ sfm_remote_cleanup_unlock_locks($db,$locks); throw new RuntimeException('cleanup lock busy remote_job_id='.$rid); } $locks[]=$name; }
    return $locks;
}
function sfm_remote_cleanup_unlock_locks(mysqli $db,array $locks): void { for($i=count($locks)-1;$i>=0;$i--){ sfm_remote_cleanup_mysql_unlock($db,$locks[$i]); } }
function sfm_remote_cleanup_run_once(mysqli $db,int $pipelineRunId,bool $dryRun=false,bool $force=false): array {
    if(!$dryRun)sfm_remote_cleanup_require_schema($db); sfm_remote_cleanup_validate_owner_fields($pipelineRunId,null);
    $st=$db->prepare('SELECT * FROM sfm_pipeline_runs WHERE id=? LIMIT 1'); if(!$st)throw new RuntimeException($db->error); $st->bind_param('i',$pipelineRunId);$st->execute();$run=$st->get_result()->fetch_assoc();$st->close(); if(!$run)throw new RuntimeException('pipeline_run not found');
    $status=strtoupper((string)$run['status']); if(!in_array($status,SFM_REMOTE_CLEANUP_PIPELINE_TERMINAL_STATUSES,true))throw new RuntimeException('pipeline not terminal'); if(!$force && sfm_remote_cleanup_active_jobs_count($db,$pipelineRunId)>0)throw new RuntimeException('pipeline has active jobs');
    $v=sfm_remote_cleanup_verify_jobs($db,$pipelineRunId); if(!$v['ok'])throw new RuntimeException(implode('; ',$v['errors'])); if(!$v['remote_job_ids'])return ['ok'=>true,'freed_bytes'=>0,'remote_job_ids'=>[],'diagnostics'=>$v['diagnostics'],'script'=>null];
    $locks=[]; try { if(!$dryRun)$locks=sfm_remote_cleanup_lock_remote_ids($db,$v['remote_job_ids']); $json=sfm_remote_cleanup_run_station_script($v['remote_job_ids'],$dryRun); } finally { if($locks)sfm_remote_cleanup_unlock_locks($db,$locks); }
    return ['ok'=>true,'freed_bytes'=>(int)($json['freed_bytes']??0),'remote_job_ids'=>$v['remote_job_ids'],'diagnostics'=>$v['diagnostics'],'deleted_paths'=>$json['paths'] ?? [],'script_result'=>$json,'dry_run'=>$dryRun];
}
function sfm_remote_cleanup_job_row_for_remote(mysqli $db,int $remoteJobId): array { $st=$db->prepare('SELECT r.remote_job_id,r.job_type,r.status,r.parent_remote_job_id,r.chunk_index,r.chunk_count,r.output_path,r.parameters_json,r.result_json_path,r.capture_session_id,NULL AS video_scan_id,NULL AS pipeline_mode FROM sfm_remote_jobs r WHERE r.remote_job_id=? AND r.pipeline_run_id IS NULL ORDER BY r.id DESC LIMIT 1'); if(!$st)throw new RuntimeException('prepare failed: '.$db->error); $st->bind_param('i',$remoteJobId); $st->execute(); $row=$st->get_result()->fetch_assoc() ?: []; $st->close(); if($row){$params=json_decode((string)($row['parameters_json']??''),true); if(is_array($params)&&isset($params['model_id'])){$row['model_id']=(int)$params['model_id'];}} return $row; }
function sfm_remote_cleanup_verify_remote_job(mysqli $db,int $remoteJobId): array { $job=sfm_remote_cleanup_job_row_for_remote($db,$remoteJobId); if(!$job)return ['ok'=>false,'errors'=>['remote_job_id='.$remoteJobId.' not found'],'remote_job_ids'=>[],'jobs'=>[],'diagnostics'=>[]]; if(!sfm_remote_cleanup_is_true_standalone_job($db,$job))return ['ok'=>false,'errors'=>['remote_job_id='.$remoteJobId.' is not eligible for standalone cleanup'],'remote_job_ids'=>[],'jobs'=>[$job],'diagnostics'=>[]]; $errors=[]; $diagnostics=[]; $ids=[]; $rid=(int)$job['remote_job_id']; $type=(string)$job['job_type']; $status=strtoupper((string)$job['status']); $contract=sfm_remote_cleanup_artifact_contract($job); $path=null; $depCount=sfm_remote_cleanup_dependency_count($db,0,$rid); $eligible=false; if(!in_array($status,sfm_remote_job_terminal_statuses(),true)){$errors[]='remote_job_id='.$rid.' not terminal: '.$status;} elseif($depCount>0){$errors[]='remote_job_id='.$rid.' has active dependencies';} elseif($status==='DONE'){ if($type==='COLMAP_DENSE_CHUNK'){ foreach($contract['paths'] as $requiredPath){ if($requiredPath==='' || !is_file($requiredPath) || filesize($requiredPath)<=0){$errors[]='remote_job_id='.$rid.' missing dense chunk required artifact: '.$requiredPath;} } $path=$contract['paths'][0] ?? null; } else { $path=sfm_remote_cleanup_first_existing_artifact($job); $result=sfm_remote_cleanup_result_json_path($job); if($result===null && !in_array($type,['EXPORT_PLY'],true))$errors[]='remote_job_id='.$rid.' result JSON missing/empty'; if($path===null)$errors[]='remote_job_id='.$rid.' required local artifact missing/empty'; } if(!$errors){$eligible=true;$ids[]=$rid;} } else {$eligible=true;$ids[]=$rid;} $diagnostics[]=['pipeline_run_id'=>null,'remote_job_id'=>$rid,'job_type'=>$type,'status'=>$status,'effective_artifact_owner_id'=>(int)$contract['owner_remote_job_id'],'local_verification_path'=>$path,'dependency_status'=>$depCount>0?'blocked':'clear','cleanup_eligibility'=>$eligible?'eligible':'blocked']; return ['ok'=>empty($errors),'errors'=>$errors,'remote_job_ids'=>$ids,'jobs'=>[$job],'diagnostics'=>$diagnostics]; }
function sfm_remote_cleanup_run_remote_job_once(mysqli $db,int $remoteJobId,bool $dryRun=false,bool $force=false): array {
    if(!$dryRun)sfm_remote_cleanup_require_schema($db); sfm_remote_cleanup_validate_owner_fields(null,$remoteJobId);
    if(sfm_remote_cleanup_job_attached_to_pipeline($db,$remoteJobId))throw new RuntimeException('remote_job_id='.$remoteJobId.' is attached to a pipeline; standalone cleanup skipped');
    $v=sfm_remote_cleanup_verify_remote_job($db,$remoteJobId); if(!$v['ok'])throw new RuntimeException(implode('; ',$v['errors'])); if(!$v['remote_job_ids'])return ['ok'=>true,'freed_bytes'=>0,'remote_job_ids'=>[],'diagnostics'=>$v['diagnostics'],'script'=>null];
    $locks=[]; try { if(!$dryRun)$locks=sfm_remote_cleanup_lock_remote_ids($db,$v['remote_job_ids']); $json=sfm_remote_cleanup_run_station_script($v['remote_job_ids'],$dryRun); } finally { if($locks)sfm_remote_cleanup_unlock_locks($db,$locks); }
    return ['ok'=>true,'freed_bytes'=>(int)($json['freed_bytes']??0),'remote_job_ids'=>$v['remote_job_ids'],'diagnostics'=>$v['diagnostics'],'deleted_paths'=>$json['paths'] ?? [],'script_result'=>$json,'dry_run'=>$dryRun];
}
function sfm_remote_cleanup_mysql_lock(mysqli $db,string $name): bool { $st=$db->prepare('SELECT GET_LOCK(?,0) got'); if(!$st)return false; $st->bind_param('s',$name); $st->execute(); $got=(int)($st->get_result()->fetch_assoc()['got'] ?? 0); $st->close(); return $got===1; }
function sfm_remote_cleanup_mysql_unlock(mysqli $db,string $name): void { $st=$db->prepare('SELECT RELEASE_LOCK(?)'); if($st){$st->bind_param('s',$name);$st->execute();$st->close();} }
function sfm_remote_cleanup_mysql_lock_used(mysqli $db,string $name): bool { $st=$db->prepare('SELECT IS_USED_LOCK(?) holder'); if(!$st)return true; $st->bind_param('s',$name); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); return !empty($row['holder']); }
function sfm_remote_cleanup_recover_stale_running(mysqli $db): int {
    $stale=max(60,(int)(getenv('SFM_REMOTE_CLEANUP_STALE_SECONDS') ?: 1800)); $backoff=300; $n=0;
    $st=$db->prepare("SELECT id,pipeline_run_id,remote_job_id FROM sfm_remote_cleanup_runs WHERE remote_cleanup_status='RUNNING' AND remote_cleanup_started_at < DATE_SUB(NOW(6), INTERVAL ? SECOND)"); if(!$st)return 0; $st->bind_param('i',$stale); $st->execute(); $rs=$st->get_result();
    while($row=$rs->fetch_assoc()){ $ids=[]; $pid=(int)($row['pipeline_run_id'] ?? 0); $rid=(int)($row['remote_job_id'] ?? 0); if($pid>0){ foreach(sfm_cleanup_remote_job_ids($db,$pid) as $job)$ids[]=(int)$job['remote_job_id']; } elseif($rid>0){ $ids[]=$rid; }
        $busy=false; foreach(array_unique($ids) as $id){ if($id>0 && sfm_remote_cleanup_mysql_lock_used($db,sfm_remote_cleanup_remote_lock_name($id))){$busy=true; break;} } if($busy)continue;
        $u=$db->prepare("UPDATE sfm_remote_cleanup_runs SET remote_cleanup_status='ERROR', remote_cleanup_finished_at=NOW(6), remote_cleanup_last_error='cleanup worker stale RUNNING recovered', next_attempt_at=DATE_ADD(NOW(6), INTERVAL ? SECOND) WHERE id=? AND remote_cleanup_status='RUNNING'"); if($u){$cid=(int)$row['id']; $u->bind_param('ii',$backoff,$cid); $u->execute(); $n+=max(0,$u->affected_rows); $u->close();}
    } $st->close(); return $n;
}
function sfm_remote_cleanup_claim_row(mysqli $db,int $cleanupId): bool { $u=$db->prepare("UPDATE sfm_remote_cleanup_runs SET remote_cleanup_status='RUNNING', remote_cleanup_started_at=NOW(6), remote_cleanup_attempts=remote_cleanup_attempts+1 WHERE id=? AND remote_cleanup_status IN ('PENDING','ERROR') AND (next_attempt_at IS NULL OR next_attempt_at<=NOW(6))"); if(!$u)return false; $u->bind_param('i',$cleanupId); $u->execute(); $ok=$u->affected_rows>0; $u->close(); return $ok; }
function sfm_remote_cleanup_claim(mysqli $db,int $pipelineRunId,bool $includeDone=false): bool { $statuses=$includeDone?"'PENDING','ERROR','DONE'":"'PENDING','ERROR'"; $u=$db->prepare("UPDATE sfm_remote_cleanup_runs SET remote_cleanup_status='RUNNING', remote_cleanup_started_at=NOW(6), remote_cleanup_attempts=remote_cleanup_attempts+1 WHERE pipeline_run_id=? AND remote_cleanup_status IN (".$statuses.") AND (remote_cleanup_status='DONE' OR next_attempt_at IS NULL OR next_attempt_at<=NOW(6))"); if(!$u)return false; $u->bind_param('i',$pipelineRunId); $u->execute(); $ok=$u->affected_rows>0; $u->close(); return $ok; }
function sfm_remote_cleanup_worker_tick(mysqli $db,int $limit=1): int { sfm_remote_cleanup_require_schema($db); sfm_remote_cleanup_recover_stale_running($db); $n=0; $res=$db->query("SELECT id,pipeline_run_id,remote_job_id FROM sfm_remote_cleanup_runs WHERE remote_cleanup_status IN ('PENDING','ERROR') AND (next_attempt_at IS NULL OR next_attempt_at<=NOW(6)) ORDER BY next_attempt_at IS NULL DESC,next_attempt_at ASC,id ASC LIMIT ".max(1,$limit)); if(!$res)return 0; while($row=$res->fetch_assoc()){ $cleanupId=(int)$row['id']; $pid=(int)($row['pipeline_run_id'] ?? 0); $remoteJobId=(int)($row['remote_job_id'] ?? 0); if($remoteJobId>0 && sfm_remote_cleanup_job_attached_to_pipeline($db,$remoteJobId)){ $q=$db->prepare("UPDATE sfm_remote_cleanup_runs SET remote_cleanup_status='SKIPPED', remote_cleanup_finished_at=NOW(6), remote_cleanup_last_error='standalone job attached to pipeline; pipeline cleanup owns remote data' WHERE id=?"); if($q){$q->bind_param('i',$cleanupId);$q->execute();$q->close();} $n++; continue; } if(!sfm_remote_cleanup_claim_row($db,$cleanupId))continue; try{ if($pid>0){$r=sfm_remote_cleanup_run_once($db,$pid,false,false);} elseif($remoteJobId>0){$r=sfm_remote_cleanup_run_remote_job_once($db,$remoteJobId,false,false);} else {throw new RuntimeException('cleanup row has neither pipeline_run_id nor remote_job_id');} $json=json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); $freed=(int)$r['freed_bytes']; $s='DONE'; $q=$db->prepare("UPDATE sfm_remote_cleanup_runs SET remote_cleanup_status=?, remote_cleanup_finished_at=NOW(6), remote_cleanup_freed_bytes=?, remote_cleanup_result_json=?, remote_cleanup_last_error=NULL WHERE id=?"); $q->bind_param('sisi',$s,$freed,$json,$cleanupId);$q->execute();$q->close();}catch(Throwable $e){ $err=$e->getMessage(); $backoff=300; $q=$db->prepare("UPDATE sfm_remote_cleanup_runs SET remote_cleanup_status='ERROR', remote_cleanup_finished_at=NOW(6), remote_cleanup_last_error=?, next_attempt_at=DATE_ADD(NOW(6), INTERVAL ? SECOND) WHERE id=?"); if($q){$q->bind_param('sii',$err,$backoff,$cleanupId);$q->execute();$q->close();} } $n++; } $res->close(); return $n; }



function sfm_cleanup_is_numeric_id($id): bool { return is_int($id) ? $id > 0 : (is_string($id) && preg_match('/^[1-9][0-9]*$/', $id) === 1); }
function sfm_cleanup_human_bytes(int $bytes): string { $u=['B','KB','MB','GB','TB']; $v=(float)$bytes; $i=0; while($v>=1024 && $i<count($u)-1){$v/=1024;$i++;} return ($i===0?(string)(int)$v:sprintf('%.1f',$v)).' '.$u[$i]; }
function sfm_cleanup_path_size(string $path): int { if(!file_exists($path)&&!is_link($path))return 0; if(is_link($path)||is_file($path))return (int)@filesize($path); $total=0; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS|FilesystemIterator::CURRENT_AS_FILEINFO),RecursiveIteratorIterator::SELF_FIRST); foreach($it as $f){ if($f->isLink())continue; if($f->isFile())$total+=(int)$f->getSize(); } return $total; }
function sfm_cleanup_safe_artifact_path(string $path, int $rid, bool $includeLogs=false): bool { $n=rtrim($path,'/'); $web=SFM_CLEANUP_WEB_OUTPUT_BASE.'/job_'.$rid; $base=SFM_CLEANUP_STATION_BASE_DEFAULT; $exact=[$web,$base.'/input/job_'.$rid,$base.'/output/job_'.$rid,$base.'/work/job_'.$rid,$base.'/status/job_'.$rid.'.json']; if($includeLogs){$exact[]=$base.'/logs/job_'.$rid.'.log';$exact[]=$base.'/logs/job_'.$rid.'.nohup.log';} $prefix=[$base.'/incoming/job_'.$rid.'_']; return in_array($n,$exact,true) || str_starts_with($n,$prefix[0]); }
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
function sfm_cleanup_remote_job_ids(mysqli $db,int $pipelineRunId): array { $jobs=[]; $st=$db->prepare('SELECT r.remote_job_id,r.job_type,r.status,r.parent_remote_job_id,r.chunk_index,r.chunk_count,r.output_path,r.parameters_json,r.result_json_path,p.capture_session_id,p.video_scan_id,p.pipeline_mode FROM sfm_remote_jobs r LEFT JOIN sfm_pipeline_runs p ON p.id=r.pipeline_run_id WHERE r.pipeline_run_id=? ORDER BY r.id ASC'); if(!$st)throw new RuntimeException('prepare failed: '.$db->error); $st->bind_param('i',$pipelineRunId); $st->execute(); $rs=$st->get_result(); while($row=$rs->fetch_assoc()){ $rid=(string)($row['remote_job_id']??''); if(sfm_cleanup_is_numeric_id($rid)){$row['remote_job_id']=(int)$rid; $params=json_decode((string)($row['parameters_json']??''),true); if(is_array($params)&&isset($params['model_id'])){$row['model_id']=(int)$params['model_id'];} $jobs[]=$row;} } $st->close(); return $jobs; }
function sfm_cleanup_remote_job_lookup(mysqli $db,array $ids): array { $out=[]; foreach($ids as $id){ $st=$db->prepare('SELECT r.pipeline_run_id,r.remote_job_id,r.job_type,r.status,r.parent_remote_job_id,r.capture_session_id,p.video_scan_id,p.pipeline_mode,p.status AS pipeline_status,p.created_at AS pipeline_created_at FROM sfm_remote_jobs r LEFT JOIN sfm_pipeline_runs p ON p.id=r.pipeline_run_id WHERE r.remote_job_id=? ORDER BY r.id DESC LIMIT 1'); if(!$st)throw new RuntimeException($db->error); $st->bind_param('i',$id); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); $out[$id]=$row ?: ['remote_job_id'=>$id,'orphan'=>true]; } return $out; }
function sfm_cleanup_job_paths(int $rid,bool $includeLogs): array { $paths=[SFM_CLEANUP_WEB_OUTPUT_BASE.'/job_'.$rid,SFM_CLEANUP_STATION_BASE_DEFAULT.'/input/job_'.$rid,SFM_CLEANUP_STATION_BASE_DEFAULT.'/output/job_'.$rid,SFM_CLEANUP_STATION_BASE_DEFAULT.'/work/job_'.$rid,SFM_CLEANUP_STATION_BASE_DEFAULT.'/status/job_'.$rid.'.json']; if($includeLogs){$paths[]=SFM_CLEANUP_STATION_BASE_DEFAULT.'/logs/job_'.$rid.'.log';$paths[]=SFM_CLEANUP_STATION_BASE_DEFAULT.'/logs/job_'.$rid.'.nohup.log';} foreach([SFM_CLEANUP_STATION_BASE_DEFAULT.'/incoming/job_'.$rid.'_*'] as $g){ foreach(glob($g)?:[] as $p)$paths[]=$p; } return array_values(array_unique($paths)); }
function sfm_cleanup_delete_job_artifacts(int $rid,array $options): array { $delete=!empty($options['delete']); $include=!empty($options['include_logs']); $res=['remote_job_id'=>$rid,'paths'=>[],'missing_paths'=>[],'errors'=>[],'reclaimable_bytes'=>0,'freed_bytes'=>0]; foreach(sfm_cleanup_job_paths($rid,$include) as $p){ if(!sfm_cleanup_safe_artifact_path($p,$rid,$include)||str_starts_with($p,'/home/storage/orders/')){$res['errors'][]=['path'=>$p,'message'=>'unsafe path rejected']; continue;} if(!file_exists($p)&&!is_link($p)){$res['missing_paths'][]=$p; $res['paths'][]=['path'=>$p,'missing'=>true,'size_bytes'=>0]; continue;} try{$d=sfm_cleanup_delete_path($p,$delete); $res['paths'][]=$d; if($delete && !empty($d['deleted']))$res['freed_bytes']+=(int)$d['size_bytes']; if(!$delete)$res['reclaimable_bytes']+=(int)$d['size_bytes'];}catch(Throwable $e){$res['errors'][]=['path'=>$p,'message'=>$e->getMessage()];} } return $res; }
function sfm_cleanup_run_protection_reasons(mysqli $db,array $run,array $options=[]): array { $reasons=[]; $status=strtoupper((string)($run['status']??'')); if(in_array($status,SFM_CLEANUP_ACTIVE_STATUSES,true))$reasons[]='active'; $created=strtotime((string)($run['created_at']??'')); if($created && $created>=time()-86400 && empty($options['force_recent']))$reasons[]='recent'; if(empty($options['force_latest'])){ $st=$db->prepare('SELECT MAX(id) id FROM sfm_pipeline_runs WHERE capture_session_id=? AND video_scan_id=? AND pipeline_mode=?'); if($st){$sid=(int)$run['capture_session_id'];$vid=(int)$run['video_scan_id'];$mode=(string)$run['pipeline_mode'];$st->bind_param('iis',$sid,$vid,$mode);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close(); if((int)($r['id']??0)===(int)$run['id'])$reasons[]='latest_for_video_mode';} } return array_values(array_unique($reasons)); }
function sfm_cleanup_select_runs(mysqli $db,array $options): array { $where=[];$types='';$params=[]; if(!empty($options['pipeline_run_id'])){$where[]='id=?';$types.='i';$params[]=(int)$options['pipeline_run_id'];} if(!empty($options['older_than'])){$where[]='created_at < ?';$types.='s';$params[]=(string)$options['older_than'].' 00:00:00';} if(!empty($options['video_scan_id'])){$where[]='CAST(video_scan_id AS CHAR)=?';$types.='s';$params[]=(string)$options['video_scan_id'];} if(!empty($options['mode'])){$where[]='pipeline_mode=?';$types.='s';$params[]=(string)$options['mode'];} $sql='SELECT id,capture_session_id,video_scan_id,pipeline_mode,status,created_at FROM sfm_pipeline_runs'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id ASC'; $st=$db->prepare($sql); if(!$st)throw new RuntimeException($db->error); if($types!=='')$st->bind_param($types,...$params); $st->execute(); $rs=$st->get_result(); $rows=[]; while($r=$rs->fetch_assoc()){ $r['protection_reasons']=sfm_cleanup_run_protection_reasons($db,$r,$options); $r['protected']=!empty($r['protection_reasons']); $rows[]=$r;} $st->close(); return $rows; }
function sfm_cleanup_pipeline_run_artifacts(mysqli $db,int $pipelineRunId,array $options=[]): array { $delete=!empty($options['delete']); $result=['pipeline_run_id'=>$pipelineRunId,'delete'=>$delete,'include_logs'=>!empty($options['include_logs']),'jobs'=>[],'deleted_paths'=>[],'missing_paths'=>[],'errors'=>[],'reclaimable_bytes'=>0,'freed_bytes'=>0]; foreach(sfm_cleanup_remote_job_ids($db,$pipelineRunId) as $job){ $rid=(int)$job['remote_job_id']; $jr=sfm_cleanup_delete_job_artifacts($rid,$options); $entry=$job+['paths'=>$jr['paths']]; $result['jobs'][]=$entry; foreach($jr['paths'] as $p){ if(empty($p['missing']))$result['deleted_paths'][]=$p['path']; } foreach($jr['missing_paths'] as $p)$result['missing_paths'][]=$p; foreach($jr['errors'] as $e)$result['errors'][]=$e; $result['reclaimable_bytes']+=(int)$jr['reclaimable_bytes']; $result['freed_bytes']+=(int)$jr['freed_bytes']; } if($delete)sfm_cleanup_update_metadata_if_available($db,$pipelineRunId,$result); return $result; }
function sfm_cleanup_discover_orphan_job_ids(string $olderThan): array { $cut=strtotime($olderThan.' 00:00:00'); $ids=[]; foreach([SFM_CLEANUP_WEB_OUTPUT_BASE.'/job_*',SFM_CLEANUP_STATION_BASE_DEFAULT.'/input/job_*',SFM_CLEANUP_STATION_BASE_DEFAULT.'/output/job_*',SFM_CLEANUP_STATION_BASE_DEFAULT.'/work/job_*',SFM_CLEANUP_STATION_BASE_DEFAULT.'/incoming/job_*',SFM_CLEANUP_STATION_BASE_DEFAULT.'/status/job_*'] as $g){ foreach(glob($g)?:[] as $p){ if(@filemtime($p)!==false && @filemtime($p)>=$cut)continue; if(preg_match('/job_([1-9][0-9]*)/',$p,$m))$ids[(int)$m[1]]=true; } } return array_keys($ids); }
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
