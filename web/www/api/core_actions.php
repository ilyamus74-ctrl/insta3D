<?php
declare(strict_types=1);

switch ($action) {

    // === Настройки ячеек склада ===
    case 'setting_cells':
        // если нужно ограничить только админами – раскомментируешь
        // if (!auth_has_role('ADMIN')) {
        //     $response = [
        //         'status'  => 'error',
        //         'message' => 'Недостаточно прав для просмотра ячеек',
        //     ];
        //     break;
        // }

        // если нужно что-то подтянуть из БД — потом сюда добавим SELECT
        // пример заготовки:
         $cells = [];
         if ($res = $dbcnx->query("SELECT id, code, qr_payload, description FROM cells ORDER BY code")) {
             while ($row = $res->fetch_assoc()) {
                 $cells[] = $row;
             }
             $res->free();
         }
        $smarty->assign('cells', $cells);

        // рендерим твой готовый шаблон с ячейками
        $smarty->assign('current_user', $user);
        ob_start();
        $smarty->display('cells_NA_API_warehouse_cells.html'); // ПОДСТАВЬ имя своего шаблона
        $html = ob_get_clean();

        $response = [
            'status' => 'ok',
            'html'   => $html,
        ];
        break;

case 'add_new_cells':
        // auth_require_role('ADMIN'); // при желании

        $first = trim($_POST['first_code'] ?? '');
        $last  = trim($_POST['last_code'] ?? '');
        $desc  = trim($_POST['description'] ?? '');

        if ($first === '' || $last === '') {
            $response = [
                'status'  => 'error',
                'message' => 'Укажи первую и последнюю ячейку, например A10 и A99',
            ];
            break;
        }

        // Нормализуем в верхний регистр
        $first = strtoupper($first);
        $last  = strtoupper($last);

        // Парсим вида A10, B25 и т.п.
        $re = '/^([A-Z])(\d{1,4})$/u';

        if (!preg_match($re, $first, $m1) || !preg_match($re, $last, $m2)) {
            $response = [
                'status'  => 'error',
                'message' => 'Коды ячеек должны быть вида A10, A99 и т.п.',
            ];
            break;
        }

        $prefix1 = $m1[1];
        $n1      = (int)$m1[2];

        $prefix2 = $m2[1];
        $n2      = (int)$m2[2];

        if ($prefix1 !== $prefix2) {
            $response = [
                'status'  => 'error',
                'message' => 'Буквенная часть должна совпадать (например A10–A99, а не A10–B20)',
            ];
            break;
        }

        if ($n1 >= $n2) {
            $response = [
                'status'  => 'error',
                'message' => 'Первая ячейка должна быть меньше последней по номеру (например A10 и A99)',
            ];
            break;
        }

        $prefix = $prefix1;

        // Смотрим уже существующие коды в этом диапазоне, чтобы не делать дубли
        $fromCode = $prefix . $n1;
        $toCode   = $prefix . $n2;

        $stmt = $dbcnx->prepare(
            "SELECT code FROM cells WHERE code BETWEEN ? AND ?"
        );
        $stmt->bind_param("ss", $fromCode, $toCode);
        $stmt->execute();
        $res = $stmt->get_result();

        $existing = [];
        while ($row = $res->fetch_assoc()) {
            $existing[$row['code']] = true;
        }
        $stmt->close();

        // Папка для файлов
        $dir = __DIR__ . '/img/cells';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $created = 0;

        for ($i = $n1; $i <= $n2; $i++) {
            $code = $prefix . $i;        // A10, A11, ...

            if (isset($existing[$code])) {
                // Уже есть такая ячейка — пропускаем
                continue;
            }

            $qrPayload  = 'CELL:' . $code;
            $qrFileName = $code . '.png';                 // ИМЯ ФАЙЛА = КОД ЯЧЕЙКИ
            $fullPath   = $dir . '/' . $qrFileName;

            // Вставляем в БД: code, qr_payload, qr_file, description
            $stmtIns = $dbcnx->prepare(
                "INSERT INTO cells (code, qr_payload, qr_file, description)
                 VALUES (?, ?, ?, ?)"
            );
            $stmtIns->bind_param("ssss", $code, $qrPayload, $qrFileName, $desc);
            $stmtIns->execute();
            $stmtIns->close();

            // Генерируем PNG через qrencode
            $cmd = sprintf(
                'qrencode -o %s -s 4 %s 2>&1',
                escapeshellarg($fullPath),
                escapeshellarg($qrPayload)
            );
            $out = [];
            $rc  = 0;
            exec($cmd, $out, $rc);
            if ($rc !== 0) {
                error_log('qrencode error for cell ' . $code . ': ' . implode("\n", $out));
            }

            $created++;
        }

        // Перечитываем список ячеек
        $cells = [];
        $sql = "SELECT id, code, qr_payload, qr_file, description
                  FROM cells
              ORDER BY code";
        if ($res2 = $dbcnx->query($sql)) {
            while ($row = $res2->fetch_assoc()) {
                $cells[] = $row;
            }
            $res2->free();
        }

        $smarty->assign('cells', $cells);
        $smarty->assign('current_user', $user);

        ob_start();
        $smarty->display('cells_NA_API_warehouse_cells.html');
        $html = ob_get_clean();

        $response = [
            'status'  => 'ok',
            'message' => 'Создано новых ячеек: ' . $created,
            'html'    => $html,
        ];
        break;

    case 'delete_cell':
        $cellId = (int)($_POST['cell_id'] ?? 0);
        if ($cellId <= 0) {
            $response = [
                'status'  => 'error',
                'message' => 'Некорректный ID ячейки',
            ];
            break;
        }

        $stmt = $dbcnx->prepare(
            "SELECT id, code, qr_payload, qr_file, description
               FROM cells
              WHERE id = ?
              LIMIT 1"
        );
        $stmt->bind_param("i", $cellId);
        $stmt->execute();
        $res = $stmt->get_result();
        $cell = $res->fetch_assoc();
        $stmt->close();

        if (!$cell) {
            $response = [
                'status'  => 'error',
                'message' => 'Ячейка не найдена',
            ];
            break;
        }
        $stmt = $dbcnx->prepare(
            "SELECT COUNT(*) AS total
               FROM warehouse_item_stock
              WHERE cell_id = ?"
        );
        $stmt->bind_param("i", $cellId);
        $stmt->execute();
        $res = $stmt->get_result();
        $usage = $res->fetch_assoc();
        $stmt->close();
        $totalUsage = (int)($usage['total'] ?? 0);
        if ($totalUsage > 0) {
            $response = [
                'status'  => 'error',
                'message' => 'Нельзя удалить ячейку: есть посылки, привязанные к этой ячейке.',
            ];
            break;
        }

        // Удаляем файл, если есть
        $dir = __DIR__ . '/img/cells';
        if (!empty($cell['qr_file'])) {
            $path = $dir . '/' . $cell['qr_file'];
            if (is_file($path)) {
                @unlink($path);
            }
        } else {
            // запасной вариант по коду
            $fallback = $dir . '/' . $cell['code'] . '.png';
            if (is_file($fallback)) {
                @unlink($fallback);
            }
        }

        // Удаляем из БД
        $stmt = $dbcnx->prepare("DELETE FROM cells WHERE id = ?");
        $stmt->bind_param("i", $cellId);
        $stmt->execute();
        $stmt->close();

        audit_log(
            $user['id'] ?? null,
            'CELL_DELETE',
            'CELL',
            $cellId,
            'Удаление ячейки склада',
            [
                'code'        => $cell['code'],
                'qr_payload'  => $cell['qr_payload'],
                'qr_file'     => $cell['qr_file'],
                'description' => $cell['description'],
            ]
        );

        // Перечитываем список ячеек
        $cells = [];
        $sql = "SELECT id, code, qr_payload, qr_file, description
                  FROM cells
              ORDER BY code";
        if ($res2 = $dbcnx->query($sql)) {
            while ($row = $res2->fetch_assoc()) {
                $cells[] = $row;
            }
            $res2->free();
        }

        $smarty->assign('cells', $cells);
        $smarty->assign('current_user', $user);

        ob_start();
        $smarty->display('cells_NA_API_warehouse_cells.html');
        $html = ob_get_clean();

        $response = [
            'status'  => 'ok',
            'message' => 'Ячейка удалена',
            'html'    => $html,
        ];
        break;

case 'warehouse_item_in':
case 'item_in':
    auth_require_login();
    $current = $user;
    $userId  = (int)$current['id'];

    $batches = [];

    $canViewAll = auth_has_permission('warehouse.in.view_all') || auth_has_role('ADMIN');
    if ($canViewAll) {
        // Админ видит ВСЕ незавершённые партии + можем сразу знать, чей это приход
        $sql = "
            SELECT
                wi.batch_uid,
                MIN(wi.created_at) AS started_at,
                COUNT(*)           AS parcel_count,
                wi.user_id,
                u.full_name        AS user_name
            FROM warehouse_item_in wi
            LEFT JOIN users u ON u.id = wi.user_id
            WHERE wi.committed = 0
            GROUP BY wi.batch_uid, wi.user_id
            ORDER BY started_at DESC
        ";
        if ($res = $dbcnx->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $batches[] = $row;
            }
            $res->free();
        }
    } else {
        // Обычный пользователь — только свои партии
        $sql = "
            SELECT
                wi.batch_uid,
            MIN(wi.created_at) AS started_at,
            COUNT(*)           AS parcel_count
            FROM warehouse_item_in wi
            WHERE wi.committed = 0
              AND wi.user_id   = ?
            GROUP BY wi.batch_uid
            ORDER BY started_at DESC
        ";
        $stmt = $dbcnx->prepare($sql);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $batches[] = $row;
        }
        $stmt->close();
    }

    $smarty->assign('batches',      $batches);
    $smarty->assign('current_user', $current);

    ob_start();
    $smarty->display('cells_NA_API_warehouse_item_in.html');
    $html = ob_get_clean();

    $response = [
        'status' => 'ok',
        'html'   => $html,
    ];
    break;

