<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$settings = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneStereoSettings.kt');
$board = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneCalibrationBoardSettings.kt');
$manager = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt');
$fullscreen = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneCalibrationFullscreen.kt');
$coach = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneStereoCoachEstimator.kt');
$stage = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneCalibrationStage.kt');

$checks = [
    [$settings, 'calibrationBoard: DualPhoneCalibrationBoardSettings'],
    [$settings, 'KEY_CALIBRATION_BOARD_JSON'],
    [$board, 'CalibrationBoardType.CHARUCO'],
    [$board, 'CalibrationBoardType.CHESSBOARD_LEGACY'],
    [$board, 'charucoSquareLengthMm: Double = 29.0'],
    [$board, 'charucoMarkerLengthMm: Double = 21.0'],
    [$manager, '"board_settings", settings.calibrationBoard.toJson()'],
    [$manager, 'CALIBRATION_STEREO_MAX_FRAME_DELTA_MS = 80.0'],
    [$manager, 'CALIBRATION_STEREO_REQUIRED_STABLE_MS = 450L'],
    [$fullscreen, 'STEREO COACH'],
    [$fullscreen, 'stereoCoach.coverageGrid'],
    [$coach, 'symmetricEpipolarError'],
    [$coach, 'MAX_REJECTED_PAIRS'],
    [$coach, 'liveRmsPx'],
    [$stage, 'targetPoseCount = 18'],
];

foreach ($checks as [$source, $needle]) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Missing token: {$needle}\n");
        exit(1);
    }
}

echo "OK\n";
