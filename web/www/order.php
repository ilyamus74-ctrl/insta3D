<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/remote_station/sfm_pipeline.php';
require_once dirname(__DIR__) . '/remote_station/sfm_cleanup.php';
require_once dirname(__DIR__) . '/libs/sfm_settings_lib.php';
require_once dirname(__DIR__) . '/libs/sfm_debug_public_lib.php';
require_once dirname(__DIR__) . '/libs/source_storage_lib.php';
auth_require_login();
$user = auth_current_user(); $userId=(int)$user['id']; $role=$user['role'] ?? 'BROKER';
$orderId=(int)($_GET['id']??0); if($orderId<=0){http_response_code(400);exit('Bad order id');}

function status_meta(string $status): array { $m=['NEW'=>['bg-secondary','bi-circle','Новая'],'ASSIGNED'=>['bg-primary','bi-person-check','В работе'],'IN_PROGRESS'=>['bg-info','bi-camera','Съемка'],'CAPTURED'=>['bg-warning','bi-check2-square','Отснята'],'UPLOADING'=>['bg-warning','bi-cloud-upload','Загружается'],'UPLOADED'=>['bg-success','bi-cloud-check','Загружена'],'PROCESSING'=>['bg-info','bi-gear','Обработка'],'READY'=>['bg-success','bi-check-circle','Готова'],'COMPLETED'=>['bg-dark','bi-check2-all','Завершена'],'CLOSED'=>['bg-dark','bi-lock','Закрыта']]; $x=$m[$status]??['bg-secondary','bi-circle',$status]; return ['class'=>$x[0],'icon'=>$x[1],'label'=>$x[2]]; }
function load_order(mysqli $dbcnx,int $orderId): ?array { $stmt=$dbcnx->prepare("SELECT o.*,b.full_name broker_name,b.email broker_email,op.full_name operator_name,op.email operator_email FROM tour_orders o LEFT JOIN users b ON b.id=o.broker_id LEFT JOIN users op ON op.id=o.operator_id WHERE o.id=? LIMIT 1"); if(!$stmt){return null;} $stmt->bind_param('i',$orderId); $stmt->execute(); $o=$stmt->get_result()->fetch_assoc()?:null; $stmt->close(); return $o; }

const MIN_REGISTERED_IMAGES_PREVIEW = 10;
const MIN_REGISTERED_IMAGES_HQ = 20;


function sfm_read_uint64_le($fh): ?int { $b=fread($fh,8); if(strlen($b)!==8){return null;} $u=unpack('Vlo/Vhi',$b); return (int)($u['lo'] + $u['hi'] * 4294967296); }
function sfm_skip_bytes($fh,int $bytes): bool { return fseek($fh,$bytes,SEEK_CUR)===0; }
function sfm_count_colmap_images_bin(string $path): int { $fh=@fopen($path,'rb'); if(!$fh){return 0;} $n=sfm_read_uint64_le($fh); fclose($fh); return $n ?? 0; }
function sfm_count_colmap_points3d_bin(string $path): int { return sfm_count_colmap_images_bin($path); }
function sfm_sparse_model_stats(int $sparseJobId, int $modelId): array {
  $dir=sfm_remote_output_dir($sparseJobId).'/colmap/sparse/'.$modelId;
  $images=0; $points=0;
  $imagesTxt=$dir.'/images.txt'; $pointsTxt=$dir.'/points3D.txt';
  if(is_file($imagesTxt)){
    foreach(file($imagesTxt, FILE_IGNORE_NEW_LINES) ?: [] as $line){ $line=trim($line); if($line!=='' && $line[0]!=='#'){ $images++; } }
    $images=(int)floor($images/2);
  }
  if(is_file($pointsTxt)){
    foreach(file($pointsTxt, FILE_IGNORE_NEW_LINES) ?: [] as $line){ $line=trim($line); if($line!=='' && $line[0]!=='#'){ $points++; } }
  }
  if($images===0){ $images=sfm_count_colmap_images_bin($dir.'/images.bin'); }
  if($points===0){ $points=sfm_count_colmap_points3d_bin($dir.'/points3D.bin'); }
  return ['model_id'=>$modelId,'registered_images'=>$images,'points3D'=>$points,'preview_enabled'=>$images>=MIN_REGISTERED_IMAGES_PREVIEW,'hq_enabled'=>$images>=MIN_REGISTERED_IMAGES_HQ];
}

function sfm_ply_is_downloadable(int $parentRemoteId): bool {
  $path=sfm_remote_output_dir($parentRemoteId).'/merged/merged_fused.ply';
  if(!is_file($path)||!is_readable($path)||filesize($path)<=100){return false;}
  $fh=@fopen($path,'rb'); if(!$fh){return false;}
  $magic=fread($fh,3); if($magic!=="ply"){fclose($fh); return false;}
  rewind($fh); $n=0; $ok=false;
  while(($line=fgets($fh))!==false){ $line=trim($line); if(preg_match('/^element\s+vertex\s+(\d+)$/',$line,$m)){$n=(int)$m[1];} if($line==='end_header'){$ok=true; break;} }
  fclose($fh); return $ok && $n>0;
}
function sfm_mesh_ply_info(int $meshRemoteId): array {
  $path=sfm_remote_output_dir($meshRemoteId).'/mesh/mesh_final.ply';
  $info=['downloadable'=>false,'vertices'=>0,'faces'=>0];
  if(!is_file($path)||!is_readable($path)||filesize($path)<=100){return $info;}
  $fh=@fopen($path,'rb'); if(!$fh){return $info;}
  if(fread($fh,3)!=="ply"){fclose($fh); return $info;}
  rewind($fh); $ok=false;
  while(($line=fgets($fh))!==false){ $line=trim($line); if(preg_match('/^element\s+vertex\s+(\d+)$/',$line,$m)){$info['vertices']=(int)$m[1];} if(preg_match('/^element\s+face\s+(\d+)$/',$line,$m)){$info['faces']=(int)$m[1];} if($line==='end_header'){$ok=true; break;} }
  fclose($fh); $info['downloadable']=$ok && $info['vertices']>0 && $info['faces']>0; return $info;
}
function sfm_job_status_class(string $status): string { $s=strtoupper($status); if($s==='DONE'){return 'bg-success';} if(in_array($s,['ERROR','FAILED','ERROR_EMPTY'],true)){return 'bg-danger';} if(in_array($s,['RUNNING','PLANNING','RUNNING_CHUNKS','MERGING'],true)){return 'bg-primary progress-bar-striped progress-bar-animated';} return 'bg-secondary'; }
function sfm_job_model_id(array $j, array $byRemote=[]): ?int { $params=json_decode((string)($j['parameters_json'] ?? '{}'), true); if(is_array($params) && array_key_exists('model_id',$params)){ return (int)$params['model_id']; } $parent=(int)($j['parent_remote_job_id'] ?? 0); if($parent>0 && isset($byRemote[$parent])){ return sfm_job_model_id($byRemote[$parent], $byRemote); } return null; }
function sfm_job_title(array $j): string { $t=(string)$j['job_type']; $mid=$j['ui_model_id'] ?? null; $model=$mid!==null?' — Model '.(int)$mid:''; if($t==='COLMAP_RECONSTRUCTION_PREVIEW'){return 'Preview reconstruction'.$model;} if($t==='COLMAP_RECONSTRUCTION_HQ'){return 'High quality reconstruction'.$model;} if($t==='COLMAP_DENSE_CHUNK'){return 'Dense chunk '.(((int)($j['chunk_index']??0))+1).' of '.max(1,(int)($j['chunk_count']??1)).$model;} if($t==='COLMAP_MESH'){return 'Mesh generation'.$model;} if($t==='COLMAP_SPARSE'){return 'Sparse reconstruction';} if($t==='EXTRACT_FRAMES'){return 'Frame extraction';} if($t==='EXPORT_PLY'){return 'Export sparse PLY'.$model;} return $t; }
function sfm_enrich_session_jobs(array $jobs): array {
  $activeStatuses=['QUEUED','RUNNING','PLANNING','RUNNING_CHUNKS','MERGING']; $failedStatuses=['ERROR','FAILED','ERROR_EMPTY'];
  $byRemote=[]; foreach($jobs as $j){ $byRemote[(int)$j['remote_job_id']]=$j; } foreach($jobs as $k=>$j){ $jobs[$k]['ui_model_id']=sfm_job_model_id($j,$byRemote); $byRemote[(int)$j['remote_job_id']]=$jobs[$k]; }
  $children=[]; foreach($jobs as $j){ if(in_array((string)$j['job_type'],['COLMAP_DENSE_CHUNK','COLMAP_MESH'],true)){ $children[(int)$j['parent_remote_job_id']][]=$j; } }
  foreach($children as &$arr){ usort($arr, fn($a,$b)=>((int)($a['chunk_index']??0))<=>((int)($b['chunk_index']??0))); } unset($arr);
  $parents=[]; $standalone=[];
  foreach($jobs as $j){
    $st=strtoupper((string)$j['status']); $jt=(string)$j['job_type']; $rid=(int)$j['remote_job_id'];
    $j['ui_model_id']=sfm_job_model_id($j,$byRemote); $j['ui_title']=sfm_job_title($j); $j['ui_progress_class']=sfm_job_status_class($st); $j['ui_can_download_merged']=in_array($jt,['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'],true) && sfm_ply_is_downloadable($rid); $j['ui_sparse_stats']=[]; if(in_array($jt,['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'],true) && $j['ui_model_id']!==null && isset($byRemote[(int)($j['parent_remote_job_id']??0)])){ $sparseParent=$byRemote[(int)$j['parent_remote_job_id']]; $j['ui_sparse_stats']=sfm_sparse_model_stats((int)$sparseParent['remote_job_id'],(int)$j['ui_model_id']); } $j['children']=$children[$rid]??[];
    if(in_array($jt,['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'],true)){$parents[]=$j;}
    elseif(!in_array($jt,['COLMAP_DENSE_CHUNK','COLMAP_MESH'],true)){$standalone[]=$j;}
  }
  foreach($standalone as $si=>$sj){ if((string)$sj['job_type']==='COLMAP_SPARSE'){ $selection=[]; foreach([0,1] as $mid){ $selection[$mid]=['label'=>'Not selected','class'=>'bg-secondary']; } foreach($parents as $rp){ if((int)($rp['parent_remote_job_id']??0)===(int)$sj['remote_job_id'] && $rp['ui_model_id']!==null){ $mid=(int)$rp['ui_model_id']; $label=((string)$rp['job_type']==='COLMAP_RECONSTRUCTION_HQ')?'Selected for HQ':'Selected for Preview'; $class=in_array(strtoupper((string)$rp['status']),$activeStatuses,true)?'bg-primary':'bg-info text-dark'; $selection[$mid]=['label'=>$label,'class'=>$class]; } } $standalone[$si]['sparse_model_selection']=$selection; } }
  $parentActive=array_values(array_filter($parents,fn($j)=>in_array(strtoupper((string)$j['status']),$activeStatuses,true)));
  usort($parentActive,fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));
  $doneParents=array_values(array_filter($parents,fn($j)=>strtoupper((string)$j['status'])==='DONE')); usort($doneParents,fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));
  $errParents=array_values(array_filter($parents,fn($j)=>in_array(strtoupper((string)$j['status']),$failedStatuses,true))); usort($errParents,fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));
  $selected=$parentActive[0]??($doneParents[0]??($errParents[0]??null));
  if(!$selected){ foreach($standalone as $j){ if(in_array(strtoupper((string)$j['status']),$activeStatuses,true)){ $selected=$j; break; } } }
  $overall=0; $stage='Waiting for upload'; $stageProgress=0; $activeChild=null;
  if($selected){ $st=strtoupper((string)$selected['status']); $overall=(int)($selected['progress_percent']??0); $stage=$selected['ui_title']; $stageProgress=$overall;
    if(in_array((string)$selected['job_type'],['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'],true)){
      $done=0; $total=max(1,(int)($selected['chunk_count']??count($selected['children']))); foreach($selected['children'] as $c){ if(strtoupper((string)$c['status'])==='DONE'){$done++;} if(!$activeChild && in_array(strtoupper((string)$c['status']),$activeStatuses,true)){$activeChild=$c;} }
      if($st==='DONE'){$overall=100;$stage='Result ready';$stageProgress=100;} elseif(in_array($st,$failedStatuses,true)){$stage='Reconstruction failed';}
      elseif($st==='MERGING'){$overall=max(90,$overall);$stage='Merge';}
      elseif($activeChild && (string)$activeChild['job_type']==='COLMAP_MESH'){$stage='Generating '.((string)($activeChild['reconstruction_mode'] ?: 'preview')).' mesh'; $stageProgress=(int)($activeChild['progress_percent']??0); $overall=max(95,(int)$stageProgress);}
      elseif($activeChild){$stage='Dense chunk '.(((int)($activeChild['chunk_index']??0))+1).' of '.$total; $stageProgress=(int)($activeChild['progress_percent']??0); $overall=(int)(5+($done/$total)*85);}
      else {$stage='Planning / queued chunks'; $overall=max(0,min(5,$overall));}
      $selected['active_child']=$activeChild;
    }
  }
  $allCards=array_merge($parents,$standalone); $active=[];$completed=[];$failed=[];
  foreach($allCards as $j){ $st=strtoupper((string)$j['status']); if(in_array($st,$activeStatuses,true)){$active[]=$j;} elseif($st==='DONE'){$completed[]=$j;} elseif(in_array($st,$failedStatuses,true)){$failed[]=$j;} else {$completed[]=$j;} }
  return ['selected'=>$selected,'overall_progress'=>max(0,min(100,$overall)),'stage'=>$stage,'stage_progress'=>max(0,min(100,$stageProgress)),'active'=>$active,'completed'=>$completed,'failed'=>$failed,'has_active'=>count($active)>0];
}

function sfm_best_sparse_model_id(int $sparseJobId, array $modelIds): int {
  $best=-1; $bestImages=-1; $bestPoints=-1;
  foreach($modelIds as $mid){ $st=sfm_sparse_model_stats($sparseJobId,(int)$mid); if($st['registered_images']>$bestImages || ($st['registered_images']===$bestImages && $st['points3D']>$bestPoints)){ $best=(int)$mid; $bestImages=(int)$st['registered_images']; $bestPoints=(int)$st['points3D']; } }
  return $best>=0?$best:0;
}


