<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$live = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/live_preview_runtime.cpp');
$map = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp');
$index = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/web/index.html');
$contract = file_get_contents(
    $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_4_1_STATIONARY_TRUTH_ROBUST_GYRO_METRIC_DEPTH_HEALTH_CONTRACT.md');

if ($live === false || $map === false || $index === false ||
    $contract === false) {
    fwrite(STDERR, "required file is missing\n");
    exit(1);
}

foreach ([
    'job_reset_revision != reset_revision',
    'LIVE_PREVIEW_STALE_RESULT_DISCARDED',
    'STALE_CALIBRATION_PAIR_REJECTED',
    'usable_baseline_mm',
    'NO_VALID_DISPARITY',
    'DEPTH_MASK_EMPTY',
    'HEATMAP_EMPTY',
    'non_black_heatmap_ratio',
    'successful_publishes_since_reset',
    'recovery_attempted_calibration_revision',
    'selected_preview_latest.jpg',
] as $token) {
    if (!str_contains($live, $token)) {
        fwrite(STDERR, "missing live-preview token: {$token}\n");
        exit(1);
    }
}

$guardPosition = strpos($live, 'job_reset_revision != reset_revision');
$writePosition = $guardPosition === false
    ? false
    : strpos($live, 'write_binary_atomic(', $guardPosition);
if ($writePosition === false || $guardPosition === false ||
    $writePosition < $guardPosition) {
    fwrite(STDERR, "preview file can be written before stale reset guard\n");
    exit(1);
}

foreach ([
    'robust_bias_estimate',
    'ROBUST_INITIAL_STILLNESS_FREEZE',
    'gyro_bias_rejected_samples',
    'gyro_bias_mad_rad_s',
    'gyro_bias_confidence',
    'apply_stationary_truth_guard',
    'AUTO_STATIONARY_TRUTH_GUARD',
    'stationary_truth_frames',
] as $token) {
    if (!str_contains($map, $token)) {
        fwrite(STDERR, "missing motion token: {$token}\n");
        exit(1);
    }
}

foreach ([
    'health_reason',
    'fresh_pairs_since_reset',
    'consecutive_failures',
] as $token) {
    if (!str_contains($index, $token)) {
        fwrite(STDERR, "missing dashboard token: {$token}\n");
        exit(1);
    }
}

foreach ([
    'written only after the stale-result guard',
    'never publishes a map keyframe',
    'bounded median/MAD window',
] as $token) {
    if (!str_contains($contract, $token)) {
        fwrite(STDERR, "missing contract token: {$token}\n");
        exit(1);
    }
}

echo "OK\n";
