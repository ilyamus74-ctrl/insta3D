<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$hostHeader = (string) file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/host_state.hpp',
);
$host = (string) file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/host_state.cpp',
);
$http = (string) file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/http_dashboard.cpp',
);
$contract = (string) file_get_contents(
    $root . '/docs/llm/tasks/APP-TOF-LM03.5-REGISTERED-RGB-ANCHORS.md',
);

$checks = [
    'host exposes dedicated machine-readable ToF diagnostic API' =>
        str_contains($hostHeader, 'tof_registered_diagnostic_json() const') &&
        str_contains($http, '"/api/tof/registered"'),
    'diagnostic is built from exact CAMERA_A frame header' =>
        str_contains($host, 'build_tof_registered_diagnostic(frame)') &&
        str_contains($host, 'frame.header.at("tof_registered")') &&
        str_contains($host, '"camera_frame_sequence", frame.sequence'),
    'diagnostic contains required timing and count fields' =>
        str_contains($host, '"camera_sensor_timestamp_ns"') &&
        str_contains($host, '"camera_elapsed_realtime_ns"') &&
        str_contains($host, '"valid_zone_count"') &&
        str_contains($host, '"projected_anchor_count"') &&
        str_contains($host, '"inside_image_count"'),
    'per-slot diagnostic carries ToF sequence pairing and anchor list' =>
        str_contains($host, '"tof_sequence"') &&
        str_contains($host, '"pair_delta_us"') &&
        str_contains($host, '"pair_threshold_us"') &&
        str_contains($host, '"anchors", std::move(anchors)'),
    'depth range summary is persisted per slot' =>
        str_contains($host, '"min_depth_mm"') &&
        str_contains($host, '"median_depth_mm"') &&
        str_contains($host, '"max_depth_mm"'),
    'disk evidence is bounded latest snapshot rather than unbounded JSONL' =>
        str_contains($host, 'tof_registered_latest.json') &&
        str_contains($host, 'kTofDiagnosticPersistStride = 15') &&
        str_contains($host, 'write_json_atomically') &&
        !str_contains($host, 'tof_registered.jsonl'),
    'status exposes diagnostic health counters' =>
        str_contains($host, '"snapshots_seen"') &&
        str_contains($host, '"snapshots_persisted"') &&
        str_contains($host, '"persist_stride_frames"'),
    'LM03.5 contract separates bounded C diagnostics from future SfM archive' =>
        str_contains($contract, 'GET /api/tof/registered') &&
        str_contains($contract, 'tof_registered_latest.json') &&
        str_contains($contract, 'Full capture-time ToF sidecars belong to LM03.5D'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$ok;
}

echo 'Result: ' . ($failed ? 'FAIL' : 'PASS') . PHP_EOL;
exit($failed ? 1 : 0);
