<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$token=trim((string)($_GET['token']??''));
$path=ltrim(trim((string)($_GET['path']??'')),'/');
if($token===''||$path===''){ http_response_code(400); exit('Bad request'); }
$stmt=$dbcnx->prepare("SELECT ptl.order_id,ptl.session_id,cs.app_session_uuid FROM public_tour_links ptl JOIN capture_sessions cs ON cs.id=ptl.session_id WHERE ptl.token=? AND ptl.is_active=1 AND (ptl.expires_at IS NULL OR ptl.expires_at>NOW(6)) LIMIT 1");
if(!$stmt){ http_response_code(500); exit('DB'); }
$stmt->bind_param('s',$token); $stmt->execute(); $lnk=$stmt->get_result()->fetch_assoc(); $stmt->close();
if(!$lnk){ http_response_code(404); exit('Not found'); }
$prefix='orders/'.(int)$lnk['order_id'].'/sessions/'.trim((string)$lnk['app_session_uuid']).'/';
if(strncmp($path,$prefix,strlen($prefix))!==0){ http_response_code(403); exit('Forbidden'); }
$allowed=['/photos/viewer_light/','/photos/viewer_hd/','/photos/previews/'];
$ok=false; foreach($allowed as $needle){ if(strpos($path,$needle)!==false){ $ok=true; break; } }
if(!$ok){ http_response_code(403); exit('Forbidden'); }
$full=APP_STORAGE_DIR.'/'.ltrim($path,'/');
if(!is_file($full)){ http_response_code(404); exit('Not found'); }
header('Content-Type: image/jpeg');
header('Content-Length: '.(string)filesize($full));
readfile($full);
