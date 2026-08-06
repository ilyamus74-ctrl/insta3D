<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'helper' => $root . '/web/remote_station/dual_phone_host/src/metric_depth_accuracy.hpp',
    'runtime' => $root . '/web/remote_station/dual_phone_host/src/live_preview_runtime.cpp',
    'probe_script' => $root . '/web/remote_station/dual_phone_host/metric_depth_probe.sh',
    'contract' => $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_5_1_METRIC_DEPTH_ACCURACY_AUDIT_AND_DYNAMIC_DISPARITY_CONTRACT.md',
];

$content = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "missing {$name}: {$path}\n");
        exit(1);
    }
    $value = file_get_contents($path);
    if ($value === false) {
        fwrite(STDERR, "cannot read {$name}: {$path}\n");
        exit(1);
    }
    $content[$name] = $value;
}

$required = [
    'helper' => [
        'kMatcherNearRangeMeters = 0.75',
        'kMaximumLiveNumDisparities = 384',
        'make_matcher(-num_disparities + 1',
        'build_left_right_consistency',
        'effective_disparity',
        'measurement_reliable',
        'AMBIGUOUS_LOCAL_DISPARITY',
        'left_right_consistency_ratio',
        'raw_disparity_px',
        'effective_disparity_px',
    ],
    'runtime' => [
        '#include "metric_depth_accuracy.hpp"',
        'metric_depth_accuracy::num_disparities',
        'metric_depth_accuracy::process',
        'cached_disparity_zero_offset_px',
        'probe_metric.publish',
        'last_num_disparities',
        'last_left_right_consistent_ratio',
    ],
    'probe_script' => [
        '/api/depth/probe',
        'raw_disparity_px',
        'effective_disparity_px',
        'left_right_consistency_ratio',
        'measurement_confidence',
    ],
    'contract' => [
        'No fixed `+17 px`',
        'Native selected-resolution calibration and uplink',
        'leaves `index.html` untouched',
    ],
];

foreach ($required as $name => $needles) {
    foreach ($needles as $needle) {
        if (!str_contains($content[$name], $needle)) {
            fwrite(STDERR, "missing {$name} marker: {$needle}\n");
            exit(1);
        }
    }
}

if (str_contains($content['runtime'], 'std::clamp(width / 3, 64, 128)')) {
    fwrite(STDERR, "runtime still uses the old 128-pixel disparity ceiling\n");
    exit(1);
}
if (preg_match('/(?:\+\s*17(?:\.0)?|0\.72)\s*(?:px)?/', $content['runtime'])) {
    fwrite(STDERR, "runtime contains an empirical metric-depth correction\n");
    exit(1);
}

$dynamicRange = static function (int $width, float $focalPx, float $baselineMm): int {
    $widthLimit = max(64, intdiv(intdiv($width, 2), 16) * 16);
    $maximum = min(384, $widthLimit);
    $near = $focalPx * $baselineMm / (0.75 * 1000.0);
    $desired = max(64, (int) ceil($near) + 16);
    $aligned = intdiv($desired + 15, 16) * 16;
    return max(64, min($maximum, $aligned));
};

$high = $dynamicRange(360, 514.38, 217.58);
$ultra = $dynamicRange(540, 771.57, 217.58);
if ($high <= 128 || $ultra <= 128 || $ultra <= $high) {
    fwrite(STDERR, "dynamic disparity range regression: HIGH={$high}, ULTRA={$ultra}\n");
    exit(1);
}

echo "OK: metric depth audit r3\n";
