<?php
declare(strict_types=1);

require_once __DIR__ . '/auto_photo_prepare_lib.php';
require_once __DIR__ . '/sfm_settings_lib.php';

function auto_photo_sparse_fail(string $code): never
{
    throw new RuntimeException($code);
}

function auto_photo_sparse_output_root(): string
{
    return defined('AUTO_PHOTO_SPARSE_OUTPUT_ROOT')
        ? rtrim((string) AUTO_PHOTO_SPARSE_OUTPUT_ROOT, '/')
        : '/home/makler/web/remote_station/output';
}

function auto_photo_sparse_output_path(int $remoteJobId): string
{
    if ($remoteJobId <= 0) {
        auto_photo_sparse_fail('invalid_remote_job_id');
    }

    return auto_photo_sparse_output_root() . '/job_' . $remoteJobId;
}

function auto_photo_sparse_result_path(int $remoteJobId): string
{
    return auto_photo_sparse_output_path($remoteJobId) . '/result.json';
}

function auto_photo_sparse_log_path(int $remoteJobId): string
{
    return auto_photo_sparse_output_path($remoteJobId) . '/logs';
}

function auto_photo_sparse_remote_frames_path(int $prepareRemoteJobId): string
{
    if ($prepareRemoteJobId <= 0) {
        auto_photo_sparse_fail('invalid_prepare_remote_job_id');
    }

    return '/home/makler_storage/output/job_' . $prepareRemoteJobId . '/frames';
}

function auto_photo_sparse_regular_file(string $path, string $error): void
{
    $stat = @lstat($path);
    if (
        is_link($path)
        || $stat === false
        || (($stat['mode'] & 0170000) !== 0100000)
    ) {
        auto_photo_sparse_fail($error);
    }
}

function auto_photo_sparse_frames_directory(string $outputPath, int $expectedCount): string
{
    $framesPath = $outputPath . '/frames';
    $outputStat = @lstat($outputPath);
    $outputReal = realpath($outputPath);
    $framesReal = realpath($framesPath);
    $stat = @lstat($framesPath);

    if (
        $expectedCount <= 0
        || is_link($outputPath)
        || $outputStat === false
        || (($outputStat['mode'] & 0170000) !== 0040000)
        || $outputReal === false
        || $framesReal === false
        || is_link($framesPath)
        || $stat === false
        || (($stat['mode'] & 0170000) !== 0040000)
        || !str_starts_with(
            $framesReal,
            rtrim($outputReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        )
    ) {
        auto_photo_sparse_fail('unsafe_frames_directory');
    }

    $count = 0;
    foreach (new DirectoryIterator($framesReal) as $entry) {
        if ($entry->isDot()) {
            continue;
        }

        $name = $entry->getFilename();
        if (preg_match('/^frame_[0-9]{6}\.jpe?g$/', $name) !== 1) {
            auto_photo_sparse_fail('invalid_frames_entry');
        }

        auto_photo_sparse_regular_file(
            $entry->getPathname(),
            'invalid_frames_entry'
        );
        $count++;
    }

    if ($count !== $expectedCount) {
        auto_photo_sparse_fail('frames_count_mismatch');
    }

    return $framesReal;
}

function auto_photo_sparse_plan(array $parent): array
{
    $prepareJobId = (int) ($parent['id'] ?? 0);
    if ($prepareJobId <= 0) {
        auto_photo_sparse_fail('prepare_job_missing');
    }

    if (($parent['job_type'] ?? '') !== AUTO_PHOTO_PREPARE_JOB_TYPE) {
        auto_photo_sparse_fail('invalid_prepare_job_type');
    }

    if (($parent['status'] ?? '') !== 'DONE') {
        auto_photo_sparse_fail('prepare_not_done');
    }

    $prepareRemoteJobId = (int) ($parent['remote_job_id'] ?? 0);
    if ($prepareRemoteJobId <= 0) {
        auto_photo_sparse_fail('prepare_remote_job_missing');
    }

    $outputPath = auto_photo_sparse_output_path($prepareRemoteJobId);
    if ((string) ($parent['output_path'] ?? '') !== $outputPath) {
        auto_photo_sparse_fail('prepare_output_path_mismatch');
    }

    $resultPath = auto_photo_sparse_result_path($prepareRemoteJobId);
    if ((string) ($parent['result_json_path'] ?? '') !== $resultPath) {
        auto_photo_sparse_fail('prepare_result_path_mismatch');
    }

    auto_photo_sparse_regular_file($resultPath, 'prepare_result_missing');
    $result = json_decode((string) file_get_contents($resultPath), true);
    if (!is_array($result)) {
        auto_photo_sparse_fail('prepare_result_invalid');
    }

    if (
        ($result['schema_version'] ?? null) !== 1
        || ($result['job_type'] ?? '') !== AUTO_PHOTO_PREPARE_JOB_TYPE
        || ($result['status'] ?? '') !== 'DONE'
        || (int) ($result['remote_job_id'] ?? 0) !== $prepareRemoteJobId
        || (int) ($result['capture_bundle_id'] ?? 0) <= 0
        || trim((string) ($result['app_bundle_uuid'] ?? '')) === ''
        || (int) ($result['frames_count'] ?? 0) <= 0
        || ($result['frames_directory'] ?? '') !== 'frames'
        || !is_array($result['warnings'] ?? null)
    ) {
        auto_photo_sparse_fail('prepare_result_contract_invalid');
    }

    $inputImages = (int) $result['frames_count'];
    $localFramesPath = auto_photo_sparse_frames_directory(
        $outputPath,
        $inputImages
    );

    return [
        'prepare_job_id' => $prepareJobId,
        'prepare_remote_job_id' => $prepareRemoteJobId,
        'capture_bundle_id' => (int) $result['capture_bundle_id'],
        'app_bundle_uuid' => (string) $result['app_bundle_uuid'],
        'input_images' => $inputImages,
        'local_frames_path' => $localFramesPath,
        'input_path' => auto_photo_sparse_remote_frames_path(
            $prepareRemoteJobId
        ),
        'job_type' => 'COLMAP_SPARSE',
    ];
}

function auto_photo_sparse_settings_snapshot(): array
{
    $effective = sfm_merge_settings(
        sfm_system_defaults(),
        [],
        [],
        []
    );
    sfm_validate_settings($effective);

    return sfm_mode_parameters($effective, 'preview');
}

function auto_photo_sparse_parameters(array $plan): array
{
    return [
        'source_type' => 'auto_photo_prepare',
        'standalone_sparse' => true,
        'prepare_job_id' => (int) $plan['prepare_job_id'],
        'prepare_remote_job_id' => (int) $plan['prepare_remote_job_id'],
        'capture_bundle_id' => (int) $plan['capture_bundle_id'],
        'app_bundle_uuid' => (string) $plan['app_bundle_uuid'],
        'input_images' => (int) $plan['input_images'],
        'settings' => auto_photo_sparse_settings_snapshot(),
    ];
}

function auto_photo_sparse_parse_model_id(mixed $value, bool $allowMissing = false): ?int
{
    if ($allowMissing && $value === null) {
        return null;
    }
    if (is_int($value) && $value >= 0) {
        return $value;
    }
    if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
        return (int) $value;
    }
    auto_photo_sparse_fail('invalid_model_id');
}

