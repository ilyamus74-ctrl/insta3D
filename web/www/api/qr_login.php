<?php
declare(strict_types=1);

require_once __DIR__ . '../../../configs/secure.php'; // даёт $dbcnx и audit_log

header('Content-Type: application/json; charset=utf-8');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'invalid json'], JSON_UNESCAPED_UNICODE);
    exit;
}

$deviceUid    = trim($data['device_uid']    ?? '');
$deviceToken  = trim($data['device_token']  ?? '');
$qrToken      = trim($data['qr_token']      ?? ''); // токен из QR-кода на бейджике

if ($deviceUid === '' || $deviceToken === '' || $qrToken === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing params'], JSON_UNESCAPED_UNICODE);
    exit;
}

global $dbcnx;
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

/**
 * 1) Проверяем устройство
 */
$stmt = $dbcnx->prepare("
    SELECT id, device_token, is_active
      FROM devices
     WHERE device_uid = ?
     LIMIT 1
");
/*
$stmt->bind_param("s", $deviceUid);
$stmt->execute();
$resDev = $stmt->get_result();
$dev = $resDev->fetch_assoc();
$stmt->close();

if (!$dev) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'device not registered'], JSON_UNESCAPED_UNICODE);
*/
$auth = auth_device_by_token($deviceUid, $deviceToken, true);
if (!$auth['ok']) {
    http_response_code($auth['http_code'] ?? 403);
    echo json_encode(['status' => 'error', 'message' => $auth['message'] ?? 'auth error'], JSON_UNESCAPED_UNICODE);
    exit;
}
/*
// проверяем токен устройства
if (!hash_equals($dev['device_token'], $deviceToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'invalid device token'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ВАЖНО: проверка is_active
if ((int)$dev['is_active'] !== 1) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'device not activated'], JSON_UNESCAPED_UNICODE);
    exit;
}
*/
$dev = $auth['device'];
/**
 * 2) Ищем пользователя по QR-токену
 *    Имя колонки подставь своё: qr_login_token / qr_token / что сделал
 */
$stmt = $dbcnx->prepare("
    SELECT id, username, full_name, email, role, is_active
      FROM users
     WHERE qr_login_token = ?
     LIMIT 1
");
$stmt->bind_param("s", $qrToken);
$stmt->execute();
$resUser = $stmt->get_result();
$user = $resUser->fetch_assoc();
$stmt->close();

if (!$user || (int)$user['is_active'] !== 1) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'user not found or inactive'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 3) Тут ты решаешь, как выдавать "сессию" приложению:
 *    - либо короткий access_token, который потом отправляется во все API
 *    - либо просто подтверждение, что этот user/device ок
 */
$accessToken = bin2hex(random_bytes(16));
// По уму — сохранить accessToken в отдельную таблицу api_sessions

// Аудит
audit_log(
    $user['id'],
    'QR_LOGIN',
    'DEVICE',
    (int)$dev['id'],
    'QR-логин пользователя через устройство',
    [
        'device_uid' => $deviceUid,
        'ip'         => $ip,
    ]
);

echo json_encode(
    [
        'status'       => 'ok',
        'access_token' => $accessToken,
        'user'         => [
            'id'        => (int)$user['id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'],
            'role'      => $user['role'],
        ],
        'device_id' => (int)$dev['id'],
    ],
    JSON_UNESCAPED_UNICODE
);
