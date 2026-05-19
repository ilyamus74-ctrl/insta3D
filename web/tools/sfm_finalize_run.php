<?php
declare(strict_types=1);

function fail(string $message): void {
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function parseArgs(array $argv): array {
    $options = getopt('', [
        'order-id:',
        'session-dir:',
        'video-path:',
        'sfm-fps::',
        'keyframe-fps::',
        'dry-run',
    ]);

    if (!isset($options['order-id'], $options['session-dir'], $options['video-path'])) {
        fail('Usage: php sfm_finalize_run.php --order-id=18 --session-dir=... --video-path=... [--sfm-fps=3] [--keyframe-fps=0.33] [--dry-run]');
    }

    $sfmFps = isset($options['sfm-fps']) ? (float)$options['sfm-fps'] : 3.0;
    $keyframeFps = isset($options['keyframe-fps']) ? (float)$options['keyframe-fps'] : 0.33;
    if ($sfmFps <= 0.0 || $keyframeFps <= 0.0) {
        fail('sfm-fps and keyframe-fps must be > 0');
    }

    return [
        'order_id' => (int)$options['order-id'],
        'session_dir' => (string)$options['session-dir'],
        'video_path' => (string)$options['video-path'],
        'sfm_fps' => $sfmFps,
        'keyframe_fps' => $keyframeFps,
        'dry_run' => array_key_exists('dry-run', $options),
    ];
}

function readJsonFile(string $path): array {
    if (!is_file($path)) {
        fail("missing file: {$path}");
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        fail("failed reading file: {$path}");
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fail("invalid JSON in file: {$path}");
    }
    return $decoded;
}

function writeJsonFile(string $path, array $data): void {
    $ok = file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    if ($ok === false) {
        fail("failed writing file: {$path}");
    }
}

function countMarkers(array $markerData): int {
    if (isset($markerData['observations']) && is_array($markerData['observations'])) {
        return count($markerData['observations']);
    }
    if (array_is_list($markerData)) {
        return count($markerData);
    }
    return 0;
}

function parseFrameIndex(string $name): ?int {
    if (preg_match('/_(\d+)\./', $name, $m) !== 1) {
        return null;
    }
    return (int)$m[1];
}

function extractSessionUuidCandidate(string $sessionDir): string {
    if (preg_match('/^([0-9a-fA-F-]{36})_\d+$/', $sessionDir, $m) === 1) {
        return $m[1];
    }
    return $sessionDir;
}

function detectScaleData(array $trajectory): array {
    $scaleOk = false;
    $scaleFactor = null;
    $scaleSamples = 0;

    foreach (['scale_ok', 'is_scale_ok', 'ok'] as $k) {
        if (array_key_exists($k, $trajectory)) {
            $scaleOk = (bool)$trajectory[$k];
            break;
        }
    }
    foreach (['scale_factor', 'scale'] as $k) {
        if (isset($trajectory[$k]) && is_numeric($trajectory[$k])) {
            $scaleFactor = (float)$trajectory[$k];
            break;
        }
    }
    foreach (['scale_samples', 'samples_count'] as $k) {
        if (isset($trajectory[$k]) && is_numeric($trajectory[$k])) {
            $scaleSamples = (int)$trajectory[$k];
            break;
        }
    }

    return [$scaleOk, $scaleFactor, $scaleSamples];
}

function collectPoseMap(array $trajectory): array {
    $poses = [];
    $items = $trajectory['trajectory_scaled']
        ?? $trajectory['poses']
        ?? $trajectory['trajectory']
        ?? $trajectory;

    if (!is_array($items)) {
        return $poses;
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $frameName = isset($item['frame_name'])
            ? (string)$item['frame_name']
            : (isset($item['image_name']) ? (string)$item['image_name'] : null);

        if ($frameName === null) {
            continue;
        }

        $idx = parseFrameIndex($frameName);
        if ($idx === null) {
            continue;
        }

        $poses[$idx] = [
            'frame_name' => $frameName,
            'x_scaled' => isset($item['x_scaled']) ? (float)$item['x_scaled'] : (float)($item['x'] ?? 0.0),
            'y_scaled' => isset($item['y_scaled']) ? (float)$item['y_scaled'] : (float)($item['y'] ?? 0.0),
            'z_scaled' => isset($item['z_scaled']) ? (float)$item['z_scaled'] : (float)($item['z'] ?? 0.0),
        ];
    }

    ksort($poses);
    return $poses;
}

function nearestIndex(array $sortedIndexes, int $target): int {
    $best = $sortedIndexes[0];
    $bestDist = abs($best - $target);

    foreach ($sortedIndexes as $idx) {
        $dist = abs($idx - $target);
        if ($dist < $bestDist) {
            $best = $idx;
            $bestDist = $dist;
        }
    }

    return $best;
}

function ensureVideoSfmRunsTable(mysqli $dbcnx): void {
    $sql = "CREATE TABLE IF NOT EXISTS video_sfm_runs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        session_id BIGINT UNSIGNED NULL,
        session_dir VARCHAR(255) NOT NULL,
        video_path VARCHAR(1024) NOT NULL,
        sfm_base_path VARCHAR(1024) NOT NULL,
        status ENUM('NOT_STARTED','RUNNING','PROCESSED','FAILED') NOT NULL DEFAULT 'NOT_STARTED',
        metric_status ENUM('NOT_READY','METRIC_READY','FAILED') NOT NULL DEFAULT 'NOT_READY',
        frames_count INT UNSIGNED NOT NULL DEFAULT 0,
        keyframes_count INT UNSIGNED NOT NULL DEFAULT 0,
        marker_count INT UNSIGNED NOT NULL DEFAULT 0,
        poses_count INT UNSIGNED NOT NULL DEFAULT 0,
        scale_ok TINYINT(1) NOT NULL DEFAULT 0,
        scale_factor DOUBLE NULL,
        scale_samples INT UNSIGNED NOT NULL DEFAULT 0,
        summary_path VARCHAR(1024) NULL,
        markers_path VARCHAR(1024) NULL,
        camera_poses_path VARCHAR(1024) NULL,
        trajectory_scaled_path VARCHAR(1024) NULL,
        keyframe_links_path VARCHAR(1024) NULL,
        log_path VARCHAR(1024) NULL,
        warning_text TEXT NULL,
        error_text TEXT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_video_sfm_run (order_id, session_dir, video_path(255)),
        KEY idx_video_sfm_order (order_id),
        KEY idx_video_sfm_session (session_id),
        KEY idx_video_sfm_status (status),
        KEY idx_video_sfm_metric_status (metric_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$dbcnx->query($sql)) {
        fail('failed to ensure video_sfm_runs table: ' . $dbcnx->error);
    }
}

function getColumnType(mysqli $dbcnx, string $tableName, string $columnName): ?string {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
        fail("invalid table name: {$tableName}");
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $columnName)) {
        fail("invalid column name: {$columnName}");
    }

    $sql = "SELECT COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";

    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row['COLUMN_TYPE'] ?? null;
}

