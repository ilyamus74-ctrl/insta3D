<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'web/remote_station/dual_phone_host/src/stereo_depth_runtime.hpp',
    'web/remote_station/dual_phone_host/src/stereo_depth_runtime.cpp',
    'web/remote_station/dual_phone_host/src/stereo_preview.cpp',
    'web/remote_station/dual_phone_host/src/http_dashboard.cpp',
    'web/remote_station/dual_phone_host/web/index.html',
    'web/remote_station/dual_phone_host/scripts/pack_session.sh',
];
$text = '';
foreach ($files as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "missing: $file\n");
        exit(1);
    }
    $text .= file_get_contents($path) . "\n";
}

$required = [
    'FHD_1920',
    'ULTRA_960',
    'HIGH_640',
    'QUALITY_480',
    'BALANCED_320',
    'THROTTLED_320',
    'processing_p95_ms',
    'source_upscaled',
    'median_depth_m',
    'stable_coverage_ratio',
    'enable_left_right_check',
    '/api/depth/profile/',
    '/api/depth/profiles',
    '/stereo/depth_raw.jpg',
    '/stereo/depth_filtered.jpg',
    '/stereo/depth_strict.jpg',
    '/stereo/confidence.jpg',
    'depth_raw_latest.jpg',
    'confidence_latest.jpg',
];
foreach ($required as $needle) {
    if (!str_contains($text, $needle)) {
        fwrite(STDERR, "missing contract token: $needle\n");
        exit(1);
    }
}

if (str_contains($text, 'AUTO_FHD')) {
    fwrite(STDERR, "AUTO must not select the experimental FHD profile\n");
    exit(1);
}

echo "OK\n";
