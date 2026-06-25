<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/libs/sfm_debug_public_lib.php';
sfm_debug_public_headers();
$token=(string)($_GET['token']??''); sfm_debug_public_rate_limit($token); $link=sfm_debug_public_validate($dbcnx,$token,true);
$type=(string)($_GET['artifact_type']??''); $pid=(int)($_GET['pipeline_run_id']??0); $fileId=(string)($_GET['file_id']??'');
if($type==='debug_bundle'){
    if(!class_exists('ZipArchive')){ http_response_code(501); exit('ZipArchive unavailable'); }
    $tmp=tempnam(sys_get_temp_dir(),'debug_bundle_'); $zip=new ZipArchive(); $zip->open($tmp,ZipArchive::OVERWRITE);
    $summary=['order_id'=>(int)$link['order_id'],'capture_session_id'=>(int)$link['capture_session_id'],'app_session_uuid'=>$link['app_session_uuid'],'created_at'=>$link['session_created_at'],'processing_state'=>$link['order_status']??''];
    $zip->addFromString('session_summary.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    $runs=[]; $st=$dbcnx->prepare('SELECT id,pipeline_mode,status,stage,started_at,finished_at,parameters_json,registration_ratio,dense_points,mesh_vertices,mesh_faces FROM sfm_pipeline_runs WHERE capture_session_id=? ORDER BY id DESC'); if($st){$sid=(int)$link['capture_session_id'];$st->bind_param('i',$sid);$st->execute();$rs=$st->get_result();while($r=$rs->fetch_assoc())$runs[]=$r;$st->close();}
    $zip->addFromString('pipeline_runs.json',json_encode($runs,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    foreach($runs as $r){ foreach(['selected_frames','quality_summary','sparse_diagnostics','pipeline_log','mesh_stats','pipeline_result','dense_ply'] as $t){ $a=sfm_debug_public_artifact_path($dbcnx,$link,(int)$r['id'],$t); if($a && filesize($a['path'])<20*1024*1024) $zip->addFile($a['path'],'run_'.$r['id'].'/'.basename($a['path'])); } }
    $zip->close(); header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="debug_bundle_session_'.(int)$link['capture_session_id'].'.zip"'); header('Content-Length: '.filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
$a=sfm_debug_public_artifact_path($dbcnx,$link,$pid,$type,$fileId); if(!$a) sfm_debug_public_fail(404);
if(function_exists('audit_log')) @audit_log(0,'DEBUG_PUBLIC_ARTIFACT_DOWNLOADED','CAPTURE_SESSION',(int)$link['capture_session_id'],'Debug artifact downloaded',['link_id'=>(int)$link['id'],'pipeline_run_id'=>$pid,'artifact_type'=>$type]);
header('Content-Type: '.$a['mime']); header('Content-Length: '.filesize($a['path'])); header('Content-Disposition: '.(((int)($_GET['download']??1))===1?'attachment':'inline').'; filename="'.str_replace('"','',basename($a['name'])).'"'); readfile($a['path']);