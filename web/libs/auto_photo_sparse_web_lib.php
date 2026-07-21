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