function resolveSessionId(mysqli $dbcnx, int $orderId, string $sessionDir): ?int {
    $sessionUuidCandidate = extractSessionUuidCandidate($sessionDir);

    $stmt = $dbcnx->prepare(
        'SELECT id FROM capture_sessions WHERE order_id = ? AND (app_session_uuid = ? OR app_session_uuid = ?) LIMIT 1'
    );
    if (!$stmt) {
        fail('failed to prepare session lookup: ' . $dbcnx->error);
    }

    $stmt->bind_param('iss', $orderId, $sessionDir, $sessionUuidCandidate);
    $stmt->execute();
    $res = $stmt->get_result();

    $sessionId = null;
    if ($row = $res->fetch_assoc()) {
        $sessionId = (int)$row['id'];
    }

    $stmt->close();
    return $sessionId;
}

function upsertVideoSfmRun(
    mysqli $dbcnx,
    int $orderId,
    ?int $sessionId,
    string $sessionDir,
    string $videoPath,
    string $sfmBaseRel,
    string $status,
    string $metricStatus,
    int $framesCount,
    int $keyframesCount,
    int $markerCount,
    int $posesCount,
    bool $scaleOk,
    ?float $scaleFactor,
    int $scaleSamples,
    array $summary
): void {
    $sql = "INSERT INTO video_sfm_runs (
        order_id, session_id, session_dir, video_path, sfm_base_path,
        status, metric_status, frames_count, keyframes_count, marker_count, poses_count,
        scale_ok, scale_factor, scale_samples, summary_path, markers_path, camera_poses_path,
        trajectory_scaled_path, keyframe_links_path, warning_text, error_text
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)
    ON DUPLICATE KEY UPDATE
        session_id = VALUES(session_id),
        sfm_base_path = VALUES(sfm_base_path),
        status = VALUES(status),
        metric_status = VALUES(metric_status),
        frames_count = VALUES(frames_count),
        keyframes_count = VALUES(keyframes_count),
        marker_count = VALUES(marker_count),
        poses_count = VALUES(poses_count),
        scale_ok = VALUES(scale_ok),
        scale_factor = VALUES(scale_factor),
        scale_samples = VALUES(scale_samples),
        summary_path = VALUES(summary_path),
        markers_path = VALUES(markers_path),
        camera_poses_path = VALUES(camera_poses_path),
        trajectory_scaled_path = VALUES(trajectory_scaled_path),
        keyframe_links_path = VALUES(keyframe_links_path),
        warning_text = NULL,
        error_text = NULL";

    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        fail('failed to prepare video_sfm_runs upsert: ' . $dbcnx->error);
    }

    $scaleOkInt = $scaleOk ? 1 : 0;
    $summaryRel = 'sfm/sfm_result_summary.json';

    $stmt->bind_param(
        'iisssssiiiiidisssss',
        $orderId,
        $sessionId,
        $sessionDir,
        $videoPath,
        $sfmBaseRel,
        $status,
        $metricStatus,
        $framesCount,
        $keyframesCount,
        $markerCount,
        $posesCount,
        $scaleOkInt,
        $scaleFactor,
        $scaleSamples,
        $summaryRel,
        $summary['markers_path'],
        $summary['camera_poses_path'],
        $summary['trajectory_scaled_path'],
        $summary['keyframe_links_path']
    );

    if (!$stmt->execute()) {
        fail('failed to upsert video_sfm_runs: ' . $stmt->error);
    }

    $stmt->close();
}

