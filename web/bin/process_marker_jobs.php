<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require_once __DIR__ . '/../www/bootstrap.php';
require_once __DIR__ . '/../libs/tour_media_derivatives_lib.php';
require_once __DIR__ . '/../libs/tour_auto_map_lib.php';
require_once __DIR__ . '/../libs/tour_auto_links_lib.php';
require_once __DIR__ . '/../libs/tour_stitching_lib.php';

const DETECTOR_BINARY = '/home/makler/web/tools/apriltag_detector_cpp/build/detect_markers';

function parse_limit(array $argv): int {
    $limit = 1;
    foreach ($argv as $arg) {
        if (strpos($arg, '--limit=') === 0) {
            $value = (int)substr($arg, 8);
            if ($value > 0) {
                $limit = $value;
            }
        }
        if ($arg === '--once') {
            $limit = 1;
        }
    }
    return $limit;
}

function resolve_session_uuid(array $session): string {
    $uuid = trim((string)($session['app_session_uuid'] ?? ''));
    if ($uuid === '') {
        $uuid = 'session_' . (int)$session['id'];
    }
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $uuid) ?: ('session_' . (int)$session['id']);
}

function abs_storage_path(string $relativePath): string {
    return APP_STORAGE_DIR . '/' . ltrim($relativePath, '/');
}

function append_log(string $path, string $line): void {
    @file_put_contents($path, sprintf("[%s] %s\n", date('c'), $line), FILE_APPEND);
}

function fail_job(mysqli $dbcnx, int $jobId, string $error): void {
    $stmt = $dbcnx->prepare("UPDATE processing_jobs SET status='FAILED', metric_status='FAILED', markers_detected_count=0, warning_text=NULL, error_text=?, updated_at=NOW(6) WHERE id=?");
    $stmt->bind_param('si', $error, $jobId);
    $stmt->execute();
    $stmt->close();
}

