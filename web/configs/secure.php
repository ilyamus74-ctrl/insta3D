<?php
//print_r($_SERVER);

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


function auth_device_by_token(
    string $deviceUid,
    string $deviceToken,
    bool $requireActive = true
): array {
    global $dbcnx;

    $deviceUid = trim($deviceUid);
    $deviceToken = trim($deviceToken);

    if ($deviceUid === '' || $deviceToken === '') {
        return [
            'ok' => false,
            'http_code' => 400,
            'message' => 'missing params',
        ];
    }

    $stmt = $dbcnx->prepare(
        "SELECT id, device_uid, device_token, is_active FROM devices WHERE device_uid = ? LIMIT 1"
    );
    if (!$stmt) {
        return [
            'ok' => false,
            'http_code' => 500,
            'message' => 'db error',
        ];
    }

    $stmt->bind_param("s", $deviceUid);
    $stmt->execute();
    $res = $stmt->get_result();
    $device = $res->fetch_assoc();
    $stmt->close();

    if (!$device) {
        return [
            'ok' => false,
            'http_code' => 403,
            'message' => 'device not registered',
        ];
    }

    if (!hash_equals($device['device_token'], $deviceToken)) {
        return [
            'ok' => false,
            'http_code' => 403,
            'message' => 'invalid device token',
        ];
    }

    if ($requireActive && (int)$device['is_active'] !== 1) {
        return [
            'ok' => false,
            'http_code' => 403,
            'message' => 'device not activated',
        ];
    }

    return [
        'ok' => true,
        'device' => $device,
    ];
}

function auth_device_by_stand_id(
    string $standId,
    string $deviceToken,
    bool $requireActive = true
): array {
    global $dbcnx;

    $standId = trim($standId);
    $deviceToken = trim($deviceToken);

    if ($standId === '' || $deviceToken === '') {
        return [
            'ok' => false,
            'http_code' => 400,
            'message' => 'missing params',
        ];
    }

    $device = null;

    if (ctype_digit($standId)) {
        $standIdInt = (int)$standId;
        $stmt = $dbcnx->prepare(
            "SELECT id, device_uid, device_token, is_active FROM devices WHERE id = ? LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param("i", $standIdInt);
            $stmt->execute();
            $res = $stmt->get_result();
            $device = $res->fetch_assoc();
            $stmt->close();
        }
    }

    if (!$device) {
        $stmt = $dbcnx->prepare(
            "SELECT id, device_uid, device_token, is_active FROM devices WHERE device_uid = ? LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param("s", $standId);
            $stmt->execute();
            $res = $stmt->get_result();
            $device = $res->fetch_assoc();
            $stmt->close();
        }
    }

    if (!$device) {
        return [
            'ok' => false,
            'http_code' => 403,
            'message' => 'device not registered',
        ];
    }

    if (!hash_equals($device['device_token'], $deviceToken)) {
        return [
            'ok' => false,
            'http_code' => 403,
            'message' => 'invalid device token',
        ];
    }

    if ($requireActive && (int)$device['is_active'] !== 1) {
        return [
            'ok' => false,
            'http_code' => 403,
            'message' => 'device not activated',
        ];
    }

    return [
        'ok' => true,
        'device' => $device,
    ];
}

require_once __DIR__ . '/connectDB.php'; // тут появляется $dbcnx (mysqli)

/**
 * Текущий пользователь из сессии или null.
 */
function auth_current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * Залогинен ли пользователь.
 */
function auth_is_logged_in(): bool {
    return isset($_SESSION['user']);
}

/**
 * Проверка роли по коду ('ADMIN', 'WORKER', 'ANALYST').
 */
function auth_has_role(string $roleCode): bool {
    $user = auth_current_user();
    if (!$user) {
        return false;
    }

    // Теперь роль одна, храним в $user['role']
    if (!empty($user['role']) && $user['role'] === $roleCode) {
        return true;
    }

    // На всякий случай поддержим старый массив 'roles'
    if (!empty($user['roles']) && in_array($roleCode, $user['roles'], true)) {
        return true;
    }

    return false;
}

/**
 * Загружает разрешения по ролям из БД.
 */
