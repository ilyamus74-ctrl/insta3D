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

$h21 = source_file(
    'web/remote_station/scripts/measure_tof_sparse_scale_h21.py'
);

ok(
    str_contains($h21, 'SFM-S01H2.1'),
    'H2.1 experiment is explicitly versioned'
);
ok(
    str_contains($h21, 'nearest_radius_sweep_px'),
    'H2.1 performs the nearest-radius sweep'
);
ok(
    str_contains($h21, 'zone_footprint_polygon'),
    'H2.1 projects a ToF zone footprint'
);
ok(
    str_contains($h21, 'footprint_median_all'),
    'H2.1 measures median sparse depth in the footprint'
);
ok(
    str_contains($h21, 'footprint_front_cluster'),
    'H2.1 measures a front sparse depth cluster'
);
ok(
    str_contains($h21, 'SKIPPED_NO_TOF_MEASUREMENT'),
    'missing ToF remains a non-fatal skip'
);
ok(
    str_contains($h21, '"geometry_mutation_enabled": False'),
    'H2.1 cannot mutate geometry'
);
ok(
    str_contains($h21, '"sparse_model_modified": False'),
    'H2.1 cannot modify sparse'
);
ok(
    str_contains($h21, '"dense_input_modified": False'),
    'H2.1 cannot modify dense input'
);
ok(
    str_contains($h21, '"fusion_enabled": False'),
    'H2.1 cannot enable fusion'
);

echo "Result: PASS\n";
