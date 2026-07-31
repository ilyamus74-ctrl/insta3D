<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mode = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneCalibrationMode.kt');
$manager = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt');
$protocol = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlProtocol.kt');
$settings = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneControlSettingsCard.kt');
$fullscreen = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneCalibrationFullscreen.kt');

$checks = [
    [$mode, 'MANUAL_STEREO'],
    [$manager, 'fun requestManualStereoPair()'],
    [$manager, 'DualPhoneManualStereoCaptureRequest'],
    [$manager, 'stereoObservationBuffer.bestPair'],
    [$manager, 'handleManualStereoCaptureAt'],
    [$manager, 'MANUAL_STEREO_CAPTURE_TIMEOUT_MS'],
    [$protocol, 'CALIBRATION_CAPTURE_AT'],
    [$protocol, 'CALIBRATION_CAPTURE_ACK'],
    [$settings, 'АВТОКАЛИБРОВКА'],
    [$settings, 'РУЧНАЯ КАЛИБРОВКА'],
    [$fullscreen, 'СНЯТЬ СИНХРОННУЮ ПАРУ'],
    [$fullscreen, 'ПОВТОРИТЬ ВРУЧНУЮ'],
    [$fullscreen, 'captureTargetElapsedRealtimeNs'],
];

foreach ($checks as [$source, $needle]) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Missing token: {$needle}\n");
        exit(1);
    }
}

$gate = strpos($manager, 'private fun evaluateCalibrationGateLocked()');
$pairSelection = strpos($manager, 'stereoObservationBuffer.bestPair(');
if ($gate === false || $pairSelection === false || $pairSelection < $gate) {
    fwrite(STDERR, "Buffered pair selection must be inside evaluateCalibrationGateLocked()\n");
    exit(1);
}

if (
    str_contains($manager, 'manualStereoCaptureRequestedAtMasterNs') ||
    str_contains($manager, 'val framesAfterButton = if (')
) {
    fwrite(STDERR, "Legacy latest-observation manual gate is still present\n");
    exit(1);
}

echo "OK\n";
