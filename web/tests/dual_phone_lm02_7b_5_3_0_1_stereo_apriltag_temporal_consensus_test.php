<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'runtime_header' => $root . '/web/remote_station/dual_phone_host/src/stereo_apriltag_runtime.hpp',
    'runtime_source' => $root . '/web/remote_station/dual_phone_host/src/stereo_apriltag_runtime.cpp',
    'accumulated_header' => $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime.hpp',
    'accumulated_source' => $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp',
    'stereo_preview' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp',
    'android_producer' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneReducedFrameProducer.kt',
    'contract' => $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_3_0_1_STEREO_APRILTAG_TEMPORAL_CONSENSUS_CONTRACT.md',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "missing {$name}: {$path}\n");
        exit(1);
    }
}

$runtime = file_get_contents($files['runtime_source']);
$header = file_get_contents($files['runtime_header']);
$accumulatedHeader = file_get_contents($files['accumulated_header']);
$accumulated = file_get_contents($files['accumulated_source']);
$preview = file_get_contents($files['stereo_preview']);
$android = file_get_contents($files['android_producer']);

foreach ([$runtime, $header, $accumulatedHeader, $accumulated, $preview, $android] as $value) {
    if ($value === false) {
        fwrite(STDERR, "cannot read source file\n");
        exit(1);
    }
}

$runtimeMarkers = [
    '20FPS_STEREO_APRILTAG_TEMPORAL_CONSENSUS',
    'kTargetInterval{50}',
    'STEREO_4_CORNERS',
    'cv::triangulatePoints',
    'cv::solvePnPGeneric',
    'SOLVEPNP_IPPE_SQUARE',
    'STEREO_VERIFIED',
    'APRILTAG_CONSTRAINT_REJECTED_LIVE',
    'live_correction_allowed',
    'apriltag_relations.json',
    'PERPENDICULAR_WALLS',
    'PARALLEL_WALLS',
];
foreach ($runtimeMarkers as $marker) {
    if (!str_contains($runtime, $marker)) {
        fwrite(STDERR, "missing runtime marker: {$marker}\n");
        exit(1);
    }
}

if (!str_contains($header, 'StereoAprilTagAnchorResult') ||
    !str_contains($header, 'preliminary_translation_trusted')) {
    fwrite(STDERR, "stereo AprilTag API is incomplete\n");
    exit(1);
}
if (!str_contains($accumulatedHeader, 'submit_apriltag_pair') ||
    !str_contains($preview, 'submit_apriltag_pair')) {
    fwrite(STDERR, "fast strict-pair feed is not wired\n");
    exit(1);
}
if (!str_contains($accumulated, 'apriltag_result.live_correction_allowed')) {
    fwrite(STDERR, "unsafe unconditional AprilTag pose replacement remains\n");
    exit(1);
}
if (!str_contains($android, 'private const val TARGET_FPS = 20L')) {
    fwrite(STDERR, "Android uplink is not configured for 20 FPS\n");
    exit(1);
}

echo "OK\n";
