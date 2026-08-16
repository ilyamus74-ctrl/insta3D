<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

function source_file(string $relative): string {
    global $root;
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] missing {$relative}\n");
        exit(1);
    }
    return (string)file_get_contents($path);
}

function ok(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[OK] {$message}\n";
}

$h22 = source_file(
    'web/remote_station/scripts/measure_tof_dense_depth_h22.py'
);

ok(str_contains($h22, 'SFM-S01H2.2'), 'H2.2 stage is explicitly versioned');
ok(str_contains($h22, 'geometric_footprint_p50'), 'geometric footprint median is measured');
ok(str_contains($h22, 'photometric_footprint_p50'), 'photometric footprint median is measured');
ok(str_contains($h22, 'distance_scale_spread_ratio'), 'distance-dependent scale diagnostic exists');
ok(str_contains($h22, 'zone_row_scale_spread_ratio'), 'zone-row diagnostic exists');
ok(str_contains($h22, 'zone_column_scale_spread_ratio'), 'zone-column diagnostic exists');
ok(str_contains($h22, 'time_scale_spread_ratio'), 'temporal drift diagnostic exists');
ok(str_contains($h22, 'image_region_scale_spread_ratio'), 'image-region diagnostic exists');
ok(str_contains($h22, 'APPROXIMATE_ONLY'), 'beyond-4m policy is explicit');
ok(str_contains($h22, 'tof_extrapolation_beyond_range'), 'ToF extrapolation policy is explicit');
ok(str_contains($h22, 'SKIPPED_NO_TOF_MEASUREMENT'), 'missing ToF is non-fatal');
ok(str_contains($h22, 'SKIPPED_DENSE_UNAVAILABLE'), 'missing Dense is non-fatal');
ok(str_contains($h22, '"geometry_mutation_enabled": False'), 'H2.2 cannot mutate geometry');
ok(str_contains($h22, '"dense_input_modified": False'), 'H2.2 cannot modify dense input');
ok(str_contains($h22, '"dense_depth_modified": False'), 'H2.2 cannot modify depth maps');
ok(str_contains($h22, '"fusion_enabled": False'), 'H2.2 cannot enable fusion');

echo "Result: PASS\n";
