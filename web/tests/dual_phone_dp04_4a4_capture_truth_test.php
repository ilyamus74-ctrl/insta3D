<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$requiredFiles = [
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/DualPhoneLocalTimelineAnalyzer.kt',
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraScanProvider.kt',
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneBundleTransfer.kt',
    'collect_insta3d_dual_adb_diagnostics.sh',
];

foreach ($requiredFiles as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path) || filesize($path) === 0) {
        fwrite(STDERR, "Missing required file: {$relative}\n");
        exit(1);
    }
}

$analyzer = file_get_contents($root . '/' . $requiredFiles[0]);
$provider = file_get_contents($root . '/' . $requiredFiles[1]);
$bundle = file_get_contents($root . '/' . $requiredFiles[2]);
$collector = file_get_contents($root . '/' . $requiredFiles[3]);

$checks = [
    'analyzer writes map' => str_contains($analyzer, 'frame_encoder_map.jsonl') || str_contains($provider, 'frame_encoder_map.jsonl'),
    'analyzer writes report' => str_contains($provider, 'local_timeline_report.json'),
    'actual capture FPS' => str_contains($provider, 'capture_result_fps_actual'),
    'actual encoder FPS' => str_contains($provider, 'encoder_fps_actual'),
    'timestamp gap analysis' => str_contains($analyzer, 'gapCount'),
    'monotonic mapping' => str_contains($analyzer, 'MAPPED_MONOTONIC_CAMERAX_START_ANCHORED'),
    'role package includes map' => str_contains($bundle, 'frame_encoder_map.jsonl'),
    'role package includes report' => str_contains($bundle, 'local_timeline_report.json'),
    'dual collector mode' => str_contains($collector, '--both'),
    'lightweight/full switch' => str_contains($collector, '--full'),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Contract check failed: {$name}\n");
        exit(1);
    }
}

echo "OK\n";
