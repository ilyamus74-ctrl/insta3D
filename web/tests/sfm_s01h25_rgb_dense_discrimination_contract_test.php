<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$script = $root . '/web/remote_station/scripts/analyze_tof_dense_rgb_h25.py';

if (!is_file($script)) {
    fwrite(STDERR, "FAIL: missing H2.5 script\n");
    exit(1);
}

$text = file_get_contents($script);
if ($text === false) {
    fwrite(STDERR, "FAIL: cannot read H2.5 script\n");
    exit(1);
}

$required = [
    'SFM-S01H2.5',
    '--observations',
    '--observation-report',
    '--tof-calibration',
    '--camera-metadata',
    '--h23-decomposition',
    '--h23-report',
    '--sparse-model-dir',
    '--dense-job-dir',
    'camera2_focal_ratio',
    'geometric_footprint_iqr_fraction',
    'geometric_local_gradient_fraction',
    'geometric_photometric_relative_difference',
    'RGB_EFFECTIVE_PROJECTION_PATTERN_SUPPORTED',
    'DENSE_LOCAL_STRUCTURE_PATTERN_SUPPORTED',
    'MIXED_PATTERN_SUPPORTED',
    'INSUFFICIENT_SUPPORT',
    'radial_distortion_perturbation_evaluated',
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
