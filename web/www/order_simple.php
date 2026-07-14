<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/remote_station/sfm_pipeline.php';
require_once dirname(__DIR__) . '/libs/sfm_manual_alignment_lib.php';
auth_require_login();
$user=auth_current_user(); $userId=(int)$user['id']; $role=(string)($user['role'] ?? 'BROKER');
$orderId=(int)($_GET['id'] ?? 0); if($orderId<=0){ http_response_code(400); exit('Bad order id'); }
function osv_status_meta(string $status): array { $m=['NEW'=>['bg-secondary','bi-circle','Новая'],'ASSIGNED'=>['bg-primary','bi-person-check','В работе'],'IN_PROGRESS'=>['bg-info','bi-camera','Съемка'],'CAPTURED'=>['bg-warning','bi-check2-square','Отснята'],'UPLOADING'=>['bg-warning','bi-cloud-upload','Загружается'],'UPLOADED'=>['bg-success','bi-cloud-check','Загружена'],'PROCESSING'=>['bg-info','bi-gear','Обработка'],'READY'=>['bg-success','bi-check-circle','Готова'],'COMPLETED'=>['bg-dark','bi-check2-all','Завершена'],'CLOSED'=>['bg-dark','bi-lock','Закрыта']]; $x=$m[$status]??['bg-secondary','bi-circle',$status]; return ['class'=>$x[0],'icon'=>$x[1],'label'=>$x[2]]; }
function osv_bytes($bytes): string { $b=(float)$bytes; if($b<=0)return '0 B'; $u=['B','KB','MB','GB','TB']; $i=0; while($b>=1024 && $i<count($u)-1){$b/=1024;$i++;} return round($b,2).' '.$u[$i]; }
function osv_remote_dir(int $remoteJobId): string { return '/home/makler/web/remote_station/output/job_'.$remoteJobId; }
function osv_ply_info(string $path): array { $out=['valid'=>false,'vertices'=>0,'faces'=>0,'size_bytes'=>0,'size_human'=>'0 B']; if(!is_file($path)){return $out;} $out['size_bytes']=(int)filesize($path); $out['size_human']=osv_bytes($out['size_bytes']); $fh=@fopen($path,'rb'); if(!$fh)return $out; $head=''; while(!feof($fh) && strlen($head)<65536){ $line=(string)fgets($fh); $head.=$line; if(trim($line)==='end_header')break; } fclose($fh); if(strpos($head,"ply\n")!==0 && strpos($head,"ply\r\n")!==0)return $out; if(preg_match('/element vertex\s+(\d+)/',$head,$m)){$out['vertices']=(int)$m[1];} if(preg_match('/element face\s+(\d+)/',$head,$m)){$out['faces']=(int)$m[1];} $out['valid']=$out['vertices']>0 || $out['faces']>0; return $out; }
function osv_json_array(?string $json): array { $d=json_decode((string)$json,true); return is_array($d)?$d:[]; }
function osv_safe_uuid(string $uuid): string { return preg_replace('/[^a-zA-Z0-9_-]/','_',$uuid) ?: 'session'; }
function osv_session_videos_dir(int $orderId,string $uuid): string { return '/home/makler/web/storage/orders/'.$orderId.'/sessions/'.osv_safe_uuid($uuid).'/videos'; }
function osv_video_scan_safe_uuid(string $uuid, int $scanId): string { $safe=preg_replace('/[^a-zA-Z0-9._-]+/','_',$uuid); return $safe!==''?$safe:('scan_'.$scanId); }
function osv_video_has_imu_sidecar(int $orderId, array $session, array $video): bool { $dir=osv_session_videos_dir($orderId,(string)($session['app_session_uuid'] ?? '')); if(!is_dir($dir)){ return false; } $scanId=(int)($video['id'] ?? 0); $appScanUuid=(string)($video['app_scan_uuid'] ?? ''); $safe=osv_video_scan_safe_uuid($appScanUuid,$scanId); $filename=(string)($video['filename'] ?? ''); $stem=$filename!==''?pathinfo($filename,PATHINFO_FILENAME):''; $base=$stem!==''?preg_replace('/_video$/','',$stem):''; $storagePath=(string)($video['storage_path'] ?? ''); $storageStem=$storagePath!==''?pathinfo($storagePath,PATHINFO_FILENAME):''; $storageBase=$storageStem!==''?preg_replace('/_video$/','',$storageStem):''; $candidates=[]; foreach([$safe,$stem,$base,$storageStem,$storageBase] as $prefix){ $prefix=trim((string)$prefix); if($prefix!==''){ $candidates[]=$dir.'/'.$prefix.'_imu.jsonl'; } } foreach(array_values(array_unique($candidates)) as $path){ if(is_file($path) && is_readable($path) && filesize($path)>0){ return true; } } return false; }
function osv_video_label(array $v): string { $label=trim((string)($v['label'] ?? '')); if($label!=='')return $label; $comment=trim((string)($v['comment'] ?? '')); return $comment!==''?$comment:(string)($v['filename'] ?? ('Video #'.(int)($v['id'] ?? 0))); }
function osv_job_title(array $j): string { $t=(string)$j['job_type']; $mid=$j['ui_model_id'] ?? null; $model=$mid!==null?' — Model '.(int)$mid:''; if($t==='COLMAP_RECONSTRUCTION_PREVIEW')return 'Preview reconstruction'.$model; if($t==='COLMAP_RECONSTRUCTION_HQ')return 'High quality reconstruction'.$model; if($t==='COLMAP_MESH')return 'Mesh generation'.$model; if($t==='MAKLERTOUR_SYNCED_DENSE')return 'Synced stereo dense'; return $t; }
function osv_pipeline_modes(): array { return ['preview'=>['label'=>'Preview','start'=>'Start preview 640'],'standard'=>['label'=>'Standard','start'=>'Start standard'],'fullhd'=>['label'=>'FullHD','start'=>'Start fullhd']]; }
function osv_sfm_run_ui_progress(array $run): array {
  $status=strtoupper((string)($run['status'] ?? ''));
  $stage=(string)($run['stage'] ?? '');
  $progress=(int)($run['progress_percent'] ?? 0);
  $params=osv_json_array($run['parameters_json'] ?? '{}');
  $autoAll=!empty($params['auto_process_all_components']);
  $auto=is_array($params['auto_components'] ?? null) ? $params['auto_components'] : [];
  $primaryDenseReady=!empty($run['artifacts']['dense']['available']);

  if($status==='CANCELLING'){
    return [
      'stage'=>'CANCELLING',
      'progress_percent'=>min(99,$progress),
      'note'=>'Cancellation requested',
    ];
  }

  $active=in_array($status,[
    'QUEUED',
    'RUNNING',
    'RUNNING_CHUNKS',
    'PLANNING',
    'MERGING',
    'STARTED',
    'PROCESSING'
  ],true);

  $combinedReady=!empty($auto['combined_model_available']);
  $alignedMerge=(string)($auto['aligned_merge'] ?? '');

  if($autoAll && $active && !$combinedReady){
    $selected=max(0,(int)($auto['selected_useful_models'] ?? 0));
    $done=max(0,(int)($auto['previews_done'] ?? 0));

    if($selected>0){
      if(!$primaryDenseReady){
        return [
          'stage'=>$stage !== '' ? $stage : 'PROCESSING',
          'progress_percent'=>min(99,$progress),
          'note'=>'Primary dense model processing; combined model pending',
        ];
      }

      $displayDone=max(1,$done);
      $autoProgress=(int)round(min(99,max(0,($displayDone / $selected) * 90)));
      if($alignedMerge==='running'){
        $autoProgress=max($autoProgress,95);
      }

      return [
        'stage'=>'AUTO COMPONENTS',
        'progress_percent'=>$autoProgress,
        'note'=>'Primary dense model ready; building combined model '.$displayDone.'/'.$selected,
      ];
    }

    return [
      'stage'=>$stage !== '' ? $stage : 'PROCESSING',
      'progress_percent'=>min(99,$progress),
      'note'=>'Primary dense model processing; combined model pending',
    ];
  }

  return [
    'stage'=>$stage,
    'progress_percent'=>$progress,
    'note'=>'',
  ];
}
function osv_pipeline_artifacts(array $run,array $jobs): array { $rid=(int)$run['id']; $out=['dense'=>['available'=>false,'viewer_url'=>'','download_url'=>'','vertices'=>0,'size_human'=>'','job_id'=>0,'remote_job_id'=>0,'model_id'=>null,'sparse_remote_job_id'=>0],'mesh'=>['available'=>false,'viewer_url'=>'','download_url'=>'','vertices'=>0,'faces'=>0,'size_human'=>''],'result_json'=>['available'=>false,'download_url'=>'']]; $recon=null; $mesh=null; foreach($jobs as $j){ if((int)($j['pipeline_run_id'] ?? 0)!==$rid)continue; $jt=(string)($j['job_type'] ?? ''); if(in_array($jt,['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'],true) && strtoupper((string)($j['status'] ?? ''))==='DONE')$recon=$j; if($jt==='COLMAP_MESH' && strtoupper((string)($j['status'] ?? ''))==='DONE')$mesh=$j; }
  $videoParam=((int)($run['video_scan_id'] ?? 0)>0)?'&video_scan_id='.(int)$run['video_scan_id']:'';
  if($recon){ $reconParams=osv_json_array($recon['parameters_json'] ?? ''); $modelId=$reconParams['model_id'] ?? null; $sparseRemote=(int)($reconParams['sparse_remote_job_id'] ?? ($reconParams['sparse_job_id'] ?? ($recon['parent_remote_job_id'] ?? 0))); $pi=osv_ply_info(osv_remote_dir((int)$recon['remote_job_id']).'/merged/merged_fused.ply'); if($pi['valid']){ $out['dense']=['available'=>true,'viewer_url'=>'/sfm_3d_viewer.php?order_id='.(int)$run['order_id'].'&session_id='.(int)$run['capture_session_id'].$videoParam.'&pipeline_run_id='.$rid.'&artifact=dense&dense_remote_job_id='.(int)$recon['remote_job_id'],'download_url'=>'/api/sfm_remote_job_status.php?job_id='.(int)$recon['id'].'&file=ply','vertices'=>$pi['vertices'],'size_human'=>$pi['size_human'],'job_id'=>(int)$recon['id'],'remote_job_id'=>(int)$recon['remote_job_id'],'model_id'=>$modelId,'sparse_remote_job_id'=>$sparseRemote]; }}
  if($mesh){ $pi=osv_ply_info(osv_remote_dir((int)$mesh['remote_job_id']).'/mesh/mesh_final.ply'); if($pi['valid'] && $pi['faces']>0){ $out['mesh']=['available'=>true,'viewer_url'=>'/sfm_3d_viewer.php?order_id='.(int)$run['order_id'].'&session_id='.(int)$run['capture_session_id'].$videoParam.'&pipeline_run_id='.$rid.'&artifact=mesh','download_url'=>'/api/sfm_pipeline_artifact.php?pipeline_run_id='.$rid.'&artifact=mesh'.$videoParam,'vertices'=>$pi['vertices'],'faces'=>$pi['faces'],'size_human'=>$pi['size_human']]; }}
  $result=(string)($run['output_result_json_path'] ?? ''); if($result===''){$result='/home/makler/web/remote_station/output/pipeline_'.$rid.'/result.json';} if(is_file($result)){$out['result_json']=['available'=>true,'download_url'=>'/api/sfm_pipeline_artifact.php?pipeline_run_id='.$rid.'&artifact=result'.$videoParam];}
  return $out; }

