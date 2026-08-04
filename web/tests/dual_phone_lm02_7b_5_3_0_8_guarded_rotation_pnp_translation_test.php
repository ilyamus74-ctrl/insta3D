<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourcePath = $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp';
$contractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_3_0_8_GUARDED_ROTATION_PNP_TRANSLATION_CONTRACT.md';

$source = file_get_contents($sourcePath);
$contract = file_get_contents($contractPath);
if ($source === false || $contract === false) {
    fwrite(STDERR, "required file is missing\n");
    exit(1);
}

$requiredSourceTokens = [
    'rotation_pnp_translation_safe(',
    'decision.visual.pnp_world_from_camera(0, 3)',
    'decision.rotation_translation_limit_m = tripod_translation_limit(',
    'decision.rotation_translation_applied = true',
    'decision.method += "_PNP_TRANSLATION"',
    '"rotation_translation_applied"',
    '"rotation_translation_candidate_m"',
    '"rotation_translation_limit_m"',
    '"rotation_translation_vector_world_m"',
    '"rotation_translation_rejection_reason"',
    'PNP_TRANSLATION_EXCEEDS_TRIPOD_BOUND',
];
foreach ($requiredSourceTokens as $token) {
    if (!str_contains($source, $token)) {
        fwrite(STDERR, "missing source token: {$token}\n");
        exit(1);
    }
}

$forbiddenRotationBlock = <<<'CPP'
decision.world_from_camera = reference.world_from_camera *
                yaw_rotation_deg(decision.fused_yaw_step_deg);
            decision.translation_m = 0.0;
CPP;
if (str_contains($source, $forbiddenRotationBlock)) {
    fwrite(STDERR, "rotation branch still forces zero translation\n");
    exit(1);
}

foreach ([
    'world model must keep walls stationary',
    'synthetic 0.12 m arc is never injected',
    'supplies only the translation column',
] as $token) {
    if (!str_contains($contract, $token)) {
        fwrite(STDERR, "missing contract token: {$token}\n");
        exit(1);
    }
}

echo "OK\n";