case 'item_stock':
    auth_require_login();
    $current = $user;
    $userId  = (int)$current['id'];

    $batches = [];

    if (auth_has_permission('warehouse.stock.view_all')) {
        $sql = "
            SELECT
                wi.batch_uid,
                MIN(wi.created_at) AS started_at,
                COUNT(*)           AS parcel_count,
                wi.user_id,
                u.full_name        AS user_name
            FROM warehouse_item_stock wi
            LEFT JOIN users u ON u.id = wi.user_id
            GROUP BY wi.batch_uid, wi.user_id
            ORDER BY started_at DESC
        ";
        //
        if ($res = $dbcnx->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $batches[] = $row;
            }
            $res->free();
        }
    } else {
        $sql = "
            SELECT
                wi.batch_uid,
                MIN(wi.created_at) AS started_at,
                COUNT(*)           AS parcel_count
            FROM warehouse_item_stock wi
            WHERE wi.user_id = ?
            GROUP BY wi.batch_uid
            ORDER BY started_at DESC
        ";
//            GROUP BY wi.batch_uid

        $stmt = $dbcnx->prepare($sql);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $batches[] = $row;
        }
        $stmt->close();
    }

    $smarty->assign('batches',      $batches);
    $smarty->assign('current_user', $current);

    ob_start();
    $smarty->display('cells_NA_API_warehouse_item_stock.html');
    $html = ob_get_clean();

    $response = [
        'status' => 'ok',
        'html'   => $html,
    ];
    break;


