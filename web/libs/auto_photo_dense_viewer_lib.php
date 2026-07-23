<?php
declare(strict_types=1);

require_once __DIR__ . '/auto_photo_dense_download_scope_lib.php';

function auto_photo_dense_viewer_can_view(
    array $row,
    int $userId,
    string $role
): bool {
    $orderStatus = (string) ($row['order_status'] ?? $row['status'] ?? '');

    return $role === 'ADMIN'
        || (int) ($row['broker_id'] ?? 0) === $userId
        || ($role === 'OPERATOR' && (
            (int) ($row['operator_id'] ?? 0) === $userId
            || (
                (int) ($row['is_published'] ?? 0) === 1
                && $orderStatus === 'NEW'
                && ($row['operator_id'] ?? null) === null
            )
        ));
}

function auto_photo_dense_viewer_vertex_count(string $path): int
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return 0;
    }
    $header = (string) fread($handle, 4096);
    fclose($handle);

    return preg_match('/^element vertex ([1-9][0-9]*)\r?$/m', $header, $match) === 1
        ? (int) $match[1]
        : 0;
}

function auto_photo_dense_viewer_payload(array $job, array $resolved): ?array
{
    $dbJobId = auto_photo_dense_download_positive_int($job['id'] ?? null);
    $remoteJobId = auto_photo_dense_download_positive_int(
        $job['remote_job_id'] ?? null
    );
    $orderId = auto_photo_dense_download_positive_int($job['order_id'] ?? null);
    $sessionId = auto_photo_dense_download_positive_int(
        $job['capture_session_id'] ?? null
    );
    $path = (string) ($resolved['path'] ?? '');
    $modelId = auto_photo_sparse_manifest_model_id($resolved['model_id'] ?? null);
    $sparseRemoteJobId = auto_photo_dense_download_positive_int(
        $resolved['sparse_remote_job_id'] ?? null
    );

    if (
        $dbJobId === null
        || $remoteJobId === null
        || $orderId === null
        || $sessionId === null
        || $modelId === null
        || $sparseRemoteJobId === null
        || $path === ''
        || !is_file($path)
        || !is_readable($path)
    ) {
        return null;
    }

    return [
        'ok' => true,
        'is_auto_photo_dense' => true,
        'is_merge' => false,
        'title' => 'Auto Photo Dense Preview · модель ' . $modelId,
        'warning' => 'Диагностическое облако Preview 640.',
        'source_video_filename' => 'Auto Photo standalone sparse',
        'order_id' => $orderId,
        'capture_session_id' => $sessionId,
        'video_scan_id' => null,
        'pipeline_run_id' => null,
        'pipeline_mode' => 'standalone Auto Photo dense preview',
        'status' => (string) ($job['status'] ?? ''),
        'summary' => [
            'points_count' => auto_photo_dense_viewer_vertex_count($path),
            'camera_poses_count' => 0,
            'keyframe_points_count' => 0,
            'camera_trajectory_available' => false,
        ],
        'artifacts' => [],
        'sparse' => ['available' => false],
        'dense' => [
            'available' => true,
            'db_job_id' => $dbJobId,
            'remote_job_id' => $remoteJobId,
            'sparse_remote_job_id' => $sparseRemoteJobId,
            'model_id' => $modelId,
            'file_size_bytes' => (int) filesize($path),
            'fused_ply_url' => '/api/sfm_remote_job_status.php?job_id='
                . $dbJobId . '&file=ply',
        ],
        'mesh' => ['available' => false],
    ];
}
