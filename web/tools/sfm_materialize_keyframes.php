<?php
declare(strict_types=1);

require_once __DIR__ . '/../configs/secure.php';

function fail(string $message): void {
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function parseArgs(): array {
    $options = getopt('', ['order-id:', 'session-id:']);
    if (!isset($options['order-id'], $options['session-id'])) {
        fail('Usage: php sfm_materialize_keyframes.php --order-id=18 --session-id=42');
    }

    $orderId = (int)$options['order-id'];
    $sessionId = (int)$options['session-id'];
    if ($orderId <= 0 || $sessionId <= 0) {
        fail('order-id and session-id must be positive integers');
    }

    return ['order_id' => $orderId, 'session_id' => $sessionId];
}

function ensureTable(mysqli $dbcnx): void {
    $sql = "CREATE TABLE IF NOT EXISTS sfm_keyframe_points (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        session_id BIGINT UNSIGNED NULL,
        video_sfm_run_id BIGINT UNSIGNED NULL,
        session_dir VARCHAR(255) NOT NULL,
        keyframe_index INT UNSIGNED NOT NULL,
        keyframe_name VARCHAR(255) NOT NULL,
        keyframe_path VARCHAR(1024) NOT NULL,
        nearest_frame_name VARCHAR(255) NULL,
        target_frame_index INT UNSIGNED NULL,
        nearest_frame_index INT UNSIGNED NULL,
        frame_delta INT UNSIGNED NULL,
        x_scaled DOUBLE NULL,
        y_scaled DOUBLE NULL,
        z_scaled DOUBLE NULL,
        distance_from_prev_m DOUBLE NULL,
        segment_break TINYINT(1) NOT NULL DEFAULT 0,
        point_type ENUM('SFM_KEYFRAME','MANUAL','OTHER') NOT NULL DEFAULT 'SFM_KEYFRAME',
        source_type ENUM('VIDEO_KEYFRAME') NOT NULL DEFAULT 'VIDEO_KEYFRAME',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_sfm_keyframe_point (order_id, session_id, keyframe_index),
        KEY idx_sfm_keyframe_order (order_id),
        KEY idx_sfm_keyframe_session (session_id),
        KEY idx_sfm_keyframe_run (video_sfm_run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$dbcnx->query($sql)) {
        fail('failed to ensure sfm_keyframe_points table: ' . $dbcnx->error);
    }
}

function loadRun(mysqli $dbcnx, int $orderId, int $sessionId): array {
    $stmt = $dbcnx->prepare('SELECT * FROM video_sfm_runs WHERE order_id = ? AND session_id = ? ORDER BY id DESC LIMIT 1');
    if (!$stmt) {
        fail('failed to prepare run lookup: ' . $dbcnx->error);
    }
    $stmt->bind_param('ii', $orderId, $sessionId);
    $stmt->execute();
    $run = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$run) {
        fail('video_sfm_run not found for order/session');
    }
    if (($run['status'] ?? '') !== 'PROCESSED') {
        fail('video_sfm_run status must be PROCESSED');
    }
    if (($run['metric_status'] ?? '') !== 'METRIC_READY') {
        fail('video_sfm_run metric_status must be METRIC_READY');
    }

    return $run;
}

function resolveSessionBase(int $orderId, array $run): string {
    $storageRoot = '/home/makler/web/storage/orders';
    $realStorageRoot = realpath($storageRoot);
    if ($realStorageRoot === false) {
        fail('storage root missing');
    }

    $sessionDir = trim((string)($run['session_dir'] ?? ''));
    if ($sessionDir === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $sessionDir)) {
        fail('invalid session_dir in run');
    }

    $candidates = [$storageRoot . '/' . $orderId . '/sessions/' . $sessionDir];
    $runVideoPath = (string)($run['video_path'] ?? '');
    if ($runVideoPath !== '' && $runVideoPath[0] === '/' && is_file($runVideoPath)) {
        $candidates[] = dirname(dirname($runVideoPath));
    }
    $runSfmBasePath = (string)($run['sfm_base_path'] ?? '');
    if ($runSfmBasePath !== '' && $runSfmBasePath[0] === '/') {
        $candidates[] = basename($runSfmBasePath) === 'sfm' ? dirname($runSfmBasePath) : $runSfmBasePath;
    }

    $storagePrefix = rtrim($realStorageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    foreach (array_values(array_unique($candidates)) as $candidate) {
        $realCandidate = realpath($candidate);
        if ($realCandidate === false) {
            continue;
        }
        if (strpos($realCandidate, $storagePrefix) !== 0) {
            continue;
        }
        if (!is_dir($realCandidate . '/sfm')) {
            continue;
        }
        return $realCandidate;
    }

    fail('session path missing under storage root');
}

