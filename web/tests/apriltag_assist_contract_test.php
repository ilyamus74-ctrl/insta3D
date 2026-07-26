<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$helper = $root
    . '/remote_station/scripts/analyze_apriltag_assist.sh';
$sparse = $root
    . '/remote_station/scripts/process_colmap_sparse.sh';
$dense = $root
    . '/remote_station/scripts/process_colmap_dense.sh';

foreach ([$helper, $sparse, $dense] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException(
            'required file not found: ' . $path
        );
    }
}

function apriltag_contract_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$helperSource = (string)file_get_contents($helper);
foreach ([
    'MARKERS_NOT_FOUND',
    'MARKERS_INSUFFICIENT',
    'MARKERS_DISCONNECTED',
    'MARKERS_READY',
    'min_registered_observations_per_tag',
    'bridge_tags',
    'sim3_applied',
] as $required) {
    apriltag_contract_ok(
        str_contains($helperSource, $required),
        'helper contract missing: ' . $required
    );
}

$sparseSource = (string)file_get_contents($sparse);
apriltag_contract_ok(
    str_contains($sparseSource, '"marker_assist":'),
    'sparse result does not expose marker_assist'
);

$denseSource = (string)file_get_contents($dense);
apriltag_contract_ok(
    str_contains($denseSource, '"marker_assist":'),
    'dense result does not propagate marker_assist'
);

echo "OK\n";
