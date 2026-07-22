<?php

declare(strict_types=1);

require_once __DIR__ . '/auto_photo_prepare_lib.php';
require_once __DIR__ . '/sfm_remote_job_lib.php';

function auto_photo_prepare_web_bundle_id(mixed $value): int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if (is_string($value) && preg_match('/^(?:[1-9][0-9]*)$/', $value) === 1) {
        return (int) $value;
    }
    throw new InvalidArgumentException('capture_bundle_id_invalid');
}

function auto_photo_prepare_web_processing_status(string $status): bool
{
    return in_array($status, ['UPLOADED', 'PROCESSING', 'READY', 'COMPLETED'], true);
}

function auto_photo_prepare_web_validate_bundle(array $row, int $orderId): void
{
    foreach (['id', 'order_id', 'capture_session_id', 'app_bundle_uuid', 'capture_type', 'filename', 'storage_path', 'size_bytes', 'status'] as $key) {
        if (!array_key_exists($key, $row)) {
            throw new RuntimeException('capture_bundle_invalid');
        }
    }
    if ((int) $row['id'] <= 0 || (int) $row['order_id'] !== $orderId) {
        throw new RuntimeException('capture_bundle_scope_invalid');
    }
    if ((int) $row['capture_session_id'] <= 0 || trim((string) $row['app_bundle_uuid']) === '') {
        throw new RuntimeException('capture_bundle_invalid');
    }
    if ((string) $row['capture_type'] !== AUTO_PHOTO_BUNDLE_CAPTURE_TYPE) {
        throw new RuntimeException('unsupported_capture_type');
    }
    if (!auto_photo_prepare_web_processing_status((string) $row['status'])) {
        throw new RuntimeException('capture_bundle_status_invalid');
    }
    if (trim((string) $row['filename']) === '' || trim((string) $row['storage_path']) === '' || (int) $row['size_bytes'] <= 0) {
        throw new RuntimeException('capture_bundle_invalid');
    }
}

function auto_photo_prepare_web_plan_validate(array $plan, array $row): int
{
    $frames = $plan['frames'] ?? null;
    $parameters = $plan['parameters'] ?? null;
    $matches = is_array($frames) && is_array($parameters)
        && (int) ($plan['capture_bundle_id'] ?? 0) === (int) $row['id']
        && (int) ($plan['order_id'] ?? 0) === (int) $row['order_id']
        && (int) ($plan['capture_session_id'] ?? 0) === (int) $row['capture_session_id']
        && (string) ($plan['app_bundle_uuid'] ?? '') === (string) $row['app_bundle_uuid']
        && ($parameters['source_type'] ?? null) === 'auto_photo_bundle'
        && ($parameters['pipeline_mode'] ?? null) === 'prepare'
        && ($parameters['already_selected_frames'] ?? null) === true
        && (int) ($parameters['input_images'] ?? -1) === count($frames);
    if (!$matches) {
        throw new RuntimeException('prepare_plan_mismatch');
    }
    if (count($frames) <= 0) {
        throw new RuntimeException('prepare_plan_empty');
    }
    return count($frames);
}

function auto_photo_prepare_web_same_bundle(array $before, array $locked): bool
{
    foreach (['id', 'order_id', 'capture_session_id', 'app_bundle_uuid', 'capture_type', 'storage_path', 'size_bytes'] as $key) {
        if (($before[$key] ?? null) !== ($locked[$key] ?? null)) {
            return false;
        }
    }
    return true;
}