function upsertProcessingJob(
    mysqli $dbcnx,
    ?int $sessionId,
    int $orderId,
    string $status,
    string $metricStatus,
    int $markerCount,
    string $warning
): void {
    $jobType = 'SFM_FINALIZE';
    $jobSession = $sessionId ?? 0;

    $find = $dbcnx->prepare(
        'SELECT id FROM processing_jobs WHERE order_id = ? AND session_id = ? AND job_type = ? ORDER BY id DESC LIMIT 1'
    );
    if (!$find) {
        fail('failed to prepare processing_jobs lookup: ' . $dbcnx->error);
    }

    $find->bind_param('iis', $orderId, $jobSession, $jobType);
    $find->execute();
    $res = $find->get_result();

    $existingId = null;
    if ($row = $res->fetch_assoc()) {
        $existingId = (int)$row['id'];
    }
    $find->close();

    if ($existingId !== null) {
        $update = $dbcnx->prepare(
            'UPDATE processing_jobs
             SET status = ?, metric_status = ?, markers_detected_count = ?, warning_text = ?, error_text = NULL, updated_at = NOW(6)
             WHERE id = ?'
        );
        if (!$update) {
            fail('failed to prepare processing_jobs update: ' . $dbcnx->error);
        }

        $update->bind_param('ssisi', $status, $metricStatus, $markerCount, $warning, $existingId);
        if (!$update->execute()) {
            fail('failed to update processing_jobs: ' . $update->error);
        }
        $update->close();
        return;
    }

    $markerKitId = 'maklertour_kit_v1';
    $markerKitType = getColumnType($dbcnx, 'processing_jobs', 'marker_kit_id');
    if ($markerKitType !== null && preg_match('/int|decimal|float|double/i', $markerKitType) === 1) {
        $markerKitId = null;
    }

    $insert = $dbcnx->prepare(
        "INSERT INTO processing_jobs (
            session_id, order_id, job_type, status, metric_status,
            marker_expected, marker_kit_id, marker_dictionary, marker_size_m,
            markers_detected_count, warning_text, error_text
        ) VALUES (?, ?, ?, ?, ?, 1, ?, 'APRILTAG_36H11', 0.1600, ?, ?, NULL)"
    );
    if (!$insert) {
        fail('failed to prepare processing_jobs insert: ' . $dbcnx->error);
    }

    $insert->bind_param('iissssis', $jobSession, $orderId, $jobType, $status, $metricStatus, $markerKitId, $markerCount, $warning);
    if (!$insert->execute()) {
        fail('failed to insert processing_jobs: ' . $insert->error);
    }

    $insert->close();
}

