<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$analyzer = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneCalibrationRealtimeAnalyzer.kt'
);
$estimator = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneLiveIntrinsicsEstimator.kt'
);
$stage = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneCalibrationStage.kt'
);
$fullscreen = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneCalibrationFullscreen.kt'
);

$checks = [
    'analyzer' => [
        'MIN_NOVELTY_SCORE',
        'REQUIRED_STABLE_MS = 220L',
        'MAX_MOTION_SCORE = 0.12',
        'noveltyAgainst',
        'coveragePercent',
        'coverageGuidance',
        'автоматический снимок',
    ],
    'estimator' => [
        'DualPhoneLiveIntrinsicsEstimator',
        'MIN_SAMPLES_FOR_SOLVE = 6',
        'Calib3d.calibrateCamera',
        'Предварительные intrinsics',
        'CALIB_FIX_K3',
        'CALIB_ZERO_TANGENT_DIST',
    ],
    'stage' => [
        'MASTER_INTRINSICS',
        'SLAVE_INTRINSICS',
        'targetPoseCount = 15',
        'STEREO_EXTRINSICS',
        'targetPoseCount = 12',
    ],
    'fullscreen' => [
        'masterIntrinsicsEstimator',
        'currentTarget by rememberUpdatedState',
        'Автоснимки этапа',
        'Покрытие ${analysis.coveragePercent}%',
        'estimate.summary()',
        'delay(55L)',
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

if (str_contains($analyzer, 'geometry?.matches(target) == true')) {
    fwrite(STDERR, "Automatic intrinsics capture must not require a rigid named pose\n");
    exit(1);
}

if (str_contains(
    $fullscreen,
    'snapshot.calibrationTargetPoseId,\n        snapshot.calibrationActive'
)) {
    fwrite(STDERR, "Analyzer must preserve novelty history while internal slot IDs advance\n");
    exit(1);
}

echo "OK\n";