function process_one_job(mysqli $dbcnx): bool {
    $job = $dbcnx->query("SELECT * FROM processing_jobs WHERE status = 'QUEUED' ORDER BY id ASC LIMIT 1")->fetch_assoc();
    if (!$job) {
        echo "No queued jobs\n";
        return false;
    }

    $jobId = (int)$job['id'];
    $sessionId = (int)$job['session_id'];

    $stmt = $dbcnx->prepare("UPDATE processing_jobs SET status='PROCESSING', updated_at=NOW(6) WHERE id=?");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $stmt->close();

    $stmt = $dbcnx->prepare("SELECT * FROM capture_sessions WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        fail_job($dbcnx, $jobId, 'Capture session not found');
        return true;
    }


    $photoStmt = $dbcnx->prepare("SELECT id, original_storage_path FROM photo_points WHERE session_id = ? AND upload_state = 'UPLOADED'");
    $photoStmt->bind_param('i', $sessionId);
    $photoStmt->execute();
    $photoRows = $photoStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $photoStmt->close();

    foreach ($photoRows as $pp) {
        $origRel = trim((string)($pp['original_storage_path'] ?? ''));
        if ($origRel === '') { continue; }
        $origAbs = abs_storage_path($origRel);
        if (!is_file($origAbs)) { continue; }
        $imgInfo = @getimagesize($origAbs);
        $w = (int)($imgInfo[0] ?? 0);
        $h = (int)($imgInfo[1] ?? 0);
$rawRel = str_replace('/photos/originals/', '/photos/raw_dualfisheye/', $origRel);
$rawAbs = abs_storage_path($rawRel);

$isKnownX4Raw = ($w === 5888 && $h === 2944);
$isAlreadyStitched = is_file($rawAbs) && ($w === 4096 && $h === 2048);

if (!$isKnownX4Raw || $isAlreadyStitched) {
    continue;
}

        if (!is_dir(dirname($rawAbs))) { @mkdir(dirname($rawAbs), 0775, true); }
        if (!is_file($rawAbs)) { @copy($origAbs, $rawAbs); }
        $st = tour_stitch_dualfisheye_to_equirect($rawAbs, $origAbs);
        if (!$st['ok']) {
            fail_job($dbcnx, $jobId, 'Dual-fisheye stitching failed');
            append_log(APP_STORAGE_DIR . '/logs/marker_worker_cron.log', 'stitch failed session_id=' . $sessionId . ' photo_point_id=' . (int)$pp['id'] . ' details=' . json_encode($st, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            return true;
        }
    }
    $derivSummary = tour_ensure_session_media_derivatives($dbcnx, $sessionId, true, true);
    if (!$derivSummary['ok']) {
        append_log(APP_STORAGE_DIR . '/logs/marker_worker_cron.log', 'derivatives warning session_id=' . $sessionId . ' summary=' . json_encode($derivSummary, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    $sessionUuid = resolve_session_uuid($session);
    $baseDir = APP_STORAGE_DIR . '/processing/sessions/' . $sessionUuid;
    $framesBaseDir = $baseDir . '/frames';
    $detectionsDir = $baseDir . '/detections';
    $logsDir = $baseDir . '/logs';
    foreach ([$baseDir, $framesBaseDir, $detectionsDir, $logsDir] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
    $processingLog = $logsDir . '/processing.log';

    $stmt = $dbcnx->prepare("SELECT * FROM video_scans WHERE session_id = ? AND upload_state = 'UPLOADED'");
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $videos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $dbcnx->prepare("SELECT * FROM photo_points WHERE session_id = ? AND upload_state = 'UPLOADED'");
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $photos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $items = [];
    foreach ($photos as $photo) {
        $sourcePath = trim((string)($photo['original_storage_path'] ?? ''));
        if ($sourcePath === '') {
            continue;
        }
        $abs = abs_storage_path($sourcePath);
        if (!is_file($abs)) {
            append_log($processingLog, "missing photo file source_id=" . (int)$photo['id'] . " path={$abs}");
            continue;
        }
        $items[] = [
            'source_type' => 'PHOTO_POINT',
            'source_id' => (int)$photo['id'],
            'source_path' => $sourcePath,
            'absolute_path' => $abs,
        ];
    }
    foreach ($videos as $video) {
        $videoPath = trim((string)($video['storage_path'] ?? ''));
        if ($videoPath === '') {
            continue;
        }
        $absVideo = abs_storage_path($videoPath);
        if (!is_file($absVideo)) {
            append_log($processingLog, "missing video file source_id=" . (int)$video['id'] . " path={$absVideo}");
            continue;
        }

        $videoFramesDir = $framesBaseDir . '/video_' . (int)$video['id'];
        if (!is_dir($videoFramesDir)) {
            @mkdir($videoFramesDir, 0775, true);
        }

        $framePattern = $videoFramesDir . '/frame_%06d.jpg';
        $cmd = 'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($absVideo) . ' -vf fps=1 -q:v 2 ' . escapeshellarg($framePattern) . ' 2>&1';
        $output = [];
        $rc = 0;
        exec($cmd, $output, $rc);
        append_log($processingLog, 'ffmpeg cmd=' . $cmd);
        append_log($processingLog, 'ffmpeg rc=' . $rc . ' output=' . implode(" | ", $output));

        foreach (glob($videoFramesDir . '/frame_*.jpg') ?: [] as $frameAbsPath) {
            $baseName = basename($frameAbsPath);
            if (!preg_match('/frame_(\d+)\.jpg$/', $baseName, $m)) {
                continue;
            }
            $frameIndex = (int)$m[1];
            $items[] = [
                'source_type' => 'VIDEO_FRAME',
                'source_id' => (int)$video['id'],
                'source_path' => $videoPath,
                'absolute_path' => $frameAbsPath,
                'frame_index' => $frameIndex,
                'timestamp_ms' => $frameIndex * 1000,
            ];
        }
    }

    if (count($items) === 0) {
        fail_job($dbcnx, $jobId, 'No uploaded media found for marker processing');
        return true;
    }

    if (!is_file(DETECTOR_BINARY)) {
        fail_job($dbcnx, $jobId, 'AprilTag detector binary not found');
        return true;
    }

    $inputJsonPath = $detectionsDir . '/input_media.json';
    $outputJsonPath = $detectionsDir . '/detections.json';
    file_put_contents($inputJsonPath, json_encode(['session_id' => $sessionId, 'items' => $items], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    $detectorCmd = escapeshellarg(DETECTOR_BINARY)
        . ' --input-list ' . escapeshellarg($inputJsonPath)
        . ' --output ' . escapeshellarg($outputJsonPath)
        . ' --tag-family ' . escapeshellarg('tag36h11')
        . ' --valid-ids ' . escapeshellarg('1-30')
        . ' --marker-size-m ' . escapeshellarg('0.160')
        . ' 2>&1';

    $detectorOutput = [];
    $detectorRc = 0;
    exec($detectorCmd, $detectorOutput, $detectorRc);
    append_log($processingLog, 'detector cmd=' . $detectorCmd);
    append_log($processingLog, 'detector rc=' . $detectorRc . ' output=' . implode(" | ", $detectorOutput));

    if ($detectorRc !== 0) {
        fail_job($dbcnx, $jobId, trim(implode("\n", $detectorOutput)) ?: 'Detector failed');
        return true;
    }

    $decoded = json_decode((string)file_get_contents($outputJsonPath), true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        fail_job($dbcnx, $jobId, (string)($decoded['error'] ?? 'Invalid detector output'));
        return true;
    }

    $viewerWarning = null;

    $stmt = $dbcnx->prepare('DELETE FROM marker_detections WHERE session_id = ?');
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $stmt->close();

    $insert = $dbcnx->prepare('INSERT INTO marker_detections (session_id, source_type, source_id, source_path, frame_index, timestamp_ms, marker_kit_id, marker_dictionary, marker_id, marker_size_m, corners_json, center_x, center_y, confidence) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $uniqueIds = [];
    $totalDetections = 0;
    foreach (($decoded['detections'] ?? []) as $det) {
        $sourceType = (string)($det['source_type'] ?? '');
        $sourceId = (int)($det['source_id'] ?? 0);
        $sourcePath = (string)($det['source_path'] ?? '');
        $frameIndex = isset($det['frame_index']) && $det['frame_index'] !== null ? (int)$det['frame_index'] : null;
        $timestampMs = isset($det['timestamp_ms']) && $det['timestamp_ms'] !== null ? (int)$det['timestamp_ms'] : null;
        $markerKitId = 'maklertour_kit_v1';
        $markerDictionary = 'APRILTAG_36H11';
        $markerId = (int)($det['marker_id'] ?? 0);
        $markerSize = 0.1600;
        $cornersJson = json_encode($det['corners'] ?? [], JSON_UNESCAPED_SLASHES);
        $centerX = (float)($det['center_x'] ?? 0.0);
        $centerY = (float)($det['center_y'] ?? 0.0);
        $confidence = (float)($det['confidence'] ?? 0.0);

        $insert->bind_param('isisiissidsddd', $sessionId, $sourceType, $sourceId, $sourcePath, $frameIndex, $timestampMs, $markerKitId, $markerDictionary, $markerId, $markerSize, $cornersJson, $centerX, $centerY, $confidence);
        $insert->execute();
        $totalDetections++;
        $uniqueIds[$markerId] = true;
    }
    $insert->close();

    $uniqueCount = count($uniqueIds);
    $warning = null;
    $metric = 'NO_MARKERS';
    if ($uniqueCount >= 3) {
        $metric = 'METRIC_READY';
    } elseif ($uniqueCount >= 1) {
        $metric = 'PARTIAL_MARKERS';
        $warning = "Only {$uniqueCount} unique markers detected. Metric reconstruction may be unstable.";
    } else {
        $warning = 'No MaklerTour markers detected. Accurate geometry and dimensions are not guaranteed.';
    }

    if ($viewerWarning !== null) {
        $warning = $warning ? ($warning . ' ' . $viewerWarning) : $viewerWarning;
    }

    $stmt = $dbcnx->prepare("UPDATE processing_jobs SET status='PROCESSED', metric_status=?, markers_detected_count=?, warning_text=?, error_text=NULL, updated_at=NOW(6) WHERE id=?");
    $stmt->bind_param('sisi', $metric, $totalDetections, $warning, $jobId);
    $stmt->execute();
    $stmt->close();

    try {
        $autoMap = run_tour_auto_map($dbcnx, $sessionId, true, false);
        append_log($processingLog, 'Auto map after marker detection:');
        append_log($processingLog, 'algorithm=' . (string)($autoMap['algorithm'] ?? TOUR_AUTO_MAP_ALGORITHM));
        append_log($processingLog, 'positioned_count=' . (int)($autoMap['positioned_count'] ?? 0));
        append_log($processingLog, 'warnings=' . json_encode($autoMap['warnings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        append_log($processingLog, 'Auto map failed: ' . $e->getMessage());
        $autoWarning = 'Marker detection completed, but auto map failed: ' . $e->getMessage();
        $warning = $warning ? ($warning . ' ' . $autoWarning) : $autoWarning;
        $stmt = $dbcnx->prepare("UPDATE processing_jobs SET warning_text=?, updated_at=NOW(6) WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('si', $warning, $jobId);
            $stmt->execute();
            $stmt->close();
        }
    }

    try {
        $autoLinks = run_tour_auto_links($dbcnx, $sessionId, true, false);
        append_log($processingLog, 'Auto links after marker detection:');
        append_log($processingLog, 'algorithm=' . (string)($autoLinks['algorithm'] ?? TOUR_AUTO_LINKS_ALGORITHM));
        append_log($processingLog, 'created_count=' . (int)($autoLinks['created_count'] ?? 0));
        append_log($processingLog, 'skipped_count=' . (int)($autoLinks['skipped_count'] ?? 0));
        append_log($processingLog, 'warnings=' . json_encode($autoLinks['warnings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        append_log($processingLog, 'Auto links failed: ' . $e->getMessage());
        $autoWarning = 'Marker detection completed, but auto links failed: ' . $e->getMessage();
        $warning = $warning ? ($warning . ' ' . $autoWarning) : $autoWarning;
        $stmt = $dbcnx->prepare("UPDATE processing_jobs SET warning_text=?, updated_at=NOW(6) WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('si', $warning, $jobId);
            $stmt->execute();
            $stmt->close();
        }
    }

    echo "Job #{$jobId} processed: {$metric}, detections={$totalDetections}\n";
    return true;
}

$limit = parse_limit($argv);
for ($i = 0; $i < $limit; $i++) {
    if (!process_one_job($dbcnx)) {
        break;
    }
}