<?php
declare(strict_types=1);

//file_put_contents('_tmp/stand_push_raw.log', date('c')." RAW=[".$raw."]\n", FILE_APPEND);
//file_put_contents('_tmp/stand_push_ct.log', date('c')." CT=[".($_SERVER['CONTENT_TYPE'] ?? '')."]\n", FILE_APPEND);

require_once __DIR__ . '../../../configs/secure.php'; // даёт $dbcnx и audit_log

header('Content-Type: application/json; charset=utf-8');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'invalid json'], JSON_UNESCAPED_UNICODE);
    exit;
}

$standId = trim($data['stand_id'] ?? '');
$deviceUid = trim($data['device_uid'] ?? '');
$deviceToken = trim($data['device_token'] ?? '');
$measurements = $data['measurements'] ?? null;

if ($standId === '' && $deviceUid !== '') {
    $standId = $deviceUid;
}

/*
if ($standId === '' || $deviceToken === '' || $measurements === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing params'], JSON_UNESCAPED_UNICODE);
    exit;
}
*/
$debug = !empty($data['debug']);
$debugPayload = $data['debug_payload'] ?? null;

if ($standId === '' || $deviceToken === '') {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'missing stand_id or device_token'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// В PROD measurements обязательны
if (!$debug && $measurements === null) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'missing measurements'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// В DEBUG разрешаем measurements=null, но нужен debug_payload
if ($debug && !is_array($debugPayload)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'missing debug_payload'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$auth = auth_device_by_stand_id($standId, $deviceToken, true);
if (!$auth['ok']) {
    http_response_code($auth['http_code'] ?? 403);
    echo json_encode(['status' => 'error', 'message' => $auth['message'] ?? 'auth error'], JSON_UNESCAPED_UNICODE);
    exit;
}


$safeStandId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $standId);
$path = "_tmp/stand_measurement_{$safeStandId}.json";

$baseDir = __DIR__ . '/_tmp';

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0775, true);
}

if ($debug) {
    $debugPath = "{$baseDir}/stand_debug_{$safeStandId}.json";

    file_put_contents(
        $debugPath,
        json_encode([
            'stand_id' => $standId,
            'device_id' => (int)$auth['device']['id'],
            'timestamp' => time(),
            'debug_payload' => $debugPayload,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}


$payload = [
    'stand_id' => $standId,
    'device_id' => (int)$auth['device']['id'],
    'timestamp' => time(),
    'measurements' => $measurements,
];

$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
if ($json === false || file_put_contents($path, $json, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'failed to store measurements'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);
