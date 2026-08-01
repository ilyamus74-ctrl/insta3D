<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$processor = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLiveDepthProcessor.kt'
);
$projector = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneRawCameraOverlayProjector.kt'
);
$overlay = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneAdaptiveOutlineOverlay.kt'
);
$contract = file_get_contents(
    $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_6_3_RAW_REGISTERED_OVERLAY_CONTRACT.md'
);

$checks = [
    'snapshot carries registered paired products' =>
        str_contains($processor, 'registeredMasterJpeg') &&
        str_contains($processor, 'registeredDenseOverlayPng') &&
        str_contains($processor, 'registeredStrictOutlinePng') &&
        str_contains($processor, 'registeredMasterFrameSequence'),
    'processor invokes raw-camera projector with rectification maps' =>
        str_contains($processor, 'DualPhoneRawCameraOverlayProjector.project(') &&
        str_contains($processor, 'mapMasterX = mapMasterX') &&
        str_contains($processor, 'mapMasterY = mapMasterY'),
    'projector restores rectified orientation and splats to source pixels' =>
        str_contains($projector, 'restoreRectifiedOrientation') &&
        str_contains($projector, 'splatRectifiedToInput') &&
        str_contains($projector, 'mapMasterX.get') &&
        str_contains($projector, 'mapMasterY.get'),
    'registered overlays are alpha PNGs' =>
        str_contains($projector, 'CvType.CV_8UC4') &&
        str_contains($projector, 'Imgcodecs.imencode(".png"'),
    'operator uses paired natural frame and registered overlays' =>
        str_contains($overlay, 'depth.registeredMasterJpeg') &&
        str_contains($overlay, 'depth.registeredDenseOverlayPng') &&
        str_contains($overlay, 'depth.registeredStrictOutlinePng') &&
        !str_contains($overlay, 'val denseBytes: ByteArray? = null'),
    'contract preserves strict geometry semantics' =>
        str_contains($contract, 'STRICT remains the geometry gate') &&
        str_contains($contract, 'invalid projected pixels remain transparent'),
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
