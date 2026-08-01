<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$processor = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLiveDepthProcessor.kt'
);
$overlay = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneAdaptiveOutlineOverlay.kt'
);
$inset = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneRectifiedDepthInset.kt'
);
$contract = file_get_contents(
    $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_6_2_NATIVE_ASPECT_CONTRACT.md'
);

$checks = [
    'post-rotation work size preserves native aspect' =>
        str_contains($processor, 'val workSize = aspectPreservingSize(') &&
        str_contains($processor, 'maxWidth.toDouble() / source.cols().toDouble()') &&
        str_contains($processor, 'maxHeight.toDouble() / source.rows().toDouble()'),
    'native-aspect fitting forbids upscaling' =>
        str_contains(
            $processor,
            "val scale = minOf(\n            1.0,"
        ),
    'operator background remains natural MASTER camera' =>
        str_contains($overlay, 'val baseBytes = masterFrame?.jpegBytes') &&
        !str_contains(
            $overlay,
            'val baseBytes = if (showDepth && depth.rectifiedMasterJpeg != null)'
        ),
    'rectified products are isolated in a registered inset' =>
        str_contains($overlay, 'DualPhoneRectifiedDepthInset(') &&
        str_contains($inset, 'RECT DEPTH') &&
        str_contains($inset, 'depth.rectifiedMasterJpeg') &&
        str_contains($inset, 'depth.filteredDepthPreviewJpeg') &&
        str_contains($inset, 'depth.strictDepthPreviewJpeg'),
    'contract forbids raw/rectified pseudo-registration' =>
        str_contains($contract, 'explicit inverse-rectification mapping') &&
        str_contains($contract, 'one uniform scale'),
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo ($passed ? '[OK] ' : '[FAIL] ') . $name . PHP_EOL;
    $failed = $failed || !$passed;
}

if ($failed) {
    exit(1);
}

echo "Result: PASS\n";