$args = parseArgs($argv);

$sessionBase = "/home/makler/web/storage/orders/{$args['order_id']}/sessions/{$args['session_dir']}";
if (!is_dir($sessionBase)) {
    $sessionBase = dirname(dirname($args['video_path']));
}

$sfmBase = $sessionBase . '/sfm';
if (!is_dir($sfmBase)) {
    fail("sfm directory not found: {$sfmBase}");
}

$markersPathAbs = $sfmBase . '/markers/marker_observations.json';
$cameraPosesPathAbs = $sfmBase . '/trajectory/camera_poses.json';
$trajectoryPathAbs = $sfmBase . '/trajectory/trajectory_scaled.json';

$markers = readJsonFile($markersPathAbs);
$cameraPoses = readJsonFile($cameraPosesPathAbs);
$trajectory = readJsonFile($trajectoryPathAbs);

$framesCount = count(glob($sfmBase . '/frames/frame_*.jpg') ?: []);

$keyframes = glob($sfmBase . '/keyframes/keyframe_*.jpg') ?: [];
sort($keyframes, SORT_NATURAL);

$keyframesCount = count($keyframes);
$markerCount = countMarkers($markers);
$posesCount = isset($cameraPoses['poses']) && is_array($cameraPoses['poses'])
    ? count($cameraPoses['poses'])
    : (array_is_list($cameraPoses) ? count($cameraPoses) : 0);

[$scaleOk, $scaleFactor, $scaleSamples] = detectScaleData($trajectory);

$status = 'PROCESSED';
$metricStatus = $scaleOk ? 'METRIC_READY' : 'FAILED';

$poseMap = collectPoseMap($trajectory);
$poseIndexes = array_keys($poseMap);
if ($poseIndexes === []) {
    fail('no registered frames found in trajectory_scaled.json');
}

$keyframeLinksPathAbs = $sfmBase . '/keyframe_links.jsonl';
$fh = fopen($keyframeLinksPathAbs, 'wb');
if ($fh === false) {
    fail('cannot create keyframe_links.jsonl');
}

$keyframeLinksCount = 0;
foreach ($keyframes as $kfPath) {
    $kfName = basename($kfPath);
    if (preg_match('/keyframe_(\d+)\.jpg$/', $kfName, $m) !== 1) {
        continue;
    }

    $kfIndex = (int)$m[1];
    $target = (int)round(($kfIndex - 1) * $args['sfm_fps'] / $args['keyframe_fps']) + 1;
    $best = isset($poseMap[$target]) ? $target : nearestIndex($poseIndexes, $target);
    $pose = $poseMap[$best];

    $line = [
        'keyframe_index' => $kfIndex,
        'keyframe_name' => $kfName,
        'nearest_frame_name' => $pose['frame_name'],
        'x_scaled' => $pose['x_scaled'],
        'y_scaled' => $pose['y_scaled'],
        'z_scaled' => $pose['z_scaled'],
    ];

    fwrite($fh, json_encode($line, JSON_UNESCAPED_SLASHES) . "\n");
    $keyframeLinksCount++;
}
fclose($fh);

