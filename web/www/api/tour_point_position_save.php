<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
auth_require_login();

function api_json(array $payload, int $code = 200): void { http_response_code($code); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function can_view_order(array $order, int $userId, string $role): bool { return $role === 'ADMIN' || ((int)$order['broker_id'] === $userId) || ($role === 'OPERATOR' && ((int)$order['operator_id'] === $userId || ((int)$order['is_published'] === 1 && (string)$order['status'] === 'NEW' && $order['operator_id'] === null))); }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_json(['ok'=>false,'error'=>'method_not_allowed'],405);
$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) api_json(['ok'=>false,'error'=>'bad_json'],400);

$user = auth_current_user(); $userId = (int)$user['id']; $role = (string)($user['role'] ?? 'BROKER');
$sessionId = (int)($data['session_id'] ?? 0); $photoPointId = (int)($data['photo_point_id'] ?? 0);
$x = (float)($data['x_m'] ?? 0); $y = (float)($data['y_m'] ?? 0); $z = (float)($data['z_m'] ?? 0); $yaw = (float)($data['yaw_deg'] ?? 0);
if ($sessionId <= 0 || $photoPointId <= 0) api_json(['ok'=>false,'error'=>'bad_input'],400);

$stmt = $dbcnx->prepare("SELECT cs.id, o.broker_id, o.operator_id, o.is_published, o.status AS order_status FROM capture_sessions cs JOIN tour_orders o ON o.id = cs.order_id WHERE cs.id = ? LIMIT 1");
if (!$stmt) api_json(['ok'=>false,'error'=>'db_prepare_session_failed'],500);
$stmt->bind_param('i', $sessionId); $stmt->execute(); $session = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$session) api_json(['ok'=>false,'error'=>'session_not_found'],404);
if (!can_view_order(['broker_id'=>$session['broker_id'],'operator_id'=>$session['operator_id'],'is_published'=>$session['is_published'],'status'=>$session['order_status']], $userId, $role)) api_json(['ok'=>false,'error'=>'forbidden'],403);

$stmt = $dbcnx->prepare("SELECT id FROM photo_points WHERE id = ? AND session_id = ? LIMIT 1");
if (!$stmt) api_json(['ok'=>false,'error'=>'db_prepare_point_failed'],500);
$stmt->bind_param('ii', $photoPointId, $sessionId); $stmt->execute(); $point = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$point) api_json(['ok'=>false,'error'=>'photo_point_not_found'],404);

$stmt = $dbcnx->prepare("UPDATE tour_point_positions SET x_m=?, y_m=?, z_m=?, yaw_deg=?, source='MANUAL', updated_at=NOW() WHERE session_id=? AND photo_point_id=?");
if (!$stmt) api_json(['ok'=>false,'error'=>'db_prepare_update_failed'],500);
$stmt->bind_param('ddddii',$x,$y,$z,$yaw,$sessionId,$photoPointId); $stmt->execute(); $updated = $stmt->affected_rows; $stmt->close();
if ($updated <= 0) {
  $stmt = $dbcnx->prepare("INSERT INTO tour_point_positions (session_id, photo_point_id, x_m, y_m, z_m, yaw_deg, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'MANUAL', NOW(), NOW())");
  if (!$stmt) api_json(['ok'=>false,'error'=>'db_prepare_insert_failed'],500);
  $stmt->bind_param('iidddd',$sessionId,$photoPointId,$x,$y,$z,$yaw);
  if (!$stmt->execute()) api_json(['ok'=>false,'error'=>'db_insert_failed'],500);
  $stmt->close();
}

api_json(['ok'=>true,'position'=>['photo_point_id'=>$photoPointId,'x_m'=>$x,'y_m'=>$y,'z_m'=>$z,'yaw_deg'=>$yaw,'source'=>'MANUAL']]);
