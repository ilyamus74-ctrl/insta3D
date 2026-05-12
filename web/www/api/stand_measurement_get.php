<?php
declare(strict_types=1);

require_once __DIR__ . '../../../configs/secure.php'; // даёт $dbcnx и audit_log

header('Content-Type: application/json; charset=utf-8');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    $data = [];
}

$standId = trim($data['stand_id'] ?? ($_GET['stand_id'] ?? ''));
$deviceUid = trim($data['device_uid'] ?? ($_GET['device_uid'] ?? ''));
$deviceToken = trim($data['device_token'] ?? ($_GET['device_token'] ?? ''));

if ($standId === '' || $deviceUid === '' || $deviceToken === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing params'], JSON_UNESCAPED_UNICODE);
    exit;
}

$auth = auth_device_by_token($deviceUid, $deviceToken, true);
if (!$auth['ok']) {
    http_response_code($auth['http_code'] ?? 403);
    echo json_encode(['status' => 'error', 'message' => $auth['message'] ?? 'auth error'], JSON_UNESCAPED_UNICODE);
    exit;
}

$safeStandId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $standId);
///$path = "/tmp/stand_measurement_{$safeStandId}.json";
$path = __DIR__ . "/_tmp/stand_measurement_{$safeStandId}.json";

if (!is_file($path)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'no measurements'], JSON_UNESCAPED_UNICODE);
    exit;
}

$contents = file_get_contents($path);
if ($contents === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'failed to read measurements'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode($contents, true);
if (!is_array($payload)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'invalid stored data'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['status' => 'ok', 'data' => $payload], JSON_UNESCAPED_UNICODE);
