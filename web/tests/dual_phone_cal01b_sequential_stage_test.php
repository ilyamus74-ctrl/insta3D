<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$stage = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneCalibrationStage.kt'
);
$manager = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt'
);
$fullscreen = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneCalibrationFullscreen.kt'
);
$store = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneCalibrationCaptureStore.kt'
);

$checks = [
    'stage' => [
        'MASTER_INTRINSICS',
        'SLAVE_INTRINSICS',
        'STEREO_EXTRINSICS',
        'COMPLETE',
        'targetPoseCount = 15',
        'targetPoseCount = 12',
        'isLocalAnalyzerActive',
        'requiresMasterObservation',
        'requiresSlaveObservation',
    ],
    'manager' => [
        'calibrationMasterAcceptedPoseCount',
        'calibrationSlaveAcceptedPoseCount',
        'calibrationStereoAcceptedPoseCount',
        'accepted_stage',
        'completed_stage',
        'stage_accepted_pose_count',
        'stage.requiresMasterObservation',
        'stage.requiresSlaveObservation',
        'Stage ${stage.displayNameRu} completed',
    ],
    'fullscreen' => [
        'КАДР ЗАСЧИТАН',
        'ЭТАП ',
        'КАЛИБРОВКА ЗАВЕРШЕНА',
        'MASTER ✓   SLAVE ✓   ОБЕ КАМЕРЫ ✓',
        'CalibrationStageProgress',
        'localAnalyzerActive',
        '"ГОТОВО"',
        '"ПРЕРВАТЬ"',
    ],
    'store' => [
        'schema_version", 2',
        'master_intrinsics',
        'slave_intrinsics',
        'stereo_extrinsics',
        'acceptance_serial',
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

if (str_contains(
    $manager,
    'val local = localCalibrationObservation ?: return null' . "\n" .
    '        val peer = peerCalibrationObservation ?: return null'
)) {
    fwrite(STDERR, "Sequential stages must not require both observations unconditionally\n");
    exit(1);
}

echo "OK\n";
