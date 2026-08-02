<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourcePath = $root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp';
$source = file_get_contents($sourcePath);

if ($source === false) {
    fwrite(STDERR, "Cannot read stereo_preview.cpp\n");
    exit(1);
}

$required = [
    'projection_usable',
    'projection_fallback_used',
    'const cv::Mat common_k',
    'projection_a = cv::Mat::zeros(3, 4, CV_64F)',
    'projection_b = cv::Mat::zeros(3, 4, CV_64F)',
    'common_k.copyTo(projection_a(cv::Rect(0, 0, 3, 3)))',
    'common_k.copyTo(projection_b(cv::Rect(0, 0, 3, 3)))',
    'focal * signed_baseline_mm',
    'q.at<double>(3, 2) = -1.0 / signed_baseline_mm',
];

foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Missing finite projection fallback marker: {$needle}\n");
        exit(1);
    }
}

echo "OK\n";
