<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require_once __DIR__ . '/../www/bootstrap.php';

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

function file_exists_safe(string $relativePath): bool {
    if ($relativePath === '') {
        return false;
    }
    $fullPath = APP_STORAGE_DIR . '/' . ltrim($relativePath, '/');
    return is_file($fullPath);
}

function write_processing_log(array $job, array $session, int $videosFound, int $photosFound, int $missingFiles): void {
    $sessionUuid = resolve_session_uuid($session);
    $baseDir = APP_STORAGE_DIR . '/processing/sessions/' . $sessionUuid;
    $framesDir = $baseDir . '/frames';
    $detectionsDir = $baseDir . '/detections';
    $logsDir = $baseDir . '/logs';

    foreach ([$baseDir, $framesDir, $detectionsDir, $logsDir] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    $line = sprintf(
        "[%s] job_id=%d session_id=%d videos_found=%d photos_found=%d missing_files=%d\n",
        date('c'),
        (int)$job['id'],
        (int)$job['session_id'],
        $videosFound,
        $photosFound,
        $missingFiles
    );
    @file_put_contents($logsDir . '/processing.log', $line, FILE_APPEND);
}

function process_one_job(mysqli $dbcnx): bool {
    $job = $dbcnx->query("SELECT * FROM processing_jobs WHERE status = 'QUEUED' ORDER BY id ASC LIMIT 1")->fetch_assoc();
    if (!$job) {
        echo "No queued jobs\n";
        return false;
    }

    $jobId = (int)$job['id'];
    $sessionId = (int)$job['session_id'];

    $stmt = $dbcnx->prepare("UPDATE processing_jobs SET status = 'PROCESSING', updated_at = NOW(6) WHERE id = ?");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $stmt->close();

    $stmt = $dbcnx->prepare("SELECT * FROM capture_sessions WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        $error = 'Capture session not found';
        $stmt = $dbcnx->prepare("UPDATE processing_jobs SET status='FAILED', metric_status='FAILED', error_text=?, updated_at=NOW(6) WHERE id=?");
        $stmt->bind_param('si', $error, $jobId);
        $stmt->execute();
        $stmt->close();
        echo "Job #{$jobId} failed: {$error}\n";
        return true;
    }

    $videos = [];
    $photos = [];
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

    $videosFound = 0;
    $photosFound = 0;
    $missingFiles = 0;

    foreach ($videos as $video) {
        $path = trim((string)($video['storage_path'] ?? ''));
        if (file_exists_safe($path)) {
            $videosFound++;
        } else {
            $missingFiles++;
        }
    }

    foreach ($photos as $photo) {
        $originalPath = trim((string)($photo['original_storage_path'] ?? ''));
        if ($originalPath !== '' && file_exists_safe($originalPath)) {
            $photosFound++;
        } else {
            $missingFiles++;
        }
    }

    write_processing_log($job, $session, $videosFound, $photosFound, $missingFiles);

    if (($videosFound + $photosFound) === 0) {
        $error = 'No uploaded media found for marker processing';
        $stmt = $dbcnx->prepare("UPDATE processing_jobs SET status='FAILED', metric_status='FAILED', markers_detected_count=0, warning_text=NULL, error_text=?, updated_at=NOW(6) WHERE id=?");
        $stmt->bind_param('si', $error, $jobId);
        $stmt->execute();
        $stmt->close();
        echo "Job #{$jobId} failed: {$error}\n";
        return true;
    }

    $warning = 'Marker detector is not connected yet. Uploaded media found, but marker detection was not executed.';
    $stmt = $dbcnx->prepare("UPDATE processing_jobs SET status='PROCESSED', metric_status='NO_MARKERS', markers_detected_count=0, warning_text=?, error_text=NULL, updated_at=NOW(6) WHERE id=?");
    $stmt->bind_param('si', $warning, $jobId);
    $stmt->execute();
    $stmt->close();

    echo "Job #{$jobId} processed: NO_MARKERS\n";
    return true;
}

$limit = parse_limit($argv);
for ($i = 0; $i < $limit; $i++) {
    $processed = process_one_job($dbcnx);
    if (!$processed) {
        break;
    }
}