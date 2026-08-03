<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runtime = $root . '/web/remote_station/dual_phone_host/src/apriltag_anchor_runtime.cpp';
$header = $root . '/web/remote_station/dual_phone_host/src/apriltag_anchor_runtime.hpp';
$accumulated = $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp';
$cmake = $root . '/web/remote_station/dual_phone_host/CMakeLists.txt';
$packer = $root . '/web/remote_station/dual_phone_host/scripts/pack_session.sh';
$contract = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_3_0_ONLINE_APRILTAG_ANCHOR_MAP_CONTRACT.md';

foreach ([$runtime, $header, $accumulated, $cmake, $packer, $contract] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "missing: {$path}\n");
        exit(1);
    }
}

$runtimeSource = file_get_contents($runtime);
$accumulatedSource = file_get_contents($accumulated);
$cmakeSource = file_get_contents($cmake);
$packerSource = file_get_contents($packer);
if ($runtimeSource === false || $accumulatedSource === false ||
    $cmakeSource === false || $packerSource === false) {
    fwrite(STDERR, "cannot read source files\n");
    exit(1);
}

$runtimeRequired = [
    'DICT_APRILTAG_36h11',
    'kTagSizeM = 0.160',
    'kMinimumKitId = 1',
    'kMaximumKitId = 30',
    'CANDIDATE',
    'MAPPED',
    'ANCHOR',
    'APRILTAG_RELOCALIZATION',
    'apriltag_observations.jsonl',
    'apriltag_constraints.jsonl',
    'apriltag_map.json',
    'apriltag_map.ply',
    'apriltag_latest.jpg',
];
foreach ($runtimeRequired as $needle) {
    if (!str_contains($runtimeSource, $needle)) {
        fwrite(STDERR, "missing AprilTag runtime marker: {$needle}\n");
        exit(1);
    }
}

$integrationRequired = [
    'AprilTagAnchorRuntime',
    'APRILTAG_RELOCALIZED',
    'APRILTAG_ANCHORED',
    'apriltag_anchor',
];
foreach ($integrationRequired as $needle) {
    if (!str_contains($accumulatedSource, $needle)) {
        fwrite(STDERR, "missing accumulated integration marker: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($cmakeSource, 'aruco') ||
    !str_contains($cmakeSource, 'src/apriltag_anchor_runtime.cpp')) {
    fwrite(STDERR, "OpenCV aruco build integration missing\n");
    exit(1);
}

foreach ([
    'apriltag_observations.jsonl',
    'apriltag_constraints.jsonl',
    'apriltag_map.json',
    'apriltag_map.ply',
    'apriltag_status.json',
    'apriltag_latest.jpg',
] as $name) {
    if (!str_contains($packerSource, $name)) {
        fwrite(STDERR, "packer missing: {$name}\n");
        exit(1);
    }
}

echo "OK\n";
