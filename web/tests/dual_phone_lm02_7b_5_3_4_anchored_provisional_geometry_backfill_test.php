<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourcePath = $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp';
$contractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_3_4_ANCHORED_PROVISIONAL_GEOMETRY_BACKFILL_CONTRACT.md';

$source = file_get_contents($sourcePath);
$contract = file_get_contents($contractPath);
if ($source === false || $contract === false) {
    fwrite(STDERR, "required file is missing
");
    exit(1);
}

foreach ([
    'kMaximumProvisionalGeometryFrames = 8',
    'struct ProvisionalGeometryFrame',
    'struct ProvisionalBackfillResult',
    'provisional_alignment_available',
    'provisional_alignment_world_from_provisional',
    'provisional_geometry_cacheable(',
    'cache_provisional_geometry(',
    'backfill_provisional_geometry(',
    'decision.provisional_chain_length = 0',
    'PROVISIONAL_SE3_WAITING_FOR_TRUSTED_ANCHOR',
    'best.world_from_camera *',
    'rigid_inverse(*provisional_current_pose)',
    'AUTO_WALK_STEREO_SE3_PROVISIONAL_PROMOTED',
    'provisional_geometry_pending_frames',
    'provisional_geometry_cached_frames',
    'provisional_geometry_backfilled_frames',
    'provisional_geometry_backfill_events',
    'provisional_geometry_discarded_frames',
    'provisional_geometry_raw_voxels_added',
    'provisional_geometry_strict_voxels_added',
] as $token) {
    if (!str_contains($source, $token)) {
        fwrite(STDERR, "missing source token: {$token}
");
        exit(1);
    }
}

foreach ([
    "decision.translation_trusted =
                reference.pose_trusted || decision.provisional_promoted;",
    "decision.provisional_promoted =
                !reference.pose_trusted",
] as $token) {
    if (str_contains($source, $token)) {
        fwrite(STDERR, "forbidden unanchored promotion remains: {$token}
");
        exit(1);
    }
}

$normalizedContract = preg_replace('/\s+/', ' ', $contract) ?? $contract;
foreach ([
    'stays provisional regardless of chain length',
    'does not create a globally trusted pose',
    'same global voxel map',
    'relocalization keyframe',
    'do not become trusted trajectory samples',
    'No IMU position integration',
    'best-chunk selection',
] as $token) {
    if (!str_contains($normalizedContract, $token)) {
        fwrite(STDERR, "missing contract token: {$token}
");
        exit(1);
    }
}

echo "OK
";
