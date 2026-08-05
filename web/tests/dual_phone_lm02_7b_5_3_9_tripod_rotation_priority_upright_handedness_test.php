<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp');
$index = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/web/index.html');
$contract = file_get_contents(
    $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_3_9_TRIPOD_ROTATION_PRIORITY_UPRIGHT_HANDEDNESS_CONTRACT.md');
if ($source === false || $index === false || $contract === false) {
    fwrite(STDERR, "missing source, dashboard or contract\n");
    exit(1);
}

foreach ([
    'kEnableUnanchoredLocalSubmapPromotion = false',
    'kMaximumRotationConfirmationStereoYawErrorDeg',
    'ROTATION_REQUIRES_GYRO_VISUAL_OR_STEREO_AGREEMENT',
    'pnp_confirms_stationary_pivot',
    'fresh_rotation_override',
    'walk_context_support',
    'kEnableUnanchoredLocalSubmapPromotion &&',
] as $token) {
    if (!str_contains($source, $token)) {
        fwrite(STDERR, "missing source token: {$token}\n");
        exit(1);
    }
}

$rotationPosition = strpos($source, 'return MotionMode::Rotation;');
$walkPosition = strpos(
    $source,
    'if (walk_safe && translation_m > pivot_limit * 1.6 &&');
if ($rotationPosition === false || $walkPosition === false ||
    $rotationPosition >= $walkPosition) {
    fwrite(STDERR, "rotation evidence is not evaluated before stereo walk\n");
    exit(1);
}

foreach ([
    'UPRIGHT · preserve left/right',
    'uniform vec3 uDisplayCenter;',
    'uniform vec3 uDisplayScale;',
    'displayScaleLocation',
    'const displayScale = upright ? [-1, 1, 1] : [1, 1, 1];',
] as $token) {
    if (!str_contains($index, $token)) {
        fwrite(STDERR, "missing dashboard token: {$token}\n");
        exit(1);
    }
}

foreach ([
    'strong near-stationary PnP',
    'disabled',
    'preserving left/right placement',
] as $token) {
    if (!str_contains($contract, $token)) {
        fwrite(STDERR, "missing contract token: {$token}\n");
        exit(1);
    }
}

echo "OK\n";
