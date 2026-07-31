<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$profile = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneCalibrationProfile.kt');
$coach = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneStereoCoachEstimator.kt');
$fullscreen = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneCalibrationFullscreen.kt');

$checks = [
    [$profile, 'RECOMMENDED_MEAN_EPIPOLAR_ERROR_PX = 1.5'],
    [$profile, 'MAX_MEAN_EPIPOLAR_ERROR_PX = 1.75'],
    [$coach, 'Stereo R/T рассчитаны с предупреждением'],
    [$fullscreen, 'ПРОФИЛЬ СОХРАНЁН И АКТИВИРОВАН С ПРЕДУПРЕЖДЕНИЕМ'],
    [$fullscreen, 'КАЛИБРОВОЧНЫЙ ПРОФИЛЬ ПРИНЯТ'],
];

foreach ($checks as [$source, $needle]) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Missing token: {$needle}\n");
        exit(1);
    }
}

echo "OK\n";
