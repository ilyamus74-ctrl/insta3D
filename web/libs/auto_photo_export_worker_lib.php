<?php
declare(strict_types=1);

function auto_photo_export_worker_parse_unsigned_id(
    mixed $value,
    bool $allowZero
): ?int {
    if (is_int($value)) {
        return ($allowZero ? $value >= 0 : $value > 0)
            ? $value
            : null;
    }

    if (!is_string($value)) {
        return null;
    }

    $pattern = $allowZero
        ? '/^(0|[1-9][0-9]*)$/'
        : '/^[1-9][0-9]*$/';

    if (!preg_match($pattern, $value)) {
        return null;
    }

    $parsed = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => $allowZero ? 0 : 1]]
    );

    if ($parsed === false || (string) $parsed !== $value) {
        return null;
    }

    return $parsed;
}

function auto_photo_export_worker_parse_positive_id(mixed $value): ?int
{
    return auto_photo_export_worker_parse_unsigned_id($value, false);
}

function auto_photo_export_worker_parse_model_id(mixed $value): ?int
{
    return auto_photo_export_worker_parse_unsigned_id($value, true);
}

function auto_photo_export_worker_parameters(array $job): ?array
{
    $parameters = json_decode(
        (string) ($job['parameters_json'] ?? ''),
        true
    );

    return is_array($parameters) ? $parameters : null;
}

function auto_photo_export_worker_is_photo_job(array $job): bool
{
    $parameters = auto_photo_export_worker_parameters($job);

    return is_array($parameters)
        && ($parameters['source_type'] ?? null) === 'auto_photo_sparse'
        && ($parameters['standalone_photo_export'] ?? null) === true;
}

function auto_photo_export_worker_plan(
    array $job,
    string $outputBase,
    string $scriptPath,
    string $configPath
): array {
    if (!auto_photo_export_worker_is_photo_job($job)) {
        return ['is_photo_export' => false];
    }

    $parameters = auto_photo_export_worker_parameters($job);
    if (!is_array($parameters)) {
        throw new RuntimeException('photo_export_parameters_invalid');
    }

    $exportRemoteJobId = auto_photo_export_worker_parse_positive_id(
        $job['remote_job_id'] ?? null
    );
    $orderId = auto_photo_export_worker_parse_positive_id(
        $job['order_id'] ?? null
    );
    $captureSessionId = auto_photo_export_worker_parse_positive_id(
        $job['capture_session_id'] ?? null
    );
    $parentRemoteJobId = auto_photo_export_worker_parse_positive_id(
        $job['parent_remote_job_id'] ?? null
    );
    $sparseRemoteJobId = auto_photo_export_worker_parse_positive_id(
        $parameters['sparse_job_id'] ?? null
    );
    $modelId = auto_photo_export_worker_parse_model_id(
        $parameters['model_id'] ?? null
    );

    if (
        $exportRemoteJobId === null
        || $orderId === null
        || $captureSessionId === null
        || $parentRemoteJobId === null
        || $sparseRemoteJobId === null
        || $modelId === null
    ) {
        throw new RuntimeException('photo_export_parameters_invalid');
    }

    if ($parentRemoteJobId !== $sparseRemoteJobId) {
        throw new RuntimeException('photo_export_parent_mismatch');
    }

    $normalizedOutputBase = rtrim($outputBase, '/');
    if ($normalizedOutputBase === '') {
        throw new RuntimeException('photo_export_path_invalid');
    }

    $exportRoot = $normalizedOutputBase . '/job_' . $exportRemoteJobId;
    $outputPath = $exportRoot . '/sparse_' . $modelId . '.ply';
    $logPath = $exportRoot . '/logs';

    if (
        !hash_equals($outputPath, (string) ($job['output_path'] ?? ''))
        || !hash_equals($logPath, (string) ($job['log_path'] ?? ''))
    ) {
        throw new RuntimeException('photo_export_path_invalid');
    }

    return [
        'is_photo_export' => true,
        'source_sparse_remote_id' => $sparseRemoteJobId,
        'model_id' => $modelId,
        'export_remote_job_id' => $exportRemoteJobId,
        'export_root' => $exportRoot,
        'output_path' => $outputPath,
        'log_path' => $logPath,
        'args' => [
            $scriptPath,
            $configPath,
            (string) $sparseRemoteJobId,
            (string) $modelId,
            $outputBase,
            $outputPath,
            (string) $exportRemoteJobId,
        ],
    ];
}

