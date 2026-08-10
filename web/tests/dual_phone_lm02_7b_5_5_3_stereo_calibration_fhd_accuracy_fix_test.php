<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'profile' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneCalibrationProfile.kt',
    'coach' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneStereoCoachEstimator.kt',
    'analyzer' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneCalibrationRealtimeAnalyzer.kt',
    'controls' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/DualPhoneCalibrationCameraControls.kt',
    'recorder' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraVideoRecorder.kt',
];
foreach ($files as $name => $path) {
    if (!is_file($path)) { fwrite(STDERR, "Missing $name: $path
"); exit(1); }
    $files[$name] = file_get_contents($path);
}
$checks = [
    'profile stores calibration dimensions' => str_contains($files['profile'], 'val imageWidth: Int = 1280') && str_contains($files['profile'], '.put("image_width", imageWidth)'),
    'profile normalizes errors to 1280 width' => str_contains($files['profile'], 'ERROR_REFERENCE_WIDTH_PX = 1280.0') && str_contains($files['profile'], 'normalizePixelError'),
    'profile acceptance uses normalized RMS' => str_contains($files['profile'], 'val normalizedRms = normalizedRmsPx') && str_contains($files['profile'], 'normalizedRms <= MAX_STEREO_RMS_PX'),
    'camera controls expose metric ready' => str_contains($files['controls'], 'METRIC_READY') && str_contains($files['controls'], 'val metricReady = zoomLocked && applied'),
    'calibration binds at 1x before frames are accepted' => str_contains($files['recorder'], 'if (enableCalibrationAnalysis) 1.0f else zoomRatio'),
    'recorder has preparation barrier' => str_contains($files['recorder'], 'PREPARING_METRIC_CONTROLS') && str_contains($files['recorder'], 'preparedControlStatus.startsWith("METRIC_READY")'),
    'frame timestamps cannot cross the metric-ready barrier' => str_contains($files['recorder'], 'calibrationMetricReadyAfterElapsedRealtimeNs') && str_contains($files['recorder'], 'captureElapsedRealtimeNs >= calibrationMetricReadyAfterElapsedRealtimeNs'),
    'analyzer rejects pre-ready camera geometry' => str_contains($files['analyzer'], 'cameraControlsReady') && str_contains($files['analyzer'], 'val qualityReady = cameraControlsReady'),
    'stereo solve validates calibration geometry' => str_contains($files['coach'], 'CALIBRATION_GEOMETRY_MISMATCH') && str_contains($files['coach'], 'calibrationGeometryError(master, slave)'),
    'stereo outlier filtering uses normalized errors' => str_contains($files['coach'], 'normalizeError(it, model.imageWidth)'),
    'stereo result keeps raw RMS with dimensions' => str_contains($files['coach'], 'rms = model.rms') && str_contains($files['coach'], 'imageWidth = model.imageWidth'),
];
$failed = false;
foreach ($checks as $label => $ok) { echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL; $failed = $failed || !$ok; }
if ($failed) exit(1);
echo "OK: LM02.7B.5.5.3 stereo calibration FHD accuracy fix" . PHP_EOL;
