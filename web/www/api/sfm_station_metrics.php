<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
auth_require_login();
header('Content-Type: application/json; charset=utf-8');
$script = '/home/makler/web/remote_station/get_station_metrics.sh';
$config = '/home/makler/web/remote_station/stations.conf';
$cmd = escapeshellarg($script) . ' ' . escapeshellarg($config) . ' 2>&1';
$output = [];
$code = 0;
@exec($cmd, $output, $code);
$raw = implode("\n", $output);
$data = json_decode($raw, true);
if ($code !== 0 || !is_array($data)) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'message' => $raw !== '' ? $raw : 'station metrics command failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);