$summary = [
    'order_id' => $args['order_id'],
    'session_dir' => $args['session_dir'],
    'frames_count' => $framesCount,
    'keyframes_count' => $keyframesCount,
    'marker_count' => $markerCount,
    'poses_count' => $posesCount,
    'scale_ok' => $scaleOk,
    'scale_factor' => $scaleFactor,
    'scale_samples' => $scaleSamples,
    'keyframe_links_count' => $keyframeLinksCount,
    'status' => $status,
    'metric_status' => $metricStatus,
    'markers_path' => 'sfm/markers/marker_observations.json',
    'camera_poses_path' => 'sfm/trajectory/camera_poses.json',
    'trajectory_scaled_path' => 'sfm/trajectory/trajectory_scaled.json',
    'keyframe_links_path' => 'sfm/keyframe_links.jsonl',
];

$summaryAbs = $sfmBase . '/sfm_result_summary.json';
writeJsonFile($summaryAbs, $summary);

$summaryRel = 'sfm/sfm_result_summary.json';
$warning = json_encode([
    'summary_path' => $summaryRel,
    'keyframe_links_path' => $summary['keyframe_links_path'],
    'frames_count' => $framesCount,
    'keyframes_count' => $keyframesCount,
    'poses_count' => $posesCount,
    'scale_factor' => $scaleFactor,
    'scale_samples' => $scaleSamples,
], JSON_UNESCAPED_SLASHES);

if ($args['dry_run']) {
    echo "DRY_RUN=1\n";
    echo "OK\n";
    echo "frames_count={$framesCount}\n";
    echo "keyframes_count={$keyframesCount}\n";
    echo "marker_count={$markerCount}\n";
    echo "poses_count={$posesCount}\n";
    echo 'scale_ok=' . ($scaleOk ? 'true' : 'false') . "\n";
    echo 'scale_factor=' . ($scaleFactor === null ? 'null' : (string)$scaleFactor) . "\n";
    echo "scale_samples={$scaleSamples}\n";
    echo "keyframe_links_count={$keyframeLinksCount}\n";
    exit(0);
}

$connectCandidates = ['/home/makler/web/configs/connectDB.php', __DIR__ . '/../configs/connectDB.php'];
foreach ($connectCandidates as $connectFile) {
    if (is_file($connectFile)) {
        require_once $connectFile;
        break;
    }
}

if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) {
    fail('failed to initialize mysqli via connectDB.php');
}

ensureVideoSfmRunsTable($dbcnx);

$sessionId = resolveSessionId($dbcnx, $args['order_id'], $args['session_dir']);
$sfmBaseRel = 'sfm';

upsertVideoSfmRun(
    $dbcnx,
    $args['order_id'],
    $sessionId,
    $args['session_dir'],
    $args['video_path'],
    $sfmBaseRel,
    $status,
    $metricStatus,
    $framesCount,
    $keyframesCount,
    $markerCount,
    $posesCount,
    $scaleOk,
    $scaleFactor,
    $scaleSamples,
    $summary
);

upsertProcessingJob($dbcnx, $sessionId, $args['order_id'], $status, $metricStatus, $markerCount, $warning);

echo "OK\n";
echo "frames_count={$framesCount}\n";
echo "keyframes_count={$keyframesCount}\n";
echo "marker_count={$markerCount}\n";
echo "poses_count={$posesCount}\n";
echo 'scale_ok=' . ($scaleOk ? 'true' : 'false') . "\n";
echo 'scale_factor=' . ($scaleFactor === null ? 'null' : (string)$scaleFactor) . "\n";
echo "scale_samples={$scaleSamples}\n";
echo "keyframe_links_count={$keyframeLinksCount}\n";
