<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$filterPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneFilteredDepthEngine.kt';
$workspacePath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneFullScreenScanWorkspace.kt';
$outlinePath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneAdaptiveOutlineOverlay.kt';
$contractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_6_OPERATOR_OUTLINE_CONTRACT.md';

foreach ([$filterPath, $workspacePath, $outlinePath, $contractPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] missing {$path}\n");
        exit(1);
    }
}

$filter = file_get_contents($filterPath);
$workspace = file_get_contents($workspacePath);
$outline = file_get_contents($outlinePath);
$contract = file_get_contents($contractPath);

$checks = [
    'OUTLINE is the default operator view' =>
        str_contains($workspace, 'OVERLAY("OUTLINE")') &&
        str_contains($workspace, 'mutableStateOf(DualPhoneMasterScanView.OVERLAY)') &&
        str_contains($workspace, 'DualPhoneContourFirstViewport'),
    'camera remains visible while depth waits or expires' =>
        str_contains($outline, 'masterFrame?.jpegBytes') &&
        str_contains($outline, 'DualPhoneDepthFreshness.EXPIRED') &&
        str_contains($outline, 'CLOCK CALIBRATING'),
    'STRICT depth is rendered as object boundaries' =>
        str_contains($outline, 'createDepthOutlineBitmap') &&
        str_contains($outline, 'зелёный контур = STRICT'),
    'metric depth uses fixed color stops' =>
        str_contains($filter, 'createMetricHeatmap') &&
        str_contains($filter, 'METRIC_COLOR_STOPS') &&
        !str_contains($filter, 'Core.NORM_MINMAX'),
    'DENSE adapts without weakening STRICT' =>
        str_contains($filter, 'DualPhoneDenseSceneProfile') &&
        str_contains($filter, 'LOW_TEXTURE(4.5f, 2)') &&
        str_contains($filter, 'STATIC_REFINE(2.5f, 5)') &&
        str_contains($filter, 'STRICT_LEFT_RIGHT_TOLERANCE_PX = 1.5f'),
    'contract defines object-oriented operator behavior' =>
        str_contains($contract, 'room recognizable') &&
        str_contains($contract, 'EXPIRED'),
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
