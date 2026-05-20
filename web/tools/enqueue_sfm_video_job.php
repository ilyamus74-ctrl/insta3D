<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../configs/secure.php';

function fail(string $m): void { fwrite(STDERR, "ERROR: {$m}\n"); exit(1); }

$options = getopt('', ['order-id:', 'session-id:', 'video-path:', 'sfm-fps::', 'keyframe-fps::', 'frame-width::', 'marker-size-m::', 'marker-family::']);
if (!isset($options['order-id'], $options['session-id'], $options['video-path'])) {
    fail('Usage: php enqueue_sfm_video_job.php --order-id=18 --session-id=42 --video-path=/abs/path.mp4');
}
$orderId = (int)$options['order-id'];
$sessionId = (int)$options['session-id'];
$videoPath = trim((string)$options['video-path']);
if ($orderId <= 0 || $sessionId <= 0) fail('order-id/session-id must be positive');
if ($videoPath === '' || $videoPath[0] !== '/' || !is_file($videoPath)) fail('video-path must be existing absolute file path');

$sfmFps = isset($options['sfm-fps']) ? (float)$options['sfm-fps'] : 3.0;
$keyframeFps = isset($options['keyframe-fps']) ? (float)$options['keyframe-fps'] : 0.33;
$frameWidth = isset($options['frame-width']) ? (int)$options['frame-width'] : 1920;
$markerSize = isset($options['marker-size-m']) ? (float)$options['marker-size-m'] : 0.16;
$markerFamily = isset($options['marker-family']) ? trim((string)$options['marker-family']) : 'tag36h11';

$payload = json_encode([
    'video_path' => $videoPath,
    'sfm_fps' => $sfmFps,
    'keyframe_fps' => $keyframeFps,
    'frame_width' => $frameWidth,
    'marker_size_m' => $markerSize,
    'marker_family' => $markerFamily,
], JSON_UNESCAPED_SLASHES);

$jobType = 'SFM_VIDEO_PIPELINE';
$stmt = $dbcnx->prepare("SELECT id,status FROM processing_jobs WHERE order_id=? AND session_id=? AND job_type=? AND warning_text=? ORDER BY id DESC LIMIT 1");
$stmt->bind_param('iiss', $orderId, $sessionId, $jobType, $payload);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing && strtoupper((string)$existing['status']) !== 'FAILED') {
    echo "OK\n";
    echo 'job_id=' . (int)$existing['id'] . "\n";
    echo "status=EXISTS\n";
    exit(0);
}

$st = $dbcnx->prepare("INSERT INTO processing_jobs (session_id, order_id, job_type, status, metric_status, marker_expected, marker_kit_id, marker_dictionary, marker_size_m, warning_text) VALUES (?, ?, ?, 'QUEUED', 'NOT_READY', 1, 'maklertour_kit_v1', 'APRILTAG_36H11', ?, ?) ON DUPLICATE KEY UPDATE status=IF(status='FAILED','QUEUED',status), metric_status=IF(status='FAILED','NOT_READY',metric_status), marker_size_m=VALUES(marker_size_m), warning_text=VALUES(warning_text), error_text=NULL, updated_at=NOW(6)");
$st->bind_param('iisds', $sessionId, $orderId, $jobType, $markerSize, $payload);
if (!$st->execute()) fail('insert/update failed: ' . $st->error);
$id = (int)$dbcnx->insert_id;
$st->close();

if ($id <= 0) {
    $stmt = $dbcnx->prepare("SELECT id FROM processing_jobs WHERE order_id=? AND session_id=? AND job_type=? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('iis', $orderId, $sessionId, $jobType);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $id = (int)($row['id'] ?? 0);
}

echo "OK\n";
echo "job_id={$id}\n";
echo "status=QUEUED\n";
