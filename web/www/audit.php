<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

auth_require_login();

$user = auth_current_user();
$role = $user['role'] ?? 'BROKER';

if ($role !== 'ADMIN') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$logs = [];

$stmt = $dbcnx->prepare("
    SELECT al.*, u.username, u.email, u.full_name
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    ORDER BY al.event_time DESC
    LIMIT 300
");

if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();
}

$smarty->assign('logs', $logs);
$smarty->display('maklertour_audit.html');
