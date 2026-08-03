<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$tool = $root . '/web/remote_station/dual_phone_host/tools/fuse_room_geometry.py';
$packer = $root . '/web/remote_station/dual_phone_host/scripts/pack_session.sh';

function failTest(string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

if (!is_file($tool)) failTest('fusion tool missing');
$source = file_get_contents($tool);
if ($source === false) failTest('cannot read fusion tool');
foreach ([
    'ROBUST_LOCAL_CLOUD_GLOBAL_PLANE_FUSION',
    'point_cloud_accumulated_filtered.ply',
    'room_planes_accumulated.json',
    'room_skeleton_accumulated.ply',
    'minimum-plane-keyframes',
    'depth_shell_filter_pairs',
] as $needle) {
    if (!str_contains($source, $needle)) failTest("missing tool contract token: $needle");
}

$packerSource = file_get_contents($packer);
if ($packerSource === false) failTest('cannot read packer');
foreach ([
    'fuse_room_geometry.py',
    'room_fusion_status.json',
    'point_cloud_accumulated_filtered.ply',
    'room_skeleton_accumulated.ply',
] as $needle) {
    if (!str_contains($packerSource, $needle)) failTest("missing packer token: $needle");
}

$tmp = sys_get_temp_dir() . '/maklertour_lm0524_' . bin2hex(random_bytes(5));
$keyframes = $tmp . '/keyframes';
if (!mkdir($keyframes, 0777, true) && !is_dir($keyframes)) {
    failTest('cannot create temporary session');
}

function syntheticPoints(int $keyframe): array {
    $points = [];
    for ($ix = 0; $ix <= 20; $ix++) {
        for ($iz = 0; $iz <= 20; $iz++) {
            $x = -1.0 + $ix * 0.1;
            $z = 1.2 + $iz * 0.1;
            $jitter = (($ix * 17 + $iz * 11 + $keyframe * 3) % 5 - 2) * 0.0005;
            $points[] = [$x, -1.0 + $jitter, $z, 80, 180, 80];
        }
    }
    for ($ix = 0; $ix <= 20; $ix++) {
        for ($iy = 0; $iy <= 20; $iy++) {
            $x = -1.0 + $ix * 0.1;
            $y = -1.0 + $iy * 0.1;
            $jitter = (($ix * 13 + $iy * 7 + $keyframe * 5) % 5 - 2) * 0.0005;
            $points[] = [$x, $y, 3.0 + $jitter, 180, 80, 80];
        }
    }
    return $points;
}

function writePly(string $path, array $points): void {
    $lines = [
        'ply', 'format ascii 1.0', 'element vertex ' . count($points),
        'property float x', 'property float y', 'property float z',
        'property uchar red', 'property uchar green', 'property uchar blue',
        'end_header',
    ];
    foreach ($points as $point) $lines[] = implode(' ', $point);
    file_put_contents($path, implode("\n", $lines) . "\n");
}

for ($keyframe = 1; $keyframe <= 3; $keyframe++) {
    $points = syntheticPoints($keyframe);
    writePly($keyframes . "/keyframe_{$keyframe}_local.ply", $points);
    writePly($keyframes . "/keyframe_{$keyframe}_world.ply", $points);
}

$command = 'python3 ' . escapeshellarg($tool) . ' ' . escapeshellarg($tmp) . ' 2>&1';
exec($command, $output, $status);
if ($status !== 0) failTest("fusion tool failed:\n" . implode("\n", $output));

$statusPath = $tmp . '/room_fusion_status.json';
if (!is_file($statusPath)) failTest('fusion status missing');
$document = json_decode((string) file_get_contents($statusPath), true, flags: JSON_THROW_ON_ERROR);
if (($document['state'] ?? '') !== 'READY') failTest('fusion did not reach READY');
if (($document['keyframes_processed'] ?? 0) !== 3) failTest('unexpected keyframe count');
if (($document['confirmed_planes'] ?? 0) < 2) failTest('synthetic floor and wall were not confirmed');
if (($document['confirmed_edges'] ?? 0) < 1) failTest('synthetic floor/wall edge was not created');
foreach ([
    'point_cloud_accumulated_filtered_raw.ply',
    'point_cloud_accumulated_filtered.ply',
    'room_planes_accumulated.json',
    'room_edges_accumulated.json',
    'room_skeleton_accumulated.ply',
    'room_fusion_diagnostics.json',
] as $name) {
    if (!is_file($tmp . '/' . $name)) failTest("missing generated file: $name");
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($iterator as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($tmp);

echo "OK\n";
