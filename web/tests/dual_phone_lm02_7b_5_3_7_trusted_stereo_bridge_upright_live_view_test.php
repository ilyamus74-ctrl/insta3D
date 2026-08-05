<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourcePath = $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp';
$indexPath = $root . '/web/remote_station/dual_phone_host/web/index.html';
$contractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_3_7_TRUSTED_STEREO_BRIDGE_UPRIGHT_LIVE_VIEW_CONTRACT.md';

$source = file_get_contents($sourcePath);
$index = file_get_contents($indexPath);
$contract = file_get_contents($contractPath);
if ($source === false || $index === false || $contract === false) {
    fwrite(STDERR, "required file is missing\n");
    exit(1);
}

foreach ([
    'trusted_stereo_bridge_pose_valid(',
    'trusted_stereo_bridge = false',
    'STEREO_SE3_TRUSTED_BRIDGE',
    'AUTO_STEREO_SE3_TRUSTED_BRIDGE',
    'trusted_stereo_bridge_frames',
    'if (decision.trusted_stereo_bridge) {',
    'decision.keyframe = false;',
    'decision.geometry_suppressed = false;',
] as $token) {
    if (!str_contains($source, $token)) {
        fwrite(STDERR, "missing source token: {$token}\n");
        exit(1);
    }
}

foreach ([
    'id="mapOrientation"',
    'UPRIGHT · 180° display roll',
    'RAW · stored Y-up',
    "mapOrientation.value === 'upright'",
    '? [0, -1, 0]',
    'mapOrientation.addEventListener',
] as $token) {
    if (!str_contains($index, $token)) {
        fwrite(STDERR, "missing live-view token: {$token}\n");
        exit(1);
    }
}

$normalizedContract = preg_replace('/\s+/', ' ', $contract) ?? $contract;
foreach ([
    'trusted as a tracking bridge',
    'does not create a keyframe',
    'does not merge its local cloud',
    '180-degree camera roll',
    'stored PLY and trajectory stay unchanged',
    'Absolute gravity alignment from IMU is not claimed',
    'No IMU position integration',
    'No synthetic translation',
] as $token) {
    if (!str_contains($normalizedContract, $token)) {
        fwrite(STDERR, "missing contract token: {$token}\n");
        exit(1);
    }
}

echo "OK\n";
