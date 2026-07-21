<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

foreach (
    [
        __DIR__ . '/../configs/connectDB.php',
        '/home/makler/web/configs/connectDB.php',
    ] as $file
) {
    if (is_file($file)) {
        require_once $file;
        break;
    }
}

foreach (
    [
        __DIR__ . '/../configs/app.php',
        '/home/makler/web/configs/app.php',
    ] as $file
) {
    if (is_file($file)) {
        require_once $file;
        break;
    }
}

if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) {
    fwrite(STDERR, "ERROR: db_unavailable\n");
    exit(1);
}

require_once __DIR__ . '/../libs/sfm_remote_job_lib.php';
require_once __DIR__ . '/../libs/auto_photo_sparse_lib.php';

function auto_photo_sparse_usage(): never
{
    fwrite(
        STDERR,
        "Usage:\n"
        . "  php tools/auto_photo_sparse.php --prepare-job-id=<ID> --dry-run\n"
        . "  php tools/auto_photo_sparse.php --prepare-job-id=<ID> --enqueue\n"
        . "  php tools/auto_photo_sparse.php --status=<SPARSE_DB_JOB_ID>\n"
    );
    exit(2);
}

try {
    $command = auto_photo_sparse_parse_cli($argv);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    auto_photo_sparse_usage();
}

if ($command['mode'] === 'status') {
    $jobId = (int) $command['job_id'];
    $statement = $dbcnx->prepare(
        "SELECT id,remote_job_id,job_type,status,progress_percent,message,"
        . "output_path,result_json_path,parameters_json "
        . "FROM sfm_remote_jobs WHERE id=? AND job_type='COLMAP_SPARSE' LIMIT 1"
    );
    if (!$statement) {
        fwrite(STDERR, 'ERROR: status_prepare_failed: ' . $dbcnx->error . "\n");
        exit(2);
    }

    $statement->bind_param('i', $jobId);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!$row) {
        fwrite(STDERR, "ERROR: sparse_job_not_found\n");
        exit(2);
    }

    echo json_encode(
        $row,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . PHP_EOL;
    exit(0);
}

$prepareJobId = (int) $command['prepare_job_id'];

try {
    if ($command['mode'] === 'dry-run') {
        $statement = $dbcnx->prepare(
            'SELECT * FROM sfm_remote_jobs WHERE id=? LIMIT 1'
        );
        if (!$statement) {
            auto_photo_sparse_fail('prepare_parent_query_failed');
        }
        $statement->bind_param('i', $prepareJobId);
        $statement->execute();
        $parent = $statement->get_result()->fetch_assoc() ?: [];
        $statement->close();

        $plan = auto_photo_sparse_plan($parent);
        echo json_encode(
            ['status' => 'DRY_RUN', 'plan' => $plan],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . PHP_EOL;
        exit(0);
    }

    $dbcnx->begin_transaction();

    $statement = $dbcnx->prepare(
        'SELECT * FROM sfm_remote_jobs WHERE id=? LIMIT 1 FOR UPDATE'
    );
    if (!$statement) {
        auto_photo_sparse_fail('prepare_parent_query_failed');
    }
    $statement->bind_param('i', $prepareJobId);
    $statement->execute();
    $parent = $statement->get_result()->fetch_assoc() ?: [];
    $statement->close();

    $plan = auto_photo_sparse_plan($parent);
    $prepareRemoteJobId = (int) $plan['prepare_remote_job_id'];

    $statement = $dbcnx->prepare(
        "SELECT id FROM sfm_remote_jobs "
        . "WHERE job_type='COLMAP_SPARSE' "
        . "AND parent_remote_job_id=? "
        . "AND status IN ('QUEUED','RUNNING') "
        . "AND JSON_VALID(parameters_json) "
        . "AND JSON_UNQUOTE(JSON_EXTRACT(parameters_json,'$.source_type'))="
        . "'auto_photo_prepare' "
        . "AND JSON_UNQUOTE(JSON_EXTRACT(parameters_json,'$.standalone_sparse'))="
        . "'true' LIMIT 1"
    );
    if (!$statement) {
        auto_photo_sparse_fail('duplicate_query_failed');
    }
    $statement->bind_param('i', $prepareRemoteJobId);
    $statement->execute();
    $active = $statement->get_result()->fetch_assoc();
    $statement->close();

    if ($active) {
        auto_photo_sparse_fail('active_standalone_sparse_exists');
    }

    $remoteJobId = sfm_job_id($dbcnx);
    $outputPath = auto_photo_sparse_output_path($remoteJobId);
    $resultPath = auto_photo_sparse_result_path($remoteJobId);
    $logPath = auto_photo_sparse_log_path($remoteJobId);
    $parametersJson = json_encode(
        auto_photo_sparse_parameters($plan),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($parametersJson === false) {
        auto_photo_sparse_fail('parameters_json_failed');
    }

    $orderId = (int) ($parent['order_id'] ?? 0);
    $captureSessionId = (int) ($parent['capture_session_id'] ?? 0);
    if ($orderId <= 0 || $captureSessionId <= 0) {
        auto_photo_sparse_fail('prepare_scope_invalid');
    }

    $jobType = 'COLMAP_SPARSE';
    $message = 'Standalone sparse from AUTO-B02 prepare';
    $inputPath = (string) $plan['input_path'];

    $statement = $dbcnx->prepare(
        "INSERT INTO sfm_remote_jobs "
        . "(order_id,capture_session_id,job_type,remote_job_id,"
        . "parent_remote_job_id,input_path,output_path,status,"
        . "progress_percent,message,result_json_path,log_path,parameters_json) "
        . "VALUES (?,?,?,?,?,?,?,'QUEUED',0,?,?,?,?)"
    );
    if (!$statement) {
        auto_photo_sparse_fail('sparse_insert_prepare_failed');
    }

    $statement->bind_param(
        'iisiissssss',
        $orderId,
        $captureSessionId,
        $jobType,
        $remoteJobId,
        $prepareRemoteJobId,
        $inputPath,
        $outputPath,
        $message,
        $resultPath,
        $logPath,
        $parametersJson
    );

    if (!$statement->execute()) {
        $error = $statement->error;
        $statement->close();
        auto_photo_sparse_fail('sparse_insert_failed: ' . $error);
    }

    $jobId = (int) $statement->insert_id;
    $statement->close();

    if ($jobId <= 0 || !$dbcnx->commit()) {
        auto_photo_sparse_fail('sparse_job_commit_failed');
    }

    echo json_encode(
        [
            'status' => 'QUEUED',
            'id' => $jobId,
            'remote_job_id' => $remoteJobId,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch (Throwable $e) {
    try {
        $dbcnx->rollback();
    } catch (Throwable) {
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(2);
}