function auto_photo_sparse_components(int $remoteJobId): array
{
    $path = auto_photo_sparse_output_path($remoteJobId)
        . '/colmap/sparse_components.json';
    auto_photo_sparse_regular_file($path, 'sparse_components_invalid');
    $components = json_decode((string) file_get_contents($path), true);
    if (!is_array($components) || !is_array($components['models'] ?? null)) {
        auto_photo_sparse_fail('sparse_components_invalid');
    }
    return $components;
}

function auto_photo_sparse_manifest_model_id(mixed $value): ?int
{
    if (is_int($value) && $value >= 0) {
        return $value;
    }
    if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
        return (int) $value;
    }
    return null;
}

function auto_photo_sparse_validate_model_id(array $components, int $modelId): array
{
    foreach ($components['models'] ?? [] as $model) {
        if (is_array($model)
            && auto_photo_sparse_manifest_model_id($model['model_id'] ?? null) === $modelId) {
            return $model;
        }
    }
    auto_photo_sparse_fail('sparse_model_not_found');
}

function auto_photo_sparse_validate_job_scope(
    mysqli $db,
    int $orderId,
    array $sparseJob
): array {
    $parameters = json_decode((string) ($sparseJob['parameters_json'] ?? ''), true);
    if (!is_array($parameters)) {
        auto_photo_sparse_fail('sparse_job_scope_invalid');
    }
    if ((int) ($sparseJob['order_id'] ?? 0) !== $orderId
        || (int) ($sparseJob['capture_session_id'] ?? 0) <= 0
        || !auto_photo_sparse_is_standalone_job($sparseJob)) {
        auto_photo_sparse_fail('standalone_photo_sparse_job_not_found');
    }

    $bundleId = $parameters['capture_bundle_id'] ?? null;
    $bundleUuid = $parameters['app_bundle_uuid'] ?? null;
    if (!is_int($bundleId) || $bundleId <= 0
        || !is_string($bundleUuid) || $bundleUuid === '') {
        auto_photo_sparse_fail('sparse_job_scope_invalid');
    }

    $sessionId = (int) ($sparseJob['capture_session_id'] ?? 0);
    $statement = $db->prepare(
        'SELECT * FROM capture_bundles WHERE id=? AND order_id=? AND capture_session_id=? LIMIT 1'
    );
    if (!$statement || !$statement->bind_param('iii', $bundleId, $orderId, $sessionId)
        || !$statement->execute()) {
        auto_photo_sparse_fail('capture_bundle_query_failed');
    }
    $result = $statement->get_result();
    $bundle = $result ? $result->fetch_assoc() : null;
    $statement->close();
    if (!is_array($bundle) || !hash_equals(
        (string) ($bundle['app_bundle_uuid'] ?? ''),
        $bundleUuid
    )) {
        auto_photo_sparse_fail('sparse_job_scope_invalid');
    }

    return ['parameters' => $parameters, 'bundle' => $bundle];
}

