<?php

declare(strict_types=1);

/**
 * Проверяет, относится ли действие к устройствам.
 */
function device_actions_can_handle(string $action): bool
{
    return in_array($action, ['view_devices', 'activate_device', 'form_edit_device', 'save_device'], true);
}

/**
 * Маршрутизирует действия, связанные с устройствами.
 */
function handle_device_action(string $action, array $user): array
{
    switch ($action) {
        case 'view_devices':
            return handle_view_devices($user);
        case 'activate_device':
            return handle_activate_device($user);
        case 'form_edit_device':
            return handle_form_edit_device($user);
        case 'save_device':
            return handle_save_device($user);
        default:
            return [
                'status'  => 'error',
                'message' => 'Unknown device action',
            ];
    }
}

/**
 * Отображает список устройств.
 */
function handle_view_devices(array $user): array
{
    global $dbcnx, $smarty;

    $devices = [];

    $sql = "SELECT id, device_uid, name, serial, model, app_version,
                   is_active, last_seen_at, last_ip,
                   created_at, activated_at
              FROM devices
          ORDER BY created_at DESC";
    if ($res = $dbcnx->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $devices[] = $row;
        }
        $res->free();
    }

    $smarty->assign('devices', $devices);
    $smarty->assign('current_user', $user);

    ob_start();
    $smarty->display('cells_NA_API_devices.html');
    $html = ob_get_clean();

    return [
        'status' => 'ok',
        'html'   => $html,
    ];
}

/**
 * Активирует или деактивирует устройство.
 */
function handle_activate_device(array $user): array
{
    global $dbcnx;

    auth_require_role('ADMIN');

    $deviceId  = (int)($_POST['device_id'] ?? 0);
    $isActive  = (int)($_POST['is_active'] ?? 0) ? 1 : 0;

    if ($deviceId <= 0) {
        return [
            'status'  => 'error',
            'message' => 'device_id required',
        ];
    }

    $sql = "UPDATE devices
               SET is_active = ?,
                   activated_at = CASE
                       WHEN ? = 1 AND activated_at IS NULL
                       THEN CURRENT_TIMESTAMP(6)
                       ELSE activated_at
                   END
             WHERE id = ?";
    $stmt = $dbcnx->prepare($sql);
    $stmt->bind_param("iii", $isActive, $isActive, $deviceId);
    $stmt->execute();
    $stmt->close();

    audit_log(
        $user['id'] ?? null,
        $isActive ? 'DEVICE_ACTIVATE' : 'DEVICE_DEACTIVATE',
        'DEVICE',
        $deviceId,
        $isActive ? 'Активация устройства' : 'Деактивация устройства'
    );

    return [
        'status'  => 'ok',
        'message' => 'Статус устройства обновлён',
    ];
}

/**
 * Возвращает данные устройства и связанные логи для формы редактирования.
 */
function handle_form_edit_device(array $user): array
{
    global $dbcnx, $smarty;

    $deviceId = (int)($_POST['device_id'] ?? 0);
    if ($deviceId <= 0) {
        return [
            'status'  => 'error',
            'message' => 'device_id required',
        ];
    }

    $sql = "SELECT
                id,
                device_uid,
                name,
                serial,
                model,
                app_version,
                device_token,
                is_active,
                last_seen_at,
                last_ip,
                created_at,
                updated_at,
                activated_at,
                notes
            FROM devices
           WHERE id = ?
           LIMIT 1";
    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        return [
            'status'  => 'error',
            'message' => 'DB error (prepare form_edit_device)',
        ];
    }
    $stmt->bind_param("i", $deviceId);
    $stmt->execute();
    $resD   = $stmt->get_result();
    $device = $resD->fetch_assoc();
    $stmt->close();

    if (!$device) {
        return [
            'status'  => 'error',
            'message' => 'Устройство не найдено',
        ];
    }

    $logs = [];

    $sqlL = "SELECT
                event_time,
                user_id,
                event_type,
                entity_id,
                ip_address,
                user_agent,
                description
             FROM audit_logs
             WHERE entity_type = 'DEVICE'
               AND entity_id   = ?
             ORDER BY event_time DESC
             LIMIT 20";

    if ($stmtL = $dbcnx->prepare($sqlL)) {
        $stmtL->bind_param("i", $deviceId);
        $stmtL->execute();
        $resL = $stmtL->get_result();
        while ($row = $resL->fetch_assoc()) {
            $logs[] = $row;
        }
        $stmtL->close();
    }

    $device['logs'] = $logs;

    $smarty->assign('device',       $device);
    $smarty->assign('current_user', $user);

    ob_start();
    $smarty->display('cells_NA_API_devices_profile.html');
    $html = ob_get_clean();

    return [
        'status' => 'ok',
        'html'   => $html,
    ];
}

/**
 * Сохраняет изменения по устройству или удаляет его.
 */
function handle_save_device(array $user): array
{
    global $dbcnx;

    auth_require_role('ADMIN');

    $deviceId = (int)($_POST['device_id'] ?? 0);
    if ($deviceId <= 0) {
        return [
            'status'  => 'error',
            'message' => 'device_id required',
        ];
    }

    $deleteFlag = !empty($_POST['delete']);

    if ($deviceId > 0 && $deleteFlag) {
        $oldDevice = null;
        if ($stmtOld = $dbcnx->prepare('SELECT * FROM devices WHERE id = ? LIMIT 1')) {
            $stmtOld->bind_param('i', $deviceId);
            $stmtOld->execute();
            $resOld   = $stmtOld->get_result();
            $oldDevice = $resOld ? $resOld->fetch_assoc() : null;
            $stmtOld->close();
        }

        $stmtDel = $dbcnx->prepare('DELETE FROM devices WHERE id = ?');
        if ($stmtDel) {
            $stmtDel->bind_param('i', $deviceId);
            $stmtDel->execute();
            $stmtDel->close();
        }

        audit_log(
            $user['id'] ?? null,
            'DEVICE_DELETE',
            'DEVICE',
            $deviceId,
            'Устройство удалено из профиля',
            $oldDevice ?: []
        );

        return [
            'status'  => 'ok',
            'message' => 'Устройство удалено',
            'deleted' => true,
        ];
    }

    $name  = trim($_POST['name']  ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $isActive = !empty($_POST['is_active']) ? 1 : 0;

    $sql = "UPDATE devices
               SET notes      = ?,
                   is_active  = ?,
                   activated_at = CASE
                       WHEN ? = 1 AND activated_at IS NULL
                       THEN CURRENT_TIMESTAMP(6)
                       ELSE activated_at
                   END
             WHERE id = ?";
    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        return [
            'status'  => 'error',
            'message' => 'DB error (prepare save_device)',
        ];
    }

    $stmt->bind_param("siii", $notes, $isActive, $isActive, $deviceId);
    $stmt->execute();
    $stmt->close();

    audit_log(
        $user['id'] ?? null,
        'DEVICE_UPDATE',
        'DEVICE',
        $deviceId,
        'Изменение данных устройства',
        [
            'notes'     => $notes,
            'is_active' => $isActive,
        ]
    );

    return [
        'status'  => 'ok',
        'message' => 'Устройство обновлено',
    ];
}