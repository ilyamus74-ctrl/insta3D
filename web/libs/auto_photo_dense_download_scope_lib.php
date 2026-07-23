<?php
declare(strict_types=1);

require_once __DIR__ . '/auto_photo_sparse_lib.php';

function auto_photo_dense_download_positive_int(mixed $value): ?int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
        return (int) $value;
    }
    return null;
}

function auto_photo_dense_download_is_candidate(array $job): bool
{
    if (!in_array(
        (string) ($job['job_type'] ?? ''),
        ['COLMAP_RECONSTRUCTION_PREVIEW', 'COLMAP_RECONSTRUCTION_HQ'],
        true
    )) {
        return false;
    }

    $parameters = json_decode((string) ($job['parameters_json'] ?? ''), true);
    if (!is_array($parameters)) {
        return false;
    }

    foreach ([
        'source_type',
        'standalone_auto_photo_dense',
        'dense_only',
        'sparse_db_job_id',
        'sparse_job_id',
        'sparse_remote_job_id',
        'model_id',
    ] as $key) {
        if (array_key_exists($key, $parameters)) {
            return true;
        }
    }

    return false;
}

function auto_photo_dense_download_valid_ply(string $file): bool
{
    $handle = @fopen($file, 'rb');
    if ($handle === false) {
        return false;
    }
    $header = (string) fread($handle, 4096);
    fclose($handle);

    if (
        strncmp($header, "ply\n", 4) !== 0
        && strncmp($header, "ply\r\n", 5) !== 0
    ) {
        return false;
    }

    return preg_match(
        '/^format (?:ascii|binary_little_endian) 1\.0\r?$/m',
        $header
    ) === 1
        && preg_match('/^element vertex ([1-9][0-9]*)\r?$/m', $header) === 1;
}

