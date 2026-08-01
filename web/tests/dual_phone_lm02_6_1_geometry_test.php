<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$processor = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLiveDepthProcessor.kt'
);
$overlay = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneAdaptiveOutlineOverlay.kt'
);
$contract = file_get_contents(
    $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_6_1_GEOMETRY_CONTRACT.md'
);

$checks = [
    'vertical baseline swaps profile dimensions' =>
        str_contains($processor, 'val workSize = if (vertical)') &&
        str_contains($processor, 'performanceProfile.workHeight.toDouble()') &&
        str_contains($processor, 'performanceProfile.workWidth.toDouble()'),
    'focal scale uses actual final disparity width' =>
        str_contains($processor, 'workMaster.cols().toDouble() /') &&
        !str_contains(
            $processor,
            "val focalPx = rectifiedFocalPx *\n                performanceProfile.workWidth.toDouble() /"
        ),
    'operator rendering preserves aspect ratio' =>
        str_contains($overlay, 'val scale = minOf(') &&
        !str_contains($overlay, 'val scale = maxOf('),
    'contract records vertical QUALITY dimensions' =>
        str_contains($contract, 'QUALITY_480  -> 270×480') &&
        str_contains($contract, 'FIT_CENTER'),
];

$failed = false;
foreach ($checks as $name => $passed) {
    if ($passed) {
        echo "[OK] {$name}\n";
    } else {
        echo "[FAIL] {$name}\n";
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

echo "Result: PASS\n";
