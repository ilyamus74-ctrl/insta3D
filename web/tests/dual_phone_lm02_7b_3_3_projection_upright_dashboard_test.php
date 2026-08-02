<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$processing = file_get_contents($root . '/web/remote_station/dual_phone_host/src/stereo_preview_processing.cpp');
$preview = file_get_contents($root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp');
$html = file_get_contents($root . '/web/remote_station/dual_phone_host/web/index.html');

$checks = [
    [$processing, 'const auto fallback_shift = translation_mm[static_cast<std::size_t>(row)]'],
    [$processing, 'StereoSGBM only needs the disparity direction here'],
    [$preview, 'const auto projection_usable = []'],
    [$preview, 'const auto shared_k = (cv::Mat_<double>(3, 3)'],
    [$preview, 'frame_a.intrinsics.fx'],
    [$preview, 'frame_b.intrinsics.fy'],
    [$html, 'grid-template-columns: repeat(2,minmax(0,1fr))'],
    [$html, 'class="camera raw-camera"'],
    [$html, 'function orientRawImage(element, camera)'],
    [$html, 'latest.rotation_degrees'],
    [$html, "quarterTurn ? '177.7778%' : '100%'"],
];

foreach ($checks as [$content, $needle]) {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "missing: {$needle}\n");
        exit(1);
    }
}

if (str_contains($processing, 'focal_length * translation_mm')) {
    fwrite(STDERR, "projection fallback still depends on zero P2 focal term\n");
    exit(1);
}

echo "OK\n";
