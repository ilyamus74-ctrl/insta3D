<?php
declare(strict_types=1);

function ensure_viewer_panoramas_for_session(mysqli $dbcnx, int $sessionId, string $processingLog): array {
    $summary = [
        'created_count' => 0,
        'existing_count' => 0,
        'failed_count' => 0,
        'errors' => [],
    ];

    $stmt = $dbcnx->prepare("SELECT id, original_storage_path FROM photo_points WHERE session_id = ? ORDER BY id ASC");
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $pointId = (int)$row['id'];
        $originalStoragePath = trim((string)($row['original_storage_path'] ?? ''));

        if ($originalStoragePath === '') {
            append_log($processingLog, "viewer warn: photo_point={$pointId} has empty original_storage_path");
            continue;
        }

        $originalFullPath = APP_STORAGE_DIR . '/' . ltrim($originalStoragePath, '/');
        $viewerStoragePath = str_replace('/photos/originals/', '/photos/viewer/', $originalStoragePath);
        $viewerFullPath = APP_STORAGE_DIR . '/' . ltrim($viewerStoragePath, '/');

        append_log($processingLog, "viewer source={$originalFullPath}");
        append_log($processingLog, "viewer target={$viewerFullPath}");

        if (!is_file($originalFullPath)) {
            $summary['failed_count']++;
            $summary['errors'][] = "photo_point={$pointId}: original file not found";
            append_log($processingLog, "viewer error: photo_point={$pointId} original missing");
            continue;
        }

        if (is_file($viewerFullPath) && filesize($viewerFullPath) > 0) {
            $summary['existing_count']++;
            append_log($processingLog, "viewer existing: photo_point={$pointId} size=" . (int)filesize($viewerFullPath));
            continue;
        }

        $viewerDir = dirname($viewerFullPath);
        if (!is_dir($viewerDir)) {
            @mkdir($viewerDir, 0775, true);
        }

        $cmd = 'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($originalFullPath) . ' -vf scale=4096:2048 -q:v 3 ' . escapeshellarg($viewerFullPath) . ' 2>&1';
        $output = [];
        $rc = 0;
        exec($cmd, $output, $rc);

        append_log($processingLog, 'viewer ffmpeg cmd=' . $cmd);
        append_log($processingLog, 'viewer ffmpeg rc=' . $rc . ' output=' . implode(' | ', $output));

        if ($rc !== 0 || !is_file($viewerFullPath) || filesize($viewerFullPath) <= 0) {
            $summary['failed_count']++;
            $summary['errors'][] = "photo_point={$pointId}: ffmpeg failed rc={$rc}";
            append_log($processingLog, "viewer error: photo_point={$pointId} create failed");
            continue;
        }

        @chmod($viewerFullPath, 0664);
        @chown($viewerFullPath, 'apache');
        @chgrp($viewerFullPath, 'apache');

        $size = @getimagesize($viewerFullPath);
        if (!is_array($size) || (int)$size[0] !== 4096 || (int)$size[1] !== 2048) {
            $summary['failed_count']++;
            $summary['errors'][] = "photo_point={$pointId}: invalid viewer resolution";
            append_log($processingLog, "viewer error: photo_point={$pointId} invalid resolution");
            continue;
        }

        $summary['created_count']++;
        append_log($processingLog, "viewer created: photo_point={$pointId} size=" . (int)filesize($viewerFullPath));
    }

    return $summary;
}