function sfm_ply_header_info(string $path): array {
  $info=['valid'=>false,'vertices'=>0,'faces'=>0,'size_bytes'=>0];
  if(!is_file($path)||!is_readable($path)){return $info;}
  $size=(int)filesize($path); $info['size_bytes']=$size; if($size<=100){return $info;}
  $fh=@fopen($path,'rb'); if(!$fh){return $info;}
  if(fread($fh,3)!=="ply"){fclose($fh); return $info;}
  rewind($fh); $ok=false;
  while(($line=fgets($fh))!==false){
    $line=trim($line);
    if(preg_match('/^element\s+vertex\s+(\d+)$/',$line,$m)){$info['vertices']=(int)$m[1];}
    if(preg_match('/^element\s+face\s+(\d+)$/',$line,$m)){$info['faces']=(int)$m[1];}
    if($line==='end_header'){$ok=true; break;}
  }
  fclose($fh); $info['valid']=$ok && $info['vertices']>0; return $info;
}
function sfm_pipeline_error_message(array $run): string {
  $msg=trim((string)($run['message'] ?? ''));
  $err=json_decode((string)($run['error_json'] ?? ''), true);
  $raw=is_array($err)?trim((string)($err['message'] ?? $err['error'] ?? $err['error_summary'] ?? '')):'';
  $src=$raw!==''?$raw:$msg;
  if(stripos($src,'zero vertices')!==false){ return 'Dense reconstruction failed: chunk 4 produced zero vertices after retry.'; }
  if($src!=='' && strcasecmp($src,'Pipeline stage failed')!==0){ return $src; }
  return $msg!==''?$msg:'Pipeline failed. See pipeline log for details.';
}
function sfm_build_pipeline_artifacts(array $run, array $jobs): array {
  $rid=(int)$run['id']; $byRemote=[]; foreach($jobs as $j){ if((int)($j['pipeline_run_id'] ?? 0)===$rid){ $byRemote[(int)$j['remote_job_id']]=$j; } }
  $sparseJob=null; $reconJob=null; $meshJob=null;
  foreach($byRemote as $j){
    $jt=(string)($j['job_type'] ?? '');
    if($jt==='COLMAP_SPARSE'){$sparseJob=$j;}
    if(in_array($jt,['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'],true)){$reconJob=$j;}
    if($jt==='COLMAP_MESH'){$meshJob=$j;}
  }
  $modelId=(int)($run['sparse_model_id'] ?? 0);
  if($modelId===0 && $reconJob){ $mid=sfm_job_model_id($reconJob,$byRemote); if($mid!==null){$modelId=(int)$mid;} }
  $art=[
    'sparse'=>['available'=>false,'model_id'=>$modelId,'registered_images'=>(int)($run['registered_images'] ?? 0),'points'=>(int)($run['sparse_points'] ?? 0),'size_bytes'=>0,'size_human'=>'0 B','download_url'=>'','viewer_url'=>''],
    'dense'=>['available'=>false,'vertices'=>(int)($run['dense_points'] ?? 0),'size_bytes'=>0,'size_human'=>'0 B','download_url'=>'','viewer_url'=>''],
    'mesh'=>['available'=>false,'vertices'=>(int)($run['mesh_vertices'] ?? 0),'faces'=>(int)($run['mesh_faces'] ?? 0),'size_bytes'=>0,'size_human'=>'0 B','engine'=>'','download_url'=>'','viewer_url'=>''],
    'result_json'=>['available'=>false,'download_url'=>''],
  ];
  if($sparseJob){ $path=sfm_remote_output_dir((int)$sparseJob['remote_job_id']).'/colmap/sparse/'.$modelId.'/model.ply'; $pi=sfm_ply_header_info($path); $st=sfm_sparse_model_stats((int)$sparseJob['remote_job_id'],$modelId); $art['sparse']['registered_images']=$st['registered_images']; $art['sparse']['points']=$st['points3D']; $art['sparse']['size_bytes']=$pi['size_bytes']; $art['sparse']['size_human']=bytes_human($pi['size_bytes']); $art['sparse']['available']=$pi['valid']; }
  if($reconJob){ $path=sfm_remote_output_dir((int)$reconJob['remote_job_id']).'/merged/merged_fused.ply'; $pi=sfm_ply_header_info($path); $art['dense']['vertices']=$pi['vertices'] ?: $art['dense']['vertices']; $art['dense']['size_bytes']=$pi['size_bytes']; $art['dense']['size_human']=bytes_human($pi['size_bytes']); $art['dense']['available']=$pi['valid']; }
  if($meshJob){ $path=sfm_remote_output_dir((int)$meshJob['remote_job_id']).'/mesh/mesh_final.ply'; $pi=sfm_ply_header_info($path); $mr=sfm_remote_output_dir((int)$meshJob['remote_job_id']).'/mesh/mesh_result.json'; $md=is_file($mr)?(json_decode((string)file_get_contents($mr),true)?:[]):[]; $art['mesh']['vertices']=$pi['vertices'] ?: (int)($md['vertices'] ?? $md['mesh_vertices'] ?? $art['mesh']['vertices']); $art['mesh']['faces']=$pi['faces'] ?: (int)($md['faces'] ?? $md['mesh_faces'] ?? $art['mesh']['faces']); $art['mesh']['engine']=(string)($md['engine'] ?? ''); $art['mesh']['size_bytes']=$pi['size_bytes']; $art['mesh']['size_human']=bytes_human($pi['size_bytes']); $art['mesh']['available']=$pi['valid'] && $art['mesh']['faces']>0; }
  $result=(string)($run['output_result_json_path'] ?? ''); if($result!=='' && is_file($result) && filesize($result)>0){$art['result_json']['available']=true;}
  foreach(['sparse','dense','mesh'] as $a){ if($art[$a]['available']){ $vid=(int)($run['video_scan_id'] ?? 0); $videoParam=$vid>0?'&video_scan_id='.$vid:''; $art[$a]['download_url']='/api/sfm_pipeline_artifact.php?pipeline_run_id='.$rid.'&artifact='.$a.$videoParam; $art[$a]['viewer_url']='/sfm_3d_viewer.php?order_id='.(int)$run['order_id'].'&session_id='.(int)$run['capture_session_id'].$videoParam.'&pipeline_run_id='.$rid.'&artifact='.$a; } }
  if($art['result_json']['available']){$art['result_json']['download_url']='/api/sfm_pipeline_artifact.php?pipeline_run_id='.$rid.'&artifact=result';}
  return $art;
}

function bytes_human($bytes): string { $b=(float)$bytes; if($b<=0){return '0 B';} $u=['B','KB','MB','GB','TB']; $i=0; while($b>=1024 && $i<count($u)-1){$b/=1024;$i++;} return round($b,2).' '.$u[$i]; }

$order=load_order($dbcnx,$orderId); if(!$order){http_response_code(404);exit('Order not found');}
$canView = $role==='ADMIN' || ((int)$order['broker_id']===$userId) || ($role==='OPERATOR' && ((int)$order['operator_id']===$userId || ((int)$order['is_published']===1 && $order['status']==='NEW' && $order['operator_id']===null)));
if(!$canView){http_response_code(403);exit('Forbidden');}
$isOrderClosedForEditing = in_array((string)$order['status'], ['READY','COMPLETED','CLOSED'], true) || !empty($order['operator_closed_at']) || !empty($order['broker_closed_at']);
$canEdit = $role==='ADMIN' || (int)$order['broker_id']===$userId;
$canEditOrderInfo = $role==='ADMIN' || ((int)$order['broker_id']===$userId && !$isOrderClosedForEditing);
$canDeleteMedia = $role==='ADMIN' || (int)$order['broker_id']===$userId || ($role==='OPERATOR' && (int)$order['operator_id']===$userId);
$canDeleteCaptureSession = $role==='ADMIN' || ($role==='OPERATOR' && (int)$order['operator_id']===$userId);
$canOperatorClose = $role==='ADMIN' || ($role==='OPERATOR' && (int)$order['operator_id']===$userId && empty($order['operator_closed_at']));
$canBrokerClose = $role==='ADMIN' || ((int)$order['broker_id']===$userId && empty($order['broker_closed_at']));
$canReopen = $role==='ADMIN' || ((int)$order['broker_id']===$userId && (string)$order['status']!=='COMPLETED');
$canCreatePublicLink = $role==='ADMIN' || (int)$order['broker_id']===$userId || ($role==='OPERATOR' && (int)$order['operator_id']===$userId);
$error=null; $success=isset($_GET['updated'])?'Заявка обновлена':(isset($_GET['closed'])?'Заявка закрыта':(isset($_GET['reopened'])?'Заявка переоткрыта':(isset($_GET['job_queued'])?'Задача обработки меток поставлена в очередь':(isset($_GET['sfm_pipeline_restarted'])?'SfM pipeline restarted':(isset($_GET['sfm_job_queued'])?'SfM job queued':(isset($_GET['photo_deleted'])?'Снимок удалён':(isset($_GET['session_deleted'])?'Сессия удалена':(isset($_GET['video_uploaded'])?'External video uploaded':null))))))));

function table_exists(mysqli $dbcnx,string $table): bool { $t=$dbcnx->real_escape_string($table); $r=$dbcnx->query("SHOW TABLES LIKE '".$t."'"); $ok=$r && $r->num_rows>0; if($r){$r->close();} return $ok; }
function column_exists(mysqli $dbcnx,string $table,string $column): bool { $t=$dbcnx->real_escape_string($table); $c=$dbcnx->real_escape_string($column); $r=$dbcnx->query("SHOW COLUMNS FROM `".$t."` LIKE '".$c."'"); $ok=$r && $r->num_rows>0; if($r){$r->close();} return $ok; }


function table_columns_info(mysqli $dbcnx,string $table): array {
  $t=$dbcnx->real_escape_string($table); $rs=$dbcnx->query('SHOW COLUMNS FROM `'.$t.'`'); $out=[];
  if($rs){ while($r=$rs->fetch_assoc()){ $out[(string)$r['Field']]=$r; } $rs->close(); }
  return $out;
}
function enum_allowed_values_from_type(string $type): array {
  if(!preg_match('/^enum\((.*)\)$/i',$type,$m)){ return []; }
  $values=[]; $raw=$m[1]; $len=strlen($raw); $buf=''; $in=false;
  for($i=0;$i<$len;$i++){
    $ch=$raw[$i];
    if(!$in){ if($ch==="'"){ $in=true; $buf=''; } continue; }
    if($ch==='\\' && $i+1<$len){ $buf.=$raw[++$i]; continue; }
    if($ch==="'"){ $values[]=$buf; $in=false; continue; }
    $buf.=$ch;
  }
  return $values;
}
function column_enum_allowed_values(mysqli $dbcnx,string $table,string $column): array {
  $t=$dbcnx->real_escape_string($table); $c=$dbcnx->real_escape_string($column);
  $rs=$dbcnx->query("SHOW COLUMNS FROM `".$t."` LIKE '".$c."'");
  $row=$rs?$rs->fetch_assoc():null; if($rs){$rs->close();}
  return $row?enum_allowed_values_from_type((string)$row['Type']):[];
}
function enum_column_accepts(mysqli $dbcnx,string $table,string $column,string $value): bool {
  $allowed=column_enum_allowed_values($dbcnx,$table,$column);
  return !$allowed || in_array($value,$allowed,true);
}
function add_optional_insert_value(mysqli $dbcnx,string $table,array $colsInfo,array &$cols,array &$params,string &$types,string $column,$value): void {
  if(!isset($colsInfo[$column])){ return; }
  $type=strtolower((string)$colsInfo[$column]['Type']);
  if(str_starts_with($type,'enum(') && !enum_column_accepts($dbcnx,$table,$column,(string)$value)){ return; }
  $cols[]=$column; $params[]=$value; $types.=is_int($value)?'i':(is_float($value)?'d':'s');
}
function bind_dynamic_params(mysqli_stmt $st,string $types,array $params): void { $st->bind_param($types,...$params); }
function create_web_upload_capture_session(mysqli $dbcnx,int $orderId,int $userId): array {
  $colsInfo=table_columns_info($dbcnx,'capture_sessions');
  if(!$colsInfo){ throw new RuntimeException('Cannot inspect capture_sessions columns for web upload session creation.'); }
  $uuid='web_'.bin2hex(random_bytes(16)); $now=date('Y-m-d H:i:s');
  $values=['order_id'=>$orderId,'app_session_uuid'=>$uuid,'created_at'=>$now,'updated_at'=>$now,'started_at'=>$now,'completed_at'=>null,'source_type'=>'WEB_UPLOAD','source_origin'=>'web_upload','created_by'=>$userId,'created_by_user_id'=>$userId,'label'=>'Web Upload Session','session_label'=>'Web Upload Session','name'=>'Web Upload Session','title'=>'Web Upload Session','comment'=>'Web Upload Session','notes'=>'Web Upload Session','description'=>'Web Upload Session','camera_model'=>'web_upload','is_web_created'=>1,'web_created'=>1,'created_from_web'=>1,'is_web_upload'=>1];
  if(isset($colsInfo['status'])){
    foreach(['UPLOADED','READY','CAPTURED','LOCAL_ONLY'] as $candidate){ if(enum_column_accepts($dbcnx,'capture_sessions','status',$candidate)){ $values['status']=$candidate; break; } }
  }
  $cols=[]; $params=[]; $types='';
  foreach($values as $c=>$v){
    if(!isset($colsInfo[$c])){ continue; }
    if($v===null && strtoupper((string)$colsInfo[$c]['Null'])==='NO'){ continue; }
    add_optional_insert_value($dbcnx,'capture_sessions',$colsInfo,$cols,$params,$types,$c,$v);
  }
  foreach($colsInfo as $c=>$info){
    if($c==='id' || in_array($c,$cols,true)){ continue; }
    $required=strtoupper((string)$info['Null'])==='NO' && $info['Default']===null && stripos((string)$info['Extra'],'auto_increment')===false;
    if(!$required){ continue; }
    $type=strtolower((string)$info['Type']);
    $fallback=str_contains($type,'int')?0:(str_contains($type,'decimal')||str_contains($type,'float')||str_contains($type,'double')?0.0:'');
    $cols[]=$c; $params[]=$fallback; $types.=is_int($fallback)?'i':(is_float($fallback)?'d':'s');
  }
  foreach(['order_id','app_session_uuid'] as $needed){ if(!in_array($needed,$cols,true)){ throw new RuntimeException('Cannot create web upload capture session: missing required '.$needed.' column.'); } }
  $sql='INSERT INTO capture_sessions (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')';
  $st=$dbcnx->prepare($sql); if(!$st){ throw new RuntimeException('Failed to create web upload capture session: '.$dbcnx->error); }
  bind_dynamic_params($st,$types,$params); if(!$st->execute()){ $msg=$st->error; $st->close(); throw new RuntimeException('Failed to create web upload capture session: '.$msg); }
  $id=(int)$dbcnx->insert_id; $st->close();
  audit_log($userId,'CAPTURE_SESSION_WEB_UPLOAD_CREATED','TOUR_ORDER',$orderId,'Web upload capture session created',['capture_session_id'=>$id,'app_session_uuid'=>$uuid]);
  return ['id'=>$id,'app_session_uuid'=>$uuid];
}

