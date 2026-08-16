<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
function source_file(string $relative): string {
    global $root;
    $path = $root . '/' . $relative;
    if (!is_file($path)) { fwrite(STDERR, "[FAIL] missing {$relative}\n"); exit(1); }
    return (string)file_get_contents($path);
}
function ok(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "[FAIL] {$message}\n"); exit(1); }
    echo "[OK] {$message}\n";
}
$h23 = source_file('web/remote_station/scripts/analyze_tof_dense_error_h23.py');
ok(str_contains($h23, 'SFM-S01H2.3'), 'H2.3 stage is explicitly versioned');
ok(str_contains($h23, 'distance_normalized_ratio'), 'H2.3 removes distance trend before zone analysis');
ok(str_contains($h23, 'estimate_row_column_effects'), 'H2.3 estimates controlled row/column effects');
ok(str_contains($h23, 'zone_normalized_scale_mm_per_colmap_unit'), 'H2.3 removes zone effects before rechecking distance');
ok(str_contains($h23, 'camera_optics_audit'), 'H2.3 audits optimized COLMAP optics against Camera2 prior');
ok(str_contains($h23, 'APPROXIMATE_ONLY'), 'geometry beyond direct ToF range remains approximate only');
ok(str_contains($h23, '"geometry_mutation_enabled": False'), 'H2.3 cannot mutate geometry');
ok(str_contains($h23, '"dense_input_modified": False'), 'H2.3 cannot modify dense input');
ok(str_contains($h23, '"fusion_enabled": False'), 'H2.3 cannot enable fusion');
echo "Result: PASS\n";
