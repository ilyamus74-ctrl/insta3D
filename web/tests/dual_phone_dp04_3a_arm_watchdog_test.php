<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manager = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt');
$recorder = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraVideoRecorder.kt');

if ($manager === false || $recorder === false) {
    fwrite(STDERR, "Could not read DP04.3a source files\n");
    exit(1);
}

$checks = [
    'ARMING phase is explicit' => str_contains($manager, 'ARMING,'),
    'ARM shows progress before CameraX preparation' => str_contains($manager, 'Preparing local CameraX recorder'),
    'ARM preparation has watchdog' => str_contains($manager, 'withTimeoutOrNull(ARM_PREPARE_TIMEOUT_MS)'),
    'ARM timeout returns to CONNECTED' => str_contains($manager, 'current.phase == DualPhoneControlPhase.ARMING'),
    'CameraProvider has timeout' => str_contains($recorder, 'withTimeoutOrNull(CAMERA_PROVIDER_TIMEOUT_MS)'),
    'CameraProvider cancellation cancels future' => str_contains($recorder, 'future.cancel(true)'),
    'Zoom operation has timeout' => str_contains($recorder, 'withTimeoutOrNull(ZOOM_APPLY_TIMEOUT_MS)'),
    'Already-correct zoom skips CameraX request' => str_contains($recorder, 'kotlin.math.abs(currentRatio - clamped) > ZOOM_RATIO_TOLERANCE'),
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}

echo "OK\n";