function ensure_sfm_remote_jobs_table(mysqli $dbcnx): void {
  $dbcnx->query("CREATE TABLE IF NOT EXISTS sfm_remote_jobs (id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, capture_session_id BIGINT NOT NULL, job_type VARCHAR(64) NOT NULL, remote_job_id INT NOT NULL, parent_remote_job_id INT NULL, input_path TEXT NULL, output_path TEXT NULL, status VARCHAR(32) NOT NULL DEFAULT 'QUEUED', progress_percent INT DEFAULT 0, message TEXT NULL, result_json_path TEXT NULL, log_path TEXT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), KEY idx_sfm_remote_jobs_order_session (order_id, capture_session_id), KEY idx_sfm_remote_jobs_remote (remote_job_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function sfm_safe_uuid(string $uuid): string { return storage_safe_session_uuid($uuid); }

function sfm_session_videos_dir(int $orderId,string $appSessionUuid): string {
  $withOrder=capture_session_videos_dir($orderId,$appSessionUuid,false,true);
  if(is_dir($withOrder)){ return $withOrder; }
  $canonical=capture_session_videos_dir($orderId,$appSessionUuid,false,false);
  if(is_dir($canonical)){ return $canonical; }
  $legacyWithOrder=capture_session_videos_dir($orderId,$appSessionUuid,true,true);
  if(is_dir($legacyWithOrder)){ return $legacyWithOrder; }
  return capture_session_videos_dir($orderId,$appSessionUuid,true,false);
}
function sfm_web_session_videos_dir(int $orderId,string $appSessionUuid): string { return capture_session_videos_dir($orderId,$appSessionUuid,false,true); }

function video_scan_safe_uuid(string $uuid,int $scanId): string { $safe=preg_replace('/[^a-zA-Z0-9._-]+/','_', $uuid); return $safe!==''?$safe:('scan_'.$scanId); }
function video_scan_metadata_info(int $scanId,string $appScanUuid,string $videoDir): array { $safe=video_scan_safe_uuid($appScanUuid,$scanId); $defs=['camera_info'=>['_camera_info.json','View camera_info'],'manifest'=>['_manifest.json','View manifest'],'imu'=>['_imu.jsonl','Download imu']]; $out=[]; foreach($defs as $type=>$def){ $path=$videoDir.'/'.$safe.$def[0]; $exists=is_file($path); $out[$type]=['exists'=>$exists,'label'=>$def[1],'url'=>$exists?('/api/video_scan_metadata.php?scan_id='.$scanId.'&type='.$type):'']; } return $out; }
function ensure_sfm_remote_jobs_chunk_columns(mysqli $dbcnx): void { foreach(['reconstruction_mode'=>'VARCHAR(20) NULL','chunk_index'=>'INT NULL','chunk_count'=>'INT NULL','retry_count'=>'INT NOT NULL DEFAULT 0','parameters_json'=>'LONGTEXT NULL'] as $c=>$def){ if(!column_exists($dbcnx,'sfm_remote_jobs',$c)){ @$dbcnx->query('ALTER TABLE sfm_remote_jobs ADD COLUMN '.$c.' '.$def); } } }
function ensure_sfm_settings_pipeline_columns(mysqli $dbcnx): void { if(!table_exists($dbcnx,'sfm_user_settings')){ @$dbcnx->query("CREATE TABLE sfm_user_settings (user_id BIGINT UNSIGNED NOT NULL, settings_json LONGTEXT NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), PRIMARY KEY(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } if(!table_exists($dbcnx,'sfm_session_settings')){ @$dbcnx->query("CREATE TABLE sfm_session_settings (capture_session_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, settings_json LONGTEXT NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), PRIMARY KEY(capture_session_id,user_id), KEY idx_sfm_session_settings_user(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } foreach(['parameters_json'=>'LONGTEXT NULL','started_by_user_id'=>'BIGINT UNSIGNED NULL','extracted_frames'=>'INT NULL','registration_ratio'=>'DECIMAL(6,2) NULL','sparse_models_count'=>'INT NULL','selected_model_id'=>'INT NULL','selected_model_points'=>'INT NULL'] as $c=>$def){ if(table_exists($dbcnx,'sfm_pipeline_runs') && !column_exists($dbcnx,'sfm_pipeline_runs',$c)){ @$dbcnx->query('ALTER TABLE sfm_pipeline_runs ADD COLUMN '.$c.' '.$def); } } }
function sfm_remote_output_dir(int $remoteJobId): string { return '/home/makler/web/remote_station/output/job_'.$remoteJobId; }
function sfm_job_id(mysqli $dbcnx): int { do { $id=random_int(10000,999999999); $st=$dbcnx->prepare('SELECT id FROM sfm_remote_jobs WHERE remote_job_id=? LIMIT 1'); if(!$st){return $id;} $st->bind_param('i',$id); $st->execute(); $exists=$st->get_result()->fetch_assoc(); $st->close(); } while($exists); return $id; }
function sfm_session_for_order(mysqli $dbcnx,int $orderId,int $sessionId): ?array { $st=$dbcnx->prepare('SELECT id, app_session_uuid FROM capture_sessions WHERE id=? AND order_id=? AND deleted_at IS NULL LIMIT 1'); if(!$st){return null;} $st->bind_param('ii',$sessionId,$orderId); $st->execute(); $row=$st->get_result()->fetch_assoc()?:null; $st->close(); return $row; }
function sfm_resolve_video_path(mysqli $dbcnx,int $orderId,int $sessionId,string $videoInput): ?string { $sess=sfm_session_for_order($dbcnx,$orderId,$sessionId); if(!$sess){return null;} $safe=sfm_safe_uuid((string)$sess['app_session_uuid']); $dir=sfm_session_videos_dir($orderId,(string)$sess['app_session_uuid']); $realDir=realpath($dir); if($realDir===false || !is_dir($realDir)){return null;} $candidate=(str_contains($videoInput,'/')?$videoInput:($realDir.'/'.$videoInput)); $real=realpath($candidate); if($real===false || !is_file($real) || !in_array(strtolower(pathinfo($real,PATHINFO_EXTENSION)),['mp4','mov','m4v'],true)){return null;} return (strpos($real,$realDir.'/')===0)?$real:null; }
function sfm_load_source_video(mysqli $dbcnx,int $orderId,int $sessionId,int $videoScanId): array {
  if($videoScanId<=0){ throw new RuntimeException('Source video is required'); }
  if(!sfm_session_for_order($dbcnx,$orderId,$sessionId)){ throw new RuntimeException('Capture session not found'); }
  $st=$dbcnx->prepare("SELECT id, session_id, filename, storage_path, app_scan_uuid, created_at, duration_sec, size_bytes FROM video_scans WHERE id=? AND session_id=? AND deleted_at IS NULL LIMIT 1");
  if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
  $st->bind_param('ii',$videoScanId,$sessionId); $st->execute(); $v=$st->get_result()->fetch_assoc(); $st->close();
  if(!$v){ throw new RuntimeException('Source video not found for this capture session'); }
  $pathInput=(string)($v['storage_path'] ?? ''); if($pathInput===''){ $pathInput=(string)($v['filename'] ?? ''); }
  $videoPath=sfm_resolve_video_path($dbcnx,$orderId,$sessionId,$pathInput) ?? sfm_resolve_video_path($dbcnx,$orderId,$sessionId,(string)($v['filename'] ?? ''));
  if($videoPath===null){ throw new RuntimeException('Video path is invalid or outside session videos directory'); }
  $v['resolved_path']=$videoPath; $v['filename']=(string)($v['filename'] ?: basename($videoPath));
  return $v;
}

function sfm_resolve_video_sidecar_files(string $videoPath): array {
  $videoReal=realpath($videoPath);
  if($videoReal===false || !is_file($videoReal)){ return ['imu_jsonl_path'=>null,'camera_info_path'=>null,'manifest_path'=>null]; }
  $dir=dirname($videoReal); $dirReal=realpath($dir); if($dirReal===false){ return ['imu_jsonl_path'=>null,'camera_info_path'=>null,'manifest_path'=>null]; }
  $stem=pathinfo($videoReal, PATHINFO_FILENAME); $base=preg_replace('/_video$/','',$stem);
  $defs=['imu_jsonl_path'=>[$stem.'_imu.jsonl',$base.'_imu.jsonl'],'camera_info_path'=>[$stem.'_camera_info.json',$base.'_camera_info.json'],'manifest_path'=>[$stem.'_manifest.json',$base.'_manifest.json']];
  $out=['imu_jsonl_path'=>null,'camera_info_path'=>null,'manifest_path'=>null];
  foreach($defs as $key=>$names){
    foreach(array_unique($names) as $name){
      $candidate=$dirReal.DIRECTORY_SEPARATOR.$name; $real=realpath($candidate);
      if($real===false || !is_file($real) || dirname($real)!==$dirReal){ continue; }
      if($key==='imu_jsonl_path' && strtolower(pathinfo($real,PATHINFO_EXTENSION))!=='jsonl'){ continue; }
      $out[$key]=$real; break;
    }
  }
  return $out;
}

function sfm_source_video_snapshot(array $v): array { $sidecars=sfm_resolve_video_sidecar_files((string)($v['resolved_path'] ?? $v['storage_path'] ?? '')); return ['video_scan_id'=>(int)$v['id'],'filename'=>(string)($v['filename'] ?? ''),'storage_path'=>(string)($v['storage_path'] ?? ''),'video_path'=>(string)($v['resolved_path'] ?? ''),'app_scan_uuid'=>(string)($v['app_scan_uuid'] ?? ''),'created_at'=>(string)($v['created_at'] ?? ''),'duration_sec'=>(float)($v['duration_sec'] ?? 0),'size_bytes'=>(int)($v['size_bytes'] ?? 0)] + $sidecars; }


function web_upload_video_profiles(): array { return ['phone_web_upload'=>'pinhole_or_opencv','gopro_fisheye'=>'fisheye','insta360_rectilinear'=>'wide_rectilinear','other'=>'unknown']; }
function sanitize_upload_filename(string $name): string { $base=basename($name); $base=preg_replace('/[^a-zA-Z0-9._-]+/','_', $base); $base=trim((string)$base,'._-'); return $base!==''?$base:'external_video'; }
function ffprobe_video_metadata(string $path): array {
  $meta=['duration_sec'=>0.0,'width'=>0,'height'=>0,'fps'=>0.0,'warning'=>''];
  $ffprobe=trim((string)@shell_exec('command -v ffprobe 2>/dev/null'));
  if($ffprobe===''){ $meta['warning']='ffprobe is not installed; duration was set to 0.'; return $meta; }
  $cmd=escapeshellarg($ffprobe).' -v error -select_streams v:0 -show_entries stream=width,height,r_frame_rate -show_entries format=duration -of json '.escapeshellarg($path).' 2>&1';
  @exec($cmd,$out,$code); $json=implode("\n",$out); $data=json_decode($json,true);
  if($code!==0 || !is_array($data)){ $meta['warning']='ffprobe failed; duration was set to 0.'; return $meta; }
  $meta['duration_sec']=max(0.0,(float)($data['format']['duration'] ?? 0));
  $stream=$data['streams'][0] ?? [];
  $meta['width']=(int)($stream['width'] ?? 0); $meta['height']=(int)($stream['height'] ?? 0);
  $rate=(string)($stream['r_frame_rate'] ?? '');
  if(preg_match('/^(\d+)\/(\d+)$/',$rate,$m) && (int)$m[2]>0){ $meta['fps']=round(((int)$m[1])/((int)$m[2]),3); }
  return $meta;
}
function video_scan_insert_fallback_value(string $column,array $values,array $info): array {
  $now=(string)($values['created_at'] ?? date('Y-m-d H:i:s'));
  $filename=(string)($values['filename'] ?? basename((string)($values['storage_path'] ?? '')));
  $storagePath=(string)($values['storage_path'] ?? $filename);
  $fallbacks=[
    'app_scan_uuid'=>(string)($values['app_scan_uuid'] ?? ('web_'.bin2hex(random_bytes(16)))),
    'filename'=>$filename,
    'storage_path'=>$storagePath,
    'source_video_path'=>$storagePath,
    'storage_base_path'=>dirname($storagePath),
    'local_camera_url'=>(string)($values['local_camera_url'] ?? ''),
    'created_at'=>$now,
    'updated_at'=>(string)($values['updated_at'] ?? $now),
    'duration_sec'=>(float)($values['duration_sec'] ?? 0),
    'size_bytes'=>(int)($values['size_bytes'] ?? 0),
    'upload_state'=>(string)($values['upload_state'] ?? 'UPLOADED'),
    'processing_state'=>(string)($values['processing_state'] ?? 'NOT_STARTED'),
    'status'=>(string)($values['status'] ?? 'UPLOADED'),
    'source_type'=>(string)($values['source_type'] ?? 'WEB_UPLOAD'),
    'source_origin'=>(string)($values['source_origin'] ?? 'web_upload'),
  ];
  if(array_key_exists($column,$fallbacks)){ return [true,$fallbacks[$column]]; }
  $type=strtolower((string)$info['Type']);
  if(str_contains($type,'int')){ return [true,0]; }
  if(str_contains($type,'decimal') || str_contains($type,'float') || str_contains($type,'double')){ return [true,0.0]; }
  if(str_starts_with($type,'enum(')){ $allowed=enum_allowed_values_from_type((string)$info['Type']); if($allowed){ return [true,$allowed[0]]; } }
  return [false,null];
}
function insert_web_uploaded_video_scan(mysqli $dbcnx,array $values): int {
  $colsInfo=table_columns_info($dbcnx,'video_scans');
  if(!$colsInfo){ throw new RuntimeException('Cannot inspect video_scans columns for web upload.'); }
  $cols=[]; $params=[]; $types='';
  foreach($values as $c=>$v){
    if(!isset($colsInfo[$c])){ continue; }
    if($v===null && strtoupper((string)$colsInfo[$c]['Null'])==='NO'){ continue; }
    add_optional_insert_value($dbcnx,'video_scans',$colsInfo,$cols,$params,$types,(string)$c,$v);
  }
  foreach($colsInfo as $c=>$info){
    if($c==='id' || in_array($c,$cols,true)){ continue; }
    $required=strtoupper((string)$info['Null'])==='NO' && $info['Default']===null && stripos((string)$info['Extra'],'auto_increment')===false;
    if(!$required){ continue; }
    [$ok,$fallback]=video_scan_insert_fallback_value((string)$c,$values,$info);
    if(!$ok){ throw new RuntimeException('Cannot insert uploaded video: missing required '.$c.' column value.'); }
    add_optional_insert_value($dbcnx,'video_scans',$colsInfo,$cols,$params,$types,(string)$c,$fallback);
  }
  if(!in_array('session_id',$cols,true)){ throw new RuntimeException('Cannot insert uploaded video: missing required session_id column.'); }
  $hasPath=false; foreach(['storage_path','filename','source_video_path'] as $pathColumn){ if(in_array($pathColumn,$cols,true)){ $hasPath=true; break; } }
  if(!$hasPath){ throw new RuntimeException('Cannot insert uploaded video: video_scans has no supported video filename/path column.'); }
  if(isset($colsInfo['app_scan_uuid']) && strtoupper((string)$colsInfo['app_scan_uuid']['Null'])==='NO' && !in_array('app_scan_uuid',$cols,true)){ throw new RuntimeException('Cannot insert uploaded video: missing required app_scan_uuid column value.'); }
  $placeholders=implode(',',array_fill(0,count($cols),'?'));
  $sql='INSERT INTO video_scans (`'.implode('`,`',$cols).'`) VALUES ('.$placeholders.')';
  $st=$dbcnx->prepare($sql); if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
  bind_dynamic_params($st,$types,$params); if(!$st->execute()){ $msg=$st->error; $st->close(); throw new RuntimeException('DB execute error while inserting video scan: '.$msg); } $id=(int)$dbcnx->insert_id; $st->close(); return $id;
}

function upload_ini_diagnostics(): string {
  $keys=['upload_max_filesize','post_max_size','max_file_uploads','max_execution_time','max_input_time','memory_limit','upload_tmp_dir'];
  $parts=[];
  foreach($keys as $key){ $value=(string)ini_get($key); if($key==='upload_tmp_dir' && $value===''){ $value='system default'; } $parts[]=$key.'='.$value; }
  return implode(', ',$parts);
}
function upload_error_message(int $error): string {
  $serverHint=' Large uploads require PHP upload_max_filesize/post_max_size/max_input_time/max_execution_time, the web server request body limit, and enough free disk in the PHP temp dir and final storage.';
  switch($error){
    case UPLOAD_ERR_INI_SIZE:
      return 'Uploaded video exceeds the PHP upload_max_filesize limit. Current effective PHP values: '.upload_ini_diagnostics().'.'.$serverHint;
    case UPLOAD_ERR_FORM_SIZE:
      return 'Uploaded video exceeds the HTML form MAX_FILE_SIZE limit. This form should not set that limit; please refresh the page and try again.'.$serverHint;
    case UPLOAD_ERR_PARTIAL:
      return 'Uploaded video was only partially uploaded. Please retry the upload and check network/proxy timeouts.'.$serverHint;
    case UPLOAD_ERR_NO_FILE:
      return 'No video file was uploaded. Choose a .mp4, .mov, or .m4v file and try again.';
    case UPLOAD_ERR_NO_TMP_DIR:
      return 'Server upload failed because PHP has no temporary upload directory configured.'.$serverHint;
    case UPLOAD_ERR_CANT_WRITE:
      return 'Server upload failed because PHP could not write the uploaded file to disk. Check free space and permissions for the PHP temp directory.'.$serverHint;
    case UPLOAD_ERR_EXTENSION:
      return 'Server upload failed because a PHP extension stopped the upload.'.$serverHint;
    default:
      return 'Upload failed with PHP error '.$error.'.'.$serverHint;
  }
}
function sfm_web_upload_max_bytes(): int { return defined('SFM_WEB_UPLOAD_MAX_BYTES') ? max(0,(int)constant('SFM_WEB_UPLOAD_MAX_BYTES')) : 20*1024*1024*1024; }

function handle_external_video_upload(mysqli $dbcnx,int $orderId,int $userId): void {
  if(empty($_FILES['video_file']) || !is_array($_FILES['video_file'])){ throw new RuntimeException(upload_error_message(UPLOAD_ERR_NO_FILE)); }
  $file=$_FILES['video_file']; $err=(int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
  if($err!==UPLOAD_ERR_OK){ throw new RuntimeException(upload_error_message($err)); }
  $tmp=(string)($file['tmp_name'] ?? ''); if($tmp==='' || !is_uploaded_file($tmp)){ throw new RuntimeException('Invalid upload source.'); }
  $size=(int)($file['size'] ?? 0); $max=sfm_web_upload_max_bytes(); if($size<=0){ throw new RuntimeException('Uploaded video is empty.'); } if($max>0 && $size>$max){ throw new RuntimeException('Video file is larger than configured SFM_WEB_UPLOAD_MAX_BYTES.'); }
  $orig=sanitize_upload_filename((string)($file['name'] ?? 'video.mp4')); $ext=strtolower(pathinfo($orig,PATHINFO_EXTENSION)); if(!in_array($ext,['mp4','mov','m4v'],true)){ throw new RuntimeException('Only .mp4, .mov, and .m4v video files are accepted.'); }
  $mime=(string)($file['type'] ?? ''); if($mime!=='' && stripos($mime,'video/')!==0){ throw new RuntimeException('Only video MIME types are accepted.'); }
  $profiles=web_upload_video_profiles(); $profile=(string)($_POST['camera_profile'] ?? 'other'); if(!isset($profiles[$profile])){ $profile='other'; }
  $target=(string)($_POST['capture_session_id'] ?? '');
  $createNew=($target==='new' || $target==='create_new' || $target==='');
  $sessionId=$createNew?0:(int)$target; $sess=$sessionId>0?sfm_session_for_order($dbcnx,$orderId,$sessionId):null;
  if(!$createNew && $sessionId<=0){ throw new RuntimeException('Invalid capture session selection.'); }
  if(!$createNew && !$sess){ throw new RuntimeException('Selected capture session does not belong to this order.'); }
  $createdSession=false; $dest=''; $sidecars=[]; $rel=''; $uuid='web_'.bin2hex(random_bytes(16));
  $dbcnx->begin_transaction();
  try{
    if(!$sess){ $sess=create_web_upload_capture_session($dbcnx,$orderId,$userId); $sessionId=(int)$sess['id']; $createdSession=true; }
    $safeSession=sfm_safe_uuid((string)$sess['app_session_uuid']).'_'.$orderId; $videoDir=sfm_web_session_videos_dir($orderId,(string)$sess['app_session_uuid']); if(!is_dir($videoDir) && !mkdir($videoDir,0775,true)){ throw new RuntimeException('Failed to create session video directory.'); } if(!is_writable($videoDir)){ throw new RuntimeException('Session video directory is not writable by the web server.'); }
    $filename=$uuid.'_video.'.$ext; $dest=$videoDir.'/'.$filename;
    if(!move_uploaded_file($tmp,$dest)){ throw new RuntimeException('Failed to move uploaded video into storage.'); }
    @chmod($dest,0664); $meta=ffprobe_video_metadata($dest); $now=date('Y-m-d H:i:s'); $rel='orders/'.$orderId.'/sessions/'.$safeSession.'/videos/'.$filename;
    $manifest=['source'=>'web_upload','camera_profile'=>$profile,'imu_available'=>false,'original_filename'=>$orig,'uploaded_at'=>$now,'size_bytes'=>$size,'duration_sec'=>$meta['duration_sec']];
    if($meta['width']>0){$manifest['width']=$meta['width'];} if($meta['height']>0){$manifest['height']=$meta['height'];} if($meta['fps']>0){$manifest['fps']=$meta['fps'];} if($meta['warning']!==''){$manifest['warning']=$meta['warning'];}
    $camera=['source'=>'web_upload','camera_profile'=>$profile,'camera_model_hint'=>$profiles[$profile],'imu_available'=>false,'notes'=>'external web upload; camera intrinsics unknown'];
    $sidecars=[$videoDir.'/'.$uuid.'_manifest.json',$videoDir.'/'.$uuid.'_camera_info.json'];
    if(file_put_contents($sidecars[0],json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))===false){ throw new RuntimeException('Failed to write upload manifest sidecar.'); }
    if(file_put_contents($sidecars[1],json_encode($camera,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))===false){ throw new RuntimeException('Failed to write camera info sidecar.'); }
    $label=trim((string)($_POST['video_label'] ?? ''));
    $values=['order_id'=>$orderId,'session_id'=>$sessionId,'filename'=>$filename,'storage_path'=>$rel,'app_scan_uuid'=>$uuid,'duration_sec'=>(float)$meta['duration_sec'],'size_bytes'=>$size,'created_at'=>$now,'updated_at'=>$now,'upload_state'=>'UPLOADED','source_type'=>'WEB_UPLOAD','source_origin'=>'web_upload','camera_profile'=>$profile,'label'=>$label,'comment'=>$label];
    $scanId=insert_web_uploaded_video_scan($dbcnx,$values);
    audit_log($userId,'VIDEO_SCAN_WEB_UPLOAD','TOUR_ORDER',$orderId,'External video uploaded from web',['capture_session_id'=>$sessionId,'video_scan_id'=>$scanId,'camera_profile'=>$profile,'storage_path'=>$rel,'ffprobe_warning'=>$meta['warning']]);
    $dbcnx->commit();
  }catch(Throwable $e){
    $dbcnx->rollback();
    foreach(array_merge([$dest],$sidecars) as $path){ if($path!=='' && is_file($path)){ @unlink($path); } }
    if($createdSession && $sessionId>0){ if($st=$dbcnx->prepare('DELETE FROM capture_sessions WHERE id=? AND order_id=? AND NOT EXISTS (SELECT 1 FROM video_scans WHERE session_id=?)')){ $st->bind_param('iii',$sessionId,$orderId,$sessionId); @$st->execute(); $st->close(); } }
    error_log('External video upload failed operation=web_upload order_id='.$orderId.' capture_session_id='.$sessionId.' original_filename='.$orig.' camera_profile='.$profile.' target_path='.$rel.' db_error='.$e->getMessage());
    throw new RuntimeException('Upload failed: '.$e->getMessage());
  }
}

function safe_rrmdir(string $path,string $allowedBase): bool {
  if($path==='' || $allowedBase===''){ error_log('safe_rrmdir refused empty path/base'); return false; }
  $realBase=realpath($allowedBase);
  if($realBase===false || !is_dir($realBase)){ error_log('safe_rrmdir allowed base missing: '.$allowedBase); return false; }
  $realBase=rtrim($realBase,DIRECTORY_SEPARATOR);
  if(!file_exists($path) && !is_link($path)){ return true; }
  if(is_link($path)){ error_log('safe_rrmdir refused symlink root: '.$path); return false; }
  $realPath=realpath($path);
  if($realPath===false){ error_log('safe_rrmdir path realpath failed: '.$path); return false; }
  $realPath=rtrim($realPath,DIRECTORY_SEPARATOR);
  if($realPath===$realBase || strpos($realPath,$realBase.DIRECTORY_SEPARATOR)!==0){ error_log('safe_rrmdir refused outside base path='.$realPath.' base='.$realBase); return false; }
  if(strlen($realPath) <= strlen($realBase)+1 || basename($realPath)===''){ error_log('safe_rrmdir refused suspicious short path: '.$realPath); return false; }
  $ok=true;
  $items=scandir($realPath);
  if($items===false){ error_log('safe_rrmdir scandir failed: '.$realPath); return false; }
  foreach($items as $item){
    if($item==='.' || $item==='..'){ continue; }
    $child=$realPath.DIRECTORY_SEPARATOR.$item;
    if(is_link($child) || is_file($child)){
      if(!@unlink($child)){ error_log('safe_rrmdir unlink failed: '.$child); $ok=false; }
    } elseif(is_dir($child)){
      if(!safe_rrmdir($child,$realBase)){ $ok=false; }
    } elseif(file_exists($child)){
      if(!@unlink($child)){ error_log('safe_rrmdir unlink special failed: '.$child); $ok=false; }
    }
  }
  if(!@rmdir($realPath)){ error_log('safe_rrmdir rmdir failed: '.$realPath); $ok=false; }
  return $ok;
}

function sfm_prepare_pipeline_dir(int $pipelineRunId): string {
  $dir=sfm_pipeline_output_dir($pipelineRunId);
  if(!is_dir($dir) && !@mkdir($dir,02775,true)){ error_log('failed to create pipeline dir: '.$dir); throw new RuntimeException('Failed to create pipeline output directory'); }
  @chmod($dir,02775);
  $log=$dir.'/pipeline.log';
  if(@file_put_contents($log,'',FILE_APPEND)===false){ error_log('failed to write pipeline log: '.$log); throw new RuntimeException('Failed to initialize pipeline log'); }
  @chmod($log,0664);
  return $dir;
}


function sfm_session_video_scan_ids(mysqli $dbcnx,int $orderId,int $captureSessionId): array {
  $ids=[]; $roleExpr="''"; foreach(['role','video_role','scan_role'] as $c){ if(column_exists($dbcnx,'video_scans',$c)){ $roleExpr='vs.`'.$c.'`'; break; } }
  $orderFilter=column_exists($dbcnx,'video_scans','order_id')?'vs.order_id=? AND vs.session_id=?':'cs.order_id=? AND vs.session_id=?';
  $sql='SELECT vs.id, '.$roleExpr.' AS role FROM video_scans vs JOIN capture_sessions cs ON cs.id=vs.session_id WHERE '.$orderFilter.' AND vs.deleted_at IS NULL AND cs.deleted_at IS NULL ORDER BY vs.id ASC';
  $st=$dbcnx->prepare($sql); if(!$st){ return $ids; }
  $st->bind_param('ii',$orderId,$captureSessionId); $st->execute(); $rs=$st->get_result(); $hasRoles=false; $hasBackbone=false;
  while($r=$rs->fetch_assoc()){ $role=strtoupper((string)($r['role'] ?? '')); if($role!==''){$hasRoles=true;} if(in_array($role,['BACKBONE','MAIN'],true)){$hasBackbone=true;} $ids[]=(int)$r['id']; }
  $st->close(); if($hasRoles && !$hasBackbone){ throw new RuntimeException('Multi-video reconstruction requires at least one BACKBONE/main video.'); }
  return array_values(array_filter($ids,fn($v)=>$v>0));
}
function start_sfm_multi_video_pipeline_run(mysqli $dbcnx,int $orderId,int $captureSessionId,string $mode,int $startedByUserId=0): int {
  if($mode!=='preview'){ throw new RuntimeException('Multi-video reconstruction currently supports Preview only; Standard and FullHD controls are reserved for later.'); }
  $ids=sfm_session_video_scan_ids($dbcnx,$orderId,$captureSessionId); if(count($ids)<2){ throw new RuntimeException('Multi-video reconstruction requires at least two videos.'); }
  $pid=start_sfm_pipeline_run($dbcnx,$orderId,$captureSessionId,$ids[0],$mode,$startedByUserId);
  $params=['multi_video'=>true,'selected_video_scan_ids'=>$ids,'frame_namespace'=>'video_<video_scan_id>_frame_<n>','matching_strategy'=>['within_video'=>'sequential','cross_video'=>'sampled_keyframes','loop_or_vocab_tree'=>'future_optional_flag']];
  $existing=[]; $st=$dbcnx->prepare('SELECT parameters_json FROM sfm_pipeline_runs WHERE id=?'); if($st){$st->bind_param('i',$pid);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close(); $decoded=json_decode((string)($row['parameters_json'] ?? '{}'),true); if(is_array($decoded)){$existing=$decoded;}}
  $json=json_encode($existing + $params,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  $st=$dbcnx->prepare("UPDATE sfm_pipeline_runs SET video_scan_id=0, run_scope='MULTI_VIDEO', parameters_json=? WHERE id=?"); if($st){$st->bind_param('si',$json,$pid);$st->execute();$st->close();}
  pipeline_log($pid,'INFO','PIPELINE','Multi-video preview selected video_scan_ids='.implode(',',$ids).'; frames must be combined before shared COLMAP sparse reconstruction.');
  return $pid;
}

function sfm_cleanup_older_runs_for_tuple(mysqli $dbcnx,int $currentRunId,int $captureSessionId,int $videoScanId,string $mode): void {
  $active=array_flip(SFM_CLEANUP_ACTIVE_STATUSES);
  $st=$dbcnx->prepare('SELECT id,status FROM sfm_pipeline_runs WHERE capture_session_id=? AND video_scan_id=? AND pipeline_mode=? AND id<>? ORDER BY id ASC');
  if(!$st){ pipeline_log($currentRunId,'WARNING','CLEANUP','Could not prepare old-run cleanup query: '.$dbcnx->error); return; }
  $st->bind_param('iisi',$captureSessionId,$videoScanId,$mode,$currentRunId); $st->execute(); $rs=$st->get_result(); $runs=[];
  while($r=$rs->fetch_assoc()){ if(!isset($active[strtoupper((string)$r['status'])])){ $runs[]=(int)$r['id']; } }
  $st->close();
  foreach($runs as $oldId){
    $res=sfm_cleanup_pipeline_run_artifacts($dbcnx,$oldId,['delete'=>true,'include_logs'=>false,'force_recent'=>true,'force_latest'=>true,'rerender_replacement'=>true]);
    $msg='old pipeline_run_id='.$oldId.' cleanup freed_bytes='.(int)($res['freed_bytes'] ?? 0);
    if(!empty($res['errors'])){ pipeline_log($currentRunId,'WARNING','CLEANUP',$msg.' errors='.json_encode($res['errors'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); }
    else { pipeline_log($currentRunId,'INFO','CLEANUP',$msg); }
  }
  $free=@disk_free_space(sfm_pipeline_output_dir($currentRunId));
  if($free!==false && $free < 1073741824){ throw new RuntimeException('Not enough disk space remains after cleanup to start reconstruction (less than 1 GiB free).'); }
}

function start_sfm_pipeline_run(mysqli $dbcnx,int $orderId,int $captureSessionId,?int $videoScanId,string $mode,int $startedByUserId=0,?int $previousPipelineRunId=null,?array $sameSettingsSnapshot=null): int {
  $preset=sfm_pipeline_preset($mode);
  $videoScanId=(int)($videoScanId ?: 0);
  $sourceVideo=sfm_load_source_video($dbcnx,$orderId,$captureSessionId,$videoScanId);
  $videoPath=(string)$sourceVideo['resolved_path'];
  $activeStatuses=['QUEUED','RUNNING','PLANNING','RUNNING_CHUNKS','MERGING','CANCELLING','RESTARTING'];
  $placeholders=implode(',',array_fill(0,count($activeStatuses),'?'));
  $sql="SELECT id FROM sfm_pipeline_runs WHERE capture_session_id=? AND video_scan_id=? AND pipeline_mode=? AND status IN ($placeholders) LIMIT 1";
  $st=$dbcnx->prepare($sql); if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
  $types='iis'.str_repeat('s',count($activeStatuses)); $params=array_merge([$captureSessionId,$videoScanId,$mode],$activeStatuses); $st->bind_param($types,...$params); $st->execute(); $active=$st->get_result()->fetch_assoc(); $st->close();
  if($active){ throw new RuntimeException($preset['label'].' is already queued or running for this source video'); }
  if($sameSettingsSnapshot!==null){ $effective=$sameSettingsSnapshot; } else { $effective=sfm_merge_settings(sfm_system_defaults(), sfm_load_user_settings($dbcnx,$startedByUserId), sfm_load_session_settings($dbcnx,$captureSessionId,$startedByUserId), []); }
  if(!isset($effective['extract']['sampling_mode']) && (isset($effective['extract']['fps']) || isset($effective['extract']['max_frames']))){ $effective['extract']['sampling_mode']='manual'; }
  $effective=sfm_merge_settings(sfm_system_defaults(), [], [], $effective); sfm_validate_settings($effective);
  $modeParams=sfm_mode_parameters($effective,$mode); $paramsArray=$effective + ['pipeline_mode'=>$mode,'mode_parameters'=>$modeParams,'source_video'=>sfm_source_video_snapshot($sourceVideo),'previous_pipeline_run_id'=>$previousPipelineRunId];
  $params=json_encode($paramsArray, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  $st=$dbcnx->prepare("INSERT INTO sfm_pipeline_runs (order_id,capture_session_id,video_scan_id,pipeline_mode,parameters_json,started_by_user_id,max_image_size,status,stage,progress_percent,message,started_at) VALUES (?,?,?,?,?,? ,?,'QUEUED','QUEUED',0,?,NOW(6))");
  if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
  $msg=$preset['label'].' queued'; $maxImageSize=(int)$modeParams['dense']['max_image_size']; $st->bind_param('iiississ',$orderId,$captureSessionId,$videoScanId,$mode,$params,$startedByUserId,$maxImageSize,$msg); $st->execute(); $pipelineRunId=(int)$dbcnx->insert_id; $st->close();
  $localDir=sfm_prepare_pipeline_dir($pipelineRunId); $logPath=$localDir.'/pipeline.log';
  $remoteDir=sfm_pipeline_remote_output_dir($pipelineRunId); if(!is_dir($remoteDir)){ @mkdir($remoteDir,0775,true); }
  $st=$dbcnx->prepare('UPDATE sfm_pipeline_runs SET unified_log_path=? WHERE id=?'); if($st){$st->bind_param('si',$logPath,$pipelineRunId);$st->execute();$st->close();}
  pipeline_log($pipelineRunId,'INFO','PIPELINE',$preset['label'].($previousPipelineRunId?' restarted from previous pipeline_run_id='.$previousPipelineRunId:' started'));
  if($previousPipelineRunId===null){ sfm_cleanup_older_runs_for_tuple($dbcnx,$pipelineRunId,$captureSessionId,$videoScanId,$mode); }
  pipeline_log($pipelineRunId,'INFO','PIPELINE','Source video_scan_id='.(int)$sourceVideo['id']);
  pipeline_log($pipelineRunId,'INFO','PIPELINE','Source filename='.(string)$sourceVideo['filename']);
  pipeline_log($pipelineRunId,'INFO','PIPELINE','Source path='.$videoPath);
  pipeline_log($pipelineRunId,'INFO','PIPELINE','Source duration='.(float)($sourceVideo['duration_sec'] ?? 0).' sec');
  $sourceSidecars=sfm_resolve_video_sidecar_files($videoPath);
  if(!empty($sourceSidecars['imu_jsonl_path'])){ pipeline_log($pipelineRunId,'INFO','EXTRACT_FRAMES','IMU | Source sidecar found: '.$sourceSidecars['imu_jsonl_path']); }
  else { pipeline_log($pipelineRunId,'INFO','EXTRACT_FRAMES','IMU | No source IMU sidecar found for video '.(string)$sourceVideo['filename']); }
  $extract=$modeParams['extract'] ?? [];
  if (($extract['sampling_mode'] ?? '') !== 'manual') {
    pipeline_log($pipelineRunId,'INFO','PIPELINE',sprintf('Effective parameters: sampling_mode=%s target_frames=%d candidate_multiplier=%s min_sampling_fps=%s max_sampling_fps=%s quality_filter=%s allow_upscale=%s max_image_size=%d mesh_depth=%d target_faces=%d',$extract['sampling_mode'] ?? 'auto_quality',(int)($extract['target_frames'] ?? 400),$extract['candidate_multiplier'] ?? 1.5,$extract['minimum_sampling_fps'] ?? 0.25,$extract['maximum_sampling_fps'] ?? 10,!empty($extract['quality_filter'])?'true':'false',!empty($extract['allow_upscale'])?'true':'false',(int)($modeParams['dense']['max_image_size'] ?? 0),(int)($modeParams['mesh']['depth'] ?? 0),(int)($modeParams['mesh']['target_faces'] ?? 0)));
  } else {
    pipeline_log($pipelineRunId,'INFO','PIPELINE','Effective parameters: fps='.$modeParams['extract']['fps'].' max_frames='.$modeParams['extract']['max_frames'].' sequential_overlap='.$modeParams['sparse']['sequential_overlap'].' max_image_size='.$modeParams['dense']['max_image_size'].' num_src_images='.$modeParams['dense']['num_src_images'].' chunk_overlap='.$modeParams['dense']['chunk_overlap'].' mesh_depth='.$modeParams['mesh']['depth'].' target_faces='.$modeParams['mesh']['target_faces']);
  }
  $rid=sfm_job_id($dbcnx); $out=sfm_remote_output_dir($rid); $result=$out.'/result.json'; $log=$out.'/logs'; $jt='EXTRACT_FRAMES'; $childMsg='pipeline extract frames queued';
  $st=$dbcnx->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,pipeline_run_id,job_type,remote_job_id,input_path,output_path,status,progress_percent,message,result_json_path,log_path,parameters_json) VALUES (?,?,?,?,?,?,?,'QUEUED',0,?,?,?,?)");
  if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
  $childParams=json_encode(['pipeline_run_id'=>$pipelineRunId,'frame_profile'=>$preset['frame_profile'],'settings'=>$modeParams,'source_video'=>sfm_source_video_snapshot($sourceVideo)], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  $st->bind_param('iiisissssss',$orderId,$captureSessionId,$pipelineRunId,$jt,$rid,$videoPath,$out,$childMsg,$result,$log,$childParams); $st->execute(); $st->close();
  $st=$dbcnx->prepare("UPDATE sfm_pipeline_runs SET root_remote_job_id=?, stage='EXTRACT_FRAMES', progress_percent=5, message='Frame extraction queued' WHERE id=?"); if($st){$st->bind_param('ii',$rid,$pipelineRunId);$st->execute();$st->close();}
  pipeline_log($pipelineRunId,'INFO','EXTRACT_FRAMES','Started, remote_job_id='.$rid);
  return $pipelineRunId;
}
function sfm_cancel_remote_jobs(array $remoteIds): void {
  $ids=array_values(array_unique(array_filter(array_map('intval',$remoteIds),fn($v)=>$v>0)));
  if(!$ids){return;}
  $cmd=array_merge([dirname(__DIR__).'/remote_station/cancel_remote_jobs.sh', dirname(__DIR__).'/remote_station/stations.conf'], array_map('strval',$ids));
  @exec(implode(' ',array_map('escapeshellarg',$cmd)).' 2>&1',$out,$code);
  if($code!==0){ error_log('cancel_remote_jobs failed: '.implode(' | ',$out)); }
}
function sfm_cancel_pipeline_jobs(array $jobs): array {
  global $dbcnx;
  $active=['QUEUED'=>1,'RUNNING'=>1,'RUNNING_CHUNKS'=>1,'PLANNING'=>1,'MERGING'=>1,'CANCELLING'=>1];
  $queued=[];
  foreach($jobs as $j){
    if(!isset($active[strtoupper((string)($j['status'] ?? ''))])){ continue; }
    $id=(int)($j['id'] ?? 0); if($id<=0){ continue; }
    $st=$dbcnx->prepare("UPDATE sfm_remote_jobs SET status='CANCELLING', cancel_requested_at=COALESCE(cancel_requested_at,NOW(6)), message='Cancellation requested; worker will stop remote job', updated_at=NOW(6) WHERE id=?");
    if($st){ $st->bind_param('i',$id); $st->execute(); $st->close(); }
    $queued[]=['id'=>$id,'remote_job_id'=>(int)($j['remote_job_id'] ?? 0),'status'=>'CANCELLING'];
  }
  return ['ok'=>true,'queued'=>$queued,'message'=>'Cancellation queued for worker'];
}
function sfm_delete_remote_pipeline_outputs(int $pipelineRunId,array $remoteIds): void {
  if($pipelineRunId<=0){return;}
  $ids=array_values(array_unique(array_filter(array_map('intval',$remoteIds),fn($v)=>$v>0)));
  $cmd=array_merge([dirname(__DIR__).'/remote_station/delete_remote_pipeline_outputs.sh', dirname(__DIR__).'/remote_station/stations.conf', (string)$pipelineRunId], array_map('strval',$ids));
  @exec(implode(' ',array_map('escapeshellarg',$cmd)).' 2>&1',$out,$code);
  if($code!==0){ error_log('delete_remote_pipeline_outputs failed: '.implode(' | ',$out)); }
}

function capture_session_storage_paths(int $orderId,string $appSessionUuid): array {
  $safeUuid=sfm_safe_uuid($appSessionUuid);
  $base=APP_STORAGE_DIR.'/orders/'.$orderId.'/sessions';
  return [$base.'/'.$safeUuid,$base,$safeUuid];
}
function db_delete_or_soft_session_rows(mysqli $dbcnx,string $table,array $whereParts,string $types,array $params,int $userId,string $reason): int {
  if(!table_exists($dbcnx,$table)){ return 0; }
  $where=implode(' AND ',$whereParts);
  if(column_exists($dbcnx,$table,'deleted_at')){
    $set=['deleted_at = NOW(6)']; $setTypes=''; $setParams=[];
    if(column_exists($dbcnx,$table,'deleted_by')){ $set[]='deleted_by = ?'; $setTypes.='i'; $setParams[]=$userId; }
    if(column_exists($dbcnx,$table,'delete_reason')){ $set[]='delete_reason = ?'; $setTypes.='s'; $setParams[]=$reason; }
    if(column_exists($dbcnx,$table,'updated_at')){ $set[]='updated_at = NOW(6)'; }
    $sql='UPDATE `'.$table.'` SET '.implode(', ',$set).' WHERE '.$where;
    $st=$dbcnx->prepare($sql); if(!$st){ error_log('prepare failed '.$table.': '.$dbcnx->error); return 0; }
    $bindTypes=$setTypes.$types; $bindParams=array_merge($setParams,$params);
    $st->bind_param($bindTypes,...$bindParams); $st->execute(); $rows=$st->affected_rows; $st->close(); return max(0,$rows);
  }
  $sql='DELETE FROM `'.$table.'` WHERE '.$where;
  $st=$dbcnx->prepare($sql); if(!$st){ error_log('prepare failed '.$table.': '.$dbcnx->error); return 0; }
  $st->bind_param($types,...$params); $st->execute(); $rows=$st->affected_rows; $st->close(); return max(0,$rows);
}

ensure_sfm_remote_jobs_table($dbcnx);
ensure_sfm_remote_jobs_chunk_columns($dbcnx);
ensure_sfm_settings_pipeline_columns($dbcnx);
//ensure_sfm_pipeline_tables($dbcnx);

if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=$_POST['action']??'';

 if($action==='update_order'){
   if(!$canEditOrderInfo){
     $error='Информация заявки доступна только для просмотра. После закрытия заявки изменение данных заблокировано.';
   } else {
    $title=trim($_POST['title']??''); $address=trim($_POST['address']??''); $area=trim($_POST['area_m2']??''); $cn=trim($_POST['customer_name']??''); $cp=trim($_POST['customer_phone']??''); $ce=trim($_POST['customer_email']??''); $pub=isset($_POST['is_published'])?1:0; $areaV=$area!==''?(float)$area:null;
    $st=$dbcnx->prepare("UPDATE tour_orders SET title=?,address=?,area_m2=?,customer_name=?,customer_phone=?,customer_email=?,is_published=?,updated_at=NOW(6) WHERE id=?");
    if($st){$st->bind_param('ssdsssii',$title,$address,$areaV,$cn,$cp,$ce,$pub,$orderId); if($st->execute()){audit_log($userId,'ORDER_UPDATED','TOUR_ORDER',$orderId,'Заявка обновлена');$st->close();header('Location: /order.php?id='.$orderId.'&updated=1');exit;} $error='DB execute error: '.$st->error; $st->close();}
   }
 }



 if($action==='upload_external_video' && $canDeleteMedia){
   try{
     handle_external_video_upload($dbcnx,$orderId,$userId);
     header('Location: /order.php?id='.$orderId.'&video_uploaded=1'); exit;
   }catch(Throwable $e){
     $error=$e->getMessage();
     if(strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))==='xmlhttprequest'){
       http_response_code(422);
       header('Content-Type: text/plain; charset=utf-8');
       echo $error;
       exit;
     }
   }
 }

 if($action==='create_processing_job_web' && ($canDeleteMedia || ($role==='OPERATOR' && (int)$order['operator_id']===$userId) || $role==='ADMIN')){
   $captureSessionId=(int)($_POST['capture_session_id']??0);
   if($captureSessionId<=0){
     $error='Не выбрана capture session для обработки';
   } else {
     $st=$dbcnx->prepare("SELECT id FROM capture_sessions WHERE id=? AND order_id=? LIMIT 1");
     if($st){
       $st->bind_param('ii',$captureSessionId,$orderId);
       $st->execute();
       $sessionExists=$st->get_result()->fetch_assoc();
       $st->close();
       if(!$sessionExists){
         $error='Capture session не найдена для этой заявки';
       } else {
         $jobType='MARKER_DETECTION';
         $st=$dbcnx->prepare("INSERT INTO processing_jobs (session_id, order_id, job_type, status, metric_status, marker_expected, marker_kit_id, marker_dictionary, marker_size_m) VALUES (?, ?, ?, 'QUEUED', 'UNKNOWN', 1, 'maklertour_kit_v1', 'APRILTAG_36H11', 0.1600) ON DUPLICATE KEY UPDATE updated_at = NOW(6)");
         if($st){
           $st->bind_param('iis',$captureSessionId,$orderId,$jobType);
           if($st->execute()){
             $st->close();
             audit_log($userId,'PROCESSING_JOB_CREATED_WEB','TOUR_ORDER',$orderId,'Создана задача обработки marker detection из web',['capture_session_id'=>$captureSessionId,'job_type'=>$jobType]);
             header('Location: /order.php?id='.$orderId.'&job_queued=1');
             exit;
           }
           $error='DB execute error: '.$st->error;
           $st->close();
         }
       }
     }
   }
 }


 if(in_array($action,['sfm_retry_job','sfm_delete_job_record','sfm_delete_job_files'],true) && $canDeleteMedia){
   try{
     $jobId=(int)($_POST['job_id']??0); if($jobId<=0){ throw new RuntimeException('Bad job id'); }
     $st=$dbcnx->prepare('SELECT * FROM sfm_remote_jobs WHERE id=? AND order_id=? LIMIT 1'); if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
     $st->bind_param('ii',$jobId,$orderId); $st->execute(); $job=$st->get_result()->fetch_assoc(); $st->close(); if(!$job){ throw new RuntimeException('SfM job not found'); }
     $jt=(string)$job['job_type']; $remote=(int)$job['remote_job_id'];
     if($action==='sfm_retry_job'){
       if(in_array($jt,['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ','COLMAP_DENSE_CHUNK'],true)){
         $rid=sfm_job_id($dbcnx); $status='QUEUED'; $progress=0; $msg='Retry queued';
         $st=$dbcnx->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,job_type,remote_job_id,parent_remote_job_id,output_path,status,progress_percent,message,result_json_path,log_path,reconstruction_mode,chunk_index,chunk_count,retry_count,parameters_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
         if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
         $out=in_array($jt,['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'],true)?(sfm_remote_output_dir($rid).'/merged/merged_fused.ply'):(string)($job['output_path']??'');
         $result=in_array($jt,['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'],true)?(sfm_remote_output_dir($rid).'/merged/result.json'):(string)($job['result_json_path']??'');
         $log=in_array($jt,['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'],true)?(sfm_remote_output_dir($rid).'/logs'):(string)($job['log_path']??'');
         $parent=$jt==='COLMAP_DENSE_CHUNK'?(int)$job['parent_remote_job_id']:(int)$job['parent_remote_job_id']; $mode=(string)($job['reconstruction_mode']??''); $chunkIndex=$job['chunk_index']===null?null:(int)$job['chunk_index']; $chunkCount=$job['chunk_count']===null?null:(int)$job['chunk_count']; $retry=(int)($job['retry_count']??0)+1; $params=(string)($job['parameters_json']??''); $sid=(int)$job['capture_session_id'];
         $st->bind_param('iisiissisissiiss',$orderId,$sid,$jt,$rid,$parent,$out,$status,$progress,$msg,$result,$log,$mode,$chunkIndex,$chunkCount,$retry,$params); $st->execute(); $st->close();
       } else { throw new RuntimeException('Retry is available for reconstruction parents and chunks'); }
     } elseif($action==='sfm_delete_job_record'){
       $st=$dbcnx->prepare('DELETE FROM sfm_remote_jobs WHERE order_id=? AND (id=? OR parent_remote_job_id=?)'); if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); } $st->bind_param('iii',$orderId,$jobId,$remote); $st->execute(); $st->close();
     } elseif($action==='sfm_delete_job_files'){
       $base='/home/makler/web/remote_station/output'; safe_rrmdir(sfm_remote_output_dir($remote),$base); @unlink('/home/makler_storage/logs/job_'.$remote.'_merge.log');
       $st=$dbcnx->prepare("UPDATE sfm_remote_jobs SET message='Job files deleted', updated_at=NOW(6) WHERE order_id=? AND (id=? OR parent_remote_job_id=?)"); if($st){ $st->bind_param('iii',$orderId,$jobId,$remote); $st->execute(); $st->close(); }
     }
     header('Location: /order.php?id='.$orderId.'&sfm_job_queued=1'); exit;
   }catch(Throwable $e){ $error=$e->getMessage(); }
 }


 if($action==='start_sfm_multi_video_pipeline' && $canDeleteMedia){
   try{
     $captureSessionId=(int)($_POST['capture_session_id']??0);
     $mode=(string)($_POST['pipeline_mode']??'preview');
     start_sfm_multi_video_pipeline_run($dbcnx,$orderId,$captureSessionId,$mode,$userId);
     header('Location: /order.php?id='.$orderId.'&sfm_job_queued=1'); exit;
   }catch(Throwable $e){ $error=$e->getMessage(); }
 }

 if($action==='start_sfm_pipeline' && $canDeleteMedia){
   try{
     $captureSessionId=(int)($_POST['capture_session_id']??0);
     $videoScanId=(int)($_POST['video_scan_id']??0);
     $mode=(string)($_POST['pipeline_mode']??'');
     start_sfm_pipeline_run($dbcnx,$orderId,$captureSessionId,$videoScanId>0?$videoScanId:null,$mode,$userId);
     header('Location: /order.php?id='.$orderId.'&sfm_job_queued=1'); exit;
   }catch(Throwable $e){ $error=$e->getMessage(); }
 }

 if(in_array($action,['restart_sfm_pipeline','restart_sfm_pipeline_same_settings'],true) && $canDeleteMedia){
   $pipelineRunId=(int)($_POST['pipeline_run_id']??0); $captureSessionId=(int)($_POST['capture_session_id']??0);
   $newPipelineRunId=0; $oldStatus=''; $oldStage=''; $oldMessage='';
   try{
     if($pipelineRunId<=0||$captureSessionId<=0){ throw new RuntimeException('Bad pipeline restart request'); }
     $st=$dbcnx->prepare('SELECT * FROM sfm_pipeline_runs WHERE id=? AND order_id=? AND capture_session_id=? LIMIT 1'); if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
     $st->bind_param('iii',$pipelineRunId,$orderId,$captureSessionId); $st->execute(); $run=$st->get_result()->fetch_assoc(); $st->close();
     if(!$run){ throw new RuntimeException('Pipeline run not found for this order'); }
     $oldStatus=(string)($run['status'] ?? ''); $oldStage=(string)($run['stage'] ?? ''); $oldMessage=(string)($run['message'] ?? '');
     pipeline_log($pipelineRunId,'INFO','RESTART','Restart requested action='.$action.' user_id='.$userId);
     pipeline_log($pipelineRunId,'INFO','RESTART','Old status='.$oldStatus.' stage='.$oldStage.' message='.$oldMessage);
     if(!sfm_session_for_order($dbcnx,$orderId,$captureSessionId)){ throw new RuntimeException('Capture session not found'); }
     $mode=(string)$run['pipeline_mode']; if(!in_array($mode,sfm_pipeline_modes(),true)){ throw new RuntimeException('Unsupported pipeline mode'); }
     sfm_load_source_video($dbcnx,$orderId,$captureSessionId,(int)($run['video_scan_id'] ?? 0));
     $st=$dbcnx->prepare('SELECT * FROM sfm_remote_jobs WHERE pipeline_run_id=?'); if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
     $st->bind_param('i',$pipelineRunId); $st->execute(); $rs=$st->get_result(); $jobs=[]; while($j=$rs->fetch_assoc()){$jobs[]=$j;} $st->close();
     $activeStatuses=['QUEUED'=>1,'RUNNING'=>1,'RUNNING_CHUNKS'=>1,'PLANNING'=>1,'MERGING'=>1,'CANCELLING'=>1,'RESTARTING'=>1,'STARTED'=>1,'PROCESSING'=>1,'ACTIVE'=>1];
     $activeJobs=[]; foreach($jobs as $j){ if(isset($activeStatuses[strtoupper((string)($j['status'] ?? ''))])){ $activeJobs[]=$j; } }
     pipeline_log($pipelineRunId,'INFO','RESTART','Active jobs count='.count($activeJobs));
     if(count($activeJobs)>0){
       pipeline_log($pipelineRunId,'WARNING','RESTART','Restart blocked because active jobs still exist; cancel first.');
       throw new RuntimeException('This pipeline still has active jobs. Cancel it first, wait for cancellation to finish, then restart.');
     }
     $sameSnapshot=null; if($action==='restart_sfm_pipeline_same_settings'){ $sameSnapshot=sfm_json_array((string)($run['parameters_json'] ?? '{}')); unset($sameSnapshot['pipeline_mode'],$sameSnapshot['mode_parameters']); }
     $newPipelineRunId=start_sfm_pipeline_run($dbcnx,$orderId,$captureSessionId,((int)($run['video_scan_id']??0))?:null,$mode,$userId,$pipelineRunId,$sameSnapshot);
     pipeline_log($pipelineRunId,'INFO','RESTART','New pipeline_run_id='.$newPipelineRunId);
     pipeline_log($newPipelineRunId,'INFO','RESTART','action='.$action.' previous_pipeline_run_id='.$pipelineRunId);
     $st=$dbcnx->prepare("UPDATE sfm_pipeline_runs SET status='CANCELLED', stage='CANCELLED', message='Superseded by rerender', finished_at=COALESCE(finished_at,NOW(6)), updated_at=NOW(6) WHERE id=?"); if($st){$st->bind_param('i',$pipelineRunId);$st->execute();$st->close();}
     try{
       $cleanupRes=sfm_cleanup_pipeline_run_artifacts($dbcnx,$pipelineRunId,['delete'=>true,'include_logs'=>false,'force_recent'=>true,'force_latest'=>true]);
       if(!empty($cleanupRes['errors'])){ pipeline_log($pipelineRunId,'WARNING','CLEANUP','Old artifact cleanup failed after rerender was queued: '.json_encode($cleanupRes['errors'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); }
       else { pipeline_log($pipelineRunId,'INFO','CLEANUP','cleanup result freed_bytes='.(int)($cleanupRes['freed_bytes'] ?? 0)); }
       pipeline_log($newPipelineRunId,!empty($cleanupRes['errors'])?'WARNING':'INFO','CLEANUP','old pipeline_run_id='.$pipelineRunId.' cleanup result freed_bytes='.(int)($cleanupRes['freed_bytes'] ?? 0).' errors='.count($cleanupRes['errors'] ?? []));
     }catch(Throwable $cleanupError){
       pipeline_log($pipelineRunId,'WARNING','CLEANUP','Old artifact cleanup failed after rerender was queued: '.$cleanupError->getMessage());
       pipeline_log($newPipelineRunId,'WARNING','CLEANUP','Old artifact cleanup failed after rerender was queued for old pipeline_run_id='.$pipelineRunId.': '.$cleanupError->getMessage());
     }
     error_log('pipeline_run_id='.$pipelineRunId.' rerender queued as pipeline_run_id='.$newPipelineRunId.' by user_id='.$userId);
     header('Location: /order.php?id='.$orderId.'&sfm_pipeline_restarted=1'); exit;
   }catch(Throwable $e){
     if($newPipelineRunId<=0 && $pipelineRunId>0){ pipeline_log($pipelineRunId,'ERROR','RESTART','Restart failed before new run was created: '.$e->getMessage()); }
     elseif($newPipelineRunId>0){ pipeline_log($pipelineRunId,'ERROR','RESTART','Restart follow-up failed after new pipeline_run_id='.$newPipelineRunId.': '.$e->getMessage()); }
     $error=$e->getMessage();
   }
 }

 if($action==='cancel_sfm_pipeline' && $canDeleteMedia){
   try{
     $pipelineRunId=(int)($_POST['pipeline_run_id']??0); $captureSessionId=(int)($_POST['capture_session_id']??0);
     if($pipelineRunId<=0||$captureSessionId<=0){ throw new RuntimeException('Bad pipeline cancel request'); }
     $st=$dbcnx->prepare('SELECT * FROM sfm_pipeline_runs WHERE id=? AND order_id=? AND capture_session_id=? LIMIT 1'); if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
     $st->bind_param('iii',$pipelineRunId,$orderId,$captureSessionId); $st->execute(); $run=$st->get_result()->fetch_assoc(); $st->close();
     if(!$run){ throw new RuntimeException('Pipeline run not found for this order'); }
     $st=$dbcnx->prepare('SELECT * FROM sfm_remote_jobs WHERE pipeline_run_id=?'); if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
     $st->bind_param('i',$pipelineRunId); $st->execute(); $rs=$st->get_result(); $jobs=[]; while($j=$rs->fetch_assoc()){$jobs[]=$j;} $st->close();
     $st=$dbcnx->prepare("UPDATE sfm_pipeline_runs SET status='CANCELLING', stage='CANCELLING', updated_at=NOW(6), message='Cancelling remote jobs' WHERE id=?"); if($st){$st->bind_param('i',$pipelineRunId);$st->execute();$st->close();}
     $cancel=sfm_cancel_pipeline_jobs($jobs);
     pipeline_log($pipelineRunId,'INFO','CANCELLING','Cancellation queued for worker: '.json_encode($cancel,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
     header('Location: /order.php?id='.$orderId.'&sfm_pipeline_cancelling=1'); exit;
   }catch(Throwable $e){ $error=$e->getMessage(); }
 }

 if(in_array($action,['sfm_extract_frames_web','sfm_colmap_sparse_web','sfm_export_ply_web','sfm_colmap_dense_web','sfm_reconstruction_preview_web','sfm_reconstruction_hq_web','sfm_generate_mesh_preview_web','sfm_generate_mesh_hq_web'],true) && $canDeleteMedia){
   try{
     if($action==='sfm_extract_frames_web'){
       $captureSessionId=(int)($_POST['capture_session_id']??0);
       $abs=sfm_resolve_video_path($dbcnx,$orderId,$captureSessionId,(string)($_POST['video_path']??($_POST['video_filename']??'')));
       if($abs===null){ throw new RuntimeException('Video path is invalid or outside session videos directory'); }
       $rid=sfm_job_id($dbcnx); $out=sfm_remote_output_dir($rid); $result=$out.'/result.json'; $log=$out.'/logs'; $jt='EXTRACT_FRAMES';
       $msg='job queued';
       $st=$dbcnx->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,job_type,remote_job_id,input_path,output_path,status,progress_percent,message,result_json_path,log_path) VALUES (?,?,?,?,?,?,'QUEUED',0,?,?,?)");
       if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
       $st->bind_param('iisisssss',$orderId,$captureSessionId,$jt,$rid,$abs,$out,$msg,$result,$log); $st->execute(); $st->close();
     } elseif($action==='sfm_colmap_sparse_web'){
       $captureSessionId=(int)($_POST['capture_session_id']??0); if(!sfm_session_for_order($dbcnx,$orderId,$captureSessionId)){ throw new RuntimeException('Capture session not found'); }
       $parent=(int)($_POST['extract_job_id']??0); if($parent<=0){throw new RuntimeException('Bad extract job id');}
       $st=$dbcnx->prepare("SELECT id FROM sfm_remote_jobs WHERE order_id=? AND capture_session_id=? AND remote_job_id=? AND job_type='EXTRACT_FRAMES' LIMIT 1");
       if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
       $st->bind_param('iii',$orderId,$captureSessionId,$parent); $st->execute(); $parentJob=$st->get_result()->fetch_assoc(); $st->close(); if(!$parentJob){throw new RuntimeException('EXTRACT_FRAMES job not found');}
       $rid=sfm_job_id($dbcnx); $input='/home/makler_storage/output/job_'.$parent.'/frames'; $out=sfm_remote_output_dir($rid); $result=$out.'/result.json'; $log=$out.'/logs'; $jt='COLMAP_SPARSE'; $msg='job queued';
       $st=$dbcnx->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,job_type,remote_job_id,parent_remote_job_id,input_path,output_path,status,progress_percent,message,result_json_path,log_path) VALUES (?,?,?,?,?,?,?,'QUEUED',0,?,?,?)");
       if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
       $st->bind_param('iisiisssss',$orderId,$captureSessionId,$jt,$rid,$parent,$input,$out,$msg,$result,$log); $st->execute(); $st->close();
     } elseif($action==='sfm_export_ply_web') {
       $colmap=(int)($_POST['colmap_job_id']??0); $model=(int)($_POST['model_id']??0); if($colmap<=0||$model<0){throw new RuntimeException('Bad COLMAP job or model id');}
       $st=$dbcnx->prepare("SELECT capture_session_id FROM sfm_remote_jobs WHERE order_id=? AND remote_job_id=? AND job_type='COLMAP_SPARSE' LIMIT 1"); $st->bind_param('ii',$orderId,$colmap); $st->execute(); $parentJob=$st->get_result()->fetch_assoc(); $st->close(); if(!$parentJob){throw new RuntimeException('COLMAP job not found');}
       $captureSessionId=(int)$parentJob['capture_session_id']; $rid=sfm_job_id($dbcnx); $out=sfm_remote_output_dir($colmap).'/sparse_'.$model.'.ply'; $log=sfm_remote_output_dir($colmap).'/logs'; $jt='EXPORT_PLY'; $msg='job queued'; $params=json_encode(['sparse_job_id'=>$colmap,'model_id'=>$model], JSON_UNESCAPED_SLASHES);
       $st=$dbcnx->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,job_type,remote_job_id,parent_remote_job_id,output_path,status,progress_percent,message,log_path,parameters_json) VALUES (?,?,?,?,?,?,'QUEUED',0,?,?,?)");
       if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
       $st->bind_param('iisiissss',$orderId,$captureSessionId,$jt,$rid,$colmap,$out,$msg,$log,$params); $st->execute(); $st->close();
     } elseif($action==='sfm_generate_mesh_preview_web' || $action==='sfm_generate_mesh_hq_web') {
       $parent=(int)($_POST['parent_remote_job_id']??0); if($parent<=0){throw new RuntimeException('Bad reconstruction parent job id');}
       $st=$dbcnx->prepare("SELECT * FROM sfm_remote_jobs WHERE order_id=? AND remote_job_id=? AND job_type IN ('COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ') LIMIT 1"); $st->bind_param('ii',$orderId,$parent); $st->execute(); $parentJob=$st->get_result()->fetch_assoc(); $st->close(); if(!$parentJob){throw new RuntimeException('Reconstruction parent not found');}
       $st=$dbcnx->prepare("SELECT id FROM sfm_remote_jobs WHERE parent_remote_job_id=? AND job_type='COLMAP_MESH' AND status IN ('QUEUED','RUNNING','DONE') LIMIT 1"); $st->bind_param('i',$parent); $st->execute(); $exists=$st->get_result()->fetch_assoc(); $st->close(); if($exists){throw new RuntimeException('Mesh job already exists for this reconstruction');}
       $mode=$action==='sfm_generate_mesh_hq_web'?'hq':'preview'; $captureSessionId=(int)$parentJob['capture_session_id']; $rid=sfm_job_id($dbcnx); $input=sfm_remote_output_dir($parent).'/merged/merged_fused.ply'; $out=sfm_remote_output_dir($rid).'/mesh'; $result=$out.'/mesh_result.json'; $log=$out.'/logs'; $jt='COLMAP_MESH'; $msg='mesh queued'; $params=json_encode(['input_ply'=>$input,'poisson_depth'=>$mode==='hq'?9:7,'target_faces'=>$mode==='hq'?500000:100000,'trim_enabled'=>false,'model_id'=>sfm_job_model_id($parentJob)], JSON_UNESCAPED_SLASHES);
       $st=$dbcnx->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,job_type,remote_job_id,parent_remote_job_id,input_path,output_path,status,progress_percent,message,result_json_path,log_path,reconstruction_mode,parameters_json) VALUES (?,?,?,?,?,?,?,'QUEUED',0,?,?,?,?,?)");
       if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
       $st->bind_param('iisiisssssss',$orderId,$captureSessionId,$jt,$rid,$parent,$input,$out,$msg,$result,$log,$mode,$params); $st->execute(); $st->close();
     } elseif($action==='sfm_colmap_dense_web') {
       $colmap=(int)($_POST['colmap_job_id']??0); $model=(int)($_POST['model_id']??0); if($colmap<=0||$model<0){throw new RuntimeException('Bad COLMAP job or model id');}
       $st=$dbcnx->prepare("SELECT capture_session_id FROM sfm_remote_jobs WHERE order_id=? AND remote_job_id=? AND job_type='COLMAP_SPARSE' LIMIT 1"); $st->bind_param('ii',$orderId,$colmap); $st->execute(); $parentJob=$st->get_result()->fetch_assoc(); $st->close(); if(!$parentJob){throw new RuntimeException('COLMAP job not found');}
       $captureSessionId=(int)$parentJob['capture_session_id']; $rid=sfm_job_id($dbcnx); $out=sfm_remote_output_dir($rid).'/dense_model_'.$model.'.ply'; $result=sfm_remote_output_dir($rid).'/dense/result.json'; $log=sfm_remote_output_dir($rid).'/dense/logs'; $jt='COLMAP_DENSE'; $msg='job queued';
       $st=$dbcnx->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,job_type,remote_job_id,parent_remote_job_id,output_path,status,progress_percent,message,result_json_path,log_path) VALUES (?,?,?,?,?,?,'QUEUED',0,?,?,?)");
       if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
       $st->bind_param('iisiissss',$orderId,$captureSessionId,$jt,$rid,$colmap,$out,$msg,$result,$log); $st->execute(); $st->close();

     } else {
       $colmap=(int)($_POST['colmap_job_id']??0); $model=(int)($_POST['model_id']??0); if($colmap<=0||$model<0){throw new RuntimeException('Bad COLMAP job or model id');}
       $st=$dbcnx->prepare("SELECT capture_session_id FROM sfm_remote_jobs WHERE order_id=? AND remote_job_id=? AND job_type='COLMAP_SPARSE' LIMIT 1"); $st->bind_param('ii',$orderId,$colmap); $st->execute(); $parentJob=$st->get_result()->fetch_assoc(); $st->close(); if(!$parentJob){throw new RuntimeException('COLMAP job not found');}
       $mode=$action==='sfm_reconstruction_hq_web'?'hq':'preview'; if(isset($_POST['best_model'])){ $model=sfm_best_sparse_model_id($colmap,[0,1]); } $captureSessionId=(int)$parentJob['capture_session_id']; $rid=sfm_job_id($dbcnx); $jt=$mode==='hq'?'COLMAP_RECONSTRUCTION_HQ':'COLMAP_RECONSTRUCTION_PREVIEW'; $out=sfm_remote_output_dir($rid).'/merged/merged_fused.ply'; $result=sfm_remote_output_dir($rid).'/merged/result.json'; $log=sfm_remote_output_dir($rid).'/logs'; $msg='chunked reconstruction queued';
       $stats=sfm_sparse_model_stats($colmap,$model); $min=$mode==='hq'?MIN_REGISTERED_IMAGES_HQ:MIN_REGISTERED_IMAGES_PREVIEW; if((int)$stats['registered_images'] < $min){ $label=$mode==='hq'?'high quality':'preview'; throw new RuntimeException('Insufficient registered images: '.(int)$stats['registered_images'].'. Minimum for '.$label.' is '.$min.'. Select another sparse model or improve sparse reconstruction.'); }
       $params=json_encode(['sparse_job_id'=>$colmap,'model_id'=>$model], JSON_UNESCAPED_SLASHES);
       $st=$dbcnx->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,job_type,remote_job_id,parent_remote_job_id,output_path,status,progress_percent,message,result_json_path,log_path,reconstruction_mode,parameters_json) VALUES (?,?,?,?,?,?,'QUEUED',0,?,?,?,?,?)");
       if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
       $st->bind_param('iisiissssss',$orderId,$captureSessionId,$jt,$rid,$colmap,$out,$msg,$result,$log,$mode,$params); $st->execute(); $st->close();
     }
     header('Location: /order.php?id='.$orderId.'&sfm_job_queued=1'); exit;
   }catch(Throwable $e){ $error=$e->getMessage(); }
 }


 if($action==='operator_close_order' && $canOperatorClose){
   $st=$dbcnx->prepare("UPDATE tour_orders SET operator_closed_at=NOW(6), operator_closed_by=?, status=IF(broker_closed_at IS NULL,'READY','COMPLETED'), updated_at=NOW(6) WHERE id=?"); if($st){$st->bind_param('ii',$userId,$orderId);$st->execute();$st->close();audit_log($userId,'ORDER_OPERATOR_CLOSED','TOUR_ORDER',$orderId,'Закрытие со стороны оператора');header('Location: /order.php?id='.$orderId.'&closed=1');exit;}
 }
 if($action==='broker_close_order' && $canBrokerClose){
   $st=$dbcnx->prepare("UPDATE tour_orders SET broker_closed_at=NOW(6), broker_closed_by=?, status=IF(operator_closed_at IS NULL,status,'COMPLETED'), updated_at=NOW(6) WHERE id=?"); if($st){$st->bind_param('ii',$userId,$orderId);$st->execute();$st->close();audit_log($userId,'ORDER_BROKER_CLOSED','TOUR_ORDER',$orderId,'Закрытие со стороны брокера');header('Location: /order.php?id='.$orderId.'&closed=1');exit;}
 }
 if($action==='reopen_order' && $canReopen){
   $st=$dbcnx->prepare("UPDATE tour_orders SET operator_closed_at=NULL,operator_closed_by=NULL,broker_closed_at=NULL,broker_closed_by=NULL,status=IF(operator_id IS NULL,'NEW','ASSIGNED') WHERE id=?"); if($st){$st->bind_param('i',$orderId);$st->execute();$st->close();audit_log($userId,'ORDER_REOPENED','TOUR_ORDER',$orderId,'Заявка переоткрыта');header('Location: /order.php?id='.$orderId.'&reopened=1');exit;}
 }

 if($action==='delete_photo_point' && $canDeleteMedia){
   $photoPointId=(int)($_POST['photo_point_id']??0);
   if($photoPointId<=0){ $error='Неверный photo point'; }
   else{
     $dbcnx->begin_transaction();
     try{
       $st=$dbcnx->prepare("SELECT pp.id, pp.session_id FROM photo_points pp JOIN capture_sessions cs ON cs.id = pp.session_id WHERE pp.id = ? AND cs.order_id = ? AND pp.deleted_at IS NULL AND cs.deleted_at IS NULL LIMIT 1 FOR UPDATE");
       if(!$st){ throw new RuntimeException('prepare failed'); }
       $st->bind_param('ii',$photoPointId,$orderId); $st->execute(); $pp=$st->get_result()->fetch_assoc(); $st->close();
       if(!$pp){ throw new RuntimeException('Фото уже удалено или не найдено'); }
       $set=["deleted_at = NOW(6)","deleted_by = ?","delete_reason = 'deleted_from_order_web'"]; if(column_exists($dbcnx,'photo_points','upload_state')){$set[]="upload_state = 'DELETED'";} if(column_exists($dbcnx,'photo_points','updated_at')){$set[]="updated_at = NOW(6)";}
       $sql="UPDATE photo_points SET ".implode(', ',$set)." WHERE id = ?"; $st=$dbcnx->prepare($sql); if(!$st){ throw new RuntimeException('prepare failed'); } $st->bind_param('ii',$userId,$photoPointId); $st->execute(); $st->close();
       $sid=(int)$pp['session_id'];
       if(table_exists($dbcnx,'marker_detections') && column_exists($dbcnx,'marker_detections','source_type') && column_exists($dbcnx,'marker_detections','source_id')){ $st=$dbcnx->prepare("DELETE FROM marker_detections WHERE session_id = ? AND source_type = 'PHOTO_POINT' AND source_id = ?"); if($st){$st->bind_param('ii',$sid,$photoPointId);$st->execute();$st->close();}}
       if(table_exists($dbcnx,'tour_point_links')){ $st=$dbcnx->prepare("DELETE FROM tour_point_links WHERE session_id = ? AND (from_photo_point_id = ? OR to_photo_point_id = ?)"); if($st){$st->bind_param('iii',$sid,$photoPointId,$photoPointId);$st->execute();$st->close();}}
       if(table_exists($dbcnx,'tour_point_positions')){ $st=$dbcnx->prepare("DELETE FROM tour_point_positions WHERE session_id = ? AND photo_point_id = ?"); if($st){$st->bind_param('ii',$sid,$photoPointId);$st->execute();$st->close();}}
       $dbcnx->commit();
       audit_log($userId,'PHOTO_POINT_DELETED','TOUR_ORDER',$orderId,'Фото удалено из web',['photo_point_id'=>$photoPointId,'capture_session_id'=>$sid]);
       header('Location: /order.php?id='.$orderId.'&photo_deleted=1'); exit;
     }catch(Throwable $e){ $dbcnx->rollback(); $error=$e->getMessage(); }
   }
 }
 if($action==='delete_capture_session' && $canDeleteCaptureSession){
   $captureSessionId=(int)($_POST['capture_session_id']??0);
   if($captureSessionId<=0){ $error='Неверная capture session'; }
   else{
     $appSessionUuid=''; $storagePath=''; $storageOk=false; $affected=[]; $remoteJobIds=[];
     $dbcnx->begin_transaction();
     try{
       $st=$dbcnx->prepare("SELECT id, order_id, app_session_uuid FROM capture_sessions WHERE id = ? AND order_id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE");
       if(!$st){ throw new RuntimeException('prepare failed'); }
       $st->bind_param('ii',$captureSessionId,$orderId); $st->execute(); $sess=$st->get_result()->fetch_assoc(); $st->close();
       if(!$sess){ throw new RuntimeException('Сессия уже удалена или не найдена'); }
       $appSessionUuid=(string)($sess['app_session_uuid'] ?? '');
       [$storagePath,$allowedBase,$safeUuid]=capture_session_storage_paths($orderId,$appSessionUuid);
       $realBase=realpath($allowedBase);
       if($realBase===false || !is_dir($realBase)){ throw new RuntimeException('Session storage base not found'); }
       if((file_exists($storagePath) || is_link($storagePath))){
         if(is_link($storagePath)){ throw new RuntimeException('Session storage path is a symlink'); }
         $realSession=realpath($storagePath);
         if($realSession===false || strpos(rtrim($realSession,DIRECTORY_SEPARATOR),rtrim($realBase,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)!==0){ throw new RuntimeException('Session storage path is outside allowed base'); }
       }
       if(table_exists($dbcnx,'sfm_remote_jobs')){
         $st=$dbcnx->prepare("SELECT remote_job_id, parent_remote_job_id FROM sfm_remote_jobs WHERE order_id=? AND capture_session_id=?");
         if($st){ $st->bind_param('ii',$orderId,$captureSessionId); $st->execute(); $rs=$st->get_result(); while($r=$rs->fetch_assoc()){ if($r['remote_job_id']!==null){$remoteJobIds[]=(int)$r['remote_job_id'];} if($r['parent_remote_job_id']!==null){$remoteJobIds[]=(int)$r['parent_remote_job_id'];} } $st->close(); }
       }
       $set=["deleted_at = NOW(6)", "app_session_uuid = CONCAT('deleted-', id, '-', LEFT(app_session_uuid, 100))"]; $setTypes=''; $setParams=[]; $types='i'; $params=[$captureSessionId];
       if(column_exists($dbcnx,'capture_sessions','deleted_by')){ $set[]='deleted_by = ?'; $setTypes.='i'; $setParams[]=$userId; }
       if(column_exists($dbcnx,'capture_sessions','delete_reason')){ $set[]='delete_reason = ?'; $setTypes.='s'; $setParams[]='Deleted from order page for retest'; }
       if(column_exists($dbcnx,'capture_sessions','updated_at')){$set[]="updated_at = NOW(6)";}
       $st=$dbcnx->prepare("UPDATE capture_sessions SET ".implode(', ',$set)." WHERE id = ?"); if(!$st){ throw new RuntimeException('prepare failed'); } $bindTypes=$setTypes.$types; $bindParams=array_merge($setParams,$params); $st->bind_param($bindTypes,...$bindParams); $st->execute(); $affected['capture_sessions']=$st->affected_rows; $st->close();
       $affected['marker_detections']=0;
       if(table_exists($dbcnx,'marker_detections')){
         if(column_exists($dbcnx,'marker_detections','session_id')){ $affected['marker_detections']+=db_delete_or_soft_session_rows($dbcnx,'marker_detections',['session_id = ?'],'i',[$captureSessionId],$userId,'session_deleted_from_order_web'); }
         elseif(column_exists($dbcnx,'marker_detections','photo_point_id')){ $affected['marker_detections']+=db_delete_or_soft_session_rows($dbcnx,'marker_detections',['photo_point_id IN (SELECT id FROM photo_points WHERE session_id = ?)'],'i',[$captureSessionId],$userId,'session_deleted_from_order_web'); }
       }
       $affected['tour_point_links']=0;
       if(table_exists($dbcnx,'tour_point_links')){
         if(column_exists($dbcnx,'tour_point_links','session_id')){ $affected['tour_point_links']+=db_delete_or_soft_session_rows($dbcnx,'tour_point_links',['session_id = ?'],'i',[$captureSessionId],$userId,'session_deleted_from_order_web'); }
         elseif(column_exists($dbcnx,'tour_point_links','from_photo_point_id')){ $affected['tour_point_links']+=db_delete_or_soft_session_rows($dbcnx,'tour_point_links',['(from_photo_point_id IN (SELECT id FROM photo_points WHERE session_id = ?) OR to_photo_point_id IN (SELECT id FROM photo_points WHERE session_id = ?))'],'ii',[$captureSessionId,$captureSessionId],$userId,'session_deleted_from_order_web'); }
       }
       $affected['tour_point_positions']=table_exists($dbcnx,'tour_point_positions') ? db_delete_or_soft_session_rows($dbcnx,'tour_point_positions',['session_id = ?'],'i',[$captureSessionId],$userId,'session_deleted_from_order_web') : 0;
       $affected['public_tour_links']=table_exists($dbcnx,'public_tour_links') ? db_delete_or_soft_session_rows($dbcnx,'public_tour_links',['session_id = ?'],'i',[$captureSessionId],$userId,'session_deleted_from_order_web') : 0;
       $affected['photo_points']=db_delete_or_soft_session_rows($dbcnx,'photo_points',['session_id = ?','deleted_at IS NULL'],'i',[$captureSessionId],$userId,'session_deleted_from_order_web');
       $affected['video_scans']=db_delete_or_soft_session_rows($dbcnx,'video_scans',['session_id = ?','deleted_at IS NULL'],'i',[$captureSessionId],$userId,'session_deleted_from_order_web');
       $affected['processing_jobs']=table_exists($dbcnx,'processing_jobs') ? db_delete_or_soft_session_rows($dbcnx,'processing_jobs',['order_id = ?','session_id = ?'],'ii',[$orderId,$captureSessionId],$userId,'session_deleted_from_order_web') : 0;
       if(table_exists($dbcnx,'sfm_remote_jobs')){ $st=$dbcnx->prepare("UPDATE sfm_remote_jobs SET status='CANCELLED', message='Cancelled because capture session was deleted', updated_at=NOW(6) WHERE order_id=? AND capture_session_id=?"); if($st){$st->bind_param('ii',$orderId,$captureSessionId);$st->execute();$affected['sfm_remote_jobs']=$st->affected_rows;$st->close();} }
       $cleanupRes=sfm_cleanup_delete_project_session_artifacts_and_media($dbcnx,$orderId,$captureSessionId,null,true,true);
       $affected['sfm_cleanup_freed_bytes']=(int)($cleanupRes['freed_bytes'] ?? 0);
       $affected['sfm_cleanup_errors']=count($cleanupRes['errors'] ?? []);
       $dbcnx->commit();
       $storageOk=safe_rrmdir($storagePath,$allowedBase);
       audit_log($userId,'CAPTURE_SESSION_DELETED','TOUR_ORDER',$orderId,'Сессия удалена из web',['order_id'=>$orderId,'capture_session_id'=>$captureSessionId,'app_session_uuid'=>$appSessionUuid,'storage_path'=>$storagePath,'deleted_files_ok'=>$storageOk,'affected'=>$affected,'user_id'=>$userId,'remote_job_ids'=>array_values(array_unique($remoteJobIds)),'remote_outputs_removed'=>true,'sfm_cleanup'=>$cleanupRes ?? null]);
       header('Location: /order.php?id='.$orderId.'&session_deleted=1'); exit;
     }catch(Throwable $e){ $dbcnx->rollback(); $error=$e->getMessage(); }
   }
 }
}
$order=load_order($dbcnx,$orderId); $order['status_meta']=status_meta((string)$order['status']);

$captureSessions=[];$videoScans=[];$capturePoints=[];$photoPoints=[];

$stmt=$dbcnx->prepare("SELECT * FROM capture_sessions WHERE order_id=? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC");
if($stmt){
  $stmt->bind_param('i',$orderId);
  $stmt->execute();
  $rs=$stmt->get_result();
  while($row=$rs->fetch_assoc()){
    $row['photos']=[];
    $row['videos']=[];
    $row['photo_count']=0;
    $row['video_count']=0;
    $captureSessions[]=$row;
  }
  $stmt->close();
}
$sessionById=[];
foreach($captureSessions as $k=>$s){$sessionById[(int)$s['id']]=$k;}

$stmt=$dbcnx->prepare("SELECT pp.*, cs.app_session_uuid FROM photo_points pp JOIN capture_sessions cs ON cs.id = pp.session_id WHERE cs.order_id = ? AND pp.deleted_at IS NULL AND cs.deleted_at IS NULL AND COALESCE(pp.upload_state,'') <> 'DELETED' ORDER BY cs.created_at DESC, pp.sequence_number ASC, pp.created_at ASC, pp.id ASC");
if($stmt){
  $stmt->bind_param('i',$orderId);
  $stmt->execute();
  $rs=$stmt->get_result();
  while($p=$rs->fetch_assoc()){
    $p['display_name']=($p['name'] ?? '')!=='' ? $p['name'] : ((($p['title'] ?? '')!=='') ? $p['title'] : ('Photo #'.$p['id']));
    $previewPath=(string)($p['preview_storage_path'] ?? ''); if($previewPath===''){$previewPath=(string)($p['preview_path'] ?? '');}
    $originalPath=(string)($p['original_storage_path'] ?? ''); if($originalPath===''){$originalPath=(string)($p['original_path'] ?? '');}
    $p['preview_effective_path']=$previewPath;
    $p['original_effective_path']=$originalPath;
    $p['preview_url']=$previewPath!==''?('/media.php?path='.rawurlencode($previewPath)):'';
    $p['original_url']=$originalPath!==''?('/media.php?path='.rawurlencode($originalPath)):'';
    $p['preview_size_human']=bytes_human((float)($p['preview_size_bytes'] ?? 0));
    $p['original_size_human']=bytes_human((float)($p['original_size_bytes'] ?? 0));
    $photoPoints[]=$p;
    $capturePoints[]=$p;
    $sid=(int)$p['session_id'];
    if(isset($sessionById[$sid])){ $idx=$sessionById[$sid]; $captureSessions[$idx]['photos'][]=$p; $captureSessions[$idx]['photo_count']++; }
  }
  $stmt->close();
}

$videoDeletedFilter=column_exists($dbcnx,'video_scans','upload_state')?" AND COALESCE(vs.upload_state,'') <> 'DELETED'":'';
$stmt=$dbcnx->prepare("SELECT vs.*, cs.app_session_uuid FROM video_scans vs JOIN capture_sessions cs ON cs.id = vs.session_id WHERE cs.order_id = ? AND vs.deleted_at IS NULL AND cs.deleted_at IS NULL".$videoDeletedFilter." ORDER BY cs.created_at DESC, vs.created_at DESC, vs.id DESC");
if($stmt){
  $stmt->bind_param('i',$orderId);
  $stmt->execute();
  $rs=$stmt->get_result();
  while($v=$rs->fetch_assoc()){
    $v['media_url']=(!empty($v['storage_path']))?('/media.php?path='.rawurlencode((string)$v['storage_path'])):'';
    $v['size_human']=bytes_human((float)($v['size_bytes'] ?? 0));
    $videoScans[]=$v;
    $sid=(int)$v['session_id'];
    if(isset($sessionById[$sid])){ $idx=$sessionById[$sid]; $captureSessions[$idx]['videos'][]=$v; $captureSessions[$idx]['video_count']++; }
  }
  $stmt->close();
}


$processingJobsBySession = [];
$stmt=$dbcnx->prepare("SELECT * FROM processing_jobs WHERE order_id = ? AND session_id IN (SELECT id FROM capture_sessions WHERE order_id = ? AND deleted_at IS NULL) ORDER BY created_at DESC, id DESC");
if($stmt){
  $stmt->bind_param('ii',$orderId,$orderId);
  $stmt->execute();
  $rs=$stmt->get_result();
  while($job=$rs->fetch_assoc()){
    $sid=(int)$job['session_id'];
    if(!isset($processingJobsBySession[$sid])){
      $processingJobsBySession[$sid]=$job;
    }
  }
  $stmt->close();
}

$markerDetectionsBySession = [];
$stmt=$dbcnx->prepare("SELECT * FROM marker_detections WHERE session_id IN (SELECT id FROM capture_sessions WHERE order_id = ? AND deleted_at IS NULL) ORDER BY id DESC");
if($stmt){
  $stmt->bind_param('i',$orderId);
  $stmt->execute();
  $rs=$stmt->get_result();
  while($d=$rs->fetch_assoc()){
    $sid=(int)$d['session_id'];
    if(!isset($markerDetectionsBySession[$sid])){ $markerDetectionsBySession[$sid]=[]; }
    $markerDetectionsBySession[$sid][]=$d;
  }
  $stmt->close();
}

$sfmPipelineRunsBySession=[];$sfmPipelineRunsBySessionVideoMode=[];$sfmLegacyRunsBySession=[];
$stmt=$dbcnx->prepare("SELECT r.*, vs.filename AS source_filename, vs.created_at AS source_created_at, vs.duration_sec AS source_duration_sec, vs.size_bytes AS source_size_bytes, vs.deleted_at AS source_deleted_at FROM sfm_pipeline_runs r LEFT JOIN video_scans vs ON vs.id=r.video_scan_id WHERE r.order_id=? ORDER BY r.created_at DESC, r.id DESC");
if($stmt){ $stmt->bind_param('i',$orderId); $stmt->execute(); $rs=$stmt->get_result(); while($r=$rs->fetch_assoc()){ $sid=(int)$r['capture_session_id']; $vid=(int)($r['video_scan_id'] ?? 0); $mode=(string)($r['pipeline_mode'] ?? ''); if(!isset($sfmPipelineRunsBySession[$sid])){$sfmPipelineRunsBySession[$sid]=[];} $r['log_url']='/api/sfm_pipeline_log.php?pipeline_run_id='.(int)$r['id']; $r['download_log_url']=$r['log_url'].'&download=1'; $r['point_cloud_url']=$r['output_point_cloud_path']?('/api/sfm_pipeline_log.php?pipeline_run_id='.(int)$r['id'].'&file=point_cloud'):''; $r['mesh_url']=$r['output_mesh_path']?('/api/sfm_pipeline_log.php?pipeline_run_id='.(int)$r['id'].'&file=mesh'):''; $r['display_message']=strtoupper((string)($r['status'] ?? ''))==='ERROR'?sfm_pipeline_error_message($r):(string)($r['message'] ?? ''); $r['source_filename']=$r['source_filename'] ?: (sfm_json_array((string)($r['parameters_json'] ?? '{}'))['source_video']['filename'] ?? 'Source video unknown'); $r['source_duration_sec']=(float)($r['source_duration_sec'] ?? (sfm_json_array((string)($r['parameters_json'] ?? '{}'))['source_video']['duration_sec'] ?? 0)); $r['source_size_human']=bytes_human((float)($r['source_size_bytes'] ?? 0)); $hasOutputFiles=((!empty($r['output_point_cloud_path']) && is_file((string)$r['output_point_cloud_path'])) || (!empty($r['output_mesh_path']) && is_file((string)$r['output_mesh_path']))); $r['artifacts_cleaned']=!empty($r['artifacts_deleted_at']) && !$hasOutputFiles; $staleTs=strtotime((string)($r['updated_at'] ?? $r['created_at'] ?? '')); $r['restart_stale_no_active']=in_array(strtoupper((string)($r['status'] ?? '')),['RESTARTING','CANCELLING'],true) && $staleTs && $staleTs < time()-600; if($r['restart_stale_no_active'] && trim((string)($r['display_message'] ?? ''))===''){ $r['display_message']='Restart failed before new run was created'; } if($r['artifacts_cleaned'] && trim((string)($r['display_message'] ?? ''))===''){ $r['display_message']='Artifacts for this old run were cleaned to free disk space. Please rerun reconstruction if needed.'; } $sfmPipelineRunsBySession[$sid][]=$r; if($vid>0 && $mode!==''){ $sfmPipelineRunsBySessionVideoMode[$sid][$vid][$mode][]=$r; } else { $sfmLegacyRunsBySession[$sid][]=$r; } } $stmt->close(); }

$sfmJobsBySession=[];
$stmt=$dbcnx->prepare("SELECT * FROM sfm_remote_jobs WHERE order_id=? ORDER BY created_at DESC, id DESC");
if($stmt){ $stmt->bind_param('i',$orderId); $stmt->execute(); $rs=$stmt->get_result(); while($j=$rs->fetch_assoc()){ $sid=(int)$j['capture_session_id']; if(!isset($sfmJobsBySession[$sid])){$sfmJobsBySession[$sid]=[];} $j['status_url']='/api/sfm_remote_job_status.php?job_id='.(int)$j['id']; $j['status_json_url']='/api/sfm_remote_job_file.php?job_id='.(int)$j['id'].'&type=status'; $j['result_json_url']='/api/sfm_remote_job_file.php?job_id='.(int)$j['id'].'&type=result'; $j['logs_url']='/api/sfm_remote_job_file.php?job_id='.(int)$j['id'].'&type=logs'; $j['ply_url']=$j['status_url'].'&file=ply'; $j['mesh_final_url']=$j['status_url'].'&file=ply'; $j['mesh_poisson_url']=$j['status_url'].'&file=ply&mesh=poisson'; $j['mesh_cleaned_url']=$j['status_url'].'&file=ply&mesh=cleaned'; $j['dense_model_ids']=[0,1]; $j['sparse_model_stats']=[]; $j['ui_model_id']=null; if((string)$j['job_type']==='COLMAP_MESH'){ $mr=sfm_remote_output_dir((int)$j['remote_job_id']).'/mesh/mesh_result.json'; $md=is_file($mr)?(json_decode((string)file_get_contents($mr),true)?:[]):[]; $mi=sfm_mesh_ply_info((int)$j['remote_job_id']); $j['mesh_engine']=$md['engine']??''; $j['mesh_vertices']=$md['vertices']??($md['mesh_vertices']??($mi['vertices']?:'')); $j['mesh_faces']=$md['faces']??($md['mesh_faces']??($mi['faces']?:'')); $j['mesh_mode']=$md['mode']??($j['reconstruction_mode']??''); $j['mesh_fallback']=!empty($md['fallback_used']); $j['mesh_duration_sec']=$md['duration_sec']??($md['duration']??''); $j['ui_can_download_mesh']=strtoupper((string)$j['status'])==='DONE' && $mi['downloadable']; } $params=json_decode((string)($j['parameters_json'] ?? '{}'), true); if(is_array($params) && array_key_exists('model_id',$params)){ $j['ui_model_id']=(int)$params['model_id']; } if((string)$j['job_type']==='COLMAP_SPARSE'){ foreach($j['dense_model_ids'] as $mid){ $j['sparse_model_stats'][(int)$mid]=sfm_sparse_model_stats((int)$j['remote_job_id'],(int)$mid); } } $sfmJobsBySession[$sid][]=$j; } $stmt->close(); }

foreach($captureSessions as $idx=>$session){
  $safeUuid=sfm_safe_uuid((string)($session['app_session_uuid'] ?? ''));
  $videoDir=sfm_session_videos_dir($orderId,(string)($session['app_session_uuid'] ?? ''));
  $diskVideos=[];
  $videosByFilename=[];
  foreach(($session['videos'] ?? []) as $scanRow){ $videosByFilename[(string)($scanRow['filename'] ?? '')]=$scanRow; }
  $realVideoDir=realpath($videoDir);
  if($realVideoDir!==false && is_dir($realVideoDir)){
    foreach(array_merge(glob($realVideoDir.'/*.mp4') ?: [], glob($realVideoDir.'/*.mov') ?: [], glob($realVideoDir.'/*.m4v') ?: []) as $vf){
      $rv=realpath($vf);
      if($rv!==false && strpos($rv,$realVideoDir.'/')===0){
        $filename=basename($rv);
        $scanRow=$videosByFilename[$filename] ?? null;
        $metadata=['camera_info'=>['exists'=>false,'url'=>'','label'=>'View camera_info'],'manifest'=>['exists'=>false,'url'=>'','label'=>'View manifest'],'imu'=>['exists'=>false,'url'=>'','label'=>'Download imu']];
        if($scanRow){ $metadata=video_scan_metadata_info((int)$scanRow['id'],(string)($scanRow['app_scan_uuid'] ?? ''),$realVideoDir); }
        $diskVideos[]=['filename'=>$filename,'path'=>$rv,'scan'=>$scanRow,'video_scan_id'=>$scanRow?(int)$scanRow['id']:0,'is_orphan'=>!$scanRow,'size_human'=>bytes_human((float)filesize($rv)),'modified_at'=>date('Y-m-d H:i:s',(int)filemtime($rv)),'uploaded_at'=>$scanRow?((string)($scanRow['created_at'] ?? '')):date('Y-m-d H:i:s',(int)filemtime($rv)),'duration_sec'=>$scanRow?(float)($scanRow['duration_sec'] ?? 0):0,'fps'=>0,'metadata'=>$metadata,'source_origin'=>$scanRow?(string)($scanRow['source_origin'] ?? ''):'','source_type'=>$scanRow?(string)($scanRow['source_type'] ?? ''):'','camera_profile'=>$scanRow?(string)($scanRow['camera_profile'] ?? ''):'','label'=>$scanRow?(string)($scanRow['label'] ?? ($scanRow['comment'] ?? '')):'','imu_available'=>$metadata['imu']['exists']];
      }
    }
  }
    $sessionSfmJobs=$sfmJobsBySession[(int)$session['id']] ?? [];
  foreach($diskVideos as $dvIdx=>$dv){
    $related=[]; $relatedRemoteIds=[]; $changed=true;
    while($changed){
      $changed=false;
      foreach($sessionSfmJobs as $job){
        $rid=(int)($job['remote_job_id'] ?? 0);
        if($rid<=0 || isset($relatedRemoteIds[$rid])){ continue; }
        $isVideoRoot=((string)($job['job_type'] ?? '')==='EXTRACT_FRAMES' && (string)($job['input_path'] ?? '') === (string)$dv['path']);
        $isChild=!empty($job['parent_remote_job_id']) && isset($relatedRemoteIds[(int)$job['parent_remote_job_id']]);
        if($isVideoRoot || $isChild){ $related[]=$job; $relatedRemoteIds[$rid]=true; $changed=true; }
      }
    }
    $active=false; $done=false; $failed=false;
    foreach($related as $job){
      $st=strtoupper((string)($job['status'] ?? ''));
      if(in_array($st,['QUEUED','RUNNING'],true)){$active=true;}
      if($st==='DONE'){$done=true;}
      if(in_array($st,['ERROR','FAILED'],true)){$failed=true;}
    }
    $diskVideos[$dvIdx]['auto_sfm_jobs']=$related;
    $diskVideos[$dvIdx]['auto_sfm_has_jobs']=count($related)>0;
    $diskVideos[$dvIdx]['auto_sfm_badge']=$failed?'Automatic pipeline: failed':($active?'Automatic upload pipeline: running':($done?'Automatic upload pipeline: completed':''));
    $diskVideos[$dvIdx]['auto_sfm_can_manual']=!$active;
  }
  $captureSessions[$idx]['sfm_disk_videos']=$diskVideos;
  $captureSessions[$idx]['sfm_remote_jobs']=$sessionSfmJobs;
  $captureSessions[$idx]['sfm_pipeline']=sfm_enrich_session_jobs($sessionSfmJobs);
  $runs=$sfmPipelineRunsBySession[(int)$session['id']] ?? [];
  foreach($diskVideos as $dvIdx=>$dv){ $vid=(int)($dv['video_scan_id'] ?? 0); $cards=[]; foreach(sfm_pipeline_modes() as $mode){ $preset=sfm_pipeline_preset($mode); $history=$vid>0?($sfmPipelineRunsBySessionVideoMode[(int)$session['id']][$vid][$mode] ?? []):[]; $latest=$history[0] ?? null; if($latest){ $latest['artifacts']=sfm_build_pipeline_artifacts($latest,$sessionSfmJobs); $rp=sfm_json_array((string)($latest['parameters_json'] ?? '{}')); $latest['ui_parameters']=isset($rp['mode_parameters'])?$rp['mode_parameters']:sfm_mode_parameters(sfm_merge_settings(sfm_system_defaults(),[],[],$rp),(string)$latest['pipeline_mode']); $ef=(int)($latest['extracted_frames'] ?? 0); $ri=(int)($latest['registered_images'] ?? 0); $latest['ui_registration_ratio']=$ef>0?round($ri*100/$ef,1):(float)($latest['registration_ratio'] ?? 0); } $cards[$mode]=['mode'=>$mode,'preset'=>$preset,'run'=>$latest,'history'=>$history]; } $diskVideos[$dvIdx]['sfm_pipeline_cards']=$cards; }
  $captureSessions[$idx]['sfm_disk_videos']=$diskVideos;
  $captureSessions[$idx]['sfm_legacy_pipeline_runs']=$sfmLegacyRunsBySession[(int)$session['id']] ?? [];
  $captureSessions[$idx]['sfm_multi_video_available']=count(array_filter($diskVideos,fn($v)=>empty($v['is_orphan']) && (int)($v['video_scan_id'] ?? 0)>0))>1;
  $sys=sfm_system_defaults(); $usr=sfm_load_user_settings($dbcnx,$userId); $ses=sfm_load_session_settings($dbcnx,(int)$session['id'],$userId);
  $captureSessions[$idx]['sfm_video_metadata']=['duration_sec'=>0,'fps'=>0];
  $captureSessions[$idx]['sfm_settings']=['system_defaults'=>$sys,'user_defaults'=>$usr,'session_overrides'=>$ses,'quality_profiles'=>sfm_quality_profiles(),'effective_settings'=>sfm_merge_settings($sys,$usr,$ses,[]),'api_url'=>'/api/sfm_settings.php?capture_session_id='.(int)$session['id']];
  $sid=(int)$session['id'];
  $captureSessions[$idx]['processing_job']=$processingJobsBySession[$sid] ?? null;
  $job = $captureSessions[$idx]['processing_job'] ?? null;
  $jobStatus = strtoupper((string)($job['status'] ?? ''));
  $isProcessing = in_array($jobStatus, ['QUEUED','PROCESSING'], true);
  $captureSessions[$idx]['is_processing'] = $isProcessing;
  $captureSessions[$idx]['processing_label'] = $jobStatus === 'QUEUED' ? 'Ожидает обработки' : ($jobStatus === 'PROCESSING' ? 'Обрабатывается' : '');
  $captureSessions[$idx]['processing_failed'] = $jobStatus === 'FAILED';
  
  $detections = $markerDetectionsBySession[$sid] ?? [];
  $unique=[];
  $sourceCounts=['PHOTO_POINT'=>0,'VIDEO_FRAME'=>0];
  foreach($detections as $det){
    $mid=(int)($det['marker_id'] ?? 0);
    if($mid>0){ $unique[$mid]=true; }
    $stype=(string)($det['source_type'] ?? '');
    if(isset($sourceCounts[$stype])){ $sourceCounts[$stype]++; }
  }
  $markerIds=array_keys($unique);
  sort($markerIds);
  $captureSessions[$idx]['marker_detections']=$detections;
  $captureSessions[$idx]['marker_unique_ids']=$markerIds;
  $captureSessions[$idx]['marker_detections_count']=count($detections);
  $captureSessions[$idx]['marker_source_counts']=$sourceCounts;
}

$publicLinksBySession=[];
$publicLinksBySession = [];

$hasPublicTourLinks = false;
$res = $dbcnx->query("SHOW TABLES LIKE 'public_tour_links'");
if ($res) {
    $hasPublicTourLinks = $res->num_rows > 0;
    $res->close();
}

if ($hasPublicTourLinks) {
    $stmt = $dbcnx->prepare("SELECT session_id, token, is_active FROM public_tour_links WHERE order_id=? ORDER BY id DESC");
    if ($stmt) {
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $sid = (int)$r['session_id'];
            if (!isset($publicLinksBySession[$sid])) {
                $publicLinksBySession[$sid] = $r;
            }
        }
        $stmt->close();
    }
}

$debugLinksBySession=[];
sfm_debug_public_ensure_schema($dbcnx);
$stmt=$dbcnx->prepare("SELECT * FROM sfm_debug_public_links WHERE order_id=? ORDER BY id DESC");
if($stmt){ $stmt->bind_param('i',$orderId); $stmt->execute(); $rs=$stmt->get_result(); while($r=$rs->fetch_assoc()){ $sid=(int)$r['capture_session_id']; if(!isset($debugLinksBySession[$sid])){ $r['status']=!empty($r['revoked_at'])?'Revoked':((!empty($r['expires_at']) && strtotime((string)$r['expires_at'])<=time())?'Expired':'Active'); $debugLinksBySession[$sid]=$r; } } $stmt->close(); }
foreach ($captureSessions as $idx => $session) {
    $sid = (int)$session['id'];
    $captureSessions[$idx]['public_link'] = $publicLinksBySession[$sid] ?? null;
    $captureSessions[$idx]['debug_public_link'] = $debugLinksBySession[$sid] ?? null;
}


$mediaTotals=['sessions'=>count($captureSessions),'photos'=>count($photoPoints),'videos'=>count($videoScans)];

$smarty->assign('current_user',$user);
$smarty->assign('order',$order);
$smarty->assign('canEdit',$canEdit);
$smarty->assign('canEditOrderInfo',$canEditOrderInfo);
$smarty->assign('canDeleteMedia',$canDeleteMedia);
$smarty->assign('canDeleteCaptureSession',$canDeleteCaptureSession);
$smarty->assign('canCreatePublicLink', $canCreatePublicLink);
$smarty->assign('isAdminDebug', $role==='ADMIN');
$smarty->assign('canOperatorClose',$canOperatorClose);
$smarty->assign('canBrokerClose',$canBrokerClose);
$smarty->assign('canReopen',$canReopen);
$smarty->assign('isOrderClosedForEditing',$isOrderClosedForEditing);
$smarty->assign('captureSessions',$captureSessions);
$smarty->assign('photoPoints',$photoPoints);
$smarty->assign('capturePoints',$capturePoints);
$smarty->assign('videoScans',$videoScans);
$smarty->assign('mediaTotals',$mediaTotals);
$smarty->assign('error',$error);
$smarty->assign('success',$success);
$smarty->display('maklertour_order.html');
