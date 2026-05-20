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
const DEFAULT_STITCHER_BIN = '/home/makler/web/tools/dualfisheye_stitcher_cpp/build/dualfisheye_stitch';

function fail(string $msg): void { fwrite(STDERR, "ERROR: {$msg}\n"); exit(1); }
function runCmd(string $cmd, ?string $logPath = null): void {
    $out = []; $rc = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    if ($logPath !== null && $out) { file_put_contents($logPath, implode("\n", $out) . "\n", FILE_APPEND); }
    if ($rc !== 0) { throw new RuntimeException("command failed: {$cmd}"); }
}

$opt = getopt('', ['order-id:', 'session-id:', 'video-path:', 'keyframe-fps::', 'frame-size::', 'output-width::', 'output-height::', 'stitcher-bin::']);
$orderId = (int)($opt['order-id'] ?? 0);
$sessionId = (int)($opt['session-id'] ?? 0);
$videoPath = (string)($opt['video-path'] ?? '');
$keyframeFps = (float)($opt['keyframe-fps'] ?? 0.33);
$frameSize = (int)($opt['frame-size'] ?? 1920);
$outputWidth = (int)($opt['output-width'] ?? 4096);
$outputHeight = (int)($opt['output-height'] ?? 2048);
$stitcherBin = (string)($opt['stitcher-bin'] ?? DEFAULT_STITCHER_BIN);

if ($orderId <= 0 || $sessionId <= 0 || $videoPath === '') {
    fail('Usage: php sfm_build_viewer_keyframes.php --order-id=18 --session-id=42 --video-path=/abs/video.mp4 [--keyframe-fps=0.33 --frame-size=1920 --output-width=4096 --output-height=2048]');
}
if ($keyframeFps <= 0 || $frameSize <= 0 || $outputWidth <= 0 || $outputHeight <= 0) {
    fail('numeric options must be positive');
}
if (!is_file($videoPath)) { fail('video-path not found: ' . $videoPath); }
if (!is_file($stitcherBin)) { fail('stitcher binary not found: ' . $stitcherBin); }

$sessionDir = '';

$stmt = $dbcnx->prepare('SELECT session_dir FROM video_sfm_runs WHERE order_id = ? AND session_id = ? ORDER BY id DESC LIMIT 1');
if (!$stmt) { fail('failed to prepare video_sfm_runs query: ' . $dbcnx->error); }
$stmt->bind_param('ii', $orderId, $sessionId);
$stmt->execute();
$run = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($run && isset($run['session_dir'])) {
    $candidate = trim((string)$run['session_dir']);
    if ($candidate !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $candidate)) {
        $sessionDir = $candidate;
    }
}

if ($sessionDir === '') {
    $stmt = $dbcnx->prepare('SELECT app_session_uuid FROM capture_sessions WHERE id = ? AND order_id = ? LIMIT 1');
    if (!$stmt) { fail('failed to prepare capture session query: ' . $dbcnx->error); }
    $stmt->bind_param('ii', $sessionId, $orderId);
    $stmt->execute();
    $sess = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$sess) { fail('capture_session not found'); }

    $uuid = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim((string)$sess['app_session_uuid']));
    if (preg_match('/_' . preg_quote((string)$orderId, '/') . '$/', $uuid)) {
        $sessionDir = $uuid;
    } else {
        $sessionDir = $uuid . '_' . $orderId;
    }
}

