<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
auth_require_login();
function api_json(array $payload, int $code = 200): void { http_response_code($code); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function can_view_order(array $order, int $userId, string $role): bool { return $role === 'ADMIN' || ((int)$order['broker_id'] === $userId) || ($role === 'OPERATOR' && ((int)$order['operator_id'] === $userId || ((int)$order['is_published'] === 1 && (string)$order['status'] === 'NEW' && $order['operator_id'] === null))); }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_json(['ok'=>false,'error'=>'method_not_allowed'],405);
$data = json_decode(file_get_contents('php://input') ?: '', true); if (!is_array($data)) api_json(['ok'=>false,'error'=>'bad_json'],400);
$user = auth_current_user(); $userId = (int)$user['id']; $role = (string)($user['role'] ?? 'BROKER');
$sessionId=(int)($data['session_id']??0); $fromId=(int)($data['from_photo_point_id']??0); $toId=(int)($data['to_photo_point_id']??0); $yaw=(float)($data['yaw_deg']??0); $pitch=(float)($data['pitch_deg']??0); $label=trim((string)($data['label']??''));
if ($sessionId<=0||$fromId<=0||$toId<=0) api_json(['ok'=>false,'error'=>'bad_input'],400);
$stmt=$dbcnx->prepare("SELECT cs.id,o.broker_id,o.operator_id,o.is_published,o.status AS order_status FROM capture_sessions cs JOIN tour_orders o ON o.id=cs.order_id WHERE cs.id=? LIMIT 1");
if(!$stmt) api_json(['ok'=>false,'error'=>'db_prepare_session_failed'],500);
$stmt->bind_param('i',$sessionId); $stmt->execute(); $session=$stmt->get_result()->fetch_assoc(); $stmt->close(); if(!$session) api_json(['ok'=>false,'error'=>'session_not_found'],404);
if (!can_view_order(['broker_id'=>$session['broker_id'],'operator_id'=>$session['operator_id'],'is_published'=>$session['is_published'],'status'=>$session['order_status']],$userId,$role)) api_json(['ok'=>false,'error'=>'forbidden'],403);
$stmt=$dbcnx->prepare("SELECT id FROM photo_points WHERE session_id=? AND id IN (?,?)"); if(!$stmt) api_json(['ok'=>false,'error'=>'db_prepare_points_failed'],500);
$stmt->bind_param('iii',$sessionId,$fromId,$toId); $stmt->execute(); $rs=$stmt->get_result(); $count=0; while($rs->fetch_assoc())$count++; $stmt->close(); if($count<2) api_json(['ok'=>false,'error'=>'photo_points_mismatch'],400);
$stmt=$dbcnx->prepare("UPDATE tour_point_links SET yaw_deg=?, pitch_deg=?, label=?, updated_at=NOW() WHERE session_id=? AND from_photo_point_id=? AND to_photo_point_id=?");
if(!$stmt) api_json(['ok'=>false,'error'=>'db_prepare_update_failed'],500);
$stmt->bind_param('ddsiii',$yaw,$pitch,$label,$sessionId,$fromId,$toId); $stmt->execute(); $updated=$stmt->affected_rows; $stmt->close();
if($updated<=0){$stmt=$dbcnx->prepare("INSERT INTO tour_point_links (session_id,from_photo_point_id,to_photo_point_id,yaw_deg,pitch_deg,label,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())"); if(!$stmt) api_json(['ok'=>false,'error'=>'db_prepare_insert_failed'],500); $stmt->bind_param('iiidds',$sessionId,$fromId,$toId,$yaw,$pitch,$label); if(!$stmt->execute()) api_json(['ok'=>false,'error'=>'db_insert_failed'],500); $linkId=(int)$stmt->insert_id; $stmt->close();} else { $linkId=null; }
api_json(['ok'=>true,'link'=>['id'=>$linkId,'from_photo_point_id'=>$fromId,'to_photo_point_id'=>$toId,'yaw_deg'=>$yaw,'pitch_deg'=>$pitch,'label'=>$label]]);

