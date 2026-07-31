<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manager = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt'
);
$fullscreen = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneCalibrationFullscreen.kt'
);
$contract = file_get_contents(
    $root . '/docs/llm/tasks/APP-DUAL-PHONE-CAL00-CONTRACT.md'
);

$checks = [
    'manager' => [
        'fun restartStereoCalibration()',
        '"retry_mode", "STEREO_ONLY"',
        '"source_calibration_run_id"',
        '"master_intrinsics", preservedMaster.toJson()',
        '"slave_intrinsics", preservedSlave.toJson()',
        'calibrationMasterIntrinsics = preservedMaster',
        'calibrationSlaveIntrinsics = preservedSlave',
        'calibrationStereoAcceptedPoseCount = 0',
        'preservedMasterIntrinsics',
        'preservedSlaveIntrinsics',
        'Повторная stereo-калибровка открыта; K/D сохранены',
    ],
    'fullscreen' => [
        'ПОВТОРИТЬ СТЕРЕО-КАЛИБРОВКУ',
        'controlManager.restartStereoCalibration()',
        'Intrinsics K/D обеих камер сохраняются',
        'Повторную stereo-калибровку запускайте на MASTER',
    ],
    'contract' => [
        'stereo-only retry',
        'preserves the already validated MASTER and SLAVE intrinsics',
        '18 new dual-visible pairs',
    ],
];

foreach ($checks as $name => $needles) {
    $source = $$name;
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            fwrite(STDERR, "{$name} missing token: {$needle}\n");
            exit(1);
        }
    }
}

$restartStart = strpos($manager, 'fun restartStereoCalibration()');
$restartEnd = strpos($manager, 'fun exitCalibrationSession()', $restartStart);
$restartBlock = substr($manager, $restartStart, $restartEnd - $restartStart);

if (
    str_contains($restartBlock, 'calibrationMasterIntrinsics = null') ||
    str_contains($restartBlock, 'calibrationSlaveIntrinsics = null')
) {
    fwrite(STDERR, "Stereo-only retry must preserve both intrinsics\n");
    exit(1);
}

echo "OK\n";
