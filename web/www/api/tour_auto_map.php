<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../libs/tour_auto_map_lib.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_login();

function auto_map_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function can_view_order_auto(array $order, int $userId, string $role): bool {
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

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) auto_map_json(['ok' => false, 'error' => 'bad_json'], 400);

$sessionId = (int)($data['session_id'] ?? 0);
$overwrite = (bool)($data['overwrite'] ?? true);
$overwriteManual = (bool)($data['overwrite_manual'] ?? false);
if ($sessionId <= 0) auto_map_json(['ok' => false, 'error' => 'bad_session_id'], 400);

$user = auth_current_user();
$userId = (int)$user['id'];
$role = (string)($user['role'] ?? 'BROKER');

$stmt = $dbcnx->prepare("SELECT cs.id, cs.order_id, o.broker_id, o.operator_id, o.is_published, o.status FROM capture_sessions cs JOIN tour_orders o ON o.id = cs.order_id WHERE cs.id = ? LIMIT 1");
if (!$stmt) auto_map_json(['ok' => false, 'error' => 'db_prepare_session_failed'], 500);
$stmt->bind_param('i', $sessionId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$session) auto_map_json(['ok' => false, 'error' => 'session_not_found'], 404);
if (!can_view_order_auto($session, $userId, $role)) auto_map_json(['ok' => false, 'error' => 'forbidden'], 403);


try {
    $result = run_tour_auto_map($dbcnx, $sessionId, $overwrite, $overwriteManual);
    auto_map_json([
        'ok' => true,
        'session_id' => $sessionId,
        'algorithm' => $result['algorithm'] ?? TOUR_AUTO_MAP_ALGORITHM,
        'positioned_count' => (int)($result['positioned_count'] ?? 0),
        'warnings' => $result['warnings'] ?? [],
        'positions' => $result['positions'] ?? [],
        'edges' => $result['edges'] ?? [],
    ]);
} catch (Throwable $e) {
    auto_map_json(['ok' => false, 'error' => 'auto_map_failed', 'message' => $e->getMessage()], 500);
}