function osv_sparse_components_for_job(int $sparseRemoteJobId): array {
  if($sparseRemoteJobId<=0) return [];
  foreach([osv_remote_dir($sparseRemoteJobId).'/colmap/sparse_components.json', osv_remote_dir($sparseRemoteJobId).'/sparse_components.json'] as $path){
    if(is_file($path)){ $d=json_decode((string)file_get_contents($path),true); return is_array($d)?$d:[]; }
  }
  return [];
}
function osv_run_sparse_components(array $run,array $jobs,int $orderId,int $sessionId): array {
  $rid=(int)($run['id'] ?? 0); $sparse=null;
  foreach($jobs as $j){ if((int)($j['pipeline_run_id']??0)===$rid && (string)($j['job_type']??'')==='COLMAP_SPARSE'){ $sparse=$j; break; } }
  if(!$sparse) return [];
  $remote=(int)$sparse['remote_job_id']; $payload=osv_sparse_components_for_job($remote); $models=$payload['models'] ?? [];
  if(!is_array($models)) return [];
  $denseByModel=[];
  foreach($jobs as $j){
    if((int)($j['pipeline_run_id']??0)!==$rid) continue;
    if(!in_array((string)($j['job_type']??''),['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'],true)) continue;
    $params=osv_json_array($j['parameters_json'] ?? '{}');
    if(!array_key_exists('model_id',$params)) continue;
    $mid=(int)$params['model_id']; $ply=osv_remote_dir((int)$j['remote_job_id']).'/merged/merged_fused.ply'; $pi=osv_ply_info($ply);
    $denseByModel[$mid]=['db_job_id'=>(int)$j['id'],'remote_job_id'=>(int)$j['remote_job_id'],'status'=>(string)$j['status'],'has_dense'=>!empty($pi['valid']),'viewer_url'=>!empty($pi['valid'])?('/sfm_3d_viewer.php?order_id='.$orderId.'&session_id='.$sessionId.'&pipeline_run_id='.$rid.'&artifact=dense&dense_remote_job_id='.(int)$j['remote_job_id']):'','download_url'=>!empty($pi['valid'])?('/api/sfm_remote_job_status.php?job_id='.(int)$j['id'].'&file=ply'):''];
  }
  $out=[];
  foreach($models as $m){ if(!is_array($m)) continue; $mid=(int)($m['model_id'] ?? 0); $dense=$denseByModel[$mid] ?? null; $out[]=['model_id'=>$mid,'registered_images'=>(int)($m['registered_images'] ?? 0),'sparse_points'=>(int)($m['points3D_count'] ?? ($m['sparse_points'] ?? 0)),'selected'=>$dense!==null,'dense_status'=>$dense['status'] ?? 'not selected','dense_remote_job_id'=>$dense['remote_job_id'] ?? 0,'viewer_url'=>$dense['viewer_url'] ?? '','download_url'=>$dense['download_url'] ?? '']; }
  return $out;
}
function osv_build_generated(array $sessions, array $merges=[]): array {
  $rows=[]; $seen=[];
  foreach($sessions as $s){
    foreach(($s['sfm_disk_videos'] ?? []) as $dv){
      foreach(($dv['sfm_pipeline_cards'] ?? []) as $pc){
        $run=$pc['run'] ?? null;
        if(!$run || strtoupper((string)($run['status'] ?? ''))!=='DONE'){ continue; }
        $art=$run['artifacts'];
        if(empty($art['dense']['available']) && empty($art['mesh']['available']) && empty($art['result_json']['available'])){ continue; }
        $key='p:'.(int)$run['id']; if(isset($seen[$key])){ continue; } $seen[$key]=true;
        $faces=(int)($art['mesh']['faces'] ?? 0); $verts=(int)(($art['mesh']['vertices'] ?? 0) ?: ($art['dense']['vertices'] ?? 0));
        $denseModelId=$art['dense']['model_id'] ?? null; $sourceLabel='Pipeline '.(string)$run['pipeline_mode'].' — '.(string)($run['source_filename'] ?: ($dv['filename'] ?? 'Video')); if($denseModelId!==null && $denseModelId!==''){$sourceLabel.=' — Model '.$denseModelId;} $rows[]=['source_type'=>'Video','source_label'=>$sourceLabel,'mode'=>(string)$run['pipeline_mode'],'model_id'=>$denseModelId,'job_id'=>(int)$run['id'],'remote_job_id'=>(int)($art['dense']['remote_job_id'] ?? 0),'sparse_remote_job_id'=>(int)($art['dense']['sparse_remote_job_id'] ?? 0),'geometry_summary'=>$faces>0?($verts.' vertices / '.$faces.' faces'):($verts.' points'),'merge_source_job_id'=>!empty($art['dense']['available'])?(int)($art['dense']['job_id'] ?? 0):0,'open_dense_url'=>(string)($art['dense']['viewer_url'] ?? ''),'download_dense_url'=>(string)($art['dense']['download_url'] ?? ''),'open_mesh_url'=>(string)($art['mesh']['viewer_url'] ?? ''),'download_mesh_url'=>(string)($art['mesh']['download_url'] ?? ''),'result_json_url'=>(string)($art['result_json']['download_url'] ?? ''),'status_url'=>(string)($run['log_url'] ?? '')];
      }
    }
    $manualRecon=[]; $manualMesh=[];
    foreach(($s['sfm_remote_jobs'] ?? []) as $j){
      $jt=(string)($j['job_type'] ?? '');
      if((int)($j['pipeline_run_id'] ?? 0)>0 || strtoupper((string)($j['status'] ?? ''))!=='DONE'){ continue; }
      if(in_array($jt,['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'],true)){ $manualRecon[]=$j; }
      elseif($jt==='COLMAP_MESH'){ $manualMesh[(int)($j['parent_remote_job_id'] ?? 0)][]=$j; }
    }
    $attachedMesh=[];
    foreach($manualRecon as $j){
      $key='j:'.(int)$j['id']; if(isset($seen[$key])){ continue; } $seen[$key]=true;
      $base=osv_remote_dir((int)$j['remote_job_id']); $dense=osv_ply_info($base.'/merged/merged_fused.ply');
      $mesh=['valid'=>false,'vertices'=>0,'faces'=>0,'size_human'=>'']; $meshUrl=''; $meshJob=null;
      foreach(($manualMesh[(int)$j['remote_job_id']] ?? []) as $mj){ $mi=osv_ply_info(osv_remote_dir((int)$mj['remote_job_id']).'/mesh/mesh_final.ply'); if($mi['valid']){ $mesh=$mi; $meshJob=$mj; $attachedMesh[(int)$mj['id']]=true; $meshUrl='/api/sfm_remote_job_status.php?job_id='.(int)$mj['id'].'&file=ply'; break; } }
      $resultPath=(string)($j['result_json_path'] ?? ''); $resultUrl=is_file($resultPath)?(string)$j['result_json_url']:'';
      if(empty($dense['valid']) && empty($mesh['valid']) && $resultUrl===''){ continue; }
      $params=osv_json_array($j['parameters_json'] ?? ''); $mode=(string)($j['reconstruction_mode'] ?: ((string)$j['job_type']==='COLMAP_RECONSTRUCTION_HQ'?'hq':'preview')); $model=$j['ui_model_id'] ?? ($params['model_id'] ?? '');
      $parts=[]; if(!empty($dense['valid']))$parts[]='dense points: '.(int)$dense['vertices']; if(!empty($mesh['valid']))$parts[]='mesh: '.(int)$mesh['vertices'].' vertices / '.(int)$mesh['faces'].' faces';
      $rows[]=['source_type'=>'Video','source_label'=>($mode==='hq'?'High quality reconstruction':'Preview reconstruction').' — Model '.($model!==''?$model:'-'),'mode'=>$mode,'model_id'=>$model,'job_id'=>(int)$j['id'],'remote_job_id'=>(int)$j['remote_job_id'],'sparse_remote_job_id'=>(int)($j['parent_remote_job_id'] ?? 0),'geometry_summary'=>implode('; ',$parts),'merge_source_job_id'=>!empty($dense['valid'])?(int)$j['id']:0,'open_dense_url'=>'','download_dense_url'=>!empty($dense['valid'])?('/api/sfm_remote_job_status.php?job_id='.(int)$j['id'].'&file=ply'):'','open_mesh_url'=>'','download_mesh_url'=>$meshUrl,'result_json_url'=>$resultUrl,'status_url'=>(string)$j['status_url']];
    }
    foreach($manualMesh as $parent=>$arr){ foreach($arr as $j){ if(isset($attachedMesh[(int)$j['id']]))continue; $key='j:'.(int)$j['id']; if(isset($seen[$key]))continue; $seen[$key]=true; $base=osv_remote_dir((int)$j['remote_job_id']); $mesh=osv_ply_info($base.'/mesh/mesh_final.ply'); if(empty($mesh['valid']))continue; $params=osv_json_array($j['parameters_json'] ?? ''); $rows[]=['source_type'=>'Video','source_label'=>'Orphan mesh generation','mode'=>'mesh','model_id'=>$j['ui_model_id'] ?? ($params['model_id'] ?? ''),'job_id'=>(int)$j['id'],'remote_job_id'=>(int)$j['remote_job_id'],'sparse_remote_job_id'=>(int)($j['parent_remote_job_id'] ?? 0),'geometry_summary'=>(int)$mesh['vertices'].' vertices / '.(int)$mesh['faces'].' faces','merge_source_job_id'=>0,'open_dense_url'=>'','download_dense_url'=>'','open_mesh_url'=>'','download_mesh_url'=>'/api/sfm_remote_job_status.php?job_id='.(int)$j['id'].'&file=ply','result_json_url'=>'','status_url'=>(string)$j['status_url']]; } }
  }
  foreach($merges as $m){ $type=(string)($m['merge_type'] ?? ''); $isAligned=($type==='aligned_shared_images_dense_ply'); $isManual=($type==='manual_correspondences_sim3_dense_ply'); $isManualInc=($type==='manual_incremental_sim3_dense_ply'); $isAutoInc=($type==='automatic_incremental_shared_images_dense_ply'); $mid=(int)$m['id']; $rows[]=['source_type'=>'Merged','source_label'=>($isManual?'Manual aligned dense cloud #':($isAligned?'Aligned merged dense cloud #':($isManualInc?'Ручное дополнение #':($isAutoInc?'Автоматическое дополнение #':'Diagnostic merged dense cloud #')))).$mid,'mode'=>$isManual?'manual Sim(3) correspondences':($isAligned?'aligned shared images':($isManualInc?'manual incremental Sim(3)':($isAutoInc?'automatic incremental shared images':'diagnostic merge'))),'model_id'=>'-','job_id'=>$mid,'remote_job_id'=>0,'sparse_remote_job_id'=>0,'geometry_summary'=>(int)$m['total_points'].' points','merge_source_job_id'=>0,'open_dense_url'=>'/sfm_3d_viewer.php?order_id='.(int)$m['order_id'].'&merge_id='.$mid.'&artifact=dense','download_dense_url'=>'/api/sfm_generated_merge_file.php?merge_id='.$mid.'&file=ply','open_mesh_url'=>'','download_mesh_url'=>'','result_json_url'=>'/api/sfm_generated_merge_file.php?merge_id='.$mid.'&file=result','status_url'=>'/api/sfm_generated_merge_file.php?merge_id='.$mid.'&file=result']; }
  return $rows;
}

