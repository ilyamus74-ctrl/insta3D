<?php

declare(strict_types=1);

$webRoot = dirname(__DIR__);
$tool = $webRoot . '/remote_station/dual_phone_host/tools/fuse_manhattan_room.py';
$pack = $webRoot . '/remote_station/dual_phone_host/scripts/pack_session.sh';

function fail_test(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function require_token(string $contents, string $token, string $label): void
{
    if (!str_contains($contents, $token)) {
        fail_test("{$label} is missing token: {$token}");
    }
}

if (!is_file($tool)) {
    fail_test('Manhattan fusion tool is missing');
}
if (!is_executable($tool)) {
    fail_test('Manhattan fusion tool is not executable');
}
if (!is_file($pack)) {
    fail_test('pack_session.sh is missing');
}

$toolContents = (string) file_get_contents($tool);
$packContents = (string) file_get_contents($pack);
foreach ([
    'CONSERVATIVE_MANHATTAN_FRAGMENT_FUSION',
    'SECOND_WALL_PROMOTED_FROM_SHARED_KEYFRAME_CORNER',
    'minimum_shared_corner_keyframes',
    'HORIZONTAL_REQUIRES_MULTIVIEW_SUPPORT',
    'room_planes_manhattan_accumulated.json',
    'room_edges_manhattan_accumulated.json',
    'room_skeleton_manhattan_accumulated.ply',
] as $token) {
    require_token($toolContents, $token, 'fusion tool');
}
foreach ([
    'fuse_manhattan_room.py',
    '[MANHATTAN-FUSION]',
    'room_planes_manhattan_accumulated.json',
    'room_edges_manhattan_accumulated.json',
    'room_skeleton_manhattan_accumulated.ply',
    'room_manhattan_fusion_status.json',
] as $token) {
    require_token($packContents, $token, 'pack_session.sh');
}

$temporary = sys_get_temp_dir() . '/maklertour_manhattan_' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0700, true) && !is_dir($temporary)) {
    fail_test('Could not create temporary directory');
}
register_shutdown_function(static function () use ($temporary): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($temporary);
});

$wallZ1 = [
    'id' => 1,
    'kind' => 'WALL',
    'type' => 'WALL_CANDIDATE',
    'support_tier' => 'CONFIRMED',
    'normal' => [0.0, 0.0, 1.0],
    'd_m' => -2.0,
    'centroid_m' => [0.0, 0.0, 2.0],
    'area_m2' => 4.0,
    'rms_m' => 0.02,
    'observation_count' => 2,
    'keyframe_ids' => [1, 2],
    'keyframe_count' => 2,
    'corners_m' => [[-2.0, -1.0, 2.0], [2.0, -1.0, 2.0], [2.0, 1.0, 2.0], [-2.0, 1.0, 2.0]],
    'gravity_alignment_error_deg' => 0.0,
];
$wallZ2 = $wallZ1;
$wallZ2['id'] = 2;
$wallZ2['d_m'] = -2.08;
$wallZ2['keyframe_ids'] = [3, 4];
$wallZ2['corners_m'] = [[-1.5, -1.0, 2.08], [1.5, -1.0, 2.08], [1.5, 1.0, 2.08], [-1.5, 1.0, 2.08]];

$wallX1 = [
    'id' => 3,
    'kind' => 'WALL',
    'type' => 'WALL_CANDIDATE',
    'support_tier' => 'MULTIVIEW_CANDIDATE',
    'normal' => [1.0, 0.0, 0.0],
    'd_m' => 0.0,
    'centroid_m' => [0.0, 0.0, 2.5],
    'area_m2' => 4.0,
    'rms_m' => 0.02,
    'observation_count' => 2,
    'keyframe_ids' => [2, 3],
    'keyframe_count' => 2,
    'corners_m' => [[0.0, -1.0, 1.0], [0.0, -1.0, 4.0], [0.0, 1.0, 4.0], [0.0, 1.0, 1.0]],
    'gravity_alignment_error_deg' => 0.0,
];
$wallX2 = $wallX1;
$wallX2['id'] = 4;
$wallX2['d_m'] = 0.07;
$wallX2['keyframe_ids'] = [4, 5];
$wallX2['corners_m'] = [[-0.07, -1.0, 1.2], [-0.07, -1.0, 3.8], [-0.07, 1.0, 3.8], [-0.07, 1.0, 1.2]];

$ceiling = [
    'id' => 5,
    'kind' => 'CEILING',
    'type' => 'CEILING_CANDIDATE',
    'support_tier' => 'SINGLE_VIEW_CANDIDATE',
    'normal' => [0.0, 1.0, 0.0],
    'd_m' => -1.5,
    'centroid_m' => [0.0, 1.5, 2.5],
    'area_m2' => 8.0,
    'rms_m' => 0.03,
    'observation_count' => 1,
    'keyframe_ids' => [2],
    'keyframe_count' => 1,
    'corners_m' => [[-2.0, 1.5, 1.0], [2.0, 1.5, 1.0], [2.0, 1.5, 4.0], [-2.0, 1.5, 4.0]],
    'gravity_alignment_error_deg' => 0.0,
];

$candidateDocument = [
    'schema_version' => 1,
    'minimum_plane_keyframes' => 3,
    'minimum_fused_plane_area_m2' => 0.35,
    'candidates' => [$wallZ1, $wallZ2, $wallX1, $wallX2, $ceiling],
];
$hypothesisDocument = [
    'schema_version' => 1,
    'pair_hypotheses' => [[
        'type' => 'WALL_WALL',
        'plane_a' => 1,
        'plane_b' => 3,
        'orthogonality_error_deg' => 0.0,
        'intersection_length_m' => 2.0,
        'shared_keyframe_ids' => [2],
        'score' => 0.9,
        'accepted_diagnostic_hypothesis' => true,
    ]],
];
file_put_contents(
    $temporary . '/room_plane_candidates_accumulated.json',
    json_encode($candidateDocument, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
);
file_put_contents(
    $temporary . '/room_corner_hypotheses_accumulated.json',
    json_encode($hypothesisDocument, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
);

$command = 'python3 ' . escapeshellarg($tool) . ' ' . escapeshellarg($temporary) . ' 2>&1';
exec($command, $output, $exitCode);
if ($exitCode !== 0) {
    fail_test("Tool execution failed:\n" . implode("\n", $output));
}
$statusPath = $temporary . '/room_manhattan_fusion_status.json';
if (!is_file($statusPath)) {
    fail_test('Manhattan status output was not created');
}
$status = json_decode((string) file_get_contents($statusPath), true, 512, JSON_THROW_ON_ERROR);
if (($status['promoted_wall_count'] ?? 0) !== 2) {
    fail_test('Fixture did not promote two walls');
}
if (($status['promoted_edge_count'] ?? 0) !== 1) {
    fail_test('Fixture did not create one deduplicated wall corner');
}
if (($status['promoted_horizontal_count'] ?? -1) !== 0) {
    fail_test('Single-view ceiling was incorrectly promoted');
}
$corner = $status['promoted_wall_corner'] ?? null;
if (!is_array($corner) || ($corner['shared_keyframe_count'] ?? 0) < 2) {
    fail_test('Merged wall corner lacks shared-keyframe support');
}
foreach ([
    'room_planes_manhattan_accumulated.json',
    'room_edges_manhattan_accumulated.json',
    'room_skeleton_manhattan_accumulated.ply',
] as $outputFile) {
    if (!is_file($temporary . '/' . $outputFile)) {
        fail_test("Missing output: {$outputFile}");
    }
}

echo "OK\n";
