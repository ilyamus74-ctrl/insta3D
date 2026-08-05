<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourcePath = $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp';
$contractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_3_6_WALK_CONTEXT_ROTATION_PUBLISH_GUARD_CONTRACT.md';

$source = file_get_contents($sourcePath);
$contract = file_get_contents($contractPath);
if ($source === false || $contract === false) {
    fwrite(STDERR, "required file is missing\n");
    exit(1);
}

foreach ([
    'kMaximumConfirmedRotationAccelerationMps2 = 0.08',
    'kMinimumConfirmedRotationYawDeg = 0.75',
    'kWalkContextHoldFrames = 18',
    'positive_rotation_evidence(',
    'walk_context_snapshot()',
    'walk_context_remaining_frames = kWalkContextHoldFrames',
    'AUTO_ROTATION_UNCONFIRMED_SUPPRESSED',
    'AUTO_ROTATION_CONFIRMED_',
    'ROTATION_REJECTED_RECENT_WALK_CONTEXT',
    'ROTATION_REQUIRES_POSITIVE_TRIPOD_CONFIRMATION',
    'record_rotation_publish_guard(',
    'rotation_publish_candidates',
    'rotation_publish_confirmed',
    'rotation_geometry_suppressed',
    'rotation_rejected_recent_walk',
    'rotation_rejected_no_tripod_confirmation',
] as $token) {
    if (!str_contains($source, $token)) {
        fwrite(STDERR, "missing source token: {$token}\n");
        exit(1);
    }
}

$forbidden = "if (rotation_available &&\n" .
    "            (!walk_safe || translation_m <= pivot_limit))";
if (str_contains($source, $forbidden)) {
    fwrite(STDERR, "missing translation is still treated as rotation proof\n");
    exit(1);
}

$normalizedContract = preg_replace('/\s+/', ' ', $contract) ?? $contract;
foreach ([
    'missing or rejected stereo translation is not rotation evidence',
    'walk-context latch',
    'positive current-frame tripod evidence',
    'must not become a keyframe',
    'No IMU position integration',
    'No synthetic camera path',
    'does not reset or replace the accumulated global map',
] as $token) {
    if (!str_contains($normalizedContract, $token)) {
        fwrite(STDERR, "missing contract token: {$token}\n");
        exit(1);
    }
}

echo "OK\n";