$stmt=$dbcnx->prepare("SELECT o.*,b.full_name broker_name,b.email broker_email,op.full_name operator_name,op.email operator_email FROM tour_orders o LEFT JOIN users b ON b.id=o.broker_id LEFT JOIN users op ON op.id=o.operator_id WHERE o.id=? LIMIT 1"); $stmt->bind_param('i',$orderId); $stmt->execute(); $order=$stmt->get_result()->fetch_assoc(); $stmt->close(); if(!$order){http_response_code(404);exit('Order not found');}
$canView=$role==='ADMIN'||(int)$order['broker_id']===$userId||($role==='OPERATOR'&&((int)$order['operator_id']===$userId||((int)$order['is_published']===1&&(string)$order['status']==='NEW'&&$order['operator_id']===null))); if(!$canView){http_response_code(403);exit('Forbidden');}
$order['status_meta']=osv_status_meta((string)$order['status']); $canDeleteMedia=$role==='ADMIN'||($role==='OPERATOR'&&(int)$order['operator_id']===$userId);
$captureSessions=[]; $stmt=$dbcnx->prepare('SELECT * FROM capture_sessions WHERE order_id=? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC'); $stmt->bind_param('i',$orderId); $stmt->execute(); $rs=$stmt->get_result(); while($r=$rs->fetch_assoc()){ $r['photos']=[];$r['videos']=[];$r['photo_count']=0;$r['video_count']=0;$captureSessions[]=$r; } $stmt->close(); $idxBySid=[]; foreach($captureSessions as $i=>$s){$idxBySid[(int)$s['id']]=$i;}
$photoPoints=[]; $stmt=$dbcnx->prepare('SELECT pp.* FROM photo_points pp JOIN capture_sessions cs ON cs.id=pp.session_id WHERE cs.order_id=? AND pp.deleted_at IS NULL AND cs.deleted_at IS NULL AND COALESCE(pp.upload_state,\'\')<>\'DELETED\''); $stmt->bind_param('i',$orderId); $stmt->execute(); $rs=$stmt->get_result(); while($p=$rs->fetch_assoc()){ $photoPoints[]=$p; $sid=(int)$p['session_id']; if(isset($idxBySid[$sid])){$captureSessions[$idxBySid[$sid]]['photos'][]=$p;$captureSessions[$idxBySid[$sid]]['photo_count']++;}} $stmt->close();
$videoScans=[]; $labelCol=''; $stmt=$dbcnx->prepare('SELECT vs.*, cs.app_session_uuid FROM video_scans vs JOIN capture_sessions cs ON cs.id=vs.session_id WHERE cs.order_id=? AND vs.deleted_at IS NULL AND cs.deleted_at IS NULL AND COALESCE(vs.upload_state,\'\')<>\'DELETED\' ORDER BY cs.created_at DESC, vs.created_at DESC, vs.id DESC'); $stmt->bind_param('i',$orderId); $stmt->execute(); $rs=$stmt->get_result(); while($v=$rs->fetch_assoc()){ $v['media_url']=!empty($v['storage_path'])?('/media.php?path='.rawurlencode((string)$v['storage_path'])):''; $v['size_human']=osv_bytes((float)($v['size_bytes'] ?? 0)); $v['label']=osv_video_label($v); $videoScans[]=$v; $sid=(int)$v['session_id']; if(isset($idxBySid[$sid])){$captureSessions[$idxBySid[$sid]]['videos'][]=$v;$captureSessions[$idxBySid[$sid]]['video_count']++;}} $stmt->close();
$captureBundlesBySession=[]; $tbl=$dbcnx->query("SHOW TABLES LIKE 'capture_bundles'"); if($tbl && $tbl->num_rows>0){ $stmt=$dbcnx->prepare('SELECT * FROM capture_bundles WHERE order_id=? ORDER BY created_at DESC, id DESC'); $stmt->bind_param('i',$orderId); $stmt->execute(); $rs=$stmt->get_result(); while($b=$rs->fetch_assoc()){ $sid=(int)$b['capture_session_id']; $b['size_human']=osv_bytes((float)($b['size_bytes'] ?? 0)); $b['download_url']='/api/capture_bundle_file.php?capture_bundle_id='.(int)$b['id']; $b['inspect_url']=$b['download_url'].'&sidecar=manifest'; $captureBundlesBySession[$sid][]=$b; } $stmt->close(); }
$runsBySession=[];$runsBySVM=[]; $stmt=$dbcnx->prepare('SELECT r.*, vs.filename source_filename FROM sfm_pipeline_runs r LEFT JOIN video_scans vs ON vs.id=r.video_scan_id WHERE r.order_id=? ORDER BY r.created_at DESC, r.id DESC'); $stmt->bind_param('i',$orderId); $stmt->execute(); $rs=$stmt->get_result(); while($r=$rs->fetch_assoc()){ $sid=(int)$r['capture_session_id']; $vid=(int)($r['video_scan_id'] ?? 0); $mode=(string)($r['pipeline_mode'] ?? ''); $r['log_url']='/api/sfm_pipeline_log.php?pipeline_run_id='.(int)$r['id']; $r['source_label']=''; $runsBySession[$sid][]=$r; if($vid>0&&$mode!=='')$runsBySVM[$sid][$vid][$mode][]=$r; } $stmt->close();
$jobsBySession=[];$denseJobsByBundle=[]; $stmt=$dbcnx->prepare('SELECT * FROM sfm_remote_jobs WHERE order_id=? ORDER BY created_at DESC, id DESC'); $stmt->bind_param('i',$orderId); $stmt->execute(); $rs=$stmt->get_result(); while($j=$rs->fetch_assoc()){ $sid=(int)$j['capture_session_id']; $j['status_url']='/api/sfm_remote_job_status.php?job_id='.(int)$j['id']; $j['result_json_url']='/api/sfm_remote_job_file.php?job_id='.(int)$j['id'].'&type=result'; $params=osv_json_array($j['parameters_json'] ?? ''); $j['ui_model_id']=$params['model_id'] ?? null; if((string)$j['job_type']==='MAKLERTOUR_SYNCED_DENSE'){ $base=(string)($j['output_path'] ?: osv_remote_dir((int)$j['remote_job_id'])); foreach(['preview'=>'dense/contact_dense_depth.jpg','debug'=>'dense/dense_depth_debug.json','summary'=>'dense/dense_depth_summary.csv'] as $k=>$rel){ $j[$k.'_url']='/api/sfm_remote_job_artifact.php?job_id='.(int)$j['id'].'&file='.$rel; $j['has_'.$k]=is_file(rtrim($base,'/').'/'.$rel); } if(!empty($params['capture_bundle_id']))$denseJobsByBundle[$sid][(int)$params['capture_bundle_id']][]=$j; } $jobsBySession[$sid][]=$j; } $stmt->close();
foreach($captureSessions as $i=>$s){ $sid=(int)$s['id']; $videos=[]; foreach(($s['videos'] ?? []) as $v){ $v['imu_available']=osv_video_has_imu_sidecar($orderId,$s,$v); $v['sfm_pipeline_cards']=[]; foreach(osv_pipeline_modes() as $mode=>$preset){ $hist=$runsBySVM[$sid][(int)$v['id']][$mode] ?? []; $run=$hist[0] ?? null; if($run){ $run['artifacts']=osv_pipeline_artifacts($run,$jobsBySession[$sid] ?? []); $run['ui_progress']=osv_sfm_run_ui_progress($run); $run['sparse_components']=osv_run_sparse_components($run,$jobsBySession[$sid] ?? [],$orderId,$sid); } $v['sfm_pipeline_cards'][$mode]=['mode'=>$mode,'preset'=>$preset,'run'=>$run,'history'=>$hist]; } $videos[]=$v; } $captureSessions[$i]['sfm_disk_videos']=$videos; $bundles=$captureBundlesBySession[$sid] ?? []; foreach($bundles as $bi=>$b){$bundles[$bi]['synced_dense_jobs']=$denseJobsByBundle[$sid][(int)$b['id']] ?? [];} $captureSessions[$i]['capture_bundles']=$bundles; $captureSessions[$i]['sfm_remote_jobs']=$jobsBySession[$sid] ?? []; }
$generatedMerges=[]; $tbl=$dbcnx->query("SHOW TABLES LIKE 'sfm_generated_model_merges'"); if($tbl && $tbl->num_rows>0){ $stmt=$dbcnx->prepare('SELECT * FROM sfm_generated_model_merges WHERE order_id=? ORDER BY created_at DESC, id DESC'); $stmt->bind_param('i',$orderId); $stmt->execute(); $rs=$stmt->get_result(); while($m=$rs->fetch_assoc()){$generatedMerges[]=$m;} $stmt->close(); }

