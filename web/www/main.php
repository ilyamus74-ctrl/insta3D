<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

auth_require_login();

$currentUser = auth_current_user();

$smarty->assign('user_settings', $currentUser);
$smarty->assign('header_data', $header_data ?? []);
$smarty->assign('main', 'main');

// MaklerTour dashboard metrics
function mt_count(mysqli $dbcnx, string $sql, string $types = '', array $params = []): int {
    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        error_log('mt_count prepare error: ' . $dbcnx->error . ' SQL=' . $sql);
        return 0;
    }

    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        error_log('mt_count execute error: ' . $stmt->error);
        $stmt->close();
        return 0;
    }

    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : [0];
    $stmt->close();

    return isset($row[0]) ? (int)$row[0] : 0;
}

$totalOrders = mt_count($dbcnx, "SELECT COUNT(*) FROM tour_orders");
$newOrders = mt_count($dbcnx, "SELECT COUNT(*) FROM tour_orders WHERE status = 'NEW'");
$inProgressOrders = mt_count($dbcnx, "SELECT COUNT(*) FROM tour_orders WHERE status IN ('ASSIGNED','IN_PROGRESS','CAPTURED','UPLOADING')");
$readyOrders = mt_count($dbcnx, "SELECT COUNT(*) FROM tour_orders WHERE status = 'READY'");
$totalUsers = mt_count($dbcnx, "SELECT COUNT(*) FROM users WHERE is_active = 1");

$smarty->assign('dashboard', [
    'totalOrders' => $totalOrders,
    'newOrders' => $newOrders,
    'inProgressOrders' => $inProgressOrders,
    'readyOrders' => $readyOrders,
    'totalUsers' => $totalUsers,
]);

$smarty->display('maklertour_dashboard.html');
