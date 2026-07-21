<?php
declare(strict_types=1);

require_once __DIR__ . '/auto_photo_sparse_lib.php';

function auto_photo_sparse_web_fail(string $code): never
{
    throw new RuntimeException($code);
}

function auto_photo_sparse_web_parse_sparse_db_id(mixed $value): int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
        return (int) $value;
    }
    auto_photo_sparse_web_fail('invalid_sparse_db_job_id');
}

function auto_photo_sparse_web_load_locked_job(mysqli $db, int $orderId, int $sparseDbJobId): array
{
    $statement = $db->prepare("SELECT * FROM sfm_remote_jobs WHERE id=? AND order_id=? AND job_type='COLMAP_SPARSE' LIMIT 1 FOR UPDATE");
    if (!$statement || !$statement->bind_param('ii', $sparseDbJobId, $orderId) || !$statement->execute()) {
        auto_photo_sparse_web_fail('standalone_photo_sparse_job_query_failed');
    }
    $result = $statement->get_result();
    if (!$result) {
        $statement->close();
        auto_photo_sparse_web_fail('standalone_photo_sparse_job_query_failed');
    }
    $job = $result->fetch_assoc();
    $statement->close();
    if (!is_array($job)) {
        auto_photo_sparse_web_fail('standalone_photo_sparse_job_not_found');
    }
    return $job;
}

function auto_photo_sparse_web_load_related_jobs(
    mysqli $db,
    int $parentRemoteJobId
): array {
    if ($parentRemoteJobId <= 0) {
        auto_photo_sparse_web_fail('invalid_prepare_remote_job_id');
    }
    $statement = $db->prepare(
        "SELECT id, status, parent_remote_job_id, parameters_json
        FROM sfm_remote_jobs
        WHERE parent_remote_job_id=?
          AND job_type='COLMAP_SPARSE'
          AND JSON_VALID(parameters_json)
          AND JSON_UNQUOTE(JSON_EXTRACT(parameters_json, '$.source_type'))='auto_photo_prepare'
          AND JSON_UNQUOTE(JSON_EXTRACT(parameters_json, '$.standalone_sparse'))='true'
        FOR UPDATE"
    );
    if (!$statement || !$statement->bind_param('i', $parentRemoteJobId)
        || !$statement->execute()) {
        auto_photo_sparse_web_fail('related_sparse_jobs_query_failed');
    }
    $result = $statement->get_result();
    if (!$result) {
        $statement->close();
        auto_photo_sparse_web_fail('related_sparse_jobs_query_failed');
    }
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $statement->close();
    return $rows;
}

