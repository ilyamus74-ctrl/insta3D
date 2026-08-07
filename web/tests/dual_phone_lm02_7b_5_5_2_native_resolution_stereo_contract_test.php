<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$producerPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneReducedFrameProducer.kt';
$transportPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneReducedFrameTransport.kt';
$envelopePath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLiveStreamFrame.kt';
$recorderPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraVideoRecorder.kt';
$controlsPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/DualPhoneCalibrationCameraControls.kt';
$hostProtocolPath = $root . '/web/remote_station/dual_phone_host/src/protocol.hpp';

foreach ([$producerPath, $transportPath, $envelopePath, $recorderPath, $controlsPath, $hostProtocolPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] missing {$path}\n");
        exit(1);
    }
}

$producer = file_get_contents($producerPath);
$transport = file_get_contents($transportPath);
$envelope = file_get_contents($envelopePath);
$recorder = file_get_contents($recorderPath);
$controls = file_get_contents($controlsPath);
$hostProtocol = file_get_contents($hostProtocolPath);

$encodeStart = strpos($producer, 'private fun encodeJpeg');
$encodeEnd = strpos($producer, 'private fun centerCrop16By9');
$encodeBlock = ($encodeStart !== false && $encodeEnd !== false)
    ? substr($producer, $encodeStart, $encodeEnd - $encodeStart)
    : '';

$checks = [
    'uplink resolution comes from selected phone mode' =>
        str_contains($producer, 'lensRepository.getSelectedVideoMode(') &&
        str_contains($producer, 'Size(selectedMode.width, selectedMode.height)'),
    'uplink rejects CameraX geometry mismatch' =>
        str_contains($producer, 'native stereo resolution mismatch') &&
        str_contains($producer, 'image.width != requestedNativeWidth'),
    'uplink encode has no crop or resize' =>
        $encodeBlock !== '' &&
        !str_contains($encodeBlock, 'centerCrop16By9(') &&
        !str_contains($encodeBlock, 'downscaleNv21(') &&
        str_contains($encodeBlock, 'sourceAspectCropped = false'),
    'uplink uses high quality native JPEG' =>
        str_contains($producer, 'JPEG_QUALITY = 85'),
    'laptop stereo locks zoom and stabilization' =>
        str_contains($producer, 'METRIC_STEREO_ZOOM_RATIO = 1.0f') &&
        str_contains($producer, 'CONTROL_VIDEO_STABILIZATION_MODE_OFF') &&
        str_contains($producer, 'LENS_OPTICAL_STABILIZATION_MODE_OFF'),
    'calibration no longer calls latency resolution cap' =>
        str_contains($recorder, 'val requestedSize = profileRequestedSize') &&
        !str_contains($recorder, 'val requestedSize = cappedCalibrationAnalysisSize(profileRequestedSize)'),
    'calibration rejects actual resolution mismatch' =>
        str_contains($recorder, 'actual_resolution_mismatch:') &&
        str_contains($recorder, 'METRIC_STEREO_MAX_WIDTH = 1920') &&
        str_contains($recorder, 'METRIC_STEREO_MAX_HEIGHT = 1080'),
    'calibration locks zoom at 1x and already disables EIS/OIS' =>
        str_contains($controls, 'ZOOM_1X_LOCKED') &&
        str_contains($controls, 'METRIC_STEREO_ZOOM_RATIO = 1.0f') &&
        str_contains($controls, 'CONTROL_VIDEO_STABILIZATION_MODE_OFF') &&
        str_contains($controls, 'LENS_OPTICAL_STABILIZATION_MODE_OFF'),
    'Android transport supports FHD and host payload limit matches' =>
        str_contains($transport, 'const val MAX_WIDTH = 1920') &&
        str_contains($transport, 'const val MAX_HEIGHT = 1080') &&
        str_contains($transport, 'const val MAX_PAYLOAD_BYTES = 2 * 1024 * 1024') &&
        str_contains($envelope, 'const val MAX_WIDTH: Int = 1920') &&
        str_contains($envelope, 'const val MAX_HEIGHT: Int = 1080') &&
        str_contains($hostProtocol, 'kMaxPayloadBytes = 2U * 1024U * 1024U'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    if ($ok) {
        fwrite(STDOUT, "[OK] {$label}\n");
    } else {
        fwrite(STDERR, "[FAIL] {$label}\n");
        $failed = true;
    }
}

if (!$failed) {
    fwrite(STDOUT, "OK: LM02.7B.5.5.2 native-resolution stereo contract\n");
}

exit($failed ? 1 : 0);
