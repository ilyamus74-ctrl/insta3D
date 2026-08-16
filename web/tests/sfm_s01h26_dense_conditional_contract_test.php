<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$script = $root . '/web/remote_station/scripts/analyze_tof_dense_conditional_h26.py';

if (!is_file($script)) {
    fwrite(STDERR, "FAIL: missing H2.6 script\n");
    exit(1);
}

$text = file_get_contents($script);
if ($text === false) {
    fwrite(STDERR, "FAIL: cannot read H2.6 script\n");
    exit(1);
}

$required = [
    'SFM-S01H2.6',
    '--h25-structure',
    '--h25-report',
    '--h23-decomposition',
    'distance_bucket+zone_index',
    'distance_bucket+zone_index+time_quartile',
    'geometric_footprint_iqr_fraction',
    'geometric_local_gradient_fraction',
    'geometric_photometric_relative_difference',
    'DENSE_LOCAL_STRUCTURE_CONDITIONAL_SUPPORTED',
    'DENSE_LOCAL_STRUCTURE_PARTIAL_SUPPORT',
    'INSUFFICIENT_SUPPORT',
    '"measurement_only": True',
    '"geometry_mutation_enabled": False',
    '"ready_for_geometry_mutation": False',
    '"camera_model_mutation_enabled": False',
    '"calibration_mutation_enabled": False',
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
