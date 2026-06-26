<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/remote_station/sfm_pipeline.php';
require_once dirname(__DIR__, 2) . '/libs/sfm_debug_public_lib.php';
auth_require_login();
$user=auth_current_user(); $userId=(int)$user['id']; $role=(string)($user['role'] ?? 'BROKER');
ensure_sfm_pipeline_tables($dbcnx);
function fail_art(int $code,string $msg): never { http_response_code($code); exit($msg); }
function ply_info_art(string $path): array { $out=['valid'=>false,'vertices'=>0,'faces'=>0]; if(!is_file($path)||!is_readable($path)||filesize($path)<=100){return $out;} $fh=@fopen($path,'rb'); if(!$fh){return $out;} if(fread($fh,3)!=="ply"){fclose($fh); return $out;} rewind($fh); $ok=false; while(($line=fgets($fh))!==false){$line=trim($line); if(preg_match('/^element\s+vertex\s+(\d+)$/',$line,$m)){$out['vertices']=(int)$m[1];} if(preg_match('/^element\s+face\s+(\d+)$/',$line,$m)){$out['faces']=(int)$m[1];} if($line==='end_header'){$ok=true; break;}} fclose($fh); $out['valid']=$ok && $out['vertices']>0; return $out; }
function remote_dir_art(int $id): string { return '/home/makler/web/remote_station/output/job_'.$id; }
$pipelineRunId=(int)($_GET['pipeline_run_id'] ?? 0); $artifact=(string)($_GET['artifact'] ?? '');
if($pipelineRunId<=0 || !in_array($artifact,['sparse','dense','mesh','result','sparse_diagnostics','camera_trajectory','world_alignment','world_alignment_override'],true)){ fail_art(400,'Bad request'); }
$st=$dbcnx->prepare('SELECT r.*, o.broker_id, o.operator_id FROM sfm_pipeline_runs r JOIN tour_orders o ON o.id=r.order_id WHERE r.id=? LIMIT 1'); if(!$st){fail_art(500,'DB prepare error');}
$st->bind_param('i',$pipelineRunId); $st->execute(); $run=$st->get_result()->fetch_assoc(); $st->close(); if(!$run){fail_art(404,'Pipeline run not found');}
$can=$role==='ADMIN' || (int)$run['broker_id']===$userId || ($role==='OPERATOR' && (int)$run['operator_id']===$userId); if(!$can){fail_art(403,'Forbidden');}
$jobs=[]; $st=$dbcnx->prepare('SELECT * FROM sfm_remote_jobs WHERE pipeline_run_id=? AND order_id=? AND capture_session_id=? ORDER BY created_at DESC, id DESC'); if($st){$oid=(int)$run['order_id']; $sid=(int)$run['capture_session_id']; $st->bind_param('iii',$pipelineRunId,$oid,$sid); $st->execute(); $rs=$st->get_result(); while($j=$rs->fetch_assoc()){$jobs[]=$j;} $st->close();}
$path=''; $name=''; $ctype='application/octet-stream';
if($artifact==='result'){ $path=(string)($run['output_result_json_path'] ?? ''); $name='pipeline_'.$pipelineRunId.'_result.json'; $ctype='application/json'; }
elseif(in_array($artifact,['sparse_diagnostics','camera_trajectory','world_alignment','world_alignment_override'],true)){ $col=['sparse_diagnostics'=>'sparse_diagnostics_path','camera_trajectory'=>'camera_trajectory_path','world_alignment'=>'world_alignment_path','world_alignment_override'=>'world_alignment_path'][$artifact]; $path=(string)($run[$col] ?? ''); if($artifact==='world_alignment_override' && $path!==''){$path=dirname($path).'/world_alignment_override.json';} $name='pipeline_'.$pipelineRunId.'_'.$artifact.'.json'; $ctype='application/json'; }
else {
  $sparse=null; $recon=null; $mesh=null; foreach($jobs as $j){$jt=(string)$j['job_type']; if($jt==='COLMAP_SPARSE' && $sparse===null){$sparse=$j;} elseif(in_array($jt,['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'],true) && strtoupper((string)($j['status'] ?? ''))==='DONE' && $recon===null){$recon=$j;} elseif($jt==='COLMAP_MESH' && $mesh===null){$mesh=$j;}}
  if($artifact==='sparse' && $sparse){ $model=(int)($run['sparse_model_id'] ?? 0); if($model===0 && $recon){ $params=json_decode((string)($recon['parameters_json'] ?? '{}'), true); if(is_array($params) && array_key_exists('model_id',$params)){$model=(int)$params['model_id'];} } $path=remote_dir_art((int)$sparse['remote_job_id']).'/colmap/sparse/'.$model.'/model.ply'; $name='pipeline_'.$pipelineRunId.'_sparse_model_'.$model.'.ply'; }
  if($artifact==='dense'){ $resolvedDense=sfm_debug_public_resolve_dense_ply_path($run,$jobs); $path=$resolvedDense ?? ''; $name='pipeline_'.$pipelineRunId.'_dense_point_cloud.ply'; }
  if($artifact==='mesh' && $mesh){ $path=remote_dir_art((int)$mesh['remote_job_id']).'/mesh/mesh_final.ply'; $name='pipeline_'.$pipelineRunId.'_final_mesh.ply'; }
  $ctype='application/octet-stream';
}
if($path===''){fail_art(404,'Artifact not found');}
$allowed=array_filter([realpath('/home/makler/web/remote_station/output'), realpath('/home/makler_storage/output')]); $real=realpath($path); if($real===false || !is_file($real)){fail_art(404,'Artifact not found');}
$inside=false; foreach($allowed as $base){$base=rtrim($base,DIRECTORY_SEPARATOR); if($real===$base || strpos($real,$base.DIRECTORY_SEPARATOR)===0){$inside=true; break;}} if(!$inside){fail_art(403,'Forbidden path');}
if(!in_array($artifact,['result','sparse_diagnostics','camera_trajectory','world_alignment','world_alignment_override'],true)){ $pi=ply_info_art($real); if(!$pi['valid']){fail_art(404,'PLY artifact is empty or invalid');} if($artifact==='mesh' && $pi['faces']<=0){fail_art(404,'Mesh artifact has no faces');} }
if(filesize($real)<=0){fail_art(404,'Artifact is empty');}
header('Content-Type: '.$ctype); header('Content-Length: '.filesize($real)); header('Content-Disposition: attachment; filename="'.str_replace('"','',$name).'"'); readfile($real);