function auth_load_permissions_for_roles(array $roleCodes): array {
    global $dbcnx;

    $roleCodes = array_values(array_filter($roleCodes, 'strlen'));
    if (!$roleCodes) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($roleCodes), '?'));
    $types = str_repeat('s', count($roleCodes));
    $sql = "
        SELECT DISTINCT rp.permission_code
        FROM role_permissions rp
        WHERE rp.role_code IN ({$placeholders})
    ";

    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        error_log('auth_load_permissions_for_roles prepare error: ' . $dbcnx->error);
        return [];
    }

    $stmt->bind_param($types, ...$roleCodes);
    if (!$stmt->execute()) {
        error_log('auth_load_permissions_for_roles execute error: ' . $stmt->error);
        $stmt->close();
        return [];
    }

    $res = $stmt->get_result();
    $permissions = [];
    while ($row = $res->fetch_assoc()) {
        if (!empty($row['permission_code'])) {
            $permissions[] = $row['permission_code'];
        }
    }
    $stmt->close();

    return $permissions;
}

/**
 * Проверка права по коду (например, warehouse.stock.manage).
 */
function auth_has_permission(string $permission): bool {
    $permissions = $_SESSION['permissions'] ?? [];
    if (isset($permissions['*'])) {
        return true;
    }
    return isset($permissions[$permission]);
}

/**
 * Обязателен логин, иначе редирект на страницу логина.
 */
function auth_require_login(): void {
    if (!auth_is_logged_in()) {
        header("Location: /login.html");
        exit;
    }
}


/**
 * Обязательное право (например, warehouse.stock.manage).
 */
