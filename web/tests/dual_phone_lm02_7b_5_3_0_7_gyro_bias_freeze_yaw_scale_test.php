<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourcePath = $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp';
$contractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_3_0_7_GYRO_BIAS_FREEZE_YAW_SCALE_CONTRACT.md';

$source = file_get_contents($sourcePath);
$contract = file_get_contents($contractPath);
if ($source === false || $contract === false) {
    fwrite(STDERR, "required file is missing\n");
    exit(1);
}

$requiredSourceTokens = [
    'kGyroBiasCalibrationSamples = 80',
    'kGyroBiasCalibrationMaximumRateRadS = 0.06',
    'kGyroBiasCalibrationMaximumAccelerationDeltaMps2 = 0.25',
    'gyro_bias_calibration_sum_rad_s += rate',
    'gyro_bias_ready = true',
    'gyro_yaw_uncalibrated_deg += rate * dt',
    'gyro_yaw_raw_deg += (rate - gyro_bias_rad_s) * dt',
    '"INITIAL_STILLNESS_FREEZE"',
    '"gyro_yaw_bias_removed_deg"',
    '"gyro_uncalibrated_yaw_step_deg"',
    '"gyro_bias_removed_step_deg"',
];
foreach ($requiredSourceTokens as $token) {
    if (!str_contains($source, $token)) {
        fwrite(STDERR, "missing source token: {$token}\n");
        exit(1);
    }
}

$forbiddenSourceTokens = [
    'kGyroStillThresholdRadS = 0.10',
    'gyro_bias_rad_s = gyro_bias_rad_s * 0.995',
    'rate * 2.0',
    'gyro_step * 2.0',
];
foreach ($forbiddenSourceTokens as $token) {
    if (str_contains($source, $token)) {
        fwrite(STDERR, "forbidden source token remains: {$token}\n");
        exit(1);
    }
}

foreach (['rotation must never be absorbed', 'No hard yaw multiplier', 'gyro_bias_ready'] as $token) {
    if (!str_contains($contract, $token)) {
        fwrite(STDERR, "missing contract token: {$token}\n");
        exit(1);
    }
}

echo "OK\n";
