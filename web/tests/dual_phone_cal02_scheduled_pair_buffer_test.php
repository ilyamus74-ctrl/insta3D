<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pairBuffer = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneStereoPairBuffer.kt');
$observation = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneCalibrationControl.kt');
$cameraControls = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/DualPhoneCalibrationCameraControls.kt');
$recorder = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraVideoRecorder.kt');
$analyzer = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneCalibrationRealtimeAnalyzer.kt');

$checks = [
    [$pairBuffer, 'capture_request_id'],
    [$pairBuffer, 'targetRelativeDeltaMs'],
    [$pairBuffer, 'capacityPerSide: Int = 96'],
    [$pairBuffer, 'MIN_COMMON_CORNERS = 20'],
    [$observation, 'captureElapsedRealtimeNs'],
    [$observation, 'timestampSource'],
    [$observation, 'captureRequestId'],
    [$cameraControls, 'SENSOR_INFO_TIMESTAMP_SOURCE'],
    [$cameraControls, 'CONTROL_AE_LOCK'],
    [$cameraControls, 'CONTROL_AWB_LOCK'],
    [$cameraControls, 'CONTROL_VIDEO_STABILIZATION_MODE_OFF'],
    [$cameraControls, 'LENS_OPTICAL_STABILIZATION_MODE_OFF'],
    [$recorder, 'DualPhoneCalibrationTimestampMapper'],
    [$recorder, 'captureElapsedRealtimeNs = captureElapsedRealtimeNs'],
    [$analyzer, 'captureElapsedRealtimeNs = frame.captureElapsedRealtimeNs'],
];

foreach ($checks as [$source, $needle]) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Missing token: {$needle}\n");
        exit(1);
    }
}

echo "OK\n";
