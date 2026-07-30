<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'runtime' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/DualPhonePreviewBindingRuntime.kt',
    'fullscreen' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneCalibrationFullscreen.kt',
    'card' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneControlSettingsCard.kt',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
}

$runtime = file_get_contents($files['runtime']);
$fullscreen = file_get_contents($files['fullscreen']);
$card = file_get_contents($files['card']);

$requirements = [
    'runtime' => [$runtime, [
        'DualPhonePreviewBindingRuntime',
        'PhoneCameraVideoRecorder',
        'selectedOrDefault()',
        'getSelectedVideoMode',
        'enableVideoCapture = !calibrationMode',
        'enableCalibrationAnalysis = calibrationMode',
        'PreviewView was not attached',
    ]],
    'fullscreen' => [$fullscreen, [
        'DualPhonePreviewBindingRuntime.bind',
        'calibrationMode = true',
        'Preview: $previewStatus',
        'Binding selected camera',
        'LocalLifecycleOwner.current',
    ]],
    'card' => [$card, [
        'DualPhonePreviewBindingRuntime.bind',
        'calibrationMode = false',
        'bindEnabled = snapshot.phase == DualPhoneControlPhase.CONNECTED',
        'LaunchedEffect(previewView, lifecycleOwner, bindEnabled)',
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

if (
    !str_contains($fullscreen, 'SCREEN_ORIENTATION_LOCKED') ||
    str_contains($fullscreen, 'SCREEN_ORIENTATION_SENSOR_LANDSCAPE')
) {
    fwrite(STDERR, "Calibration orientation policy regressed\n");
    exit(1);
}

if (!str_contains($runtime, 'withContext(Dispatchers.Main.immediate)')) {
    fwrite(STDERR, "CameraX binding must execute on the main dispatcher\n");
    exit(1);
}

if (!str_contains($runtime, 'bindMutex.lock()') ||
    !str_contains($runtime, 'bindMutex.unlock()')
) {
    fwrite(STDERR, "Preview rebinds must be serialized\n");
    exit(1);
}

echo "OK\n";
