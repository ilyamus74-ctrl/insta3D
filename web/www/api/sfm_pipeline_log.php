<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/remote_station/sfm_pipeline.php';
auth_require_login();
$user=auth_current_user(); $userId=(int)$user['id']; $role=(string)($user['role'] ?? 'BROKER');
ensure_sfm_pipeline_tables($dbcnx);
$pipelineRunId=(int)($_GET['pipeline_run_id'] ?? 0);
if($pipelineRunId<=0){ http_response_code(400); exit('Bad pipeline_run_id'); }
$st=$dbcnx->prepare('SELECT r.*, o.broker_id, o.operator_id FROM sfm_pipeline_runs r JOIN tour_orders o ON o.id=r.order_id WHERE r.id=? LIMIT 1');
if(!$st){ http_response_code(500); exit('DB prepare error'); }
$st->bind_param('i',$pipelineRunId); $st->execute(); $run=$st->get_result()->fetch_assoc(); $st->close();
if(!$run){ http_response_code(404); exit('Pipeline run not found'); }
$can=$role==='ADMIN' || (int)$run['broker_id']===$userId || ($role==='OPERATOR' && (int)$run['operator_id']===$userId);
if(!$can){ http_response_code(403); exit('Forbidden'); }
$file=(string)($_GET['file'] ?? '');
if($file==='point_cloud' || $file==='mesh'){
    $path=(string)($file==='point_cloud' ? ($run['output_point_cloud_path'] ?? '') : ($run['output_mesh_path'] ?? ''));
    $base=realpath(sfm_pipeline_output_dir($pipelineRunId)); $real=$path!==''?realpath($path):false;
    if($base===false || $real===false || strpos($real, rtrim($base,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)!==0 || !is_file($real)){ http_response_code(404); exit('File not found'); }
    header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="'.basename($real).'"'); readfile($real); exit;
}
$path=(string)($run['unified_log_path'] ?? ''); if($path===''){ $path=sfm_pipeline_output_dir($pipelineRunId).'/pipeline.log'; }
if(isset($_GET['download'])){ header('Content-Type: text/plain; charset=utf-8'); header('Content-Disposition: attachment; filename="pipeline_'.$pipelineRunId.'.log"'); if(is_file($path)){ readfile($path); } exit; }
header('Content-Type: text/plain; charset=utf-8');
echo sfm_pipeline_last_log($path, 300);