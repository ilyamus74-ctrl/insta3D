<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$processing = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/stereo_preview_processing.cpp'
);
$preview = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp'
);

if ($processing === false || $preview === false) {
    fwrite(STDERR, "Unable to read dual-phone host sources\n");
    exit(1);
}

$requiredProcessing = [
    'const auto decoded_size = decoded.size();',
    'scale_intrinsics(calibration, decoded_size)',
    'cannot scale intrinsics to an empty image',
    'scaled intrinsics contain an invalid focal length',
];

foreach ($requiredProcessing as $needle) {
    if (!str_contains($processing, $needle)) {
        fwrite(STDERR, "Missing processing contract: {$needle}\n");
        exit(1);
    }
}

if (str_contains(
    $processing,
    'return {std::move(decoded), scale_intrinsics(calibration, decoded.size()), 0};'
)) {
    fwrite(STDERR, "Moved-from decoded.size() regression is present\n");
    exit(1);
}

$requiredPreview = [
    'pair.camera_a.rotation_degrees - processing_rotation',
    'pair.camera_b.rotation_degrees - processing_rotation',
    'display_rotation_a_degrees',
    'display_rotation_b_degrees',
    'display_disparity',
];

foreach ($requiredPreview as $needle) {
    if (!str_contains($preview, $needle)) {
        fwrite(STDERR, "Missing display-orientation contract: {$needle}\n");
        exit(1);
    }
}

echo "OK\n";
