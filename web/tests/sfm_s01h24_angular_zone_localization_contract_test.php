<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$script = $root . '/web/remote_station/scripts/analyze_tof_dense_zone_h24.py';

if (!is_file($script)) {
    fwrite(STDERR, "FAIL: missing H2.4 script\n");
    exit(1);
}

$text = file_get_contents($script);
if ($text === false) {
    fwrite(STDERR, "FAIL: cannot read H2.4 script\n");
    exit(1);
}

$required = [
    'SFM-S01H2.4',
    '--observations',
    '--observation-report',
    '--tof-calibration',
    '--h22-candidates',
    '--h22-report',
    '--h23-decomposition',
    '--h23-report',
    '--sparse-model-dir',
    '--dense-job-dir',
    'geometric_footprint_p50',
    'zone_grid_signed_ratio_residual_p50',
    'active_perturbation_sensitivity',
    'ZONE_ANGULAR_PATTERN_SUPPORTED',
    'RGB_IMAGE_REGION_PATTERN_SUPPORTED',
    'MIXED_PATTERN_SUPPORTED',
    'INSUFFICIENT_SUPPORT',
    '"measurement_only": True',
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
