<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/DualPhoneFrameTelemetry.kt',
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraVideoRecorder.kt',
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraScanProvider.kt',
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraInfoCollector.kt',
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt',
    'app/MaklerTour/tools/dual_phone_capture_sync_validator.py',
    'docs/llm/tasks/APP-DUAL-PHONE-DP04-2-TELEMETRY.md',
];

foreach ($required as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path) || filesize($path) <= 0) {
        throw new RuntimeException("Required DP04.2 file missing: {$relative}");
    }
}

$telemetry = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/DualPhoneFrameTelemetry.kt'
);
$recorder = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraVideoRecorder.kt'
);
$provider = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraScanProvider.kt'
);
$manager = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt'
);
$validator = file_get_contents(
    $root . '/app/MaklerTour/tools/dual_phone_capture_sync_validator.py'
);

$tokens = [
    [$telemetry, 'CAMERA2_CAPTURE_RESULT_SENSOR_TIMESTAMP'],
    [$telemetry, 'UNVERIFIED_SEPARATE_TIMELINES'],
    [$telemetry, 'SENSOR_ROLLING_SHUTTER_SKEW'],
    [$telemetry, 'MediaExtractor'],
    [$recorder, 'Camera2Interop.Extender(builder)'],
    [$recorder, 'setSessionCaptureCallback(frameCaptureCallback)'],
    [$provider, 'dual_phone_stereo_video_member'],
    [$provider, 'imuRecorder.start('],
    [$provider, 'encoder_pts_status'],
    [$provider, 'frame_to_encoder_mapping_status'],
    [$manager, 'keepStopMessage'],
    [$validator, 'NEAREST_RELATIVE_TO_SCHEDULED_START'],
    [$validator, 'CALLBACK_RECEIVE_FALLBACK'],
];

foreach ($tokens as [$content, $token]) {
    if (!str_contains($content, $token)) {
        throw new RuntimeException("DP04.2 contract token missing: {$token}");
    }
}

echo "OK\n";
