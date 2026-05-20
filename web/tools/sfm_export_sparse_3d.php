<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$connectCandidates = ['/home/makler/web/configs/connectDB.php', __DIR__ . '/../configs/connectDB.php'];
foreach ($connectCandidates as $connectFile) {
    if (is_file($connectFile)) {
        require_once $connectFile;
        break;
    }
}
if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) {
    fwrite(STDERR, "ERROR: failed to initialize mysqli via connectDB.php\n");
    exit(1);
}

const STORAGE_ROOT = '/home/makler/web/storage/orders';

function fail(string $msg): void { fwrite(STDERR, "ERROR: {$msg}\n"); exit(1); }
function jread(string $path): array {
    if (!is_file($path)) fail('missing file: ' . $path);
    $raw = file_get_contents($path);
    $data = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($data)) fail('invalid json: ' . $path);
    return $data;
}
function jwrite(string $path, array $data): void {
    if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
        fail('failed writing: ' . $path);
    }
}

$opt = getopt('', ['order-id:', 'session-id:']);
$orderId = (int)($opt['order-id'] ?? 0);
$sessionId = (int)($opt['session-id'] ?? 0);
if ($orderId <= 0 || $sessionId <= 0) {
    fail('Usage: php sfm_export_sparse_3d.php --order-id=18 --session-id=42');
}

$stmt = $dbcnx->prepare('SELECT session_dir FROM video_sfm_runs WHERE order_id = ? AND session_id = ? ORDER BY id DESC LIMIT 1');
if (!$stmt) fail('db prepare failed: ' . $dbcnx->error);
$stmt->bind_param('ii', $orderId, $sessionId);
$stmt->execute();
$run = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$run) fail('video_sfm_runs not found');
$sessionDir = trim((string)($run['session_dir'] ?? ''));
if ($sessionDir === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $sessionDir)) fail('invalid session_dir');

$sessionBase = STORAGE_ROOT . '/' . $orderId . '/sessions/' . $sessionDir;
$sfmBase = $sessionBase . '/sfm';
$realRoot = realpath(STORAGE_ROOT);
$realSession = realpath($sessionBase);
if ($realRoot === false || $realSession === false) fail('storage/session path missing');
if (strpos($realSession, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) fail('session path outside storage root');

$pointsPath = $realSession . '/sfm/colmap/sparse/0_txt/points3D.txt';
$trajPath = $realSession . '/sfm/trajectory/trajectory_scaled.json';
$keyLinksPath = $realSession . '/sfm/keyframe_links.jsonl';
if (!is_file($pointsPath) || !is_file($trajPath) || !is_file($keyLinksPath)) fail('missing required sfm files');

$points = [];
$fh = fopen($pointsPath, 'rb');
if ($fh === false) fail('failed to read points3D');
while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $parts = preg_split('/\s+/', $line);
    if (!is_array($parts) || count($parts) < 7) continue;
    $x = (float)$parts[1]; $y = (float)$parts[2]; $z = (float)$parts[3];
    $r = max(0, min(255, (int)$parts[4]));
    $g = max(0, min(255, (int)$parts[5]));
    $b = max(0, min(255, (int)$parts[6]));
    $points[] = [$x, $y, $z, $r, $g, $b];
}
fclose($fh);

$trajRaw = jread($trajPath);
$trajItems = $trajRaw['trajectory_scaled'] ?? $trajRaw['poses'] ?? $trajRaw['trajectory'] ?? $trajRaw;
if (!is_array($trajItems)) $trajItems = [];
$cameraTrajectory = [];
$poseByFrame = [];
foreach ($trajItems as $item) {
    if (!is_array($item)) continue;
    $frameName = (string)($item['frame_name'] ?? $item['image_name'] ?? '');
    if ($frameName === '') continue;
    $row = [
        'frame_index' => (int)($item['frame_index'] ?? preg_replace('/\D+/', '', (string)$frameName) ?? 0),
        'frame_name' => $frameName,
        'x' => isset($item['x_scaled']) ? (float)$item['x_scaled'] : (float)($item['x'] ?? 0.0),
        'y' => isset($item['y_scaled']) ? (float)$item['y_scaled'] : (float)($item['y'] ?? 0.0),
        'z' => isset($item['z_scaled']) ? (float)$item['z_scaled'] : (float)($item['z'] ?? 0.0),
    ];
    $cameraTrajectory[] = $row;
    $poseByFrame[$frameName] = $row;
}

$keyframePoints = [];
$lines = file($keyLinksPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) fail('failed reading keyframe links');
foreach ($lines as $line) {
    $row = json_decode($line, true);
    if (!is_array($row)) continue;
    $kfName = (string)($row['keyframe_name'] ?? '');
    if ($kfName === '') continue;
    $frameName = (string)($row['frame_name'] ?? '');
    $pose = $poseByFrame[$frameName] ?? null;
    if (!$pose && isset($row['frame_index'])) {
        $idx = (int)$row['frame_index'];
        foreach ($cameraTrajectory as $p) { if ((int)$p['frame_index'] === $idx) { $pose = $p; break; } }
    }
    if (!$pose) continue;
    $keyframePoints[] = [
        'keyframe_index' => (int)($row['keyframe_index'] ?? count($keyframePoints) + 1),
        'keyframe_name' => $kfName,
        'x' => (float)$pose['x'],
        'y' => (float)$pose['y'],
        'z' => (float)$pose['z'],
        'preview_url' => '/media.php?path=' . rawurlencode('orders/' . $orderId . '/sessions/' . basename($realSession) . '/sfm/viewer_keyframes/' . $kfName),
    ];
}

$outDir = $realSession . '/sfm/3d';
@mkdir($outDir, 0775, true);

$plyPath = $outDir . '/sparse_points.ply';
$ply = "ply\nformat ascii 1.0\nelement vertex " . count($points) . "\nproperty float x\nproperty float y\nproperty float z\nproperty uchar red\nproperty uchar green\nproperty uchar blue\nend_header\n";
foreach ($points as $p) { $ply .= sprintf("%.6f %.6f %.6f %d %d %d\n", $p[0], $p[1], $p[2], $p[3], $p[4], $p[5]); }
if (file_put_contents($plyPath, $ply) === false) fail('failed writing ply');

jwrite($outDir . '/camera_trajectory.json', $cameraTrajectory);
jwrite($outDir . '/keyframe_points_3d.json', $keyframePoints);
$summary = [
    'ok' => true,
    'points_count' => count($points),
    'camera_poses_count' => count($cameraTrajectory),
    'keyframe_points_count' => count($keyframePoints),
    'artifact_type' => 'SPARSE_3D',
];
jwrite($outDir . '/sfm_3d_summary.json', $summary);

echo "OK\n";
echo 'points_count=' . count($points) . "\n";
echo 'camera_poses_count=' . count($cameraTrajectory) . "\n";
echo 'keyframe_points_count=' . count($keyframePoints) . "\n";
