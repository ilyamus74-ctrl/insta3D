<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$producerPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneReducedFrameProducer.kt';
$performancePath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneDepthPerformanceController.kt';
$workspacePath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneFullScreenScanWorkspace.kt';
$contractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7A_16X9_HIGH_RES_CONTRACT.md';

foreach ([$producerPath, $performancePath, $workspacePath, $contractPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] missing {$path}\n");
        exit(1);
    }
}

$producer = file_get_contents($producerPath);
$performance = file_get_contents($performancePath);
$workspace = file_get_contents($workspacePath);
$contract = file_get_contents($contractPath);

$checks = [
    'CameraX prefers 16:9 1280x720 analysis' =>
        str_contains($producer, 'ResolutionSelector.Builder()') &&
        str_contains($producer, 'RATIO_16_9_FALLBACK_AUTO_STRATEGY') &&
        str_contains($producer, 'CAPTURE_TARGET_WIDTH = 1_280') &&
        str_contains($producer, 'CAPTURE_TARGET_HEIGHT = 720'),
    'non-16:9 fallback is cropped without stretching' =>
        str_contains($producer, 'centerCrop16By9') &&
        str_contains($producer, 'cropNv21') &&
        str_contains($producer, 'sourceAspectCropped'),
    'phone-to-phone stream remains bounded' =>
        str_contains($producer, 'DualPhoneReducedFrame.MAX_WIDTH') &&
        str_contains($producer, 'DualPhoneReducedFrame.MAX_HEIGHT') &&
        str_contains($producer, 'TARGET_FPS = 10L'),
    'adaptive controller contains HIGH_640 probe' =>
        str_contains($performance, 'name = "HIGH_640"') &&
        str_contains($performance, 'workWidth = 640') &&
        str_contains($performance, 'workHeight = 360') &&
        str_contains($performance, 'minProcessingIntervalMs = 250L'),
    'SLAVE exposes capture and stereo sizes' =>
        str_contains($workspace, 'analysisSourceWidth') &&
        str_contains($workspace, 'encodedWidth') &&
        str_contains($workspace, '16:9 CENTER CROP'),
    'contract preserves local fallback and desktop continuation' =>
        str_contains($contract, 'GrafikStation with RTX 3080') &&
        str_contains($contract, 'local low-resolution fallback'),
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

exit($failed ? 1 : 0);