$sessionBase = STORAGE_ROOT . '/' . $orderId . '/sessions/' . $sessionDir;
$sfmBase = $sessionBase . '/sfm';
$realRoot = realpath(STORAGE_ROOT);
if ($realRoot === false) { fail('storage root missing'); }
@mkdir($sfmBase, 0775, true);
$realSession = realpath($sessionBase);
if ($realSession === false || strpos($realSession, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
    fail('resolved session path is outside storage root');
}

$dirs = [
    $sfmBase . '/viewer_left',
    $sfmBase . '/viewer_right',
    $sfmBase . '/viewer_dualfisheye',
    $sfmBase . '/viewer_keyframes',
    $sfmBase . '/logs',
];
foreach ($dirs as $d) { @mkdir($d, 0775, true); }

$logPath = $sfmBase . '/logs/viewer_keyframes_' . date('Ymd_His') . '.log';
file_put_contents($logPath, '[' . date('c') . "] start\n", FILE_APPEND);

$leftPattern = $sfmBase . '/viewer_left/keyframe_%06d.jpg';
$rightPattern = $sfmBase . '/viewer_right/keyframe_%06d.jpg';
$dualDir = $sfmBase . '/viewer_dualfisheye';
$outDir = $sfmBase . '/viewer_keyframes';

runCmd('rm -rf ' . escapeshellarg($sfmBase . '/viewer_left') . ' ' . escapeshellarg($sfmBase . '/viewer_right') . ' ' . escapeshellarg($sfmBase . '/viewer_dualfisheye') . ' ' . escapeshellarg($sfmBase . '/viewer_keyframes')
    . ' && mkdir -p ' . escapeshellarg($sfmBase . '/viewer_left') . ' ' . escapeshellarg($sfmBase . '/viewer_right') . ' ' . escapeshellarg($sfmBase . '/viewer_dualfisheye') . ' ' . escapeshellarg($sfmBase . '/viewer_keyframes'), $logPath);

runCmd('ffmpeg -y -i ' . escapeshellarg($videoPath)
    . ' -map 0:v:0 -vf ' . escapeshellarg('fps=' . $keyframeFps . ',scale=' . $frameSize . ':' . $frameSize)
    . ' -q:v 2 ' . escapeshellarg($leftPattern), $logPath);

runCmd('ffmpeg -y -i ' . escapeshellarg($videoPath)
    . ' -map 0:v:1 -vf ' . escapeshellarg('fps=' . $keyframeFps . ',scale=' . $frameSize . ':' . $frameSize)
    . ' -q:v 2 ' . escapeshellarg($rightPattern), $logPath);

$leftFiles = glob($sfmBase . '/viewer_left/keyframe_*.jpg') ?: [];
$rightFiles = glob($sfmBase . '/viewer_right/keyframe_*.jpg') ?: [];
$leftMap = [];
foreach ($leftFiles as $f) { $leftMap[basename($f)] = $f; }
$rightMap = [];
foreach ($rightFiles as $f) { $rightMap[basename($f)] = $f; }
$names = array_values(array_intersect(array_keys($leftMap), array_keys($rightMap)));
sort($names, SORT_STRING);
if (!$names) { fail('no matching left/right keyframes extracted'); }

$count = 0;
foreach ($names as $name) {
    $left = $leftMap[$name];
    $right = $rightMap[$name];
    $dual = $dualDir . '/' . $name;
    $out = $outDir . '/' . $name;

    runCmd('ffmpeg -y -i ' . escapeshellarg($left) . ' -i ' . escapeshellarg($right) . ' -filter_complex hstack ' . escapeshellarg($dual), $logPath);

    $stitchCmd = escapeshellarg($stitcherBin)
        . ' --input ' . escapeshellarg($dual)
        . ' --output ' . escapeshellarg($out)
        . ' --output-width ' . (int)$outputWidth
        . ' --output-height ' . (int)$outputHeight
        . ' --fov 197'
        . ' --blend-width 22'
        . ' --left-yaw 180'
        . ' --right-yaw 0'
        . ' --jpeg-quality 92';
    runCmd($stitchCmd, $logPath);
    $count++;
}

$summary = [
    'ok' => true,
    'viewer_keyframes_count' => $count,
    'output_width' => $outputWidth,
    'output_height' => $outputHeight,
    'source' => 'dual_video_streams',
    'left_stream' => '0:v:0',
    'right_stream' => '0:v:1',
];
file_put_contents($sfmBase . '/viewer_keyframes_summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "OK\n";
echo 'viewer_keyframes_count=' . $count . "\n";
