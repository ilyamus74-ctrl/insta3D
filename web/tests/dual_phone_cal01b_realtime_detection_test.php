<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'plan' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneCalibrationPosePlan.kt',
    'observation' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneCalibrationControl.kt',
    'stage' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneCalibrationStage.kt',
    'analyzer' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneCalibrationRealtimeAnalyzer.kt',
    'store' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneCalibrationCaptureStore.kt',
    'manager' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt',
    'protocol' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlProtocol.kt',
    'runtime' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/DualPhonePreviewBindingRuntime.kt',
    'frame' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/StereoCaptureExperimental.kt',
    'recorder' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraVideoRecorder.kt',
    'fullscreen' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneCalibrationFullscreen.kt',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}
");
        exit(1);
    }
}

$content = [];
foreach ($files as $name => $path) {
    $content[$name] = file_get_contents($path);
}

$requirements = [
    'plan' => [
        'DualPhoneCalibrationPosePlan',
        'centre_medium',
        'final_centre_oblique',
    ],
    'stage' => [
        'MASTER_INTRINSICS',
        'SLAVE_INTRINSICS',
        'STEREO_EXTRINSICS',
        'targetPoseCount = 12',
    ],
    'observation' => [
        'DualPhoneCalibrationObservation',
        'calibration_stage',
        'quality_ready',
        'frame_sequence',
    ],
    'analyzer' => [
        'OpenCvCalibrationBoardDetector',
        'qualityReady',
        'REQUIRED_STABLE_MS',
        'boardClipped',
        'poseMatches',
    ],
    'store' => [
        'dual_phone_calibration_samples',
        'master_intrinsics',
        'slave_intrinsics',
        'stereo_extrinsics',
        'raw_frames_unrotated',
        'rotation_degrees_applied',
        'capture_manifest.json',
    ],
    'manager' => [
        'reportCalibrationObservation',
        'evaluateCalibrationGateLocked',
        'CALIBRATION_OBSERVATION',
        'CALIBRATION_STATE',
        'calibrationLastAcceptedLocalFrameSequence',
        'calibrationMasterAcceptedPoseCount',
        'calibrationSlaveAcceptedPoseCount',
        'calibrationStereoAcceptedPoseCount',
    ],
    'protocol' => [
        'CALIBRATION_OBSERVATION',
        'CALIBRATION_STATE',
    ],
    'runtime' => [
        'latestCalibrationFrame',
        'calibrationFrame(sequence',
    ],
    'frame' => [
        'imageProxyRotationDegrees',
    ],
    'recorder' => [
        'imageProxyRotationDegrees = imageProxyRotationDegrees',
        'rotationDegreesApplied = rotationDegrees',
    ],
    'fullscreen' => [
        'КАЛИБРОВКА',
        'КАДР ЗАСЧИТАН',
        'КАЛИБРОВКА ЗАВЕРШЕНА',
        'DualPhoneCalibrationRealtimeAnalyzer',
        'reportCalibrationObservation',
        'DualPhoneCalibrationCaptureStore',
        'CalibrationCornerOverlay',
        'PreviewView.ScaleType.FIT_CENTER',
    ],
];

foreach ($requirements as $name => $needles) {
    foreach ($needles as $needle) {
        if (!str_contains($content[$name], $needle)) {
            fwrite(STDERR, "{$name} missing token: {$needle}
");
            exit(1);
        }
    }
}

if (substr_count($content['plan'], 'spec("') !== 24) {
    fwrite(STDERR, "CAL01B pose plan must contain exactly 24 targets
");
    exit(1);
}

if (str_contains(
    $content['recorder'],
    'rotationDegreesApplied = imageProxyRotationDegrees'
)) {
    fwrite(STDERR, "Raw calibration frames must not apply CameraX display rotation
");
    exit(1);
}

if (str_contains($content['fullscreen'], 'PreviewView.ScaleType.FILL_CENTER')) {
    fwrite(STDERR, "CAL01B preview must not crop the ChArUco board
");
    exit(1);
}

echo "OK
";
