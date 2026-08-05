<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp');
$contract = file_get_contents($root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_3_8_LOCAL_SUBMAP_CONTINUATION_CONTRACT.md');
if ($source === false || $contract === false) {
    fwrite(STDERR, "missing source or contract\n");
    exit(1);
}

foreach ([
    'kLocalSubmapPromotionMinimumChain',
    'kLocalSubmapPromotionMinimumTravelM',
    'AUTO_STEREO_SE3_PROVISIONAL_BRIDGE',
    'AUTO_LOCAL_SUBMAP_STEREO_SE3_PROMOTED',
    'provisional_stereo_bridge',
    'local_submap_promoted',
    'decision.method += "_CUMULATIVE_KEYFRAME"',
] as $token) {
    if (!str_contains($source, $token)) {
        fwrite(STDERR, "missing source token: {$token}\n");
        exit(1);
    }
}
foreach ([
    'continuous cached provisional chain',
    'does not invent',
    'first unmeasured interval',
] as $token) {
    if (!str_contains($contract, $token)) {
        fwrite(STDERR, "missing contract token: {$token}\n");
        exit(1);
    }
}

echo "OK\n";