function auth_require_permission(string $permission): void {
    auth_require_login();
    if (!auth_has_permission($permission)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

/**
 * Обязательная роль (например, только админ).
 */
function auth_require_role(string $roleCode): void {
    auth_require_login();
    if (!auth_has_role($roleCode)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

/**
 * Проверка права по action (view_users, view_devices, save_user и т.п.).
 */
function auth_can_action(string $action): bool {
    $allowed = $_SESSION['allowed_actions'] ?? [];
    if (isset($allowed['*'])) {
        return true;
    }
    return isset($allowed[$action]);
}

/**
 * Обязательное право по action.
 */
function auth_require_action(string $action): void {
    auth_require_login();
    if (!auth_can_action($action)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

/**
 * Гарантирует наличие пункта меню "Connectors" в группе Settings.
 
function auth_add_connectors_menu(array &$menuTree, array &$allowedActions): void {
    $groupCode = 'settings';
    $actionCode = 'view_connectors';
    $menuKey = 'settings.connectors';

    if (!isset($menuTree[$groupCode])) {
        $menuTree[$groupCode] = [
            'code'  => $groupCode,
            'title' => 'Setting',
            'icon'  => 'bi bi-gear-wide',
            'items' => [],
        ];
    }

    $exists = false;
    foreach ($menuTree[$groupCode]['items'] as $item) {
        if (($item['menu_key'] ?? '') === $menuKey || ($item['action'] ?? '') === $actionCode) {
            $exists = true;
            break;
        }
    }

    if (!$exists) {
        $menuTree[$groupCode]['items'][] = [
            'menu_key' => $menuKey,
            'title'    => 'Connectors',
            'icon'     => 'bi bi-plug',
            'action'   => $actionCode,
        ];
    }

    $allowedActions[$actionCode] = true;
}
*/

/**
 * Логин: проверка username/password, загрузка меню/прав, обновление статистики, запись в сессию и аудит.
 */
function auth_login(string $username, string $password): bool {
    global $dbcnx;

    $sql = "SELECT id,
                   username,
                   password_hash,
                   full_name,
                   email,
                   is_active,
                   role,
                   ui_lang,
                   ui_settings
            FROM users
            WHERE username = ?
            LIMIT 1";
    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res  = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if (!$user || (int)$user['is_active'] !== 1) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    $userId   = (int)$user['id'];
    $roleCode = $user['role'] ?: 'USER';

    // Разбор ui_settings JSON (если есть)
    $settings = [];
    if (!empty($user['ui_settings'])) {
        $tmp = json_decode($user['ui_settings'], true);
        if (is_array($tmp)) {
            $settings = $tmp;
        }
    }

    // Обновление статистики входа
    $ip = $_SERVER['REMOTE_ADDR']     ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $sqlUpd = "UPDATE users
               SET last_login_at   = CURRENT_TIMESTAMP(6),
                   login_count     = login_count + 1,
                   last_login_ip   = ?,
                   last_user_agent = ?
               WHERE id = ?";
    $stmtU = $dbcnx->prepare($sqlUpd);
    if ($stmtU) {
        $stmtU->bind_param("ssi", $ip, $ua, $userId);
        $stmtU->execute();
        $stmtU->close();
    } else {
        error_log('auth_login update prepare error: ' . $dbcnx->error);
    }

    // === Тянем меню и права для роли из БД ===
    $menuTree       = [];
    $allowedActions = [];

    $sqlMenu = "
        SELECT
            mg.code  AS group_code,
            mg.title AS group_title,
            mg.icon  AS group_icon,
            mi.menu_key,
            mi.title AS item_title,
            mi.icon  AS item_icon,
            mi.action
        FROM role_menu rm
        JOIN menu_items mi
          ON mi.menu_key = rm.menu_key
         AND mi.is_active = 1
        JOIN menu_groups mg
          ON mg.code = mi.group_code
         AND mg.is_active = 1
        WHERE rm.role_code  = ?
          AND rm.is_allowed = 1
        ORDER BY mg.sort_order, mi.sort_order, mi.id
    ";

    $stmtM = $dbcnx->prepare($sqlMenu);
    if ($stmtM) {
        $stmtM->bind_param("s", $roleCode);
        $stmtM->execute();
        $resM = $stmtM->get_result();

        while ($row = $resM->fetch_assoc()) {
            $gCode = $row['group_code'];

            if (!isset($menuTree[$gCode])) {
                $menuTree[$gCode] = [
                    'code'  => $gCode,
                    'title' => $row['group_title'],
                    'icon'  => $row['group_icon'],
                    'items' => [],
                ];
            }

            $menuTree[$gCode]['items'][] = [
                'menu_key' => $row['menu_key'],
                'title'    => $row['item_title'],
                'icon'     => $row['item_icon'],
                'action'   => $row['action'],
            ];

            $allowedActions[$row['action']] = true;
        }

        $stmtM->close();
    }

    $permissions = auth_load_permissions_for_roles([$roleCode]);

//    auth_add_connectors_menu($menuTree, $allowedActions);

    // Пишем всё в сессию
    $_SESSION['user'] = [
        'id'        => $userId,
        'username'  => $user['username'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'role'      => $roleCode,
        'roles'     => [$roleCode],                 // для старого кода
        'ui_lang'   => $user['ui_lang'] ?? 'uk',
        'settings'  => $settings,
    ];

    $_SESSION['menu']            = $menuTree;       // дерево меню для сайдбара
    $_SESSION['allowed_actions'] = $allowedActions; // разрешённые экшены
    $_SESSION['permissions']     = array_fill_keys($permissions, true);

    ////audit_log($userId, 'LOGIN', null, null, 'Пользователь вошёл в систему');
    try {
       audit_log($userId, 'LOGIN', null, null, 'Пользователь вошёл в систему');
     } catch (Throwable $e) {
       error_log('audit_log failed: ' . $e->getMessage());
     }
    return true;
}

/**
 * Логаут + аудит.
 */
function auth_logout(): void {
    $user = auth_current_user();
    if ($user) {
        audit_log((int)$user['id'], 'LOGOUT', null, null, 'Пользователь вышел из системы');
    }

    $_SESSION['user'] = null;
    unset($_SESSION['user']);
}

/**
 * Запись события в таблицу audit_logs.
 */
function audit_log(
    $userId,
    $eventType,
    $entityType,
    $entityId,
    $description,
    array $extra = []
): void {
    global $dbcnx;

    $uid  = (int) (microtime(true) * 1000000); // UID на основе времени
    $ip   = $_SERVER['REMOTE_ADDR']     ?? null;
    $ua   = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $json = $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null;

    $sql = "INSERT INTO audit_logs (
                uid_created, event_time, user_id, event_type,
                entity_type, entity_id, ip_address, user_agent,
                description, extra_data
            ) VALUES (
                ?, CURRENT_TIMESTAMP(6), ?, ?, ?, ?, ?, ?, ?, ?
            )";

    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        error_log('audit_log prepare error: ' . $dbcnx->error);
        return;
    }

    $stmt->bind_param(
        "iississss",
        $uid,
        $userId,
        $eventType,
        $entityType,
        $entityId,
        $ip,
        $ua,
        $description,
        $json
    );

    if (!$stmt->execute()) {
        error_log('audit_log execute error: ' . $stmt->error);
    }

    $stmt->close();
}


function auth_login_by_qr_token(string $qrToken): ?array {
    global $dbcnx;

    $qrToken = trim($qrToken);
    if ($qrToken === '') {
        return null;
    }

    // Берём те же поля, что и в auth_login
    $sql = "SELECT id,
                   username,
                   full_name,
                   email,
                   is_active,
                   role,
                   ui_lang,
                   ui_settings,
                   qr_login_token
              FROM users
             WHERE qr_login_token = ?
             LIMIT 1";
    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("s", $qrToken);
    $stmt->execute();
    $res  = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if (!$user || (int)$user['is_active'] !== 1) {
        return null;
    }

    $userId   = (int)$user['id'];
    $roleCode = $user['role'] ?: 'USER';

    // Разбор ui_settings (как в auth_login)
    $settings = [];
    if (!empty($user['ui_settings'])) {
        $tmp = json_decode($user['ui_settings'], true);
        if (is_array($tmp)) {
            $settings = $tmp;
        }
    }

    // Обновляем статистику входа
    $ip = $_SERVER['REMOTE_ADDR']     ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $sqlUpd = "UPDATE users
                  SET last_login_at   = CURRENT_TIMESTAMP(6),
                      login_count     = login_count + 1,
                      last_login_ip   = ?,
                      last_user_agent = ?
                WHERE id = ?";
    $stmtU = $dbcnx->prepare($sqlUpd);
    if ($stmtU) {
        $stmtU->bind_param("ssi", $ip, $ua, $userId);
        $stmtU->execute();
        $stmtU->close();
    } else {
        error_log('auth_login_by_qr_token update prepare error: ' . $dbcnx->error);
    }

    $permissions = auth_load_permissions_for_roles([$roleCode]);

    // === СТРОИМ МЕНЮ И ПРАВА ТОЧНО ТАК ЖЕ, КАК В auth_login ===
    $menuTree       = [];
    $allowedActions = [];

    $sqlMenu = "
        SELECT
            mg.code  AS group_code,
            mg.title AS group_title,
            mg.icon  AS group_icon,
            mi.menu_key,
            mi.title AS item_title,
            mi.icon  AS item_icon,
            mi.action
        FROM role_menu rm
        JOIN menu_items mi
          ON mi.menu_key = rm.menu_key
         AND mi.is_active = 1
        JOIN menu_groups mg
          ON mg.code = mi.group_code
         AND mg.is_active = 1
        WHERE rm.role_code  = ?
          AND rm.is_allowed = 1
        ORDER BY mg.sort_order, mi.sort_order, mi.id
    ";

    if ($stmtM = $dbcnx->prepare($sqlMenu)) {
        $stmtM->bind_param("s", $roleCode);
        $stmtM->execute();
        $resM = $stmtM->get_result();

        while ($row = $resM->fetch_assoc()) {
            $gCode = $row['group_code'];

            if (!isset($menuTree[$gCode])) {
                $menuTree[$gCode] = [
                    'code'  => $gCode,
                    'title' => $row['group_title'],
                    'icon'  => $row['group_icon'],
                    'items' => [],
                ];
            }

            $menuTree[$gCode]['items'][] = [
                'menu_key' => $row['menu_key'],
                'title'    => $row['item_title'],
                'icon'     => $row['item_icon'],
                'action'   => $row['action'],
            ];

            if (!empty($row['action'])) {
                $allowedActions[$row['action']] = true;
            }
        }

        $stmtM->close();
    }

//    auth_add_connectors_menu($menuTree, $allowedActions);

    // Пишем в сессию то же самое, что и auth_login
    $_SESSION['user'] = [
        'id'        => $userId,
        'username'  => $user['username'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'role'      => $roleCode,
        'roles'     => [$roleCode],
        'ui_lang'   => $user['ui_lang'] ?? 'uk',
        'settings'  => $settings,
    ];

    $_SESSION['menu']            = $menuTree;
    $_SESSION['allowed_actions'] = $allowedActions;
    $_SESSION['permissions']     = array_fill_keys($permissions, true);

    audit_log(
        $userId,
        'QR_LOGIN',
        'USER',
        $userId,
        'Вход по QR-токену',
        ['ip' => $ip, 'ua' => $ua]
    );

    // Возвращаем уже "сессионную" версию юзера
    return $_SESSION['user'];
}


/**
 * Загрузка прав/меню для роли из таблицы role_menu.
 * Возвращает массив вида:
 * [
 *   'view_users'  => ['can_view' => true, 'can_edit' => true, 'can_delete' => true],
 *   'view_devices'=> ['can_view' => true, 'can_edit' => false, ...],
 *   ...
 * ]
 */
function auth_load_role_menu(string $roleCode): array {
    global $dbcnx;

    $perms = [];

    $sql = "SELECT menu_key, can_view, can_edit, can_delete
              FROM role_menu
             WHERE role_code = ?";
    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        error_log('auth_load_role_menu prepare error: ' . $dbcnx->error);
        return $perms;
    }

    $stmt->bind_param("s", $roleCode);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $key = $row['menu_key'];
        $perms[$key] = [
            'can_view'   => ((int)$row['can_view']   === 1),
            'can_edit'   => ((int)$row['can_edit']   === 1),
            'can_delete' => ((int)$row['can_delete'] === 1),
        ];
    }

    $stmt->close();
    return $perms;
}
