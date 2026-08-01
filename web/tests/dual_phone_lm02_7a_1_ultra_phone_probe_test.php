<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$transportPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneReducedFrameTransport.kt';
$framePath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLiveStreamFrame.kt';
$performancePath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneDepthPerformanceController.kt';
$contourPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneContourFirstViewport.kt';
$contractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7A_1_ULTRA_960_CONTRACT.md';

foreach ([$transportPath, $framePath, $performancePath, $contourPath, $contractPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] missing {$path}\n");
        exit(1);
    }
}

$transport = file_get_contents($transportPath);
$frame = file_get_contents($framePath);
$performance = file_get_contents($performancePath);
$contour = file_get_contents($contourPath);
$contract = file_get_contents($contractPath);

$checks = [
    'transport is bounded at 960x540 and 512 KiB' =>
        str_contains($transport, 'const val MAX_WIDTH = 960') &&
        str_contains($transport, 'const val MAX_HEIGHT = 540') &&
        str_contains($transport, 'const val MAX_PAYLOAD_BYTES = 512 * 1024'),
    'metadata envelope matches transport bounds' =>
        str_contains($frame, 'const val MAX_WIDTH: Int = 960') &&
        str_contains($frame, 'const val MAX_HEIGHT: Int = 540') &&
        str_contains($frame, 'const val MAX_PAYLOAD_BYTES: Int = 512 * 1024'),
    'adaptive controller starts with ULTRA_960' =>
        strpos($performance, 'name = "ULTRA_960"') <
            strpos($performance, 'name = "HIGH_640"') &&
        str_contains($performance, 'workWidth = 960') &&
        str_contains($performance, 'workHeight = 540') &&
        str_contains($performance, 'minProcessingIntervalMs = 400L'),
    'ULTRA probe retains automatic downgrade and thermal floors' =>
        str_contains($performance, 'ULTRA_MAX_P95_MS = 340L') &&
        str_contains($performance, 'MAX_ADAPTIVE_LEVEL = 4') &&
        str_contains($performance, 'DualPhoneDepthThermalState.WARM -> 2') &&
        str_contains($performance, 'DualPhoneDepthThermalState.HOT -> 4') &&
        str_contains($performance, 'DualPhoneDepthThermalState.CRITICAL -> 5'),
    'STRICT contour is thin and neutral' =>
        str_contains($projector, 'createNeutralOutline(strictGradient, strictInput)') &&
        !str_contains($projector, 'createGreenOutline') &&
        !str_contains($projector, 'Imgproc.dilate(strictGradient') &&
        str_contains($projector, 'pixels[outputIndex] = 0xdc.toByte()'),
    'operator outline opacity is mode-aware and restrained' =>
        str_contains($contour, 'strictPaintAlpha(mode, freshness)') &&
        str_contains($contour, 'DualPhoneOperatorOverlayMode.OUTLINE -> 0') &&
        str_contains($contour, 'DualPhoneOperatorOverlayMode.ASSIST -> 72'),
    'contract keeps CPU laptop offload optional' =>
        str_contains($contract, 'ordinary CPU laptop') &&
        str_contains($contract, 'GPU acceleration is an optional optimization'),
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
