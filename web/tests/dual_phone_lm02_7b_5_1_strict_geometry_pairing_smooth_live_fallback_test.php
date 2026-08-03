<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
function src(string $path): string {
    global $root;
    $v = file_get_contents($root . '/' . $path);
    if ($v === false) { fwrite(STDERR, "cannot read $path\n"); exit(1); }
    return $v;
}
function need(string $s, string $n): void {
    if (!str_contains($s, $n)) { fwrite(STDERR, "missing: $n\n"); exit(1); }
}
$host = src('web/remote_station/dual_phone_host/src/host_state.cpp');
$hostH = src('web/remote_station/dual_phone_host/src/host_state.hpp');
$preview = src('web/remote_station/dual_phone_host/src/stereo_preview.cpp');
$previewH = src('web/remote_station/dual_phone_host/src/stereo_preview.hpp');
$live = src('web/remote_station/dual_phone_host/src/live_preview_runtime.cpp');
$geom = src('web/remote_station/dual_phone_host/src/room_geometry_runtime.cpp');
$geomH = src('web/remote_station/dual_phone_host/src/room_geometry_runtime.hpp');
$web = src('web/remote_station/dual_phone_host/web/index.html');
need($host, 'kRelaxedPairDeltaMs = 60.0');
need($host, 'kRelaxedPairGraceMs = 250.0');
need($host, 'submit_live_only');
need($host, '{"live_only", !strict_ready}');
need($hostH, 'pair_relaxed_count_');
need($previewH, 'std::string sync_mode = "STRICT"');
need($previewH, 'void submit_live_only');
need($preview, 'live_only_submitted');
need($live, 'input_sync_mode');
need($live, 'job.pair.sync_mode');
need($geom, 'kMinimumGeometryInterval{2000}');
need($geom, 'rejected_interval_frames');
need($geom, 'Clone only after backpressure accepts this job');
need($geomH, 'bool submit(');
need($web, 'relaxed ${pair.relaxed_pairs || 0}');
echo "OK\n";
