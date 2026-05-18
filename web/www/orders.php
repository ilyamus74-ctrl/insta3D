<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/api/form_helpers.php';

auth_require_login();

$user = auth_current_user();
$userId = (int)$user['id'];
$role = $user['role'] ?? 'BROKER';

function mt_order_status_meta(string $status): array {
    $map = [
        'NEW' => ['bg-secondary', 'bi-circle', 'Новая'],
        'ASSIGNED' => ['bg-primary', 'bi-person-check', 'В работе'],
        'IN_PROGRESS' => ['bg-info', 'bi-camera', 'Съемка'],
        'CAPTURED' => ['bg-warning', 'bi-check2-square', 'Отснята'],
        'UPLOADING' => ['bg-warning', 'bi-cloud-upload', 'Загружается'],
        'UPLOADED' => ['bg-success', 'bi-cloud-check', 'Загружена'],
        'PROCESSING' => ['bg-info', 'bi-gear', 'Обработка'],
        'READY' => ['bg-success', 'bi-check-circle', 'Готова'],
        'CLOSED' => ['bg-dark', 'bi-lock', 'Закрыта'],
    ];
    $meta = $map[$status] ?? ['bg-secondary', 'bi-circle', $status];
    return ['class' => $meta[0], 'icon' => $meta[1], 'label' => $meta[2]];
}


$error = null;
$success = isset($_GET['created']) ? 'Заявка создана' : (isset($_GET['updated']) ? 'Заявка обновлена' : null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_order') {
        $formToken = trim($_POST['form_token'] ?? '');
        if (!mt_consume_form_token($dbcnx, $userId, 'create_order', $formToken)) {
            $error = 'Форма уже была отправлена или устарела. Обновите страницу и попробуйте снова.';
        } else {
            $title = trim($_POST['title'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $areaM2 = trim($_POST['area_m2'] ?? '');
            $customerName = trim($_POST['customer_name'] ?? '');
            $customerPhone = trim($_POST['customer_phone'] ?? '');
            $customerEmail = trim($_POST['customer_email'] ?? '');
            $isPublished = isset($_POST['is_published']) ? 1 : 0;

            if ($title === '' || $address === '') {
                $error = 'Заполните название и адрес объекта';
            } else {
                $publicToken = bin2hex(random_bytes(16));
                $areaValue = ($areaM2 !== '') ? (float)$areaM2 : null;

                $stmt = $dbcnx->prepare("\n                    INSERT INTO tour_orders\n                    (broker_id, title, address, area_m2, customer_name, customer_phone, customer_email, status, is_published, public_token)\n                    VALUES (?, ?, ?, ?, ?, ?, ?, 'NEW', ?, ?)\n                ");

                if (!$stmt) {
                    $error = 'DB prepare error: ' . $dbcnx->error;
                } else {
                    $stmt->bind_param("issdsssis", $userId, $title, $address, $areaValue, $customerName, $customerPhone, $customerEmail, $isPublished, $publicToken);
                    if ($stmt->execute()) {
                        $newOrderId = (int)$stmt->insert_id;
                        audit_log($userId, 'ORDER_CREATED', 'TOUR_ORDER', $newOrderId, 'Создана заявка', ['title' => $title, 'address' => $address, 'area_m2' => $areaValue, 'is_published' => $isPublished]);
                        $stmt->close();
                        header('Location: /orders.php?created=1');
                        exit;
                    }
                    $error = 'DB execute error: ' . $stmt->error;
                    $stmt->close();
                }
            }
        }
    }

    if ($action === 'update_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $areaM2 = trim($_POST['area_m2'] ?? '');
        $customerName = trim($_POST['customer_name'] ?? '');
        $customerPhone = trim($_POST['customer_phone'] ?? '');
        $customerEmail = trim($_POST['customer_email'] ?? '');
        $isPublished = isset($_POST['is_published']) ? 1 : 0;

        $stmt = $dbcnx->prepare("SELECT broker_id, status FROM tour_orders WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }

        if (empty($existing)) {
            $error = 'Заявка не найдена';
        } elseif (!($role === 'ADMIN' || (int)$existing['broker_id'] === $userId)) {
            $error = 'Недостаточно прав';
        } elseif ($role !== 'ADMIN' && $existing['status'] === 'CLOSED') {
            $error = 'Закрытую заявку редактировать нельзя';
        } else {
            $areaValue = ($areaM2 !== '') ? (float)$areaM2 : null;

            $stmt = $dbcnx->prepare("UPDATE tour_orders SET title=?, address=?, area_m2=?, customer_name=?, customer_phone=?, customer_email=?, is_published=? WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("ssdsssii", $title, $address, $areaValue, $customerName, $customerPhone, $customerEmail, $isPublished, $orderId);
                if ($stmt->execute()) {
                    audit_log($userId, 'ORDER_UPDATED', 'TOUR_ORDER', $orderId, 'Заявка обновлена');
                    $stmt->close();
                    header('Location: /orders.php?updated=1');
                    exit;
                }
                $error = 'DB execute error: ' . $stmt->error;
                $stmt->close();
            }
        }
    }
}

$sql = "SELECT o.*, b.full_name AS broker_name, op.full_name AS operator_name FROM tour_orders o LEFT JOIN users b ON b.id=o.broker_id LEFT JOIN users op ON op.id=o.operator_id";

if ($role === 'ADMIN') {
    $sql .= " ORDER BY o.created_at DESC LIMIT 200";
    $stmt = $dbcnx->prepare($sql);
} elseif ($role === 'OPERATOR') {
    $sql .= "
        WHERE o.broker_id = ?
           OR o.operator_id = ?
           OR (o.is_published = 1 AND o.status = 'NEW' AND o.operator_id IS NULL)
        ORDER BY o.created_at DESC
        LIMIT 200
    ";
    $stmt = $dbcnx->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $userId, $userId);
    }
} else {
    $sql .= " WHERE o.broker_id=? ORDER BY o.created_at DESC LIMIT 200";
    $stmt = $dbcnx->prepare($sql);
    if ($stmt) { $stmt->bind_param("i", $userId); }
}
$orders=[];
if ($stmt) { $stmt->execute(); $res=$stmt->get_result(); while($row=$res->fetch_assoc()){ $row['status_meta']=mt_order_status_meta((string)$row['status']); $orders[]=$row; } $stmt->close(); }

$createOrderToken = '';
try { $createOrderToken = mt_create_form_token($dbcnx, $userId, 'create_order'); } catch (Throwable $e) { error_log($e->getMessage()); }

$smarty->assign('createOrderToken', $createOrderToken);
$smarty->assign('current_user', $user);
$smarty->assign('orders', $orders);
$smarty->assign('error', $error);
$smarty->assign('success', $success);

$smarty->display('maklertour_orders.html');
