<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

auth_require_login();

$user = auth_current_user();
$userId = (int)$user['id'];
$role = $user['role'] ?? 'BROKER';

$sessionId = (int)($_GET['session_id'] ?? 0);
if ($sessionId <= 0) {
    http_response_code(400);
    exit('Bad session id');
}

function can_view_order_tour(array $order, int $userId, string $role): bool {
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

$stmt = $dbcnx->prepare("
    SELECT
        cs.id AS session_id,
        cs.app_session_uuid,
        cs.camera_model,
        cs.status AS session_status,
        o.*
    FROM capture_sessions cs
    JOIN tour_orders o ON o.id = cs.order_id
    WHERE cs.id = ?
    LIMIT 1
");
if (!$stmt) {
    http_response_code(500);
    exit('DB error');
}

$stmt->bind_param('i', $sessionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    exit('Session not found');
}

if (!can_view_order_tour($row, $userId, $role)) {
    http_response_code(403);
    exit('Forbidden');
}

$smarty->assign('current_user', $user);
$smarty->assign('sessionId', $sessionId);
$smarty->assign('orderId', (int)$row['id']);
$smarty->assign('orderTitle', $row['title'] ?? '');
$smarty->assign('sessionUuid', $row['app_session_uuid'] ?? '');
$smarty->display('maklertour_tour.html');
