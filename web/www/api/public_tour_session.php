<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../libs/tour_media_derivatives_lib.php';
header('Content-Type: application/json; charset=utf-8');

function api_json(array $payload, int $code = 200): void { http_response_code($code); echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function pm_url(string $token, ?string $path): string { $path=trim((string)$path); return $path==='' ? '' : '/public_media.php?token='.rawurlencode($token).'&path='.rawurlencode($path); }

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') api_json(['ok'=>false,'error'=>'bad_token'],400);

$stmt=$dbcnx->prepare("SELECT * FROM public_tour_links WHERE token=? LIMIT 1");
if(!$stmt) api_json(['ok'=>false,'error'=>'db_prepare_failed'],500);
$stmt->bind_param('s',$token); $stmt->execute(); $lnk=$stmt->get_result()->fetch_assoc(); $stmt->close();
if(!$lnk || (int)$lnk['is_active']!==1) api_json(['ok'=>false,'error'=>'not_found'],404);
if($lnk['expires_at']!==null && strtotime((string)$lnk['expires_at'])<=time()) api_json(['ok'=>false,'error'=>'expired'],410);
$sessionId=(int)$lnk['session_id'];

$stmt=$dbcnx->prepare("SELECT cs.id,cs.order_id,cs.app_session_uuid,cs.status,o.title,o.address FROM capture_sessions cs JOIN tour_orders o ON o.id=cs.order_id WHERE cs.id=? LIMIT 1");
if(!$stmt) api_json(['ok'=>false,'error'=>'db_prepare_session_failed'],500);
$stmt->bind_param('i',$sessionId); $stmt->execute(); $session=$stmt->get_result()->fetch_assoc(); $stmt->close();
if(!$session) api_json(['ok'=>false,'error'=>'session_not_found'],404);

$photoPoints=[]; $stmt=$dbcnx->prepare("SELECT id,name,room_name,sequence_number,preview_storage_path,original_storage_path,initial_yaw_deg,initial_pitch_deg,initial_hfov FROM photo_points WHERE session_id=? ORDER BY COALESCE(sequence_number,999999),created_at,id");
if(!$stmt) api_json(['ok'=>false,'error'=>'db_prepare_photo_failed'],500);
$stmt->bind_param('i',$sessionId); $stmt->execute(); $rs=$stmt->get_result();
while($p=$rs->fetch_assoc()){
  $orig=(string)($p['original_storage_path']??'');
  $light=tour_viewer_variant_path_from_original($orig,'viewer_light');
  $hd=tour_viewer_variant_path_from_original($orig,'viewer_hd');
  if($orig!=='' && ($light!=='' || $hd!=='')) {
    $ensure=tour_ensure_photo_viewer_derivatives($orig,false);
    if(!$ensure['ok']){
      error_log('public_tour_session derivatives warning session_id='.(string)$sessionId.' photo_point_id='.(string)$p['id'].' original_storage_path='.$orig.' errors='.json_encode($ensure['errors'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
  }
  $lightExists=($light!=='') && is_file(tour_storage_abs_path($light)) && (int)filesize(tour_storage_abs_path($light))>0;
  $hdExists=($hd!=='') && is_file(tour_storage_abs_path($hd)) && (int)filesize(tour_storage_abs_path($hd))>0;
  $pan=$lightExists ? $light : ($hdExists ? $hd : '');
  $photoPoints[]=[
    'id'=>(int)$p['id'], 'name'=>(string)($p['name']?:('Point #'.$p['id'])), 'room_name'=>$p['room_name'],
    'sequence_number'=>$p['sequence_number']!==null?(int)$p['sequence_number']:null,
    'preview_url'=>pm_url($token,$p['preview_storage_path']??''),
    'panorama_light_url'=>pm_url($token,$lightExists?$light:''), 'panorama_hd_url'=>pm_url($token,$hdExists?$hd:''), 'panorama_url'=>pm_url($token,$pan),
    'initial_yaw_deg'=>isset($p['initial_yaw_deg'])?(float)$p['initial_yaw_deg']:0.0,
    'initial_pitch_deg'=>isset($p['initial_pitch_deg'])?(float)$p['initial_pitch_deg']:0.0,
    'initial_hfov'=>isset($p['initial_hfov'])?(float)$p['initial_hfov']:100.0,
    'markers'=>[], 'marker_labels'=>[], 'marker_detections_count'=>0, 'avg_marker_confidence'=>0.0, 'detected_markers'=>[]
  ];
  if(!$lightExists && !$hdExists){ $photoPoints[count($photoPoints)-1]['panorama_missing_reason']='viewer_derivatives_missing'; }
}
$stmt->close();
$links=[]; $stmt=$dbcnx->prepare("SELECT id,from_photo_point_id,to_photo_point_id,yaw_deg,pitch_deg,target_yaw_deg,target_pitch_deg,target_hfov,label,source,shared_markers_json,confidence FROM tour_point_links WHERE session_id=? ORDER BY id");
if($stmt){ $stmt->bind_param('i',$sessionId); $stmt->execute(); $rs=$stmt->get_result(); while($r=$rs->fetch_assoc()){$links[]=['id'=>(int)$r['id'],'session_id'=>$sessionId,'from_photo_point_id'=>(int)$r['from_photo_point_id'],'to_photo_point_id'=>(int)$r['to_photo_point_id'],'yaw_deg'=>(float)$r['yaw_deg'],'pitch_deg'=>(float)$r['pitch_deg'],'target_yaw_deg'=>$r['target_yaw_deg']!==null?(float)$r['target_yaw_deg']:null,'target_pitch_deg'=>$r['target_pitch_deg']!==null?(float)$r['target_pitch_deg']:null,'target_hfov'=>$r['target_hfov']!==null?(float)$r['target_hfov']:null,'label'=>(string)($r['label']??''),'source'=>(string)($r['source']??'MANUAL'),'shared_markers'=>(json_decode((string)($r['shared_markers_json']??'[]'),true)?:[]),'confidence'=>isset($r['confidence'])?(float)$r['confidence']:null]; } $stmt->close(); }
$positions=[]; $stmt=$dbcnx->prepare("SELECT photo_point_id,x_m,y_m,z_m,yaw_deg,source FROM tour_point_positions WHERE session_id=?");
if($stmt){ $stmt->bind_param('i',$sessionId); $stmt->execute(); $rs=$stmt->get_result(); while($r=$rs->fetch_assoc()){ $pid=(int)$r['photo_point_id']; $positions[(string)$pid]=['photo_point_id'=>$pid,'x_m'=>(float)$r['x_m'],'y_m'=>(float)$r['y_m'],'z_m'=>(float)$r['z_m'],'yaw_deg'=>(float)$r['yaw_deg'],'source'=>(string)($r['source']??'UNKNOWN')]; } $stmt->close(); }

api_json(['ok'=>true,'public'=>true,'session'=>$session,'photo_points'=>$photoPoints,'links'=>$links,'positions'=>$positions,'settings'=>['show_marker_hotspots'=>false,'allow_debug'=>false,'default_quality'=>'light']]);
