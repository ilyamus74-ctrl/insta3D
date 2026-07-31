<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$estimator = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneStereoCoachEstimator.kt'
);

$required = [
    'Calib3d.stereoRectify(',
    'Calib3d.undistortPoints(',
    'rectifiedVerticalError(',
    'MAX_PAIR_RECTIFIED_VERTICAL_ERROR_PX',
    'OUTLIER_MAD_MULTIPLIER',
    'MIN_OUTLIER_MAD_PX',
];

foreach ($required as $needle) {
    if (!str_contains($estimator, $needle)) {
        fwrite(STDERR, "Missing token: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
    'symmetricEpipolarError(',
    'pointLineDistance(',
    'OUTLIER_MEDIAN_MULTIPLIER',
];

foreach ($forbidden as $needle) {
    if (str_contains($estimator, $needle)) {
        fwrite(STDERR, "Deprecated token remains: {$needle}\n");
        exit(1);
    }
}

echo "OK\n";
