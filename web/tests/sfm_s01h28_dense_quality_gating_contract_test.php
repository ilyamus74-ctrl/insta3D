<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$script = $root . '/web/remote_station/scripts/analyze_tof_dense_quality_h28.py';

if (!is_file($script)) {
    fwrite(STDERR, "FAIL: missing H2.8 script\n");
    exit(1);
}
$text = file_get_contents($script);
if ($text === false) {
    fwrite(STDERR, "FAIL: cannot read H2.8 script\n");
    exit(1);
}

$required = [
    'SFM-S01H2.8',
    '--h26-rows',
    '--h26-report',
    '--h27-report',
    '--h22-candidates',
    'geometric_local_gradient_fraction',
    'geometric_photometric_relative_difference',
    'CLEAN_DENSE',
    'UNSTABLE_DENSE',
    'DENSE_QUALITY_GATE_SUPPORTED',
    'DENSE_QUALITY_GATE_PARTIAL_SUPPORT',
    'DENSE_QUALITY_GATE_NOT_SUPPORTED',
    'INSUFFICIENT_SUPPORT',
    'LOW_QUALITY_QUANTILE = 0.25',
    'HIGH_QUALITY_QUANTILE = 0.75',
    '"selection_uses_metric_residuals": False',
    '"measurement_only": True',
    '"camera_model_mutation_enabled": False',
    '"calibration_mutation_enabled": False',
    '"geometry_mutation_enabled": False',
    '"ready_for_geometry_mutation": False',
    '"sparse_model_modified": False',
    '"camera_poses_modified": False',
    '"points3d_modified": False',
    '"dense_input_modified": False',
    '"dense_depth_modified": False',
    '"fusion_enabled": False',
];
foreach ($required as $needle) {
    if (strpos($text, $needle) === false) {
        fwrite(STDERR, "FAIL: missing contract token: {$needle}\n");
        exit(1);
    }
}
echo "Result: PASS\n";
