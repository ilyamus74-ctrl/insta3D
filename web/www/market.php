<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

auth_require_login();

$user = auth_current_user();
$userId = (int)$user['id'];
$role = $user['role'] ?? 'BROKER';

$error = null;
$success = isset($_GET['taken']) ? 'Заявка взята в работу' : null;

if (!in_array($role, ['ADMIN', 'OPERATOR'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'take_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);

        if ($orderId <= 0) {
            $error = 'Некорректная заявка';
        } else {
            $stmt = $dbcnx->prepare("
                UPDATE tour_orders
                SET operator_id = ?, status = 'ASSIGNED'
                WHERE id = ?
                  AND status = 'NEW'
                  AND operator_id IS NULL
                  AND is_published = 1
            ");

            if (!$stmt) {
                $error = 'DB prepare error: ' . $dbcnx->error;
            } else {
                $stmt->bind_param("ii", $userId, $orderId);
                $stmt->execute();

                if ($stmt->affected_rows === 1) {
                    audit_log(
                             $userId,
                             'ORDER_TAKEN',
                             'TOUR_ORDER',
                             $orderId,
                             'Оператор взял заявку в работу'
                             );
                    $stmt->close();
                    header('Location: /market.php?taken=1');
                    exit;
                }

                $stmt->close();
                $error = 'Заявку уже взяли или она недоступна';
            }
        }
    }
}

$sql = "
    SELECT o.*, b.full_name AS broker_name
    FROM tour_orders o
    LEFT JOIN users b ON b.id = o.broker_id
    WHERE o.status = 'NEW'
      AND o.operator_id IS NULL
      AND o.is_published = 1
    ORDER BY o.created_at ASC
    LIMIT 200
";

$orders = [];

$stmt = $dbcnx->prepare($sql);
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt->close();
} else {
    $error = 'DB prepare error: ' . $dbcnx->error;
}

$myOrders = [];

$sqlMy = "
    SELECT o.*, b.full_name AS broker_name
    FROM tour_orders o
    LEFT JOIN users b ON b.id = o.broker_id
    WHERE o.operator_id = ?

    ORDER BY o.updated_at DESC
    LIMIT 200
";

$stmtMy = $dbcnx->prepare($sqlMy);
if ($stmtMy) {
    $stmtMy->bind_param("i", $userId);
    $stmtMy->execute();
    $resMy = $stmtMy->get_result();
    while ($row = $resMy->fetch_assoc()) {
        $myOrders[] = $row;
    }
    $stmtMy->close();
} else {
    $error = 'DB prepare error: ' . $dbcnx->error;
}

$smarty->assign('current_user', $user);
$smarty->assign('orders', $orders);
$smarty->assign('myOrders', $myOrders);
$smarty->assign('error', $error);
$smarty->assign('success', $success);

$smarty->display('maklertour_market.html');
