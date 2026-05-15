<?php
declare(strict_types=1);

function ensure_tour_viewer_derivatives(mysqli $dbcnx, int $sessionId): array {
    $summary = [
        'created_count' => 0,
        'existing_count' => 0,
        'failed_count' => 0,
        'errors' => [],
    ];

    $stmt = $dbcnx->prepare("SELECT id, original_storage_path FROM photo_points WHERE session_id = ? ORDER BY id ASC");
    if (!$stmt) {
        return ['created_count' => 0, 'existing_count' => 0, 'failed_count' => 1, 'errors' => ['prepare failed']];
    }
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $pointId = (int)$row['id'];
        $originalStoragePath = trim((string)($row['original_storage_path'] ?? ''));
        if ($originalStoragePath === '') {
            continue;
        }

        $source = APP_STORAGE_DIR . '/' . ltrim($originalStoragePath, '/');
        if (!is_file($source)) {
            $summary['failed_count']++;
            $summary['errors'][] = "photo_point={$pointId}: original missing";
            continue;
        }

        $targets = [
            [
                'path' => str_replace('/photos/originals/', '/photos/viewer_light/', $originalStoragePath),
                'scale' => '2048:1024',
                'qv' => 5,
            ],
            [
                'path' => str_replace('/photos/originals/', '/photos/viewer_hd/', $originalStoragePath),
                'scale' => '4096:2048',
                'qv' => 3,
           ],
        ];

        foreach ($targets as $target) {
            $targetPath = (string)$target['path'];
            if ($targetPath === $originalStoragePath) {
                $summary['failed_count']++;
                $summary['errors'][] = "photo_point={$pointId}: bad originals path";
                continue;
            }
            $targetFull = APP_STORAGE_DIR . '/' . ltrim($targetPath, '/');
            if (is_file($targetFull) && filesize($targetFull) > 0) {
                $summary['existing_count']++;
                continue;
            }

            $dir = dirname($targetFull);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $cmd = 'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($source)
                . ' -vf ' . escapeshellarg('scale=' . (string)$target['scale'])
                . ' -q:v ' . escapeshellarg((string)$target['qv'])
                . ' ' . escapeshellarg($targetFull) . ' 2>&1';
            $output = [];
            $rc = 0;
            exec($cmd, $output, $rc);
            if ($rc !== 0 || !is_file($targetFull) || filesize($targetFull) <= 0) {
                $summary['failed_count']++;
                $summary['errors'][] = "photo_point={$pointId}: create failed {$targetPath}";
                continue;
            }
            @chmod($targetFull, 0664);
            $summary['created_count']++;
        }
    }

    return $summary;
}
