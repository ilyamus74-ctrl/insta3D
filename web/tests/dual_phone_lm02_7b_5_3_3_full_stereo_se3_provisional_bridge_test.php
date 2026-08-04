<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourcePath = $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp';
$contractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_3_3_FULL_STEREO_SE3_PROVISIONAL_BRIDGE_CONTRACT.md';

$source = file_get_contents($sourcePath);
$contract = file_get_contents($contractPath);
if ($source === false || $contract === false) {
    fwrite(STDERR, "required file is missing\n");
    exit(1);
}

$requiredSourceTokens = [
    'reverse_neighbours',
    'findFundamentalMat(',
    'fit_rigid_transform(',
    'estimate_stereo_se3(',
    'kStereoSe3RansacIterations = 192',
    'kStereoSe3RansacThresholdM = 0.14',
    'STEREO_SE3_RANSAC',
    'STEREO_SE3_PNP_CONFIRMED',
    'pnp_confirms_stereo(',
    'pose_trusted = true',
    'provisional_chain_length',
    'TRACKING_PROVISIONAL_SE3',
    'AUTO_WALK_STEREO_SE3_PROVISIONAL_PROMOTED',
    'provisional_tracking_frames',
    'provisional_promotions',
    'maximum_provisional_chain',
    'if (!decision.geometry_suppressed) {',
];
foreach ($requiredSourceTokens as $token) {
    if (!str_contains($source, $token)) {
        fwrite(STDERR, "missing source token: {$token}\n");
        exit(1);
    }
}

$forbiddenSourceTokens = [
    "estimate_known_yaw_stereo_translation(\n                reference,",
    '? "AUTO_WALK_PNP_DEPTH"',
];
foreach ($forbiddenSourceTokens as $token) {
    if (str_contains($source, $token)) {
        fwrite(STDERR, "forbidden source token remains: {$token}\n");
        exit(1);
    }
}

foreach ([
    'full metric',
    'Kabsch/SVD SE(3)',
    'Gyroscope yaw is a consistency check only',
    'untrusted provisional tracking',
    'split the session into reconstruction chunks',
] as $token) {
    if (!str_contains($contract, $token)) {
        fwrite(STDERR, "missing contract token: {$token}\n");
        exit(1);
    }
}

echo "OK\n";
