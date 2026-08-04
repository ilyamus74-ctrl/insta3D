<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$httpPath = $root . '/web/remote_station/dual_phone_host/src/http_dashboard.cpp';
$indexPath = $root . '/web/remote_station/dual_phone_host/web/index.html';
$contractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_3_5_LIVE_ACCUMULATED_MAP_TAB_CONTRACT.md';

$http = file_get_contents($httpPath);
$index = file_get_contents($indexPath);
$contract = file_get_contents($contractPath);
if ($http === false || $index === false || $contract === false) {
    fwrite(STDERR, "required file is missing\n");
    exit(1);
}

foreach ([
    '/api/map/raw.ply',
    '/api/map/multiview.ply',
    '/api/map/strict.ply',
    '/api/map/strict-multiview.ply',
    '/api/map/trajectory.json',
    'point_cloud_accumulated_raw.ply',
    'point_cloud_accumulated_multiview.ply',
    'point_cloud_accumulated_temporal_strict_raw.ply',
    'point_cloud_accumulated_temporal_strict_multiview.ply',
    'state_.session_directory() / map_asset',
    '"no-store, max-age=0"',
] as $token) {
    if (!str_contains($http, $token)) {
        fwrite(STDERR, "missing HTTP token: {$token}\n");
        exit(1);
    }
}

foreach ([
    'data-tab="map"',
    'id="mapCanvas"',
    'id="mapCloudMode"',
    'id="mapTrajectoryEnabled"',
    'id="mapAutoRefresh"',
    'function parseAsciiPly(text)',
    "canvas.getContext('webgl'",
    'function requestMapRefresh(force)',
    'updateMapRuntimeStatus(stereo.accumulated_map || {})',
    'mapModeEndpoint(mapCloudMode.value)',
    'loadedMapSignature',
    'cloud refreshes after accepted map changes without resetting the view',
] as $token) {
    if (!str_contains($index, $token)) {
        fwrite(STDERR, "missing dashboard token: {$token}\n");
        exit(1);
    }
}

foreach ([
    'self-contained WebGL point-cloud viewer',
    'RAW is the default',
    'does not request PLY at camera-frame rate',
    'does not reset the operator',
    'does not modify tracking',
] as $token) {
    if (!str_contains($contract, $token)) {
        fwrite(STDERR, "missing contract token: {$token}\n");
        exit(1);
    }
}

foreach (['three.js', 'cdn.jsdelivr.net', 'unpkg.com'] as $forbidden) {
    if (str_contains(strtolower($index), $forbidden)) {
        fwrite(STDERR, "external viewer dependency found: {$forbidden}\n");
        exit(1);
    }
}

echo "OK\n";
