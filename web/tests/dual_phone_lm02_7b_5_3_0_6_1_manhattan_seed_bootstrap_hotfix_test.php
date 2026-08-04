<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = $root . '/web/remote_station/dual_phone_host/tools/fuse_manhattan_room.py';

if (!is_file($source)) {
    fwrite(STDERR, "Missing Manhattan fusion source\n");
    exit(1);
}

$contents = file_get_contents($source);
if ($contents === false) {
    fwrite(STDERR, "Unable to read Manhattan fusion source\n");
    exit(1);
}

foreach ([
    'CONFIRMED_WALL_FRAGMENT_BOOTSTRAP',
    'DIRECT_MULTIVIEW',
    'combined_keyframes >= 3',
    'first_single != second_single',
    'mode_priority',
] as $token) {
    if (!str_contains($contents, $token)) {
        fwrite(STDERR, "Missing contract token: {$token}\n");
        exit(1);
    }
}

$python = <<<'PY'
import importlib.util
import json
import sys

source = sys.argv[1]
spec = importlib.util.spec_from_file_location("fuse_manhattan_room_hotfix_test", source)
if spec is None or spec.loader is None:
    raise RuntimeError("unable to load Manhattan module")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

candidates = {
    1: {
        "id": 1,
        "support_tier": "CONFIRMED",
        "keyframe_count": 4,
    },
    2: {
        "id": 2,
        "support_tier": "MULTIVIEW_CANDIDATE",
        "keyframe_count": 2,
    },
    3: {
        "id": 3,
        "support_tier": "SINGLE_VIEW_CANDIDATE",
        "keyframe_count": 1,
    },
    4: {
        "id": 4,
        "support_tier": "SINGLE_VIEW_CANDIDATE",
        "keyframe_count": 1,
    },
}

direct = {
    "type": "WALL_WALL",
    "plane_a": 1,
    "plane_b": 2,
    "accepted_diagnostic_hypothesis": True,
    "orthogonality_error_deg": 2.0,
    "shared_keyframe_ids": [8],
    "combined_keyframe_count": 5,
    "score": 0.70,
}
bootstrap = {
    "type": "WALL_WALL",
    "plane_a": 1,
    "plane_b": 3,
    "accepted_diagnostic_hypothesis": True,
    "orthogonality_error_deg": 1.0,
    "shared_keyframe_ids": [11],
    "combined_keyframe_count": 5,
    "score": 0.95,
}
unsafe = {
    "type": "WALL_WALL",
    "plane_a": 3,
    "plane_b": 4,
    "accepted_diagnostic_hypothesis": True,
    "orthogonality_error_deg": 1.0,
    "shared_keyframe_ids": [11],
    "combined_keyframe_count": 3,
    "score": 0.99,
}

selected_direct = module.choose_seed_pair(candidates, [bootstrap, direct], 8.0)
assert selected_direct is not None
assert selected_direct["plane_b"] == 2
assert selected_direct["seed_selection_mode"] == "DIRECT_MULTIVIEW"

selected_bootstrap = module.choose_seed_pair(candidates, [bootstrap], 8.0)
assert selected_bootstrap is not None
assert selected_bootstrap["plane_b"] == 3
assert selected_bootstrap["seed_selection_mode"] == "CONFIRMED_WALL_FRAGMENT_BOOTSTRAP"

assert module.choose_seed_pair(candidates, [unsafe], 8.0) is None
print(json.dumps({"state": "OK"}))
PY;

$tmp = tempnam(sys_get_temp_dir(), 'manhattan_seed_hotfix_');
if ($tmp === false) {
    fwrite(STDERR, "Unable to create temporary test file\n");
    exit(1);
}
file_put_contents($tmp, $python);

$command = sprintf(
    'python3 %s %s 2>&1',
    escapeshellarg($tmp),
    escapeshellarg($source)
);
exec($command, $output, $status);
@unlink($tmp);

if ($status !== 0) {
    fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
    exit(1);
}

$decoded = null;
foreach (array_reverse($output) as $line) {
    $candidate = json_decode(trim($line), true);
    if (is_array($candidate) && ($candidate['state'] ?? null) === 'OK') {
        $decoded = $candidate;
        break;
    }
}
if (!is_array($decoded)) {
    fwrite(STDERR, "Unexpected Python test result\n");
    exit(1);
}

echo "OK\n";
