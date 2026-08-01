<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workspace = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneFullScreenScanWorkspace.kt'
);
$contour = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneContourFirstViewport.kt'
);
$slave = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneSlaveAspectSafePreview.kt'
);
$contract = file_get_contents(
    $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_6_4_CONTOUR_FIRST_UI_CONTRACT.md'
);

$checks = [
    'MASTER exposes OUTLINE ASSIST and HEATMAP modes' =>
        str_contains($workspace, 'ASSIST("ASSIST")') &&
        str_contains($workspace, 'HEATMAP("HEAT")') &&
        str_contains($workspace, 'DualPhoneContourFirstViewport'),
    'OUTLINE disables the DENSE fill' =>
        str_contains($contour, 'mode != DualPhoneOperatorOverlayMode.OUTLINE') &&
        str_contains($contour, 'DualPhoneOperatorOverlayMode.OUTLINE -> 0'),
    'ASSIST uses a bounded weak DENSE layer' =>
        str_contains($contour, 'DualPhoneOperatorOverlayMode.ASSIST -> 86'),
    'RECT DEPTH inset is restricted to HEATMAP' =>
        str_contains($contour, 'mode == DualPhoneOperatorOverlayMode.HEATMAP') &&
        str_contains($contour, 'DualPhoneRectifiedDepthInset'),
    'registered layers share one rotation and center-crop transform' =>
        substr_count($contour, 'rotationDegrees = baseRotation') >= 3 &&
        str_contains($contour, 'drawContourCenterCrop'),
    'SLAVE sharp foreground is FIT_CENTER with zero crop' =>
        str_contains($workspace, 'DualPhoneSlaveAspectSafePreview') &&
        str_contains($workspace, 'display FIT_CENTER · crop 0%') &&
        str_contains($slave, 'fitCenter = true') &&
        str_contains($slave, 'minOf('),
    'SLAVE decorative background does not replace foreground' =>
        str_contains($slave, 'fitCenter = false') &&
        str_contains($slave, 'canvas.drawColor'),
    'APP contract records contour-first and aspect-safe invariants' =>
        str_contains($contract, 'OUTLINE is the default operator mode') &&
        str_contains($contract, 'FIT_CENTER') &&
        str_contains($contract, 'zero crop'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    if ($ok) {
        echo "[OK] $label\n";
    } else {
        echo "[FAIL] $label\n";
        $failed = true;
    }
}

exit($failed ? 1 : 0);
