<?php
$root = dirname(__DIR__, 2);
$profile = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneCalibrationProfile.kt');
$coach = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneStereoCoachEstimator.kt');
$ui = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneCalibrationFullscreen.kt');

$checks = [
    'final summary identifies actual stereo resolution' => str_contains($profile, 'STEREO ${imageWidth}×${imageHeight}'),
    'final summary labels actual metrics as RAW' => str_contains($profile, 'RAW @${imageWidth}×${imageHeight}: RMS'),
    'final summary labels normalized scale explicitly' => str_contains($profile, 'QUALITY EQUIV @1280 (только шкала)'),
    'profile exposes rejection metric' => str_contains($profile, 'fun rejectionMetricRu()'),
    'profile exposes EPI audit hint' => str_contains($profile, 'fun geometryAuditHintRu()'),
    'audit can flag sync suspicion' => str_contains($profile, 'SYNC_SUSPECT'),
    'audit can flag systematic EPI' => str_contains($profile, 'SYSTEMATIC_EPI'),
    'RMS threshold remains 2.0 reference pixels' => str_contains($profile, 'const val MAX_STEREO_RMS_PX = 2.0'),
    'EPI threshold remains 1.75 reference pixels' => str_contains($profile, 'const val MAX_MEAN_EPIPOLAR_ERROR_PX = 1.75'),
    'live coach carries raw RMS' => str_contains($coach, 'val rawLiveRmsPx: Double? = null'),
    'live coach carries raw EPI' => str_contains($coach, 'val rawMeanEpipolarErrorPx: Double? = null'),
    'live coach labels actual solve resolution' => str_contains($coach, 'solve ${imageWidth}×${imageHeight}'),
    'live coach distinguishes quality equivalent' => str_contains($coach, 'QUALITY EQUIV@1280 RMS'),
    'UI explains normalized scale does not resize calibration' => str_contains($ui, 'кадры до 1280 не уменьшаются'),
    'UI warning uses normalized EPI' => str_contains($ui, 'finalResult.stereo.normalizedMeanEpipolarErrorPx?.let'),
];

$failed = [];
foreach ($checks as $name => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$ok) $failed[] = $name;
}

if ($failed) {
    fwrite(STDERR, 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo 'OK: LM02.7B.5.5.4 stereo metric labels and EPI attribution' . PHP_EOL;