function auto_photo_export_worker_prepare_paths(
    array $plan,
    string $outputBase
): void {
    if (empty($plan['is_photo_export'])) {
        return;
    }

    $outputBaseReal = realpath($outputBase);
    if (
        $outputBaseReal === false
        || !is_dir($outputBaseReal)
        || is_link($outputBase)
    ) {
        throw new RuntimeException('photo_export_path_invalid');
    }

    $exportRemoteJobId = auto_photo_export_worker_parse_positive_id(
        $plan['export_remote_job_id'] ?? null
    );
    if ($exportRemoteJobId === null) {
        throw new RuntimeException('photo_export_parameters_invalid');
    }

    $expectedExportRoot = $outputBaseReal . '/job_' . $exportRemoteJobId;
    $expectedLogPath = $expectedExportRoot . '/logs';
    $exportRoot = (string) ($plan['export_root'] ?? '');
    $logPath = (string) ($plan['log_path'] ?? '');

    if (
        !hash_equals($expectedExportRoot, $exportRoot)
        || !hash_equals($expectedLogPath, $logPath)
        || is_link($exportRoot)
        || is_link($logPath)
    ) {
        throw new RuntimeException('photo_export_path_invalid');
    }

    if (
        !is_dir($exportRoot)
        && !mkdir($exportRoot, 0775)
        && !is_dir($exportRoot)
    ) {
        throw new RuntimeException('photo_export_directory_create_failed');
    }

    if (is_link($exportRoot)) {
        throw new RuntimeException('photo_export_path_invalid');
    }

    if (
        !is_dir($logPath)
        && !mkdir($logPath, 0775)
        && !is_dir($logPath)
    ) {
        throw new RuntimeException('photo_export_directory_create_failed');
    }

    if (is_link($logPath)) {
        throw new RuntimeException('photo_export_path_invalid');
    }

    $exportRootReal = realpath($exportRoot);
    $logPathReal = realpath($logPath);

    if (
        $exportRootReal === false
        || $logPathReal === false
        || !hash_equals($expectedExportRoot, $exportRootReal)
        || !hash_equals($expectedLogPath, $logPathReal)
    ) {
        throw new RuntimeException('photo_export_path_invalid');
    }
}

function auto_photo_export_worker_completion(
    array $plan,
    int $commandExitCode,
    int $legacyParentRemoteJobId = 0,
    int $legacyModelId = 0
): array {
    if (empty($plan['is_photo_export'])) {
        if ($commandExitCode !== 0) {
            return [
                'status' => 'ERROR',
                'progress' => 0,
                'message' => 'command_failed',
            ];
        }

        return [
            'status' => 'DONE',
            'progress' => 100,
            'message' => 'PLY exported: job_'
                . $legacyParentRemoteJobId
                . '/colmap/sparse/'
                . $legacyModelId
                . '/model.ply',
        ];
    }

    if ($commandExitCode !== 0) {
        return [
            'status' => 'ERROR',
            'progress' => 0,
            'message' => 'command_failed',
        ];
    }

    $outputPath = (string) ($plan['output_path'] ?? '');
    if (
        $outputPath === ''
        || !is_file($outputPath)
        || filesize($outputPath) <= 0
    ) {
        return [
            'status' => 'ERROR',
            'progress' => 0,
            'message' => 'photo_export_output_missing',
        ];
    }

    return [
        'status' => 'DONE',
        'progress' => 100,
        'message' => 'PLY exported: job_'
            . (int) $plan['export_remote_job_id']
            . '/sparse_'
            . (int) $plan['model_id']
            . '.ply',
    ];
}
