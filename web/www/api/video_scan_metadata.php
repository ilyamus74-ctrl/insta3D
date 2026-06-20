<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
auth_require_login();

$user = auth_current_user();
$userId = (int)$user['id'];
$role = (string)($user['role'] ?? 'BROKER');
$scanId = (int)($_GET['scan_id'] ?? 0);
$type = (string)($_GET['type'] ?? '');

$types = [
    'camera_info' => ['suffix' => '_camera_info.json', 'content_type' => 'application/json', 'download' => false],
    'manifest' => ['suffix' => '_manifest.json', 'content_type' => 'application/json', 'download' => false],
    'imu' => ['suffix' => '_imu.jsonl', 'content_type' => 'application/x-ndjson', 'download' => true],
];

if ($scanId <= 0 || !isset($types[$type])) {
    http_response_code(400);
    exit('Bad metadata request');
}

function metadata_safe_name(string $value, string $fallback): string {
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $value);
    return $safe !== '' ? $safe : $fallback;
}

$stmt = $dbcnx->prepare("SELECT vs.id, vs.app_scan_uuid, cs.id session_id, cs.app_session_uuid, cs.order_id, o.broker_id, o.operator_id FROM video_scans vs JOIN capture_sessions cs ON cs.id = vs.session_id JOIN tour_orders o ON o.id = cs.order_id WHERE vs.id = ? AND vs.deleted_at IS NULL AND cs.deleted_at IS NULL LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    exit('DB prepare error');
}
$stmt->bind_param('i', $scanId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$row) {
    http_response_code(404);
    exit('Metadata not found');
}

$canView = $role === 'ADMIN' || (int)$row['broker_id'] === $userId || ($role === 'OPERATOR' && (int)$row['operator_id'] === $userId);
if (!$canView) {
    http_response_code(403);
    exit('Forbidden');
}

$orderId = (int)$row['order_id'];
$safeSessionUuid = metadata_safe_name((string)$row['app_session_uuid'], 'session_' . (int)$row['session_id']);
$safeScanUuid = metadata_safe_name((string)$row['app_scan_uuid'], 'scan_' . $scanId);
$filename = $safeScanUuid . $types[$type]['suffix'];
$dir = APP_STORAGE_DIR . '/orders/' . $orderId . '/sessions/' . $safeSessionUuid . '/videos';
$realDir = realpath($dir);
$realFile = $realDir !== false ? realpath($realDir . '/' . $filename) : false;

if ($realDir === false || $realFile === false || !is_file($realFile) || strpos($realFile, $realDir . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit('Metadata not found');
}

header('Content-Type: ' . $types[$type]['content_type'] . '; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if ($types[$type]['download']) {
    header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"\\') . '"');
}
header('Content-Length: ' . (string)filesize($realFile));
readfile($realFile);