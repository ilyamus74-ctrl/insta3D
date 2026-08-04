<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourcePath = $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp';
$exportPath = $root . '/web/remote_station/dual_phone_host/scripts/export_offline_colmap_trajectory.py';
$contractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_3_2_ONLINE_STEREO_TRANSLATION_GUARD_CONTRACT.md';

$source = file_get_contents($sourcePath);
$export = file_get_contents($exportPath);
$contract = file_get_contents($contractPath);
if ($source === false || $export === false || $contract === false) {
    fwrite(STDERR, "required file is missing\n");
    exit(1);
}

$requiredSourceTokens = [
    'kMinimumStereoTranslationPairs = 14',
    'estimate_known_yaw_stereo_translation(',
    'STEREO_3D3D_KNOWN_YAW',
    'AUTO_WALK_TRANSLATION_UNCERTAIN',
    'TRACKING_TRANSLATION_UNCERTAIN',
    'decision.geometry_suppressed = true',
    'if (!decision.geometry_suppressed) {',
    'decision.valid && decision.translation_trusted',
    '!pnp_walk_translation_safe(decision.visual)',
    'translation_uncertain_frames',
    'geometry_suppressed_frames',
    'stereo_translation_attempts',
    'stereo_translation_successes',
];
foreach ($requiredSourceTokens as $token) {
    if (!str_contains($source, $token)) {
        fwrite(STDERR, "missing source token: {$token}\n");
        exit(1);
    }
}

$forbiddenSourceTokens = [
    "decision.valid && !decision.rotation_only &&\n                        decision.visual.pnp_valid",
    "if (!best.valid) {\n            for (auto iterator = tracking_buffer.rbegin();",
];
foreach ($forbiddenSourceTokens as $token) {
    if (str_contains($source, $token)) {
        fwrite(STDERR, "forbidden source token remains: {$token}\n");
        exit(1);
    }
}

foreach ([
    'value.get("trajectory", value.get("samples", value))',
    'live_path_length_m',
] as $token) {
    if (!str_contains($export, $token)) {
        fwrite(STDERR, "missing exporter token: {$token}\n");
        exit(1);
    }
}

foreach ([
    'known-yaw stereo 3D-to-3D translation estimator',
    'must not be merged into the accumulated cloud',
    'must not replace the last trusted tracking reference',
    'No inertial position integration',
] as $token) {
    if (!str_contains($contract, $token)) {
        fwrite(STDERR, "missing contract token: {$token}\n");
        exit(1);
    }
}

echo "OK\n";
