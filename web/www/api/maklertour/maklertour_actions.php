<?php
declare(strict_types=1);

$user = auth_current_user();
$userId = (int)$user['id'];
$role = $user['role'] ?? 'BROKER';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function mt_render_template(Smarty\Smarty $smarty, string $template, array $vars = []): string {
    foreach ($vars as $k => $v) {
        $smarty->assign($k, $v);
    }
    return $smarty->fetch($template);
}

function mt_require_admin(string $role): void {
    if ($role !== 'ADMIN') {
        throw new RuntimeException('Forbidden');
    }
}

function mt_require_operator_or_admin(string $role): void {
    if (!in_array($role, ['ADMIN', 'OPERATOR'], true)) {
        throw new RuntimeException('Forbidden');
    }
}

if ($action === 'view_orders') {
    $orders = [];

    if ($role === 'ADMIN') {
        $stmt = $dbcnx->prepare("
            SELECT o.*, b.full_name AS broker_name, op.full_name AS operator_name
            FROM tour_orders o
            LEFT JOIN users b ON b.id = o.broker_id
            LEFT JOIN users op ON op.id = o.operator_id
            ORDER BY o.created_at DESC
            LIMIT 200
        ");
    } else {
        $stmt = $dbcnx->prepare("
            SELECT o.*, b.full_name AS broker_name, op.full_name AS operator_name
            FROM tour_orders o
            LEFT JOIN users b ON b.id = o.broker_id
            LEFT JOIN users op ON op.id = o.operator_id
            WHERE o.broker_id = ?
            ORDER BY o.created_at DESC
            LIMIT 200
        ");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
        }
    }

    if (!$stmt) {
        throw new RuntimeException('DB prepare error: ' . $dbcnx->error);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt->close();

    $html = mt_render_template($smarty, 'maklertour_orders_fragment.html', [
        'orders' => $orders,
        'current_user' => $user,
        'error' => null,
        'success' => null,
    ]);

    $response = [
        'status' => 'ok',
        'html' => $html,
    ];
    return;
}

if ($action === 'create_order') {
    $title = trim($_POST['title'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $areaM2 = trim($_POST['area_m2'] ?? '');
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $customerEmail = trim($_POST['customer_email'] ?? '');

    if ($title === '') {
        $response = ['status' => 'error', 'message' => 'Название объекта обязательно'];
        return;
    }

    $areaValue = is_numeric($areaM2) ? (float)$areaM2 : 0.0;
    $publicToken = bin2hex(random_bytes(16));

    $stmt = $dbcnx->prepare("
        INSERT INTO tour_orders
        (broker_id, title, address, area_m2, customer_name, customer_phone, customer_email, status, public_token)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'NEW', ?)
    ");

    if (!$stmt) {
        throw new RuntimeException('DB prepare error: ' . $dbcnx->error);
    }

    $stmt->bind_param(
        "issdssss",
        $userId,
        $title,
        $address,
        $areaValue,
        $customerName,
        $customerPhone,
        $customerEmail,
        $publicToken
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('DB execute error: ' . $stmt->error);
    }

    $newOrderId = (int)$stmt->insert_id;
    $stmt->close();

    audit_log(
        $userId,
        'ORDER_CREATED',
        'TOUR_ORDER',
        $newOrderId,
        'Создана заявка',
        [
            'title' => $title,
            'address' => $address,
            'area_m2' => $areaValue,
        ]
    );

    $_POST['action'] = 'view_orders';
    $action = 'view_orders';

    // reload list
    $orders = [];
    $stmt = $dbcnx->prepare("
        SELECT o.*, b.full_name AS broker_name, op.full_name AS operator_name
        FROM tour_orders o
        LEFT JOIN users b ON b.id = o.broker_id
        LEFT JOIN users op ON op.id = o.operator_id
        WHERE o.broker_id = ?
        ORDER BY o.created_at DESC
        LIMIT 200
    ");
    if (!$stmt) {
        throw new RuntimeException('DB prepare error: ' . $dbcnx->error);
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt->close();

    $html = mt_render_template($smarty, 'maklertour_orders_fragment.html', [
        'orders' => $orders,
        'current_user' => $user,
        'error' => null,
        'success' => 'Заявка создана',
    ]);

    $response = [
        'status' => 'ok',
        'html' => $html,
        'message' => 'Заявка создана',
        'order_id' => $newOrderId,
    ];
    return;
}

if ($action === 'view_market') {
    mt_require_operator_or_admin($role);

    $orders = [];
    $stmt = $dbcnx->prepare("
        SELECT o.*, b.full_name AS broker_name
        FROM tour_orders o
        LEFT JOIN users b ON b.id = o.broker_id
        WHERE o.status = 'NEW'
          AND o.operator_id IS NULL
        ORDER BY o.created_at ASC
        LIMIT 200
    ");

    if (!$stmt) {
        throw new RuntimeException('DB prepare error: ' . $dbcnx->error);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt->close();

    $myOrders = [];
    $stmt = $dbcnx->prepare("
        SELECT o.*, b.full_name AS broker_name
        FROM tour_orders o
        LEFT JOIN users b ON b.id = o.broker_id
        WHERE o.operator_id = ?
          AND o.status IN ('ASSIGNED','IN_PROGRESS','CAPTURED','UPLOADING','UPLOADED','PROCESSING')
        ORDER BY o.updated_at DESC
        LIMIT 200
    ");

    if (!$stmt) {
        throw new RuntimeException('DB prepare error: ' . $dbcnx->error);
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $myOrders[] = $row;
    }
    $stmt->close();

    $html = mt_render_template($smarty, 'maklertour_market_fragment.html', [
        'orders' => $orders,
        'myOrders' => $myOrders,
        'current_user' => $user,
        'error' => null,
        'success' => null,
    ]);

    $response = [
        'status' => 'ok',
        'html' => $html,
    ];
    return;
}

if ($action === 'take_order') {
    mt_require_operator_or_admin($role);

    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        $response = ['status' => 'error', 'message' => 'Некорректная заявка'];
        return;
    }

    $stmt = $dbcnx->prepare("
        UPDATE tour_orders
        SET operator_id = ?, status = 'ASSIGNED'
        WHERE id = ?
          AND status = 'NEW'
          AND operator_id IS NULL
    ");

    if (!$stmt) {
        throw new RuntimeException('DB prepare error: ' . $dbcnx->error);
    }

    $stmt->bind_param("ii", $userId, $orderId);
    $stmt->execute();
    $ok = ($stmt->affected_rows === 1);
    $stmt->close();

    if (!$ok) {
        $response = ['status' => 'error', 'message' => 'Заявку уже взяли или она недоступна'];
        return;
    }

    audit_log(
        $userId,
        'ORDER_TAKEN',
        'TOUR_ORDER',
        $orderId,
        'Оператор взял заявку в работу',
        [
            'order_id' => $orderId,
            'operator_id' => $userId,
        ]
    );

    $response = [
        'status' => 'ok',
        'message' => 'Заявка взята в работу',
        'reload_action' => 'view_market',
    ];
    return;
}

if ($action === 'view_audit') {
    mt_require_admin($role);

    $logs = [];
    $stmt = $dbcnx->prepare("
        SELECT al.*, u.username, u.email, u.full_name
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        ORDER BY al.event_time DESC
        LIMIT 300
    ");

    if (!$stmt) {
        throw new RuntimeException('DB prepare error: ' . $dbcnx->error);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();

    $html = mt_render_template($smarty, 'maklertour_audit_fragment.html', [
        'logs' => $logs,
    ]);

    $response = [
        'status' => 'ok',
        'html' => $html,
    ];
    return;
}

$response = [
    'status' => 'error',
    'message' => 'Unknown maklertour action: ' . $action,
];