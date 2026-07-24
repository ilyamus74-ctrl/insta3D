<?php
declare(strict_types=1);

function f01cb_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function f01cb_run(array $arguments, array $environment = []): array
{
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    foreach ($environment as $name => $value) {
        $command = $name . '=' . escapeshellarg($value) . ' ' . $command;
    }
    $lines = [];
    $code = 0;
    exec($command . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
}

$root = dirname(__DIR__);
$process = realpath(
    $root . '/remote_station/scripts/process_maklertour_synced_dense.sh'
);
f01cb_ok(is_string($process) && $process !== '', 'process script exists');

$temp = sys_get_temp_dir() . '/stereo_global_fusion_wiring_' .
    bin2hex(random_bytes(6));
$package = $temp . '/package';
$station = $temp . '/station';
$output = $temp . '/output';
$input = $temp . '/input.tgz';

foreach ([
    $package . '/capture/pairs',
    $package . '/calibration',
    $station . '/venv/bin',
    $station . '/scripts',
    $station . '/status',
    $station . '/logs',
] as $directory) {
    f01cb_ok(
        mkdir($directory, 0777, true) || is_dir($directory),
        'mkdir ' . $directory
    );
}

file_put_contents(
    $package . '/bundle_manifest.json',
    json_encode(['capture_type' => 'synced_depth_frames'])
);
file_put_contents(
    $package . '/capture/synced_depth_manifest.json',
    json_encode(['pairs' => []])
);
file_put_contents(
    $package . '/calibration/stereo_extrinsics.json',
    json_encode(['fixture' => true])
);

foreach ([
    'dense_depth_from_synced_capture.py',
    'stereo_visual_odometry.py',
    'stereo_global_fusion.py',
] as $script) {
    file_put_contents($station . '/scripts/' . $script, "# fixture\n");
}

$fakePython = <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
command_path="${1:-}"
station_base="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
call_order="$station_base/call_order.log"

if [[ "$command_path" == *"/dense_depth_from_synced_capture.py" ]]; then
  output_dir="$4"
  mkdir -p "$output_dir/pair_clouds"
  printf 'dense\n' >> "$call_order"
  cat > "$output_dir/pair_cloud_manifest.json" <<'JSON'
{"schema_version":1,"coordinate_system":"rectified_cam0_pair_local","units":"mm","global_fusion_complete":false,"pair_cloud_count":3,"pair_clouds":[{"pair_index":0},{"pair_index":1},{"pair_index":2}]}
JSON
  cat > "$output_dir/dense_depth_debug.json" <<'JSON'
{"P1":[[800,0,320,0],[0,800,240,0],[0,0,1,0]],"global_fusion_complete":false}
JSON
  printf 'pair_index\n' > "$output_dir/dense_depth_summary.csv"
  printf 'preview\n' > "$output_dir/contact_dense_depth.jpg"
  printf 'preview\n' > "$output_dir/contact_pair_clouds.jpg"
  exit 0
fi

if [[ "$command_path" == *"/stereo_visual_odometry.py" ]]; then
  output_dir="$2"
  printf 'odometry\n' >> "$call_order"
  cat > "$output_dir/stereo_trajectory.json" <<'JSON'
{"schema_version":1,"coordinate_system":"stereo_f01_world","units":"mm","pose_convention":"transform_cam0_to_world","pair_count":3,"accepted_pose_count":2,"rejected_pose_count":1,"trajectory_status":"partial","global_fusion_complete":false,"poses":[]}
JSON
  cat > "$output_dir/stereo_odometry_debug.json" <<'JSON'
{"schema_version":1,"accepted_pose_count":2,"rejected_pose_count":1,"global_fusion_complete":false}
JSON
  exit 0
fi

if [[ "$command_path" == *"/stereo_global_fusion.py" ]]; then
  output_dir="$2"
  fusion_dir="$output_dir/global_fusion"
  mkdir -p "$fusion_dir"
  printf 'fusion\n' >> "$call_order"
  python3 - "$fusion_dir/fused_global_no_icp.ply" <<'PY'
import sys
path=sys.argv[1]
header=(
    "ply\n"
    "format binary_little_endian 1.0\n"
    "element vertex 4\n"
    "property float x\n"
    "property float y\n"
    "property float z\n"
    "property uchar red\n"
    "property uchar green\n"
    "property uchar blue\n"
    "end_header\n"
).encode("ascii")
with open(path,"wb") as f:
    f.write(header)
    f.write(b"\0" * 60)
PY
  cat > "$fusion_dir/global_fusion_manifest.json" <<'JSON'
{"schema_version":1,"fusion_stage":"initial_no_icp","coordinate_system":"stereo_f01_world","units":"mm","trajectory_status":"partial","included_cloud_count":2,"excluded_cloud_count":1,"fused_points_before_voxel":1200,"fused_points_after_voxel":800,"voxel_size_mm":20.0,"output_ply":"global_fusion/fused_global_no_icp.ply","global_alignment_available":true,"icp_applied":false,"loop_closure_applied":false,"global_fusion_complete":false}
JSON
  exit 0
fi

if [[ "$command_path" == "-" ]]; then
  shift
  exec python3 - "$@"
fi

echo "unexpected fake python invocation: $*" >&2
exit 90
BASH;

$fakePythonPath = $station . '/venv/bin/python';
file_put_contents($fakePythonPath, $fakePython);
chmod($fakePythonPath, 0755);

[$tarCode, $tarOutput] = f01cb_run([
    'tar',
    '-czf',
    $input,
    '-C',
    $package,
    '.',
]);
f01cb_ok($tarCode === 0, 'fixture archive: ' . $tarOutput);

[$code, $commandOutput] = f01cb_run(
    [
        'bash',
        $process,
        '902',
        $input,
        $output,
        '20',
        '128',
        '7',
    ],
    ['STATION_BASE' => $station]
);
f01cb_ok($code === 0, 'job succeeds: ' . $commandOutput);

$order = file($station . '/call_order.log', FILE_IGNORE_NEW_LINES);
f01cb_ok(
    $order === ['dense', 'odometry', 'fusion'],
    'processor order: ' . json_encode($order)
);

$result = json_decode(
    (string) file_get_contents($output . '/result.json'),
    true
);
f01cb_ok(is_array($result), 'result JSON');
f01cb_ok(($result['status'] ?? null) === 'DONE', 'result status');
f01cb_ok(
    ($result['fusion_stage'] ?? null) === 'initial_no_icp',
    'fusion stage'
);
f01cb_ok(
    ($result['included_cloud_count'] ?? null) === 2,
    'included clouds'
);
f01cb_ok(
    ($result['excluded_cloud_count'] ?? null) === 1,
    'excluded clouds'
);
f01cb_ok(
    ($result['fused_points_before_voxel'] ?? null) === 1200,
    'points before voxel'
);
f01cb_ok(
    ($result['fused_points_after_voxel'] ?? null) === 800,
    'points after voxel'
);
f01cb_ok(
    (float) ($result['voxel_size_mm'] ?? 0) === 20.0,
    'voxel size'
);
f01cb_ok(
    ($result['icp_applied'] ?? null) === false,
    'ICP remains false'
);
f01cb_ok(
    ($result['global_fusion_complete'] ?? null) === false,
    'global completion remains false'
);
f01cb_ok(
    str_ends_with(
        (string) ($result['global_fusion_manifest'] ?? ''),
        '/dense/global_fusion/global_fusion_manifest.json'
    ),
    'fusion manifest path'
);
f01cb_ok(
    str_ends_with(
        (string) ($result['fused_global_no_icp'] ?? ''),
        '/dense/global_fusion/fused_global_no_icp.ply'
    ),
    'global PLY path'
);
f01cb_ok(
    is_file((string) $result['fused_global_no_icp']),
    'global PLY exists'
);

$status = json_decode(
    (string) file_get_contents($output . '/status.json'),
    true
);
f01cb_ok(is_array($status), 'status JSON');
f01cb_ok(($status['status'] ?? null) === 'DONE', 'final status DONE');
f01cb_ok(
    str_contains((string) ($status['message'] ?? ''), 'fusion=initial_no_icp'),
    'status fusion summary'
);
f01cb_ok(
    str_contains((string) ($status['message'] ?? ''), 'points=800'),
    'status point summary'
);

$source = (string) file_get_contents($process);
$densePosition = strpos($source, 'dense_depth_from_synced_capture.py');
$odometryPosition = strpos($source, 'stereo_visual_odometry.py');
$fusionPosition = strpos($source, 'stereo_global_fusion.py');
f01cb_ok($densePosition !== false, 'dense wired');
f01cb_ok($odometryPosition !== false, 'odometry wired');
f01cb_ok($fusionPosition !== false, 'fusion wired');
f01cb_ok(
    $densePosition < $odometryPosition &&
    $odometryPosition < $fusionPosition,
    'processing order in source'
);
f01cb_ok(
    str_contains($source, '"icp_applied":false'),
    'result ICP false'
);
f01cb_ok(
    str_contains($source, '"global_fusion_complete":false'),
    'result completion false'
);
f01cb_ok(
    !str_contains($source, '"global_fusion_complete":true'),
    'no premature completion'
);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $item) {
    if ($item->isDir() && !$item->isLink()) {
        rmdir($item->getPathname());
    } else {
        unlink($item->getPathname());
    }
}
rmdir($temp);

echo "OK\n";