case 'open_item_in_batch':
    auth_require_login();
    $current = $user;
    $userId  = (int)$current['id'];

    $batchUid = isset($_POST['batch_uid']) ? (int)$_POST['batch_uid'] : 0;
    if ($batchUid <= 0) {
        // новая партия
        $batchUid = (int)(microtime(true) * 1000000);
    }

    $items = [];

    $canViewAll = auth_has_permission('warehouse.in.view_all') || auth_has_role('ADMIN');
    if ($canViewAll) {
        // Админ видит ВСЕ посылки в партии, кто бы их ни создавал
        $sql = "
            SELECT
                id,
                tuid,
                tracking_no,
                receiver_name,
                receiver_company,
                receiver_address,
                weight_kg,
                size_l_cm,
                size_w_cm,
                size_h_cm,
                created_at
            FROM warehouse_item_in
            WHERE batch_uid = ?
              AND committed = 0
            ORDER BY created_at ASC
        ";
        $stmt = $dbcnx->prepare($sql);
        $stmt->bind_param("i", $batchUid);
    } else {
        // Обычный юзер — только свои записи
        $sql = "
            SELECT
                id,
                tuid,
                tracking_no,
                receiver_name,
                receiver_company,
                receiver_address,
                weight_kg,
                size_l_cm,
                size_w_cm,
                size_h_cm,
                created_at
            FROM warehouse_item_in
            WHERE batch_uid = ?
              AND user_id   = ?
              AND committed = 0
            ORDER BY created_at ASC
        ";
        $stmt = $dbcnx->prepare($sql);
        $stmt->bind_param("ii", $batchUid, $userId);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    $smarty->assign('batch_uid',    $batchUid);
    $smarty->assign('items',        $items);
    $smarty->assign('current_user', $current);

        $dest_country = [];
        $sql = "SELECT `id`,`code_iso2`,`code_iso3`,`name_en`,`name_local` FROM `dest_countries` WHERE `is_active` = '1' ORDER BY `id`";
        $stmt = $dbcnx->prepare($sql);
        $stmt->execute();
        $res3 = $stmt->get_result();


        if ($res3 = $dbcnx->query($sql)) {
            while ($row = $res3->fetch_assoc()) {
                $dest_country[] = $row;
            }
            $res3->free();
        }
    $stmt->close();

    $smarty->assign('dest_country', $dest_country);
    $stand_devices = [];
    $sql = "SELECT device_uid, name, device_token
              FROM devices
             WHERE name LIKE 'stand\\_%'
             ORDER BY name ASC, device_uid ASC";
    if ($resStand = $dbcnx->query($sql)) {
        while ($row = $resStand->fetch_assoc()) {
            $stand_devices[] = $row;
        }
        $resStand->free();
    }
    $smarty->assign('stand_devices', $stand_devices);

    require_once __DIR__ . '/ocr_templates.php';
    require_once __DIR__ . '/ocr_dicts.php';


    ob_start();
    $smarty->display('cells_NA_API_warehouse_item_in_batch.html');
    $html = ob_get_clean();

    $response = [
        'status' => 'ok',
        'html'   => $html,
    ];
    break;

case 'add_new_item_in':
    auth_require_login();
    $current = $user;

    // Кто сейчас залогинен — ОПЕРАТОР
    $operatorUserId = (int)$current['id'];

    // 1) партия
    $batchUid = isset($_POST['batch_uid']) ? (int)$_POST['batch_uid'] : 0;
    if ($batchUid <= 0) {
        // новая партия — владелец = текущий пользователь
        $batchUid     = (int)(microtime(true) * 1000000); // uid_created
        $ownerUserId  = $operatorUserId;
    } else {
        // существующая партия — ищем её владельца
        $ownerUserId = $operatorUserId; // по умолчанию

        $stmtOwner = $dbcnx->prepare(
            "SELECT user_id
               FROM warehouse_item_in
              WHERE batch_uid = ?
                AND committed = 0
              ORDER BY created_at ASC
              LIMIT 1"
        );
        if ($stmtOwner) {
            $stmtOwner->bind_param("i", $batchUid);
            $stmtOwner->execute();
            $resOwner = $stmtOwner->get_result();
            if ($rowOwner = $resOwner->fetch_assoc()) {
                $ownerUserId = (int)$rowOwner['user_id'];
            }
            $stmtOwner->close();
        }
    }

    // 2) поля из формы
    $tuid        = trim($_POST['tuid']        ?? '');
    $tracking    = trim($_POST['tracking_no'] ?? '');

    $carrierCode = trim($_POST['carrier_code'] ?? '');
    $carrierName = trim($_POST['carrier_name'] ?? '');

    $rcCountryCode = trim($_POST['receiver_country_code'] ?? '');
    // имя страны сейчас не приходит, можно оставить пустым
    $rcCountryName = '';

    $rcName        = trim($_POST['receiver_name']    ?? '');
    $rcCompany     = trim($_POST['receiver_company'] ?? '');
    $rcAddress     = trim($_POST['receiver_address'] ?? '');

    $sndName       = trim($_POST['sender_name']    ?? '');
    $sndCompany    = trim($_POST['sender_company'] ?? '');

    // вес и габариты: если пусто → 0
    $weightKg = $_POST['weight_kg'] ?? '';
    $sizeL    = $_POST['size_l_cm'] ?? '';
    $sizeW    = $_POST['size_w_cm'] ?? '';
    $sizeH    = $_POST['size_h_cm'] ?? '';

    $weightKg = ($weightKg === '' || $weightKg === null) ? 0.0 : (float)$weightKg;
    $sizeL    = ($sizeL    === '' || $sizeL    === null) ? 0.0 : (float)$sizeL;
    $sizeW    = ($sizeW    === '' || $sizeW    === null) ? 0.0 : (float)$sizeW;
    $sizeH    = ($sizeH    === '' || $sizeH    === null) ? 0.0 : (float)$sizeH;

    // пока не используем
    $labelImage = null;
    $boxImage   = null;

    if ($tuid === '' || $tracking === '') {
        $response = [
            'status'  => 'error',
            'message' => 'Нужны хотя бы TUID и трек-номер',
        ];
        break;
    }

    $uidCreated = (int)(microtime(true) * 1000000);
    $deviceId   = 0; // для веба 0, для мобилки можно класть реальный device_id

    $sql = "INSERT INTO warehouse_item_in (
                batch_uid, uid_created, user_id, device_id, committed,
                tuid, tracking_no, carrier_code, carrier_name,
                receiver_country_code, receiver_country_name,
                receiver_name, receiver_company, receiver_address,
                sender_name, sender_company,
                weight_kg, size_l_cm, size_w_cm, size_h_cm,
                label_image, box_image
            ) VALUES (
                ?, ?, ?, ?, 0,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?
            )";

    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        $response = [
            'status'  => 'error',
            'message' => 'DB error: ' . $dbcnx->error,
        ];
        break;
    }

    // iiiisssssssssssddddss  = 21 параметр
    $stmt->bind_param(
        "iiiisssssssssssddddss",
        $batchUid,
        $uidCreated,
        $ownerUserId,    // владелец партии
        $deviceId,
        $tuid,
        $tracking,
        $carrierCode,
        $carrierName,
        $rcCountryCode,
        $rcCountryName,
        $rcName,
        $rcCompany,
        $rcAddress,
        $sndName,
        $sndCompany,
        $weightKg,
        $sizeL,
        $sizeW,
        $sizeH,
        $labelImage,
        $boxImage
    );
    $stmt->execute();
    $stmt->close();

    audit_log(
        $operatorUserId,                 // кто реально добавил
        'WAREHOUSE_IN_ADD_PARCEL',
        'WAREHOUSE_IN',
        $batchUid,                       // entity_id = batch_uid
        'Добавлена посылка в партию прихода',
        [
            'batch_uid'        => $batchUid,
            'owner_user_id'    => $ownerUserId,
            'operator_user_id' => $operatorUserId,
            'tuid'             => $tuid,
            'tracking_no'      => $tracking,
        ]
    );

    $response = [
        'status'    => 'ok',
        'message'   => 'Посылка добавлена',
        'batch_uid' => $batchUid,
    ];
    break;


