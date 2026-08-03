<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runtime = $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp';
$contract = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_2_6_AUTO_MOTION_RECOVERY_BUFFER_CONTRACT.md';

foreach ([$runtime, $contract] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "missing: {$path}
");
        exit(1);
    }
}

$source = file_get_contents($runtime);
if ($source === false) {
    fwrite(STDERR, "cannot read runtime
");
    exit(1);
}

$required = [
    'AUTO_MOTION_RECOVERY_BUFFER',
    'AUTO_ROTATION',
    'AUTO_WALK',
    'TRACKING_COASTING',
    'decide_pose_with_retries',
    'kMaximumTrackingBufferFrames',
    'kMaximumRecoveryAttempts',
    'recovery_successes',
    'acceleration_motion_mps2',
    'RECOVERED_',
];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "missing runtime marker: {$needle}
");
        exit(1);
    }
}

if (str_contains($source, 'kMaximumSafePnpTranslationM')) {
    fwrite(STDERR, "legacy tripod-only translation gate remains
");
    exit(1);
}

echo "OK
";
