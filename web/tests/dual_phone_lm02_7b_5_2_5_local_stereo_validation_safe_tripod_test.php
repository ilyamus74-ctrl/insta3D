<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$required = [
    'web/remote_station/dual_phone_host/src/stereo_depth_runtime.hpp',
    'web/remote_station/dual_phone_host/src/stereo_depth_runtime.cpp',
    'web/remote_station/dual_phone_host/src/stereo_preview.cpp',
    'web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp',
    'web/remote_station/dual_phone_host/tools/analyze_local_stereo_geometry.py',
    'web/remote_station/dual_phone_host/scripts/pack_session.sh',
];
foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        fwrite(STDERR, "missing: {$relative}\n");
        exit(1);
    }
}

$depthHeader = file_get_contents($root . '/web/remote_station/dual_phone_host/src/stereo_depth_runtime.hpp');
$depthCpp = file_get_contents($root . '/web/remote_station/dual_phone_host/src/stereo_depth_runtime.cpp');
$preview = file_get_contents($root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp');
$tracking = file_get_contents($root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp');
$tool = file_get_contents($root . '/web/remote_station/dual_phone_host/tools/analyze_local_stereo_geometry.py');
$pack = file_get_contents($root . '/web/remote_station/dual_phone_host/scripts/pack_session.sh');

$needles = [
    [$depthHeader, 'rectified_principal_x_px'],
    [$depthHeader, 'rectified_principal_y_px'],
    [$depthCpp, '(rectified_principal_x_px + 0.5) * scale_x - 0.5'],
    [$preview, 'oriented_principal_x_px'],
    [$preview, 'cached_rectified_principal_y_px'],
    [$tracking, 'kMaximumSafePnpTranslationM = 0.08'],
    [$tracking, 'LOCAL_STEREO_VALIDATION_SAFE_TRIPOD'],
    [$tracking, 'PNP_TRANSLATION_UNSAFE_FOR_TRIPOD'],
    [$tool, 'FRONT_PARALLEL_LOCAL_DEPTH_LAYER'],
    [$tool, 'LOCAL_TO_WORLD_MATRIX_APPLIED_EXACTLY'],
    [$pack, 'local_stereo_validation.json'],
];
foreach ($needles as [$haystack, $needle]) {
    if (!str_contains((string) $haystack, $needle)) {
        fwrite(STDERR, "missing marker: {$needle}\n");
        exit(1);
    }
}

echo "OK\n";
