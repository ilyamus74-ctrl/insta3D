<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
auth_require_login();

$user = auth_current_user();
$userId = (int)$user['id'];
$role = (string)($user['role'] ?? 'BROKER');

function api3d_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function can_view_order(array $order, int $userId, string $role): bool {
    return $role === 'ADMIN' || ((int)$order['broker_id'] === $userId)
        || ($role === 'OPERATOR' && ((int)$order['operator_id'] === $userId || ((int)$order['is_published'] === 1 && (string)$order['status'] === 'NEW' && $order['operator_id'] === null)));
}

$orderId = filter_var((string)($_GET['order_id'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sessionId = filter_var((string)($_GET['session_id'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($orderId === false || $sessionId === false) api3d_json(['ok'=>false,'error'=>'bad_params'],400);
$orderId=(int)$orderId; $sessionId=(int)$sessionId;

$stmt = $dbcnx->prepare('SELECT id, broker_id, operator_id, is_published, status FROM tour_orders WHERE id = ? LIMIT 1');
if (!$stmt) api3d_json(['ok'=>false,'error'=>'db_prepare_order_failed'],500);
$stmt->bind_param('i', $orderId); $stmt->execute(); $order = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$order) api3d_json(['ok'=>false,'error'=>'order_not_found'],404);
if (!can_view_order($order, $userId, $role)) api3d_json(['ok'=>false,'error'=>'forbidden'],403);

$stmt = $dbcnx->prepare('SELECT session_dir FROM video_sfm_runs WHERE order_id = ? AND session_id = ? ORDER BY id DESC LIMIT 1');
if (!$stmt) api3d_json(['ok'=>false,'error'=>'db_prepare_run_failed'],500);
$stmt->bind_param('ii', $orderId, $sessionId); $stmt->execute(); $run = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$run) api3d_json(['ok'=>false,'error'=>'sfm_run_not_found'],404);

$sessionDir = trim((string)($run['session_dir'] ?? ''));
if ($sessionDir === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $sessionDir)) api3d_json(['ok'=>false,'error'=>'invalid_session_dir'],500);

$base = '/home/makler/web/storage/orders/' . $orderId . '/sessions/' . $sessionDir . '/sfm/3d';
$summaryPath = $base . '/sfm_3d_summary.json';
$plyPath = $base . '/sparse_points.ply';
$trajPath = $base . '/camera_trajectory.json';
$keyPath = $base . '/keyframe_points_3d.json';
if (!is_file($summaryPath) || !is_file($plyPath) || !is_file($trajPath) || !is_file($keyPath)) api3d_json(['ok'=>false,'error'=>'artifacts_missing'],404);

$summary = json_decode((string)file_get_contents($summaryPath), true);
if (!is_array($summary)) api3d_json(['ok'=>false,'error'=>'bad_summary'],500);

$prefix = 'orders/' . $orderId . '/sessions/' . $sessionDir . '/sfm/3d/';
api3d_json([
    'ok' => true,
    'summary' => $summary,
    'artifacts' => [
        'sparse_points_ply_url' => '/media.php?path=' . rawurlencode($prefix . 'sparse_points.ply'),
        'camera_trajectory_url' => '/media.php?path=' . rawurlencode($prefix . 'camera_trajectory.json'),
        'keyframe_points_url' => '/media.php?path=' . rawurlencode($prefix . 'keyframe_points_3d.json'),
    ],
]);
