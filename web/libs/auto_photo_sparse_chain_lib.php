<?php
declare(strict_types=1);

require_once __DIR__ . '/auto_photo_sparse_lib.php';
require_once __DIR__ . '/sfm_remote_job_lib.php';

/** Enqueues one standalone sparse job from a freshly locked completed prepare job. */
function auto_photo_sparse_chain_enqueue_from_prepare(
    mysqli $db,
    int $prepareDbJobId,
    ?callable $insertIdReader = null,
    ?callable $remoteJobIdFactory = null
): array {
    if ($prepareDbJobId <= 0) {
        throw new RuntimeException('prepare_job_id_invalid');
    }

    $inTransaction = false;
    try {
        if (!$db->begin_transaction()) {
            throw new RuntimeException('prepare_parent_query_failed');
        }
        $inTransaction = true;

        $statement = $db->prepare(
            'SELECT id,order_id,capture_session_id,job_type,remote_job_id,'
            . 'output_path,result_json_path,status,parameters_json '
            . 'FROM sfm_remote_jobs WHERE id=? AND job_type=? LIMIT 1 FOR UPDATE'
        );
        if (!$statement) {
            throw new RuntimeException('prepare_parent_query_failed');
        }
        $type = AUTO_PHOTO_PREPARE_JOB_TYPE;
        $statement->bind_param('is', $prepareDbJobId, $type);
        if (!$statement->execute() || !($result = $statement->get_result())) {
            $statement->close();
            throw new RuntimeException('prepare_parent_query_failed');
        }
        $prepareJob = $result->fetch_assoc();
        $statement->close();
        if (!is_array($prepareJob)) {
            throw new RuntimeException('prepare_parent_missing');
        }

        $plan = auto_photo_sparse_plan($prepareJob);
        $orderId = (int) $prepareJob['order_id'];
        $sessionId = (int) $prepareJob['capture_session_id'];
        if ($orderId <= 0 || $sessionId <= 0) {
            throw new RuntimeException('prepare_scope_invalid');
        }
        $prepareRemoteJobId = (int) $plan['prepare_remote_job_id'];
        $prepareIdText = (string) $prepareDbJobId;
        $prepareRemoteText = (string) $prepareRemoteJobId;
        $bundleIdText = (string) $plan['capture_bundle_id'];
        $uuid = (string) $plan['app_bundle_uuid'];

        $statement = $db->prepare(
            "SELECT id,remote_job_id,status FROM sfm_remote_jobs "
            . "WHERE order_id=? AND capture_session_id=? AND job_type='COLMAP_SPARSE' "
            . "AND pipeline_run_id IS NULL AND parent_remote_job_id=? "
            . "AND status IN ('QUEUED','RUNNING','DONE') AND JSON_VALID(parameters_json) "
            . "AND JSON_UNQUOTE(JSON_EXTRACT(parameters_json,'$.source_type'))='auto_photo_prepare' "
            . "AND JSON_UNQUOTE(JSON_EXTRACT(parameters_json,'$.standalone_sparse'))='true' "
            . "AND JSON_UNQUOTE(JSON_EXTRACT(parameters_json,'$.prepare_job_id'))=? "
            . "AND JSON_UNQUOTE(JSON_EXTRACT(parameters_json,'$.prepare_remote_job_id'))=? "
            . "AND JSON_UNQUOTE(JSON_EXTRACT(parameters_json,'$.capture_bundle_id'))=? "
            . "AND JSON_UNQUOTE(JSON_EXTRACT(parameters_json,'$.app_bundle_uuid'))=? LIMIT 1 FOR UPDATE"
        );
        if (!$statement) {
            throw new RuntimeException('sparse_duplicate_query_failed');
        }
        $statement->bind_param('iiissss', $orderId, $sessionId, $prepareRemoteJobId, $prepareIdText, $prepareRemoteText, $bundleIdText, $uuid);
        if (!$statement->execute() || !($result = $statement->get_result())) {
            $statement->close();
            throw new RuntimeException('sparse_duplicate_query_failed');
        }
        $duplicate = $result->fetch_assoc();
        $statement->close();
        if (is_array($duplicate)) {
            if ((int) ($duplicate['id'] ?? 0) <= 0 || (int) ($duplicate['remote_job_id'] ?? 0) <= 0) {
                throw new RuntimeException('sparse_duplicate_result_invalid');
            }
            if (!$db->commit()) {
                throw new RuntimeException('sparse_commit_failed');
            }
            $inTransaction = false;
            return ['duplicate'=>true, 'prepare_db_job_id'=>$prepareDbJobId, 'prepare_remote_job_id'=>$prepareRemoteJobId, 'sparse_db_job_id'=>(int)$duplicate['id'], 'sparse_remote_job_id'=>(int)$duplicate['remote_job_id'], 'capture_bundle_id'=>(int)$plan['capture_bundle_id'], 'input_images'=>(int)$plan['input_images']];
        }

        $remoteJobId = $remoteJobIdFactory !== null ? (int) $remoteJobIdFactory($db) : sfm_job_id($db);
        if ($remoteJobId <= 0 || $remoteJobId === $prepareRemoteJobId) {
            throw new RuntimeException('sparse_remote_job_id_invalid');
        }
        $parametersJson = json_encode(auto_photo_sparse_parameters($plan), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($parametersJson === false) {
            throw new RuntimeException('sparse_parameters_encode_failed');
        }
        $outputPath = auto_photo_sparse_output_path($remoteJobId);
        $resultPath = auto_photo_sparse_result_path($remoteJobId);
        $logPath = auto_photo_sparse_log_path($remoteJobId);
        $inputPath = (string) $plan['input_path'];
        $jobType = 'COLMAP_SPARSE';
        $message = 'Standalone sparse auto-queued after Auto Photo prepare';
        $statement = $db->prepare(
            "INSERT INTO sfm_remote_jobs (order_id,capture_session_id,pipeline_run_id,job_type,remote_job_id,parent_remote_job_id,input_path,output_path,status,progress_percent,message,result_json_path,log_path,parameters_json) VALUES (?,?,NULL,?,?,?,?,?,'QUEUED',0,?,?,?,?)"
        );
        if (!$statement) {
            throw new RuntimeException('sparse_insert_failed');
        }
        $statement->bind_param('iisiissssss', $orderId, $sessionId, $jobType, $remoteJobId, $prepareRemoteJobId, $inputPath, $outputPath, $message, $resultPath, $logPath, $parametersJson);
        if (!$statement->execute()) {
            $statement->close();
            throw new RuntimeException('sparse_insert_failed');
        }
        $statement->close();
        $sparseDbJobId = $insertIdReader !== null ? (int) $insertIdReader($db) : (int) $db->insert_id;
        if ($sparseDbJobId <= 0) {
            throw new RuntimeException('sparse_insert_id_invalid');
        }
        if (!$db->commit()) {
            throw new RuntimeException('sparse_commit_failed');
        }
        $inTransaction = false;
        return ['duplicate'=>false, 'prepare_db_job_id'=>$prepareDbJobId, 'prepare_remote_job_id'=>$prepareRemoteJobId, 'sparse_db_job_id'=>$sparseDbJobId, 'sparse_remote_job_id'=>$remoteJobId, 'capture_bundle_id'=>(int)$plan['capture_bundle_id'], 'input_images'=>(int)$plan['input_images']];
    } catch (Throwable $e) {
        if ($inTransaction) {
            try { $db->rollback(); } catch (Throwable) {}
        }
        throw $e;
    }
}