case 'delete_item_in':
    auth_require_login();

    $current = $user;
    $userId  = (int)$current['id'];
    $isAdmin = auth_has_permission('warehouse.in.manage_all') || auth_has_role('ADMIN');

    $itemId = (int)($_POST['item_id'] ?? 0);
    if ($itemId <= 0) {
        $response = [
            'status'  => 'error',
            'message' => 'item_id не задан',
        ];
        break;
    }

    $stmtItem = $dbcnx->prepare(
        "SELECT id, batch_uid, user_id, committed\n           FROM warehouse_item_in\n          WHERE id = ?"
    );
    if (!$stmtItem) {
        $response = [
            'status'  => 'error',
            'message' => 'DB error (prepare select item): ' . $dbcnx->error,
        ];
        break;
    }

    $stmtItem->bind_param("i", $itemId);
    $stmtItem->execute();
    $itemRow = $stmtItem->get_result()->fetch_assoc();
    $stmtItem->close();

    if (!$itemRow) {
        $response = [
            'status'  => 'error',
            'message' => 'Посылка не найдена',
        ];
        break;
    }

    $batchUid   = (int)($itemRow['batch_uid'] ?? 0);
    $itemUserId = (int)($itemRow['user_id']   ?? 0);
    $committed  = (int)($itemRow['committed'] ?? 0);

    if ($committed !== 0) {
        $response = [
            'status'  => 'error',
            'message' => 'Нельзя удалить завершённую посылку',
        ];
        break;
    }

    if (!$isAdmin && $itemUserId !== $userId) {
        $response = [
            'status'  => 'error',
            'message' => 'Недостаточно прав для удаления посылки',
        ];
        break;
    }

    if ($isAdmin) {
        $stmtDel = $dbcnx->prepare(
            "DELETE FROM warehouse_item_in\n             WHERE id = ?\n               AND committed = 0"
        );
        if ($stmtDel) {
            $stmtDel->bind_param("i", $itemId);
        }
    } else {
        $stmtDel = $dbcnx->prepare(
            "DELETE FROM warehouse_item_in\n             WHERE id = ?\n               AND user_id = ?\n               AND committed = 0"
        );
        if ($stmtDel) {
            $stmtDel->bind_param("ii", $itemId, $userId);
        }
    }

    if (!$stmtDel) {
        $response = [
            'status'  => 'error',
            'message' => 'DB error (prepare delete): ' . $dbcnx->error,
        ];
        break;
    }

    $stmtDel->execute();
    $stmtDel->close();

    $items = [];

    audit_log(
        $userId,
        'WAREHOUSE_IN_DELETE_PARCEL',
        'WAREHOUSE_IN',
        $itemId,
        'Удалена посылка из партии прихода',
        [
            'batch_uid'    => $batchUid,
            'item_id'      => $itemId,
            'item_user_id' => $itemUserId,
            'deleted_by'   => $userId,
            'is_admin'     => $isAdmin,
        ]
    );


    if ($isAdmin) {
        $sql = "
            SELECT
                id,
                tuid,
                tracking_no,
                receiver_name,
                receiver_company,
                receiver_address,
                weight_kg,
                size_l_cm,
                size_w_cm,
                size_h_cm,
                created_at
            FROM warehouse_item_in
            WHERE batch_uid = ?
              AND committed = 0
            ORDER BY created_at ASC
        ";
        $stmt = $dbcnx->prepare($sql);
        $stmt->bind_param("i", $batchUid);
    } else {
        $sql = "
            SELECT
                id,
                tuid,
                tracking_no,
                receiver_name,
                receiver_company,
                receiver_address,
                weight_kg,
                size_l_cm,
                size_w_cm,
                size_h_cm,
                created_at
            FROM warehouse_item_in
            WHERE batch_uid = ?
              AND user_id   = ?
              AND committed = 0
            ORDER BY created_at ASC
        ";
        $stmt = $dbcnx->prepare($sql);
        $stmt->bind_param("ii", $batchUid, $userId);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    $smarty->assign('batch_uid',    $batchUid);
    $smarty->assign('items',        $items);
    $smarty->assign('current_user', $current);

        $dest_country = [];
        $sql = "SELECT `id`,`code_iso2`,`code_iso3`,`name_en`,`name_local` FROM `dest_countries` WHERE `is_active` = '1' ORDER BY `id`";
        $stmt = $dbcnx->prepare($sql);
        $stmt->execute();
        $res3 = $stmt->get_result();


        if ($res3 = $dbcnx->query($sql)) {
            while ($row = $res3->fetch_assoc()) {
                $dest_country[] = $row;
            }
            $res3->free();
        }
    $stmt->close();

    $smarty->assign('dest_country', $dest_country);

    require_once __DIR__ . '/ocr_templates.php';
    require_once __DIR__ . '/ocr_dicts.php';


    ob_start();
    $smarty->display('cells_NA_API_warehouse_item_in_batch.html');
    $html = ob_get_clean();

    $response = [
        'status'  => 'ok',
        'message' => 'Посылка удалена',
        'html'    => $html,
    ];
    break;



case 'commit_item_in_batch':
    auth_require_login();
    $current  = $user;
    $userId   = (int)$current['id'];
    $batchUid = (int)($_POST['batch_uid'] ?? 0);

    if ($batchUid <= 0) {
        $response = [
            'status'  => 'error',
            'message' => 'batch_uid не задан',
        ];
        break;
    }

    $isAdmin = auth_has_permission('warehouse.in.manage_all') || auth_has_role('ADMIN');

    // 1) сколько незакоммиченных
    $stmt = $dbcnx->prepare(
        "SELECT COUNT(*) AS cnt
           FROM warehouse_item_in
          WHERE batch_uid = ?
            AND committed = 0"
    );
    if (!$stmt) {
        $response = [
            'status'  => 'error',
            'message' => 'DB error (prepare count): ' . $dbcnx->error,
        ];
        break;
    }

    $stmt->bind_param("i", $batchUid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cnt = (int)($res['cnt'] ?? 0);

    if ($cnt === 0) {
        $response = [
            'status'  => 'ok',
            'message' => 'Партия уже была завершена или пустая',
        ];
        break;
    }

    // 2) копируем в stock
    $sqlCopy = "INSERT INTO warehouse_item_stock (
                    batch_uid, uid_created, user_id, device_id, created_at,
                    tuid, tracking_no, carrier_code, carrier_name,
                    receiver_country_code, receiver_country_name,
                    receiver_name, receiver_company, receiver_address,
                    sender_name, sender_company,
                    weight_kg, size_l_cm, size_w_cm, size_h_cm,
                    label_image, box_image
                )
                SELECT
                    batch_uid, uid_created, user_id, device_id, created_at,
                    tuid, tracking_no, carrier_code, carrier_name,
                    receiver_country_code, receiver_country_name,
                    receiver_name, receiver_company, receiver_address,
                    sender_name, sender_company,
                    weight_kg, size_l_cm, size_w_cm, size_h_cm,
                    label_image, box_image
                  FROM warehouse_item_in
                 WHERE batch_uid = ?
                   AND committed = 0";

    $stmt = $dbcnx->prepare($sqlCopy);
    if (!$stmt) {
        $response = [
            'status'  => 'error',
            'message' => 'DB error (prepare copy): ' . $dbcnx->error,
        ];
        break;
    }

    $stmt->bind_param("i", $batchUid);
    $stmt->execute();
    $stmt->close();

    // 3) помечаем как committed
    $stmt = $dbcnx->prepare(
        "UPDATE warehouse_item_in
            SET committed = 1
          WHERE batch_uid = ?
            AND committed = 0"
    );
    if (!$stmt) {
        $response = [
            'status'  => 'error',
            'message' => 'DB error (prepare update): ' . $dbcnx->error,
        ];
        break;
    }

    $stmt->bind_param("i", $batchUid);
    $stmt->execute();
    $stmt->close();

    // 4) аудит
    audit_log(
        $userId,
        'WAREHOUSE_IN_COMMIT',
        'WAREHOUSE_IN',
        $batchUid,
        'Партия прихода переведена на склад',
        [
            'batch_uid'    => $batchUid,
            'committed_by' => $userId,
            'is_admin'     => $isAdmin,
            'items_count'  => $cnt,
        ]
    );

    $response = [
        'status'  => 'ok',
        'message' => 'Партия прихода завершена и перенесена на склад',
    ];
    break;


    // === 2) Заготовка под "Устройства" ===
/*    case 'view_devices':
        // потом сюда добавишь SELECT по устройствам и свой шаблон
        ob_start();
        $smarty->display('NiceAdmin/devices_list.html'); // создашь позже
        $html = ob_get_clean();

        $response = [
            'status' => 'ok',
            'html'   => $html,
        ];
        break;
*/
    // сюда можешь добавить другие действия:
    // case 'set_lang': ... (то, что мы делали до этого)
    // case 'get_user_panel_html': ...
    // сюда добавишь остальные действия:
    // case 'save_user_settings':
    // case 'get_parcel_list':
    // case 'render_label_block':
    // и т.д.
    default:
        http_response_code(400);
        $response = [
            'status'  => 'error',
            'message' => 'Unknown action',
        ];
        break;

}