function auto_photo_sparse_validate_prepare_chain(
    mysqli $db,
    int $orderId,
    array $sparseJob,
    array $scope
): array {
    $parameters = $scope['parameters'] ?? null;
    $bundle = $scope['bundle'] ?? null;
    if (!is_array($parameters) || !is_array($bundle)) {
        auto_photo_sparse_fail('sparse_job_scope_invalid');
    }
    $prepareDbJobId = $parameters['prepare_job_id'] ?? null;
    $prepareRemoteJobId = $parameters['prepare_remote_job_id'] ?? null;
    if (!is_int($prepareDbJobId) || $prepareDbJobId <= 0
        || !is_int($prepareRemoteJobId) || $prepareRemoteJobId <= 0) {
        auto_photo_sparse_fail('prepare_chain_invalid');
    }

    $sessionId = (int) ($sparseJob['capture_session_id'] ?? 0);
    $type = AUTO_PHOTO_PREPARE_JOB_TYPE;
    $statement = $db->prepare(
        'SELECT * FROM sfm_remote_jobs WHERE id=? AND order_id=? AND capture_session_id=? AND job_type=? LIMIT 1'
    );
    if (!$statement || !$statement->bind_param(
        'iiis', $prepareDbJobId, $orderId, $sessionId, $type
    ) || !$statement->execute()) {
        auto_photo_sparse_fail('prepare_job_query_failed');
    }
    $result = $statement->get_result();
    $prepareJob = $result ? $result->fetch_assoc() : null;
    $statement->close();
    if (!is_array($prepareJob)
        || (int) ($prepareJob['id'] ?? 0) !== $prepareDbJobId
        || (int) ($prepareJob['order_id'] ?? 0) !== $orderId
        || (int) ($prepareJob['capture_session_id'] ?? 0) !== $sessionId
        || (string) ($prepareJob['job_type'] ?? '') !== $type
        || (int) ($prepareJob['remote_job_id'] ?? 0) !== $prepareRemoteJobId
        || (int) ($sparseJob['parent_remote_job_id'] ?? 0) !== $prepareRemoteJobId) {
        auto_photo_sparse_fail('prepare_chain_invalid');
    }

    $plan = auto_photo_sparse_plan($prepareJob);
    if ((int) $plan['capture_bundle_id'] !== (int) $bundle['id']
        || !hash_equals(
            (string) $bundle['app_bundle_uuid'],
            (string) $plan['app_bundle_uuid']
        )) {
        auto_photo_sparse_fail('prepare_chain_invalid');
    }

    return [
        'parameters' => $parameters,
        'bundle' => $bundle,
        'prepare_job' => $prepareJob,
        'plan' => $plan,
    ];
}

function auto_photo_sparse_parse_cli(array $argv): array
{
    $values = [];
    $flags = [];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run' || $argument === '--enqueue') {
            if (isset($flags[$argument])) {
                auto_photo_sparse_fail('invalid_cli_mode');
            }
            $flags[$argument] = true;
            continue;
        }

        if (preg_match('/^--(prepare-job-id|status)=([0-9]+)$/', $argument, $m)) {
            if (isset($values[$m[1]])) {
                auto_photo_sparse_fail('invalid_cli_mode');
            }
            $values[$m[1]] = (int) $m[2];
            continue;
        }

        auto_photo_sparse_fail('unknown_cli_argument');
    }

    $hasStatus = isset($values['status']);
    $hasPrepare = isset($values['prepare-job-id']);
    $hasDryRun = isset($flags['--dry-run']);
    $hasEnqueue = isset($flags['--enqueue']);

    if (
        $hasStatus
        && !$hasPrepare
        && !$hasDryRun
        && !$hasEnqueue
        && $values['status'] > 0
    ) {
        return ['mode' => 'status', 'job_id' => $values['status']];
    }

    if (
        !$hasStatus
        && $hasPrepare
        && ($hasDryRun xor $hasEnqueue)
        && $values['prepare-job-id'] > 0
    ) {
        return [
            'mode' => $hasDryRun ? 'dry-run' : 'enqueue',
            'prepare_job_id' => $values['prepare-job-id'],
        ];
    }

    auto_photo_sparse_fail('invalid_cli_mode');
}

function auto_photo_sparse_is_standalone_job(array $job): bool
{
    if ((string) ($job['job_type'] ?? '') !== 'COLMAP_SPARSE') {
        return false;
    }

    $parameters = sfm_json_array((string) ($job['parameters_json'] ?? '{}'));

    return ($parameters['source_type'] ?? '') === 'auto_photo_prepare'
        && ($parameters['standalone_sparse'] ?? false) === true;
}
