<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp';
$cpp = file_get_contents($source);
if ($cpp === false) {
    fwrite(STDERR, "Unable to read accumulated map runtime\n");
    exit(1);
}

$needles = [
    'apply_cumulative_keyframe_gate',
    'translation_from_last_keyframe_m',
    'yaw_from_last_keyframe_deg',
    'registration_keyframes.back().world_from_camera',
    'forced_relocalization',
    'std::abs(decision.yaw_from_last_keyframe_deg)',
    'apply_cumulative_keyframe_gate(decision);',
    'decision.yaw_from_last_keyframe_deg;',
];
foreach ($needles as $needle) {
    if (!str_contains($cpp, $needle)) {
        fwrite(STDERR, "Missing cumulative keyframe token: {$needle}\n");
        exit(1);
    }
}

$gate = strpos($cpp, 'apply_cumulative_keyframe_gate(decision);');
$validation = strpos($cpp, 'append_pose_validation({');
if ($gate === false || $validation === false || $gate > $validation) {
    fwrite(STDERR, "Cumulative keyframe gate must run before pose validation\n");
    exit(1);
}

echo "OK\n";
