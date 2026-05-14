<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function api_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function can_view_order(array $order, int $userId, string $role): bool {
    return $role === 'ADMIN'
        || ((int)$order['broker_id'] === $userId)
        || (
            $role === 'OPERATOR'
            && (
                (int)$order['operator_id'] === $userId
                || (
                    (int)$order['is_published'] === 1
                    && (string)$order['status'] === 'NEW'
                    && $order['operator_id'] === null
                )
            )
        );
}

auth_require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
$data = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($data)) api_json(['ok' => false, 'error' => 'bad_json'], 400);

$sessionId = (int)($data['session_id'] ?? 0);
$linkId = (int)($data['link_id'] ?? 0);
if ($sessionId <= 0 || $linkId <= 0) api_json(['ok' => false, 'error' => 'bad_input'], 400);

$user = auth_current_user();
$userId = (int)$user['id'];
$role = (string)($user['role'] ?? 'BROKER');

$stmt = $dbcnx->prepare("SELECT cs.id,o.broker_id,o.operator_id,o.is_published,o.status AS order_status FROM capture_sessions cs JOIN tour_orders o ON o.id = cs.order_id WHERE cs.id = ? LIMIT 1");
if (!$stmt) api_json(['ok' => false, 'error' => 'db_prepare_session_failed'], 500);
$stmt->bind_param('i', $sessionId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$session) api_json(['ok' => false, 'error' => 'session_not_found'], 404);
if (!can_view_order(['broker_id' => $session['broker_id'], 'operator_id' => $session['operator_id'], 'is_published' => $session['is_published'], 'status' => $session['order_status']], $userId, $role)) api_json(['ok' => false, 'error' => 'forbidden'], 403);

$stmt = $dbcnx->prepare("DELETE FROM tour_point_links WHERE id = ? AND session_id = ? LIMIT 1");
if (!$stmt) api_json(['ok' => false, 'error' => 'db_prepare_delete_failed'], 500);
$stmt->bind_param('ii', $linkId, $sessionId);
if (!$stmt->execute()) {
    $stmt->close();
    api_json(['ok' => false, 'error' => 'db_delete_failed'], 500);
}
$stmt->close();

api_json(['ok' => true]);

