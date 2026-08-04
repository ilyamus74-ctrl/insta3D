<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourcePath = $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp';
$packPath = $root . '/web/remote_station/dual_phone_host/scripts/pack_session.sh';
$contractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_3_0_9_CONSTRAINED_TRIPOD_ARCHIVE_PRUNE_CONTRACT.md';

$source = file_get_contents($sourcePath);
$pack = file_get_contents($packPath);
$contract = file_get_contents($contractPath);
if ($source === false || $pack === false || $contract === false) {
    fwrite(STDERR, "required file is missing\n");
    exit(1);
}

$requiredSourceTokens = [
    'kTripodHorizontalSmoothing = 0.35',
    'kTripodMaximumHorizontalStepM = 0.025',
    'tripod_horizontal_step_limit(',
    'tripod_total_position_limit(',
    'apply_tripod_rotation_constraint(',
    'tripod_anchor_position_world_m[1]',
    'decision.world_from_camera(1, 3) =',
    'tripod_anchor_position_world_m[1];',
    'rotation_translation_horizontal_step_limit_m',
    'rotation_translation_total_limit_m',
    'rotation_translation_yaw_from_anchor_deg',
    '_PNP_TRANSLATION_XZ_CONSTRAINED',
];
foreach ($requiredSourceTokens as $token) {
    if (!str_contains($source, $token)) {
        fwrite(STDERR, "missing source token: {$token}\n");
        exit(1);
    }
}

$forbiddenSourceTokens = [
    'decision.world_from_camera(1, 3) =\n                    decision.visual.pnp_world_from_camera(1, 3);',
    'decision.method += "_PNP_TRANSLATION";',
];
foreach ($forbiddenSourceTokens as $token) {
    if (str_contains($source, $token)) {
        fwrite(STDERR, "forbidden source token remains: {$token}\n");
        exit(1);
    }
}

$requiredPackTokens = [
    '--include-intermediate-models',
    'INCLUDE_INTERMEDIATE_MODELS=0',
    'MODEL_DIR="$PACKAGE_ROOT/models"',
    '01_cloud_filtered_multiview.ply',
    '02_cloud_temporal_strict_multiview.ply',
    '03_room_manhattan_skeleton.ply',
    '04_camera_trajectory.ply',
    '05_apriltag_map.ply',
    'INTERMEDIATE_DIR="$MODEL_DIR/intermediate"',
];
foreach ($requiredPackTokens as $token) {
    if (!str_contains($pack, $token)) {
        fwrite(STDERR, "missing pack token: {$token}\n");
        exit(1);
    }
}

$unconditionalKeyframeCopy = <<<'SH'
if [[ -d "$SESSION_DIR/keyframes" ]]; then
  mkdir -p "$PACKAGE_ROOT/keyframes"
  cp -a "$SESSION_DIR/keyframes/." "$PACKAGE_ROOT/keyframes/"
fi
SH;
if (str_contains($pack, $unconditionalKeyframeCopy)) {
    fwrite(STDERR, "keyframe models are still copied unconditionally\n");
    exit(1);
}

foreach ([
    'anchor Y coordinate is fixed',
    'Returning near the anchor yaw',
    'curated `models/` set',
    '--include-intermediate-models',
] as $token) {
    if (!str_contains($contract, $token)) {
        fwrite(STDERR, "missing contract token: {$token}\n");
        exit(1);
    }
}

echo "OK\n";
