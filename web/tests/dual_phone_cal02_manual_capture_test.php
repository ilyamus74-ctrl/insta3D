<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mode = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneCalibrationMode.kt');
$manager = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt');
$settings = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneControlSettingsCard.kt');
$fullscreen = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneCalibrationFullscreen.kt');

$checks = [
    [$mode, 'MANUAL_STEREO'],
    [$manager, 'fun requestManualStereoPair()'],
    [$manager, 'manualStereoCaptureRequestedAtMasterNs'],
    [$manager, 'framesAfterButton'],
    [$manager, 'MANUAL_STEREO_CAPTURE_TIMEOUT_MS'],
    [$settings, 'АВТОКАЛИБРОВКА'],
    [$settings, 'РУЧНАЯ КАЛИБРОВКА'],
    [$fullscreen, 'СНЯТЬ СИНХРОННУЮ ПАРУ'],
    [$fullscreen, 'ПОВТОРИТЬ ВРУЧНУЮ'],
];

foreach ($checks as [$source, $needle]) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Missing token: {$needle}\n");
        exit(1);
    }
}

$startSession = strpos($manager, 'fun startCalibrationSession(');
$restartStereo = strpos($manager, 'fun restartStereoCalibration(');
$gate = strpos($manager, 'private fun evaluateCalibrationGateLocked()');
$manualFrameGate = strpos($manager, 'val framesAfterButton = if (');

if (
    $startSession === false ||
    $restartStereo === false ||
    $gate === false ||
    $manualFrameGate === false ||
    ($manualFrameGate > $startSession && $manualFrameGate < $restartStereo) ||
    $manualFrameGate < $gate
) {
    fwrite(STDERR, "Manual frame gate must be inside evaluateCalibrationGateLocked()\n");
    exit(1);
}

echo "OK\n";
