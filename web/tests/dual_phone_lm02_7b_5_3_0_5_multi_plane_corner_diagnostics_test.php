<?php

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child)) {
            remove_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

$webRoot = dirname(__DIR__);
$tool = $webRoot . '/remote_station/dual_phone_host/tools/analyze_multi_plane_corners.py';
$pack = $webRoot . '/remote_station/dual_phone_host/scripts/pack_session.sh';

assert_true(is_file($tool), 'multi-plane diagnostic tool is missing');
assert_true(is_executable($tool), 'multi-plane diagnostic tool is not executable');
assert_true(is_file($pack), 'pack_session.sh is missing');

$packContents = file_get_contents($pack);
assert_true($packContents !== false, 'cannot read pack_session.sh');
foreach ([
    'analyze_multi_plane_corners.py',
    'room_plane_candidates_accumulated.json',
    'room_corner_hypotheses_accumulated.json',
    'room_candidate_skeleton_accumulated.ply',
    'room_multi_plane_status.json',
] as $token) {
    assert_true(str_contains($packContents, $token), "pack_session.sh does not include {$token}");
}

$temp = sys_get_temp_dir() . '/maklertour-multiplane-' . bin2hex(random_bytes(6));
assert_true(mkdir($temp, 0700, true), 'cannot create temporary session');

try {
    $planes = [
        'schema_version' => 1,
        'coordinate_system' => 'X_right_Y_up_Z_forward_meters',
        'minimum_keyframes' => 3,
        'confirmed_plane_count' => 1,
        'observation_count' => 6,
        'planes' => [],
        'all_groups' => [
            [
                'id' => 1,
                'type' => 'WALL_CANDIDATE',
                'normal' => [1.0, 0.0, 0.0],
                'd_m' => 0.0,
                'centroid_m' => [0.0, 0.0, 2.0],
                'area_m2' => 4.0,
                'rms_m' => 0.02,
                'observation_count' => 3,
                'keyframe_count' => 3,
                'keyframe_ids' => [1, 2, 3],
                'corners_m' => [
                    [0.0, -1.0, 1.0], [0.0, -1.0, 3.0],
                    [0.0, 1.0, 3.0], [0.0, 1.0, 1.0],
                ],
                'confirmed' => true,
            ],
            [
                'id' => 2,
                'type' => 'WALL_CANDIDATE',
                'normal' => [0.0, 0.0, 1.0],
                'd_m' => -3.0,
                'centroid_m' => [-1.0, 0.0, 3.0],
                'area_m2' => 4.0,
                'rms_m' => 0.02,
                'observation_count' => 2,
                'keyframe_count' => 2,
                'keyframe_ids' => [2, 3],
                'corners_m' => [
                    [-2.0, -1.0, 3.0], [0.0, -1.0, 3.0],
                    [0.0, 1.0, 3.0], [-2.0, 1.0, 3.0],
                ],
                'confirmed' => false,
            ],
            [
                'id' => 3,
                'type' => 'CEILING_CANDIDATE',
                'normal' => [0.0, 1.0, 0.0],
                'd_m' => -1.0,
                'centroid_m' => [-1.0, 1.0, 2.0],
                'area_m2' => 4.0,
                'rms_m' => 0.02,
                'observation_count' => 1,
                'keyframe_count' => 1,
                'keyframe_ids' => [3],
                'corners_m' => [
                    [-2.0, 1.0, 1.0], [0.0, 1.0, 1.0],
                    [0.0, 1.0, 3.0], [-2.0, 1.0, 3.0],
                ],
                'confirmed' => false,
            ],
        ],
    ];
    $fusionDiagnostics = [
        'parameters' => ['minimum_fused_plane_area_m2' => 0.35],
    ];
    file_put_contents(
        $temp . '/room_planes_accumulated.json',
        json_encode($planes, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
    );
    file_put_contents(
        $temp . '/room_fusion_diagnostics.json',
        json_encode($fusionDiagnostics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
    );

    $output = [];
    $exitCode = 0;
    exec(
        'python3 ' . escapeshellarg($tool) . ' ' . escapeshellarg($temp) . ' 2>&1',
        $output,
        $exitCode
    );
    assert_true($exitCode === 0, "diagnostic tool failed: " . implode("\n", $output));

    $statusPath = $temp . '/room_multi_plane_status.json';
    assert_true(is_file($statusPath), 'status JSON was not created');
    $status = json_decode((string) file_get_contents($statusPath), true, 512, JSON_THROW_ON_ERROR);
    assert_true($status['state'] === 'READY', 'status is not READY');
    assert_true($status['accepted_wall_corner_hypotheses'] >= 1, 'wall corner was not detected');
    assert_true($status['accepted_room_corner_triples'] >= 1, 'room corner triple was not detected');
    assert_true(
        in_array('SECOND_WALL_PRESENT_BUT_UNCONFIRMED', $status['diagnosis'], true),
        'missing second-wall diagnosis'
    );
    assert_true(
        in_array('CEILING_PRESENT_SINGLE_VIEW_ONLY', $status['diagnosis'], true),
        'missing ceiling support diagnosis'
    );

    foreach ([
        'room_plane_candidates_accumulated.json',
        'room_corner_hypotheses_accumulated.json',
        'room_candidate_skeleton_accumulated.ply',
    ] as $file) {
        assert_true(is_file($temp . '/' . $file), "output {$file} was not created");
    }
} finally {
    remove_tree($temp);
}

echo "OK\n";
