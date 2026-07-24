<?php
declare(strict_types=1);

function f01bb_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function f01bb_run(array $arguments, array $environment = []): array
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
f01bb_ok(is_string($process) && $process !== '', 'process script exists');

$temp = sys_get_temp_dir() . '/stereo_odometry_job_wiring_' .
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
    f01bb_ok(
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
file_put_contents(
    $station . '/scripts/dense_depth_from_synced_capture.py',
    "# fixture\n"
);
file_put_contents(
    $station . '/scripts/stereo_visual_odometry.py',
    "# fixture\n"
);

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
{"schema_version":1,"coordinate_system":"stereo_f01_world","units":"mm","pair_count":3,"accepted_pose_count":2,"rejected_pose_count":1,"trajectory_status":"partial","global_fusion_complete":false,"poses":[]}
JSON
  cat > "$output_dir/stereo_odometry_debug.json" <<'JSON'
{"schema_version":1,"accepted_pose_count":2,"rejected_pose_count":1,"global_fusion_complete":false}
JSON
  printf '{"trajectory_status":"partial"}\n'
  exit 0
fi

if [[ "$command_path" == "-" ]]; then
  exec python3 "$@"
fi

echo "unexpected fake python invocation: $*" >&2
exit 90
BASH;

$fakePythonPath = $station . '/venv/bin/python';
file_put_contents($fakePythonPath, $fakePython);
chmod($fakePythonPath, 0755);

[$tarCode, $tarOutput] = f01bb_run([
    'tar',
    '-czf',
    $input,
    '-C',
    $package,
    '.',
]);
f01bb_ok($tarCode === 0, 'fixture archive: ' . $tarOutput);

[$code, $commandOutput] = f01bb_run(
    [
        'bash',
        $process,
        '901',
        $input,
        $output,
        '20',
        '128',
        '7',
    ],
    ['STATION_BASE' => $station]
);
f01bb_ok($code === 0, 'job succeeds: ' . $commandOutput);

$order = file($station . '/call_order.log', FILE_IGNORE_NEW_LINES);
f01bb_ok(
    $order === ['dense', 'odometry'],
    'dense must run before odometry: ' . json_encode($order)
);

$result = json_decode(
    (string) file_get_contents($output . '/result.json'),
    true
);
f01bb_ok(is_array($result), 'result JSON');
f01bb_ok(($result['status'] ?? null) === 'DONE', 'result status');
f01bb_ok(
    ($result['job_type'] ?? null) === 'MAKLERTOUR_SYNCED_DENSE',
    'job type'
);
f01bb_ok(($result['pair_cloud_count'] ?? null) === 3, 'pair cloud count');
f01bb_ok(
    ($result['trajectory_pair_count'] ?? null) === 3,
    'trajectory pair count'
);
f01bb_ok(
    ($result['trajectory_status'] ?? null) === 'partial',
    'partial trajectory is published'
);
f01bb_ok(
    ($result['accepted_pose_count'] ?? null) === 2,
    'accepted pose count'
);
f01bb_ok(
    ($result['rejected_pose_count'] ?? null) === 1,
    'rejected pose count'
);
f01bb_ok(
    ($result['global_fusion_complete'] ?? null) === false,
    'global fusion remains false'
);
f01bb_ok(
    str_ends_with(
        (string) ($result['stereo_trajectory'] ?? ''),
        '/dense/stereo_trajectory.json'
    ),
    'trajectory artifact path'
);
f01bb_ok(
    str_ends_with(
        (string) ($result['stereo_odometry_debug'] ?? ''),
        '/dense/stereo_odometry_debug.json'
    ),
    'odometry debug artifact path'
);

$status = json_decode(
    (string) file_get_contents($output . '/status.json'),
    true
);
f01bb_ok(is_array($status), 'status JSON');
f01bb_ok(($status['status'] ?? null) === 'DONE', 'final status DONE');
f01bb_ok(
    str_contains((string) ($status['message'] ?? ''), 'trajectory=partial'),
    'final status contains trajectory summary'
);

$source = (string) file_get_contents($process);
$densePosition = strpos($source, 'dense_depth_from_synced_capture.py');
$odometryPosition = strpos($source, 'stereo_visual_odometry.py');
f01bb_ok($densePosition !== false, 'dense processor wired');
f01bb_ok($odometryPosition !== false, 'odometry processor wired');
f01bb_ok(
    $densePosition < $odometryPosition,
    'odometry runs after F01A'
);
f01bb_ok(
    str_contains($source, '"global_fusion_complete":false'),
    'result refuses global fusion claim'
);
f01bb_ok(
    !str_contains($source, '"global_fusion_complete":true'),
    'no global fusion true'
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