function auto_photo_dense_download_sparse_components(int $remoteJobId): ?array
{
    try {
        $jobDirectory = auto_photo_sparse_output_path($remoteJobId);
        $colmapDirectory = $jobDirectory . '/colmap';
        $manifestPath = $colmapDirectory . '/sparse_components.json';

        $jobStat = @lstat($jobDirectory);
        $colmapStat = @lstat($colmapDirectory);
        $manifestStat = @lstat($manifestPath);
        if (
            is_link($jobDirectory)
            || is_link($colmapDirectory)
            || is_link($manifestPath)
            || $jobStat === false
            || $colmapStat === false
            || $manifestStat === false
            || (($jobStat['mode'] & 0170000) !== 0040000)
            || (($colmapStat['mode'] & 0170000) !== 0040000)
            || (($manifestStat['mode'] & 0170000) !== 0100000)
            || (int) $manifestStat['size'] <= 0
            || (int) $manifestStat['size'] > 2097152
        ) {
            return null;
        }

        $realJob = realpath($jobDirectory);
        $realColmap = realpath($colmapDirectory);
        $realManifest = realpath($manifestPath);
        if (
            $realJob === false
            || $realColmap === false
            || $realManifest === false
            || dirname($realColmap) !== $realJob
            || dirname($realManifest) !== $realColmap
        ) {
            return null;
        }

        $components = json_decode(
            (string) file_get_contents($realManifest),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return is_array($components)
            && is_array($components['models'] ?? null)
            ? $components
            : null;
    } catch (Throwable) {
        return null;
    }
}

function auto_photo_dense_download_resolve(mysqli $db, array $job): ?array
{
    try {
        if (
            (string) ($job['job_type'] ?? '') !== 'COLMAP_RECONSTRUCTION_PREVIEW'
            || (string) ($job['status'] ?? '') !== 'DONE'
            || ($job['pipeline_run_id'] ?? null) !== null
            || (string) ($job['reconstruction_mode'] ?? '') !== 'preview'
        ) {
            return null;
        }

        $parameters = json_decode(
            (string) ($job['parameters_json'] ?? ''),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (
            !is_array($parameters)
            || ($parameters['source_type'] ?? null) !== 'auto_photo_sparse'
            || ($parameters['standalone_auto_photo_dense'] ?? null) !== true
            || ($parameters['dense_only'] ?? null) !== true
        ) {
            return null;
        }

        $sparseDbJobId = auto_photo_dense_download_positive_int(
            $parameters['sparse_db_job_id'] ?? null
        );
        $sparseJobId = auto_photo_dense_download_positive_int(
            $parameters['sparse_job_id'] ?? null
        );
        $sparseRemoteJobId = auto_photo_dense_download_positive_int(
            $parameters['sparse_remote_job_id'] ?? null
        );
        $modelId = auto_photo_sparse_manifest_model_id(
            $parameters['model_id'] ?? null
        );
        $orderId = auto_photo_dense_download_positive_int(
            $job['order_id'] ?? null
        );
        $captureSessionId = auto_photo_dense_download_positive_int(
            $job['capture_session_id'] ?? null
        );
        $denseRemoteJobId = auto_photo_dense_download_positive_int(
            $job['remote_job_id'] ?? null
        );

        if (
            $sparseDbJobId === null
            || $sparseJobId === null
            || $sparseRemoteJobId === null
            || $modelId === null
            || $orderId === null
            || $captureSessionId === null
            || $denseRemoteJobId === null
            || $denseRemoteJobId === $sparseRemoteJobId
            || (int) ($job['parent_remote_job_id'] ?? 0) !== $sparseRemoteJobId
            || $sparseJobId !== $sparseRemoteJobId
        ) {
            return null;
        }

        $statement = $db->prepare(
            'SELECT * FROM sfm_remote_jobs WHERE id=? LIMIT 1'
        );
        if (
            !$statement
            || !$statement->bind_param('i', $sparseDbJobId)
            || !$statement->execute()
        ) {
            return null;
        }
        $result = $statement->get_result();
        $sparse = $result ? $result->fetch_assoc() : null;
        $statement->close();

        if (
            !is_array($sparse)
            || (int) ($sparse['id'] ?? 0) !== $sparseDbJobId
            || (int) ($sparse['order_id'] ?? 0) !== $orderId
            || (int) ($sparse['capture_session_id'] ?? 0) !== $captureSessionId
            || (string) ($sparse['job_type'] ?? '') !== 'COLMAP_SPARSE'
            || (int) ($sparse['remote_job_id'] ?? 0) !== $sparseRemoteJobId
            || ($sparse['pipeline_run_id'] ?? null) !== null
            || (string) ($sparse['status'] ?? '') !== 'DONE'
            || (string) ($sparse['output_path'] ?? '')
                !== auto_photo_sparse_output_path($sparseRemoteJobId)
        ) {
            return null;
        }

        $scope = auto_photo_sparse_validate_job_scope(
            $db,
            $orderId,
            $sparse
        );
        $sparseParameters = $scope['parameters'] ?? null;
        $bundle = $scope['bundle'] ?? null;
        if (!is_array($sparseParameters) || !is_array($bundle)) {
            return null;
        }

        $prepareDbJobId = auto_photo_dense_download_positive_int(
            $sparseParameters['prepare_job_id'] ?? null
        );
        $prepareRemoteJobId = auto_photo_dense_download_positive_int(
            $sparseParameters['prepare_remote_job_id'] ?? null
        );
        if (
            $prepareDbJobId === null
            || $prepareRemoteJobId === null
            || (int) ($sparse['parent_remote_job_id'] ?? 0)
                !== $prepareRemoteJobId
        ) {
            return null;
        }

        $prepareType = AUTO_PHOTO_PREPARE_JOB_TYPE;
        $statement = $db->prepare(
            'SELECT * FROM sfm_remote_jobs '
            . 'WHERE id=? AND order_id=? AND capture_session_id=? '
            . 'AND job_type=? LIMIT 1'
        );
        if (
            !$statement
            || !$statement->bind_param(
                'iiis',
                $prepareDbJobId,
                $orderId,
                $captureSessionId,
                $prepareType
            )
            || !$statement->execute()
        ) {
            return null;
        }
        $result = $statement->get_result();
        $prepare = $result ? $result->fetch_assoc() : null;
        $statement->close();

        if (
            !is_array($prepare)
            || (int) ($prepare['id'] ?? 0) !== $prepareDbJobId
            || (int) ($prepare['order_id'] ?? 0) !== $orderId
            || (int) ($prepare['capture_session_id'] ?? 0) !== $captureSessionId
            || (string) ($prepare['job_type'] ?? '') !== $prepareType
            || (int) ($prepare['remote_job_id'] ?? 0) !== $prepareRemoteJobId
            || ($prepare['pipeline_run_id'] ?? null) !== null
            || (string) ($prepare['status'] ?? '') !== 'DONE'
            || (string) ($prepare['output_path'] ?? '')
                !== auto_photo_sparse_output_path($prepareRemoteJobId)
        ) {
            return null;
        }

        $prepareParameters = json_decode(
            (string) ($prepare['parameters_json'] ?? ''),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (
            !is_array($prepareParameters)
            || ($prepareParameters['source_type'] ?? null)
                !== 'auto_photo_bundle'
            || ($prepareParameters['pipeline_mode'] ?? null) !== 'prepare'
            || ($prepareParameters['already_selected_frames'] ?? null) !== true
            || ($prepareParameters['capture_bundle_id'] ?? null)
                !== (int) $bundle['id']
            || ($prepareParameters['app_bundle_uuid'] ?? null)
                !== (string) $bundle['app_bundle_uuid']
            || (int) ($prepareParameters['input_images'] ?? 0) <= 0
        ) {
            return null;
        }

        $components = auto_photo_dense_download_sparse_components(
            $sparseRemoteJobId
        );
        if ($components === null) {
            return null;
        }
        auto_photo_sparse_validate_model_id($components, $modelId);

        $base = auto_photo_sparse_output_path($denseRemoteJobId);
        $mergedDirectory = $base . '/merged';
        $expected = $mergedDirectory . '/merged_fused.ply';
        $baseStat = @lstat($base);
        $mergedStat = @lstat($mergedDirectory);
        $fileStat = @lstat($expected);
        $realBase = realpath($base);
        $realMerged = realpath($mergedDirectory);
        $realFile = realpath($expected);

        if (
            !hash_equals($expected, (string) ($job['output_path'] ?? ''))
            || is_link($base)
            || is_link($mergedDirectory)
            || is_link($expected)
            || $baseStat === false
            || $mergedStat === false
            || $fileStat === false
            || (($baseStat['mode'] & 0170000) !== 0040000)
            || (($mergedStat['mode'] & 0170000) !== 0040000)
            || (($fileStat['mode'] & 0170000) !== 0100000)
            || (int) $fileStat['size'] <= 0
            || !is_readable($expected)
            || $realBase === false
            || $realMerged === false
            || $realFile === false
            || dirname($realMerged) !== $realBase
            || dirname($realFile) !== $realMerged
            || !auto_photo_dense_download_valid_ply($realFile)
        ) {
            return null;
        }

        return [
            'base' => $realBase,
            'path' => $realFile,
            'download_name' => 'job_' . $denseRemoteJobId
                . '_merged_fused.ply',
            'sparse_db_job_id' => $sparseDbJobId,
            'sparse_remote_job_id' => $sparseRemoteJobId,
            'model_id' => $modelId,
        ];
    } catch (Throwable) {
        return null;
    }
}
