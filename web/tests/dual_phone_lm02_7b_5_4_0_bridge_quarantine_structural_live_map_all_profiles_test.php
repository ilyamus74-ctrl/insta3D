<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp');
$http = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/http_dashboard.cpp');
$index = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/web/index.html');
$contract = file_get_contents(
    $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_4_0_BRIDGE_QUARANTINE_STRUCTURAL_LIVE_MAP_ALL_PROFILES_CONTRACT.md');

if ($source === false || $http === false || $index === false ||
    $contract === false) {
    fwrite(STDERR, "required file is missing\n");
    exit(1);
}

foreach ([
    'kStructuralMinimumPixelSamples',
    'structural_voxel_supported',
    'point_cloud_accumulated_structural.ply',
    'kMaximumBridgePnpPoseDisagreementM',
    'pnp_confirms_stereo_bridge',
    'trusted_bridge_publish_ready',
    'trusted_bridge_confirmed_steps * 3U',
    '_QUARANTINED',
    '_CONFIRMED_CUMULATIVE_KEYFRAME',
    'source_profile == "ULTRA_960"',
    'source_profile == "QUALITY_480"',
    'source_profile == "BALANCED_320"',
    'source_profile == "THROTTLED_320"',
    'source_profile == "FHD_1920"',
] as $token) {
    if (!str_contains($source, $token)) {
        fwrite(STDERR, "missing source token: {$token}\n");
        exit(1);
    }
}

foreach ([
    '/api/map/structural.ply',
    'point_cloud_accumulated_structural.ply',
] as $token) {
    if (!str_contains($http, $token)) {
        fwrite(STDERR, "missing HTTP token: {$token}\n");
        exit(1);
    }
}

foreach ([
    '<option value="structural" selected>',
    "structural: '/api/map/structural.ply'",
    "}[mode] || '/api/map/structural.ply';",
] as $token) {
    if (!str_contains($index, $token)) {
        fwrite(STDERR, "missing dashboard token: {$token}\n");
        exit(1);
    }
}

$successBlockStart = strpos($index, 'if (success) {');
$successBlockEnd = $successBlockStart === false
    ? false
    : strpos($index, 'selectedImageLoading = false;', $successBlockStart);
if ($successBlockStart === false || $successBlockEnd === false) {
    fwrite(STDERR, "selected preview success block is missing\n");
    exit(1);
}
$successBlock = substr(
    $index,
    $successBlockStart,
    $successBlockEnd - $successBlockStart);
if (str_contains($successBlock, 'hideDepthProbe();')) {
    fwrite(STDERR, "depth probe is still hidden by image refresh\n");
    exit(1);
}
if (!str_contains($index, "selectedPreview.addEventListener('mouseleave', hideDepthProbe)")) {
    fwrite(STDERR, "depth probe mouseleave reset is missing\n");
    exit(1);
}

$normalizedContract = preg_replace('/\\s+/', ' ', $contract);
if (!is_string($normalizedContract)) {
    fwrite(STDERR, "contract normalization failed\n");
    exit(1);
}
foreach ([
    'does not delete the accumulated RAW map',
    'not a sliding-window',
    'every concrete depth profile',
    'not hidden by the next heatmap image refresh',
] as $token) {
    if (!str_contains($normalizedContract, $token)) {
        fwrite(STDERR, "missing contract token: {$token}\n");
        exit(1);
    }
}

echo "OK\n";
