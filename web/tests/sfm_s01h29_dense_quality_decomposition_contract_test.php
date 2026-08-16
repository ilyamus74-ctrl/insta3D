<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$script = $root . '/web/remote_station/scripts/analyze_tof_dense_quality_decomposition_h29.py';

if (!is_file($script)) {
    fwrite(STDERR, "FAIL: missing H2.9 script\n");
    exit(1);
}

$text = file_get_contents($script);
if ($text === false) {
    fwrite(STDERR, "FAIL: cannot read H2.9 script\n");
    exit(1);
}

$required = [
    'SFM-S01H2.9',
    '--h28-rows',
    '--h28-report',
    'CLEAN_DENSE',
    'MIXED_LOCAL_DENSE_INSTABILITY_AND_SYSTEMATIC_DEPTH_SCALE_DEFORMATION_SUPPORTED',
    'SYSTEMATIC_DEPTH_SCALE_DEFORMATION_PERSISTS_IN_CLEAN_DENSE',
    'QUALITY_GATING_COLLAPSES_CONTROLLED_DEFORMATION',
    'distance_normalized_ratio',
    'zone_normalized_scale_mm_per_colmap_unit',
    'bootstrap_iterations',
    'metric-range validation',
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
