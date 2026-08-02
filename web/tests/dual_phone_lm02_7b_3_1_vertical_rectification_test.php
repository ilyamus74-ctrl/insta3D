<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'processor' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp',
    'processing' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview_processing.cpp',
    'header' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview_processing.hpp',
    'pack' => $root . '/web/remote_station/dual_phone_host/scripts/pack_session.sh',
    'contract' => $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_3_1_VERTICAL_RECTIFICATION_FIX_CONTRACT.md',
];

foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
}

$content = array_map(static fn(string $path): string => file_get_contents($path), $paths);

$required = [
    'processor' => [
        'RectificationAxis cached_axis',
        'rectification_axis(projection_b)',
        'projection_shift(projection_b, cached_axis)',
        'projection_a(cv::Rect(0, 0, 3, 3)).clone()',
        'projection_b(cv::Rect(0, 0, 3, 3)).clone()',
        'orient_for_horizontal_disparity(',
        'processing_rotation_degrees',
        'rectification_axis_state',
        'map_valid_fraction_a',
        'require_usable_rectified_image(',
        'raw_a_latest.jpg',
        'rectified_a_latest.jpg',
        'disparity_latest.jpg',
        'explicit Impl(std::filesystem::path session_path)',
    ],
    'processing' => [
        'RectificationAxis::Vertical',
        'projection_b.at<double>(1, 3)',
        'cv::ROTATE_90_COUNTERCLOCKWISE',
        'rectified_projection_shift > 0.0',
        'rectification produced an effectively black image',
        'map_valid_fraction(',
    ],
    'header' => [
        'enum class RectificationAxis',
        'struct ImageStatistics',
        'RectificationAxis rectification_axis(',
        'ImageStatistics image_statistics(',
    ],
    'pack' => [
        'raw_a_latest.jpg',
        'raw_b_latest.jpg',
        'rectified_a_latest.jpg',
        'rectified_b_latest.jpg',
        'disparity_latest.jpg',
        '! -name MANIFEST.sha256',
    ],
    'contract' => [
        'dominant `P2(1,3)` means vertical stereo',
        'rectified images 90 degrees counter-clockwise',
        'Metric depth remains',
    ],
];

foreach ($required as $name => $needles) {
    foreach ($needles as $needle) {
        if (!str_contains($content[$name], $needle)) {
            fwrite(STDERR, "{$name} missing token: {$needle}\n");
            exit(1);
        }
    }
}

if (str_contains($content['processor'], 'cached_translation.at<double>(0, 0)')) {
    fwrite(STDERR, "StereoSGBM must not derive direction only from raw T.x\n");
    exit(1);
}

if (preg_match('/find \. -type f -print0.*MANIFEST\.sha256/s', $content['pack']) === 1) {
    fwrite(STDERR, "MANIFEST.sha256 must not include itself\n");
    exit(1);
}

echo "OK\n";
