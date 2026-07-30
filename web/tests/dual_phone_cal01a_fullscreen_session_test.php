<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'protocol' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlProtocol.kt',
    'manager' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt',
    'card' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneControlSettingsCard.kt',
    'fullscreen' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneCalibrationFullscreen.kt',
    'main' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
}

$protocol = file_get_contents($files['protocol']);
$manager = file_get_contents($files['manager']);
$card = file_get_contents($files['card']);
$fullscreen = file_get_contents($files['fullscreen']);
$main = file_get_contents($files['main']);

$requirements = [
    'protocol' => [$protocol, [
        'ENTER_CALIBRATION',
        'ENTER_CALIBRATION_ACK',
        'EXIT_CALIBRATION_REQUEST',
        'EXIT_CALIBRATION',
        'calibrationRunId',
    ]],
    'manager' => [$manager, [
        'startCalibrationSession',
        'exitCalibrationSession',
        'calibrationActive',
        'calibration_run_id',
        'operator_lens_baseline_mm',
        'CALIBRATION_TARGET_POSE_COUNT',
    ]],
    'card' => [$card, [
        'Калибровка двух телефонов',
        'onStartCalibration',
        'DualPhoneCalibrationFullscreen',
        '!snapshot.calibrationActive',
    ]],
    'fullscreen' => [$fullscreen, [
        'SCREEN_ORIENTATION_SENSOR_LANDSCAPE',
        'usePlatformDefaultWidth = false',
        'PreviewView.ScaleType.FILL_CENTER',
        'Accepted poses:',
        'Завершить калибровку',
        'DualPhoneRecorderPreviewRegistry.register',
    ]],
    'main' => [$main, [
        'dualPhoneControl.startCalibrationSession()',
        'dualPhoneControl.exitCalibrationSession()',
    ]],
];

foreach ($requirements as $name => [$content, $needles]) {
    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            fwrite(STDERR, "{$name} missing token: {$needle}\n");
            exit(1);
        }
    }
}

if (str_contains($fullscreen, 'room_id') || str_contains($manager, 'room_id')) {
    fwrite(STDERR, "Calibration session must not be tied to a room session\n");
    exit(1);
}

if (!str_contains($manager, 'settings.operatorLensBaselineMm')) {
    fwrite(STDERR, "Calibration must require saved operator baseline\n");
    exit(1);
}

echo "OK\n";