function upsertPoint(mysqli $dbcnx, int $orderId, int $sessionId, int $runId, string $sessionDir, string $keyframePath, array $row): void {
    $sql = "INSERT INTO sfm_keyframe_points (
        order_id, session_id, video_sfm_run_id, session_dir,
        keyframe_index, keyframe_name, keyframe_path,
        nearest_frame_name, target_frame_index, nearest_frame_index, frame_delta,
        x_scaled, y_scaled, z_scaled,
        distance_from_prev_m, segment_break,
        point_type, source_type, is_active
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'SFM_KEYFRAME', 'VIDEO_KEYFRAME', 1)
    ON DUPLICATE KEY UPDATE
        video_sfm_run_id = VALUES(video_sfm_run_id),
        session_dir = VALUES(session_dir),
        keyframe_name = VALUES(keyframe_name),
        keyframe_path = VALUES(keyframe_path),
        nearest_frame_name = VALUES(nearest_frame_name),
        target_frame_index = VALUES(target_frame_index),
        nearest_frame_index = VALUES(nearest_frame_index),
        frame_delta = VALUES(frame_delta),
        x_scaled = VALUES(x_scaled),
        y_scaled = VALUES(y_scaled),
        z_scaled = VALUES(z_scaled),
        distance_from_prev_m = VALUES(distance_from_prev_m),
        segment_break = VALUES(segment_break),
        is_active = 1";

    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        fail('failed to prepare keyframe upsert: ' . $dbcnx->error);
    }

    $keyframeIndex = isset($row['keyframe_index']) ? (int)$row['keyframe_index'] : 0;
    $keyframeName = (string)($row['keyframe_name'] ?? '');
    $nearestFrameName = isset($row['nearest_frame_name']) ? (string)$row['nearest_frame_name'] : null;
    $targetFrameIndex = isset($row['target_frame_index']) ? (int)$row['target_frame_index'] : null;
    $nearestFrameIndex = isset($row['nearest_frame_index']) ? (int)$row['nearest_frame_index'] : null;
    $frameDelta = isset($row['frame_delta']) ? abs((int)$row['frame_delta']) : null;
    $x = isset($row['x_scaled']) ? (float)$row['x_scaled'] : null;
    $y = isset($row['y_scaled']) ? (float)$row['y_scaled'] : null;
    $z = isset($row['z_scaled']) ? (float)$row['z_scaled'] : null;
    $distPrev = isset($row['distance_from_prev_m']) ? (float)$row['distance_from_prev_m'] : null;
    $segmentBreak = !empty($row['segment_break']) ? 1 : 0;

    $stmt->bind_param(
        'iiisisssiiiddddi',
        $orderId,
        $sessionId,
        $runId,
        $sessionDir,
        $keyframeIndex,
        $keyframeName,
        $keyframePath,
        $nearestFrameName,
        $targetFrameIndex,
        $nearestFrameIndex,
        $frameDelta,
        $x,
        $y,
        $z,
        $distPrev,
        $segmentBreak
    );

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        fail('failed to upsert keyframe point: ' . $err);
    }

    $stmt->close();
}

$args = parseArgs();
ensureTable($dbcnx);
$run = loadRun($dbcnx, $args['order_id'], $args['session_id']);
$sessionBase = resolveSessionBase($args['order_id'], $run);
$sessionDir = basename($sessionBase);

$summaryPath = $sessionBase . '/sfm/sfm_result_summary.json';
$keyframeLinksPath = $sessionBase . '/sfm/keyframe_links.jsonl';
if (!is_file($summaryPath)) {
    fail('summary file missing: ' . $summaryPath);
}
if (!is_file($keyframeLinksPath)) {
    fail('keyframe_links file missing: ' . $keyframeLinksPath);
}

$summary = json_decode((string)file_get_contents($summaryPath), true);
if (!is_array($summary)) {
    fail('invalid summary json');
}

$lines = file($keyframeLinksPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    fail('failed reading keyframe links');
}

$keyframesRead = 0;
$upserted = 0;
$runId = (int)$run['id'];
foreach ($lines as $line) {
    $row = json_decode($line, true);
    if (!is_array($row)) {
        continue;
    }
    $keyframeName = (string)($row['keyframe_name'] ?? '');
    if ($keyframeName === '' || !preg_match('/^keyframe_[0-9]{6}\.jpg$/', $keyframeName)) {
        continue;
    }
    $keyframeIndex = isset($row['keyframe_index']) ? (int)$row['keyframe_index'] : 0;
    if ($keyframeIndex <= 0) {
        continue;
    }

    $keyframePath = $sessionBase . '/sfm/keyframes/' . $keyframeName;
    if (!is_file($keyframePath)) {
        fail('keyframe image missing: ' . $keyframePath);
    }

    $keyframesRead++;
    upsertPoint($dbcnx, $args['order_id'], $args['session_id'], $runId, $sessionDir, $keyframePath, $row);
    $upserted++;
}

echo "OK\n";
echo 'video_sfm_run_id=' . $runId . "\n";
echo 'keyframes_read=' . $keyframesRead . "\n";
echo 'points_upserted=' . $upserted . "\n";
