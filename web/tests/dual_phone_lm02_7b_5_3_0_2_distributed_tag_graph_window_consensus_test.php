<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = $root . '/web/remote_station/dual_phone_host/src/stereo_apriltag_runtime.cpp';
$pack = $root . '/web/remote_station/dual_phone_host/scripts/pack_session.sh';

$cpp = file_get_contents($source);
$sh = file_get_contents($pack);

if ($cpp === false || $sh === false) {
    fwrite(STDERR, "Unable to read implementation files\n");
    exit(1);
}

$needles = [
    'kStereoWindowRequiredHits',
    'stereo_window_hits',
    'stereo_consensus_valid',
    'DISTRIBUTED_STEREO_APRILTAG_GRAPH',
    'TAG_GRAPH_PROPAGATED',
    'UNANCHORED_COMPONENT',
    'stereo_rejection_reasons',
    'strict_pair_fps',
    'apriltag_tag_graph.json',
];

foreach ($needles as $needle) {
    if (!str_contains($cpp, $needle)) {
        fwrite(STDERR, "Missing source contract token: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($sh, 'apriltag_tag_graph.json')) {
    fwrite(STDERR, "pack_session.sh does not package apriltag_tag_graph.json\n");
    exit(1);
}

echo "OK\n";
