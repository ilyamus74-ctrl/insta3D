<?php
declare(strict_types=1);

require_once __DIR__ . '../../../configs/connectDB.php';
require_once __DIR__ . '../../../configs/secure.php'; // session + audit_log

header('Content-Type: application/json; charset=utf-8');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'invalid json'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mode = $data['mode'] ?? 'enroll';

global $dbcnx;
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

switch ($mode) {

    // ==================== 1) ENROLL / PING УСТРОЙСТВА ====================
    case 'enroll':
    default:
        $deviceUid  = trim($data['device_uid']  ?? '');
        $name       = trim($data['name']        ?? '');
        $serial     = trim($data['serial']      ?? '');
        $model      = trim($data['model']       ?? '');
        $appVersion = trim($data['app_version'] ?? '');

        if ($deviceUid === '') {
            http_response_code(400);
            echo json_encode(
                ['status' => 'error', 'message' => 'device_uid required'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        $stmt = $dbcnx->prepare("SELECT id, device_token, is_active FROM devices WHERE device_uid = ? LIMIT 1");
        $stmt->bind_param("s", $deviceUid);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if ($row) {
            $deviceId    = (int)$row['id'];
            $deviceToken = $row['device_token'];
            $isActive    = (int)$row['is_active'];

            $sql = "UPDATE devices
                       SET name        = ?,
                           serial      = ?,
                           model       = ?,
                           app_version = ?,
                           last_seen_at = CURRENT_TIMESTAMP(6),
                           last_ip     = ?
                     WHERE id = ?";
            $stmt = $dbcnx->prepare($sql);
            $stmt->bind_param("sssssi", $name, $serial, $model, $appVersion, $ip, $deviceId);
            $stmt->execute();
            $stmt->close();

        } else {
            $deviceToken = bin2hex(random_bytes(16));
            $uidCreated  = (int)(microtime(true) * 1000000);

            $sql = "INSERT INTO devices (
                        uid_created, device_uid, name, serial, model,
                        app_version, device_token, is_active, last_seen_at, last_ip
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP(6), ?
                    )";
            $stmt = $dbcnx->prepare($sql);
            $stmt->bind_param(
                "isssssss",
                $uidCreated,
                $deviceUid,
                $name,
                $serial,
                $model,
                $appVersion,
                $deviceToken,
                $ip
            );
            $stmt->execute();
            $deviceId = $stmt->insert_id;
            $stmt->close();

            $isActive = 0;
        }

        audit_log(
            null,
            'DEVICE_ENROLL',
            'DEVICE',
            $deviceId ?? null,
            'Запрос регистрации/обновления устройства',
            [
                'mode'        => 'enroll',
                'device_uid'  => $deviceUid,
                'name'        => $name,
                'serial'      => $serial,
                'model'       => $model,
                'app_version' => $appVersion,
                'ip'          => $ip,
            ]
        );

        echo json_encode(
            [
                'status'       => 'ok',
                'device_token' => $deviceToken,
                'is_active'    => (int)$isActive,
            ],
            JSON_UNESCAPED_UNICODE
        );
        break;

    // ==================== 2) QR-LOGIN ПОЛЬЗОВАТЕЛЯ ====================
    case 'qr_login':
        $deviceUid   = trim($data['device_uid']   ?? '');
        $deviceToken = trim($data['device_token'] ?? '');
        $qrToken     = trim($data['qr_token']     ?? '');

        if ($deviceUid === '' || $deviceToken === '' || $qrToken === '') {
            http_response_code(400);
            echo json_encode(
                ['status' => 'error', 'message' => 'device_uid, device_token и qr_token обязательны'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        // Проверяем устройство и его активность
/*        $stmt = $dbcnx->prepare("SELECT id, is_active FROM devices WHERE device_uid = ? AND device_token = ? LIMIT 1");
        $stmt->bind_param("ss", $deviceUid, $deviceToken);
        $stmt->execute();
        $res = $stmt->get_result();
        $dev = $res->fetch_assoc();
        $stmt->close();

        if (!$dev) {
            echo json_encode(
                ['status' => 'error', 'message' => 'unknown device or bad token'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        if ((int)$dev['is_active'] !== 1) {
*/
        $auth = auth_device_by_token($deviceUid, $deviceToken, true);
        if (!$auth['ok']) {
            echo json_encode(
//                ['status' => 'error', 'message' => 'device not activated'],
                ['status' => 'error', 'message' => $auth['message'] ?? 'auth error'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

//        $deviceId = (int)$dev['id'];
        $deviceId = (int)$auth['device']['id'];
        // Логиним пользователя по QR-токену
        $user = auth_login_by_qr_token($qrToken);
        if (!$user) {
            echo json_encode(
                ['status' => 'error', 'message' => 'invalid or disabled QR token'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        // Можно привязать сессию к устройству (если нужно)
        $_SESSION['device_id']  = $deviceId;
        $_SESSION['device_uid'] = $deviceUid;

        audit_log(
            $user['id'] ?? null,
            'QR_DEVICE_LOGIN',
            'DEVICE',
            $deviceId,
            'Вход по QR-токену с мобильного устройства',
            [
                'device_uid'  => $deviceUid,
                'ip'          => $ip,
                'app_version' => $data['app_version'] ?? null,
            ]
        );

        echo json_encode(
            [
                'status' => 'ok',
                'user_id' => (int)$user['id'],
                'role'    => $user['role'] ?? 'USER',
                'session_id'=> session_id(),            // <<< ЭТО ДОБАВИЛИ
            ],
            JSON_UNESCAPED_UNICODE
        );
        break;
}