function auto_photo_sparse_web_enqueue_exhaustive(
    mysqli $db,
    int $orderId,
    mixed $rawSparseDbJobId,
    ?callable $insertIdReader = null,
    ?callable $remoteJobIdFactory = null
): array {
    $sourceSparseDbJobId = auto_photo_sparse_web_parse_sparse_db_id(
        $rawSparseDbJobId
    );
    try {
        if (!$db->begin_transaction()) {
            auto_photo_sparse_web_fail('exhaustive_retry_transaction_failed');
        }
        $sourceJob = auto_photo_sparse_web_load_locked_job(
            $db,
            $orderId,
            $sourceSparseDbJobId
        );
        $scope = auto_photo_sparse_validate_job_scope($db, $orderId, $sourceJob);
        $chain = auto_photo_sparse_validate_prepare_chain(
            $db,
            $orderId,
            $sourceJob,
            $scope
        );
        $parentRemoteJobId = (int) $sourceJob['parent_remote_job_id'];
        $relatedJobs = auto_photo_sparse_web_load_related_jobs(
            $db,
            $parentRemoteJobId
        );
        $policy = auto_photo_sparse_retry_policy($sourceJob, $relatedJobs);
        if ($policy['allowed'] !== true) {
            throw new RuntimeException((string) $policy['reason']);
        }
        $parameters = auto_photo_sparse_parameters($chain['plan'], true);
        if (($parameters['retry_mode'] ?? null) !== 'exhaustive'
            || ($parameters['settings']['sparse']['matcher'] ?? null) !== 'exhaustive') {
            auto_photo_sparse_web_fail('exhaustive_retry_parameters_invalid');
        }
        $parametersJson = json_encode(
            $parameters,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (!is_string($parametersJson)) {
            auto_photo_sparse_web_fail('exhaustive_retry_parameters_encode_failed');
        }
        $newRemoteJobId = $remoteJobIdFactory !== null
            ? (int) $remoteJobIdFactory($db)
            : sfm_job_id($db);
        $outputPath = auto_photo_sparse_output_path($newRemoteJobId);
        $resultPath = auto_photo_sparse_result_path($newRemoteJobId);
        $logPath = auto_photo_sparse_log_path($newRemoteJobId);
        $captureSessionId = (int) $sourceJob['capture_session_id'];
        $jobType = 'COLMAP_SPARSE';
        $status = 'QUEUED';
        $progressPercent = 0;
        $message = 'Standalone exhaustive sparse retry from AUTO-B02 prepare';
        $statement = $db->prepare(
            'INSERT INTO sfm_remote_jobs '
            . '(order_id, capture_session_id, job_type, remote_job_id, '
            . 'parent_remote_job_id, input_path, output_path, status, '
            . 'progress_percent, message, result_json_path, log_path, '
            . 'parameters_json, pipeline_run_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        if (!$statement || !$statement->bind_param(
            'iisiisssissss',
            $orderId,
            $captureSessionId,
            $jobType,
            $newRemoteJobId,
            $parentRemoteJobId,
            $chain['plan']['input_path'],
            $outputPath,
            $status,
            $progressPercent,
            $message,
            $resultPath,
            $logPath,
            $parametersJson
        ) || !$statement->execute()) {
            auto_photo_sparse_web_fail('exhaustive_retry_insert_failed');
        }
        $statement->close();
        $newDbJobId = $insertIdReader !== null
            ? (int) $insertIdReader($db)
            : (int) $db->insert_id;
        if ($newDbJobId <= 0) {
            auto_photo_sparse_web_fail('exhaustive_retry_insert_id_invalid');
        }
        if (!$db->commit()) {
            auto_photo_sparse_web_fail('exhaustive_retry_commit_failed');
        }
        return [
            'sparse_db_job_id' => $newDbJobId,
            'remote_job_id' => $newRemoteJobId,
            'source_sparse_db_job_id' => $sourceSparseDbJobId,
        ];
    } catch (Throwable $e) {
        try {
            $db->rollback();
        } catch (Throwable) {
        }
        throw $e;
    }
}

function auto_photo_sparse_web_select_model(mysqli $db, int $orderId, mixed $rawSparseDbJobId, mixed $rawModelId): array
{
    $sparseDbJobId = auto_photo_sparse_web_parse_sparse_db_id($rawSparseDbJobId);
    $modelId = auto_photo_sparse_parse_model_id($rawModelId);
    try {
        if (!$db->begin_transaction()) {
            auto_photo_sparse_web_fail('sparse_model_transaction_failed');
        }
        $sparseJob = auto_photo_sparse_web_load_locked_job($db, $orderId, $sparseDbJobId);
        $scope = auto_photo_sparse_validate_job_scope($db, $orderId, $sparseJob);
        $chain = auto_photo_sparse_validate_prepare_chain($db, $orderId, $sparseJob, $scope);
        if (
            strtoupper((string) ($sparseJob['status'] ?? ''))
            !== 'DONE'
        ) {
            throw new RuntimeException('sparse_job_not_ready');
        }
        $components = auto_photo_sparse_components(
            (int) $sparseJob['remote_job_id']
        );
        auto_photo_sparse_validate_model_id($components, $modelId);
        $parameters = $chain['parameters'];
        $parameters['selected_model_id'] = $modelId;
        $parametersJson = json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($parametersJson)) {
            auto_photo_sparse_web_fail('sparse_parameters_encode_failed');
        }
        $statement = $db->prepare('UPDATE sfm_remote_jobs SET parameters_json=? WHERE id=? AND order_id=?');
        if (!$statement || !$statement->bind_param('sii', $parametersJson, $sparseDbJobId, $orderId) || !$statement->execute()) {
            auto_photo_sparse_web_fail('sparse_model_update_failed');
        }
        $statement->close();
        if (!$db->commit()) {
            auto_photo_sparse_web_fail('sparse_model_commit_failed');
        }
        return ['sparse_db_job_id' => $sparseDbJobId, 'model_id' => $modelId];
    } catch (Throwable $e) {
        try {
            $db->rollback();
        } catch (Throwable) {
        }
        throw $e;
    }
}