function auto_photo_prepare_web_start_bundle(mysqli $db, int $orderId, mixed $rawCaptureBundleId, ?callable $insertIdReader = null, ?callable $remoteJobIdFactory = null): array
{
    $bundleId = auto_photo_prepare_web_bundle_id($rawCaptureBundleId);
    if ($orderId <= 0) {
        throw new RuntimeException('order_id_invalid');
    }

    $bundle = auto_photo_bundle_load_row($db, $bundleId);
    auto_photo_prepare_web_validate_bundle($bundle, $orderId);
    $archivePath = auto_photo_bundle_resolve_archive_path($bundle);
    $index = auto_photo_bundle_build_index_from_row($bundle);
    if (($index['validation_status'] ?? null) !== 'VALID' || !empty($index['blocking_errors']) || (int) ($index['photos_count_actual'] ?? 0) <= 0) {
        throw new RuntimeException('bundle_index_invalid');
    }
    auto_photo_bundle_write_index_atomic($index, auto_photo_bundle_index_cache_path($bundle, $archivePath));

    $materialization = auto_photo_bundle_materialize_from_row($bundle);
    if (($materialization['status'] ?? null) !== 'READY' || (int) ($materialization['photos_count'] ?? 0) <= 0) {
        throw new RuntimeException('materialization_not_ready');
    }
    $plan = auto_photo_prepare_plan($bundle);
    $inputImages = auto_photo_prepare_web_plan_validate($plan, $bundle);

    $inTransaction = false;
    try {
        if (!$db->begin_transaction()) {
            throw new RuntimeException('prepare_transaction_failed');
        }
        $inTransaction = true;

        $locked = auto_photo_prepare_web_locked_bundle($db, $bundleId);
        auto_photo_prepare_web_validate_bundle($locked, $orderId);
        if (!auto_photo_prepare_web_same_bundle($bundle, $locked)) {
            throw new RuntimeException('capture_bundle_changed');
        }
        $lockedPlan = auto_photo_prepare_plan($locked);
        $inputImages = auto_photo_prepare_web_plan_validate($lockedPlan, $locked);
        $parameters = $lockedPlan['parameters'];

        $duplicate = auto_photo_prepare_web_duplicate($db, $orderId, $locked, $bundleId);
        if ($duplicate !== null) {
            if ((int) ($duplicate['id'] ?? 0) <= 0 || (int) ($duplicate['remote_job_id'] ?? 0) <= 0) {
                throw new RuntimeException('prepare_duplicate_result_invalid');
            }
            if (!$db->commit()) {
                throw new RuntimeException('prepare_commit_failed');
            }
            $inTransaction = false;
            return ['duplicate' => true, 'capture_bundle_id' => $bundleId, 'prepare_db_job_id' => (int) $duplicate['id'], 'prepare_remote_job_id' => (int) $duplicate['remote_job_id'], 'input_images' => $inputImages];
        }

        $remoteJobId = $remoteJobIdFactory !== null ? (int) $remoteJobIdFactory($db) : sfm_job_id($db);
        if ($remoteJobId <= 0 || auto_photo_prepare_web_remote_exists($db, $remoteJobId)) {
            throw new RuntimeException('prepare_remote_job_id_invalid');
        }
        $parametersJson = json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($parametersJson === false) {
            throw new RuntimeException('prepare_parameters_encode_failed');
        }
        $outputPath = '/home/makler/web/remote_station/output/job_' . $remoteJobId;
        $resultPath = $outputPath . '/result.json';
        $jobType = AUTO_PHOTO_PREPARE_JOB_TYPE;
        $message = 'Auto photo prepare queued';
        $statement = $db->prepare('INSERT INTO sfm_remote_jobs (order_id,capture_session_id,job_type,remote_job_id,output_path,status,progress_percent,message,result_json_path,parameters_json) VALUES (?,?,?,?,?,\'QUEUED\',0,?,?,?)');
        if (!$statement || !$statement->bind_param('iisissss', $orderId, $locked['capture_session_id'], $jobType, $remoteJobId, $outputPath, $message, $resultPath, $parametersJson) || !$statement->execute()) {
            if ($statement) $statement->close();
            throw new RuntimeException('prepare_insert_failed');
        }
        $statement->close();
        $insertedId = $insertIdReader !== null ? (int) $insertIdReader($db) : (int) $db->insert_id;
        if ($insertedId <= 0) {
            throw new RuntimeException('prepare_insert_id_invalid');
        }
        if (!$db->commit()) {
            throw new RuntimeException('prepare_commit_failed');
        }
        $inTransaction = false;
        return ['duplicate' => false, 'capture_bundle_id' => $bundleId, 'prepare_db_job_id' => $insertedId, 'prepare_remote_job_id' => $remoteJobId, 'input_images' => $inputImages];
    } catch (Throwable $e) {
        if ($inTransaction) {
            try { $db->rollback(); } catch (Throwable) {}
        }
        throw $e;
    }
}

function auto_photo_prepare_web_locked_bundle(mysqli $db, int $bundleId): array
{
    $sql = 'SELECT id,order_id,capture_session_id,app_bundle_uuid,capture_type,filename,storage_path,size_bytes,status,created_at,updated_at FROM capture_bundles WHERE id=? LIMIT 1 FOR UPDATE';
    $statement = $db->prepare($sql);
    if (!$statement || !$statement->bind_param('i', $bundleId) || !$statement->execute() || !($result = $statement->get_result())) {
        if ($statement) $statement->close();
        throw new RuntimeException('capture_bundle_lock_failed');
    }
    $row = $result->fetch_assoc();
    $statement->close();
    if (!is_array($row)) throw new RuntimeException('capture_bundle_not_found');
    return $row;
}

function auto_photo_prepare_web_duplicate(mysqli $db, int $orderId, array $bundle, int $bundleId): ?array
{
    $sql = "SELECT id,remote_job_id FROM sfm_remote_jobs WHERE order_id=? AND capture_session_id=? AND job_type=? AND status IN ('QUEUED','RUNNING','DONE') AND JSON_VALID(parameters_json) AND JSON_UNQUOTE(JSON_EXTRACT(parameters_json,'$.source_type'))='auto_photo_bundle' AND JSON_UNQUOTE(JSON_EXTRACT(parameters_json,'$.pipeline_mode'))='prepare' AND JSON_UNQUOTE(JSON_EXTRACT(parameters_json,'$.capture_bundle_id'))=? AND JSON_UNQUOTE(JSON_EXTRACT(parameters_json,'$.app_bundle_uuid'))=? LIMIT 1 FOR UPDATE";
    $statement = $db->prepare($sql);
    $sessionId = (int) $bundle['capture_session_id'];
    $type = AUTO_PHOTO_PREPARE_JOB_TYPE;
    $bundleIdText = (string) $bundleId;
    $uuid = (string) $bundle['app_bundle_uuid'];
    if (!$statement || !$statement->bind_param('iisss', $orderId, $sessionId, $type, $bundleIdText, $uuid) || !$statement->execute() || !($result = $statement->get_result())) {
        if ($statement) $statement->close();
        throw new RuntimeException('prepare_duplicate_query_failed');
    }
    $row = $result->fetch_assoc();
    $statement->close();
    return is_array($row) ? $row : null;
}

function auto_photo_prepare_web_remote_exists(mysqli $db, int $remoteJobId): bool
{
    $statement = $db->prepare('SELECT id FROM sfm_remote_jobs WHERE remote_job_id=? LIMIT 1');
    if (!$statement || !$statement->bind_param('i', $remoteJobId) || !$statement->execute() || !($result = $statement->get_result())) {
        if ($statement) $statement->close();
        throw new RuntimeException('prepare_remote_job_query_failed');
    }
    $row = $result->fetch_assoc();
    $statement->close();
    return is_array($row);
}
