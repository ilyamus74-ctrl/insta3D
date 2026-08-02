<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$processing = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/stereo_preview_processing.cpp'
);
$processingHeader = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/stereo_preview_processing.hpp'
);
$preview = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp'
);

if ($processing === false || $processingHeader === false || $preview === false) {
    fwrite(STDERR, "Unable to read LM02.7B.3.4 sources\n");
    exit(1);
}

$required = [
    [$processing, 'Stereo calibration and stereoRectify operate on'],
    [$processing, 'the unrotated sensor/JPEG landscape pixels'],
    [$processing, 'return {std::move(decoded), scale_intrinsics(calibration, decoded.size()), 0}'],
    [$processing, 'rectified_projection_shift < 0.0'],
    [$processing, 'cv::ROTATE_90_COUNTERCLOCKWISE'],
    [$processing, 'cv::ROTATE_90_CLOCKWISE'],
    [$processingHeader, 'double rectified_projection_shift'],
    [$preview, 'rectification_a, projection_a'],
    [$preview, 'rectification_b, projection_b'],
    [$preview, 'STEREO_RECTIFICATION_MAPS_READY'],
    [$preview, 'camera_a_rotation_metadata'],
    [$preview, 'last_raw_image_write'],
    [$preview, 'cached_projection_shift < 0.0 ? -90 : 90'],
];

foreach ($required as [$source, $needle]) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Missing LM02.7B.3.4 contract fragment: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
    [$processing, 'rotate_frame(decoded'],
    [$processing, 'compatible_aspect(rotated.size()'],
    [$preview, 'const auto projection_usable = []'],
    [$preview, 'shared_k'],
    [$preview, 'kMinimumMapValidFraction'],
    [$preview, 'projection_a(cv::Rect(0, 0, 3, 3))'],
    [$preview, 'projection_b(cv::Rect(0, 0, 3, 3))'],
];

foreach ($forbidden as [$source, $needle]) {
    if (str_contains($source, $needle)) {
        fwrite(STDERR, "Forbidden pre-Android-parity fragment remains: {$needle}\n");
        exit(1);
    }
}

$rawWrite = strpos($preview, 'session_directory / "raw_a_latest.jpg"');
$mapBuild = strpos($preview, 'cv::stereoRectify(');
if ($rawWrite === false || $mapBuild === false || $rawWrite > $mapBuild) {
    fwrite(STDERR, "Raw diagnostic frames must be written before rectification\n");
    exit(1);
}

echo "OK\n";
