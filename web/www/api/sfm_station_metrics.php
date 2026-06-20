<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
auth_require_login();
header('Content-Type: application/json; charset=utf-8');

$cacheFile = '/home/makler/web/remote_station/output/station_metrics.json';

if (!is_file($cacheFile) || !is_readable($cacheFile)) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'message' => 'station metrics cache not available yet',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents($cacheFile);
$cacheMtime = filemtime($cacheFile) ?: time();
$cacheAgeSec = max(0, time() - $cacheMtime);
$data = is_string($raw) ? json_decode($raw, true) : null;

if (!is_array($data)) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'message' => 'station metrics cache is invalid',
        'cache_mtime' => $cacheMtime,
        'cache_age_sec' => $cacheAgeSec,
        'stale' => $cacheAgeSec > 30,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$data['cache_mtime'] = $cacheMtime;
$data['cache_age_sec'] = $cacheAgeSec;
if ($cacheAgeSec > 30) {
    $data['stale'] = true;
}

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