function osv_merge_badge(array $m,array $meta=[]): string {
  $status=strtoupper((string)($m['status'] ?? ''));
  $msg=strtolower((string)($m['message'] ?? ''));
  if(in_array($status,['REJECTED','FAILED','ERROR','CANCELLED'],true) || str_contains($msg,'rejected')) return 'Rejected';
  if(str_contains($msg,'anchor only') || str_contains((string)($m['merge_type'] ?? ''),'anchor_only')) return 'Anchor only';
  $type=(string)($m['merge_type'] ?? '');
  $hasIncluded=array_key_exists('included',$meta) || array_key_exists('included_models',$meta);
  $included=$meta['included'] ?? ($meta['included_models'] ?? []);
  if($type==='aligned_shared_images_dense_ply' && $hasIncluded && is_array($included) && count($included)<2) return 'Anchor only';
  if($type==='manual_correspondences_sim3_dense_ply') return 'Ручная сборка';
  if($type==='aligned_shared_images_dense_ply') return 'Автоматическая сборка';
  if($type==='manual_incremental_sim3_dense_ply') return 'Ручное дополнение';
  if($type==='automatic_incremental_shared_images_dense_ply') return 'Автоматическое дополнение';
  return 'Диагностика';
}
function osv_build_models_assemblies_by_session(array $sessions,array $merges,int $orderId): array {
  $bySession=[];
  foreach($sessions as $s){
    $sid=(int)$s['id']; $items=[]; $dense=[];
    foreach(($s['sfm_remote_jobs'] ?? []) as $j){
      if(!in_array((string)($j['job_type'] ?? ''),['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'],true) || strtoupper((string)($j['status'] ?? ''))!=='DONE') continue;
      $ply=osv_remote_dir((int)$j['remote_job_id']).'/merged/merged_fused.ply'; $pi=osv_ply_info($ply); if(empty($pi['valid'])) continue;
      $params=osv_json_array($j['parameters_json'] ?? '{}'); $model=$j['ui_model_id'] ?? ($params['model_id'] ?? null); $sparse=(int)($j['parent_remote_job_id'] ?? ($params['sparse_remote_job_id'] ?? 0));
      $resultMeta=is_file((string)($j['result_json_path'] ?? ''))?(json_decode((string)file_get_contents((string)$j['result_json_path']),true)?:[]):[];
      $registeredImages=(int)($j['registered_images'] ?? ($params['registered_images'] ?? ($resultMeta['registered_images'] ?? ($resultMeta['summary']['registered_images'] ?? 0))));
      $modelLabel=$model===null?'-':(string)$model;
      $row=['kind'=>'model','badge'=>'Модель','model_id'=>$model,'model_label'=>$modelLabel,'db_job_id'=>(int)$j['id'],'remote_job_id'=>(int)$j['remote_job_id'],'sparse_remote_job_id'=>$sparse,'registered_images'=>$registeredImages,'dense_points'=>(int)$pi['vertices'],'status'=>(string)$j['status'],'open_url'=>'/sfm_3d_viewer.php?order_id='.$orderId.'&session_id='.$sid.'&pipeline_run_id='.(int)$j['pipeline_run_id'].'&artifact=dense&dense_remote_job_id='.(int)$j['remote_job_id'],'download_url'=>'/api/sfm_remote_job_status.php?job_id='.(int)$j['id'].'&file=ply','manual_candidates'=>[]];
      $dense[]=$row;
    }
    foreach($dense as $idx=>$row){ foreach($dense as $cand){ if((int)$cand['remote_job_id']===(int)$row['remote_job_id']) continue; $cand['manual_align_url']='/sfm_manual_align.php?order_id='.$orderId.'&anchor_kind=remote&anchor_id='.(int)$row['remote_job_id'].'&source_kind=remote&source_id='.(int)$cand['remote_job_id']; $row['manual_candidates'][]=$cand; } $items[]=$row; }
    foreach($merges as $m){
      if((int)($m['capture_session_id'] ?? 0)!==$sid) continue;
      $meta=is_file((string)($m['result_json_path']??''))?(json_decode((string)file_get_contents((string)$m['result_json_path']),true)?:[]):[];
      $badge=osv_merge_badge($m,$meta);
      $anchorOk=true; $resolved=null;
      try { $resolved=sfm_manual_resolve_merge_anchor($GLOBALS['dbcnx'],$orderId,(int)$m['id']); } catch(Throwable $e) { $anchorOk=false; }
      $leaf=$resolved['leaf_source_jobs'] ?? ($meta['leaf_source_jobs'] ?? osv_json_array($m['source_jobs_json'] ?? '[]'));
      $leafIds=[]; if(is_array($leaf)){foreach($leaf as $lj){if(is_array($lj)&&isset($lj['remote_job_id']))$leafIds[(int)$lj['remote_job_id']]=true;}}
      $leafModelIds=[]; if(is_array($leaf)){foreach($leaf as $lj){if(is_array($lj)&&array_key_exists('model_id',$lj)&&$lj['model_id']!==null&&$lj['model_id']!=='')$leafModelIds[]=(string)$lj['model_id'];}}
      $addCandidates=[];
      if($anchorOk){ foreach($dense as $cand){ if(isset($leafIds[(int)$cand['remote_job_id']])) continue; $cand['manual_incremental_url']='/sfm_manual_align.php?order_id='.$orderId.'&anchor_kind=merge&anchor_id='.(int)$m['id'].'&source_kind=remote&source_id='.(int)$cand['remote_job_id']; $addCandidates[]=$cand; } }
      $items[]=['kind'=>'merge','badge'=>$badge,'merge_id'=>(int)$m['id'],'merge_type'=>(string)$m['merge_type'],'status'=>(string)$m['status'],'source_jobs_json'=>(string)($m['source_jobs_json'] ?? ''),'points'=>(int)($m['total_points'] ?? 0),'parent_merge_id'=>$meta['parent_merge_id'] ?? null,'leaf_count'=>count($leafIds),'leaf_model_ids'=>implode(', ',array_values(array_unique($leafModelIds))),'scale'=>$meta['scale'] ?? ($meta['transform']['scale'] ?? null),'rms'=>$meta['rms'] ?? ($meta['rms_error'] ?? null),'pairs'=>$meta['pairs_count'] ?? (isset($meta['pairs'])&&is_array($meta['pairs'])?count($meta['pairs']):null),'method'=>$meta['method'] ?? ($meta['merge_method'] ?? ''),'open_url'=>'/sfm_3d_viewer.php?order_id='.$orderId.'&merge_id='.(int)$m['id'].'&artifact=dense','download_url'=>'/api/sfm_generated_merge_file.php?merge_id='.(int)$m['id'].'&file=ply','result_json_url'=>'/api/sfm_generated_merge_file.php?merge_id='.(int)$m['id'].'&file=result','incremental_candidates'=>$addCandidates];
    }
    $bySession[$sid]=$items;
  }
  return $bySession;
}
$modelsAssembliesBySession=osv_build_models_assemblies_by_session($captureSessions,$generatedMerges,$orderId);
foreach($captureSessions as $i=>$s){ $captureSessions[$i]['models_assemblies']=$modelsAssembliesBySession[(int)$s['id']] ?? []; }
$generatedModels=osv_build_generated($captureSessions,$generatedMerges); $anchorOptions=[]; $defaultSparseRemoteJobId=0; foreach($generatedModels as $gm){ $mid=(int)($gm['model_id'] ?? 0); if($mid>0){ $anchorOptions[$mid]='Model '.$mid; } if($defaultSparseRemoteJobId<=0 && (int)($gm['sparse_remote_job_id'] ?? 0)>0){ $defaultSparseRemoteJobId=(int)$gm['sparse_remote_job_id']; } } ksort($anchorOptions); $mediaTotals=['sessions'=>count($captureSessions),'photos'=>count($photoPoints),'videos'=>count($videoScans),'capture_bundles'=>array_sum(array_map(fn($s)=>count($s['capture_bundles'] ?? []),$captureSessions)),'generated_models'=>count($generatedModels)];
$smarty->assign('models_assemblies_by_session',$modelsAssembliesBySession); $smarty->assign('anchor_options',$anchorOptions); $smarty->assign('default_sparse_remote_job_id',$defaultSparseRemoteJobId); $smarty->assign('current_user',$user); $smarty->assign('order',$order); $smarty->assign('captureSessions',$captureSessions); $smarty->assign('videoScans',$videoScans); $smarty->assign('generated_models',$generatedModels); $smarty->assign('mediaTotals',$mediaTotals); $smarty->assign('canDeleteMedia',$canDeleteMedia); $smarty->display('maklertour_order_simple.html');
