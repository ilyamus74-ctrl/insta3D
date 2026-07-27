<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$connectCandidates = [
    '/home/makler/web/configs/connectDB.php',
    __DIR__ . '/../configs/connectDB.php',
];
foreach ($connectCandidates as $file) {
    if (is_file($file)) {
        require_once $file;
        break;
    }
}
if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) {
    fwrite(STDERR, "ERROR: DB unavailable\n");
    exit(1);
}

$opts = getopt('', [
    'pipeline-run-id:',
    'parent-remote-job-id:',
    'dry-run',
]);
$pipelineId = (int)($opts['pipeline-run-id'] ?? 0);
$parentRemote = (int)($opts['parent-remote-job-id'] ?? 0);
$dryRun = isset($opts['dry-run']);
if ($pipelineId <= 0 || $parentRemote <= 0) {
    fwrite(
        STDERR,
        "Usage: php sfm_resume_dense_parent.php "
        . "--pipeline-run-id=N --parent-remote-job-id=N [--dry-run]\n"
    );
    exit(2);
}

$st = $dbcnx->prepare(
    "SELECT * FROM sfm_remote_jobs
     WHERE pipeline_run_id=? AND remote_job_id=?
       AND job_type IN (
         'COLMAP_RECONSTRUCTION_PREVIEW',
         'COLMAP_RECONSTRUCTION_HQ'
       )
     LIMIT 1"
);
if (!$st) {
    throw new RuntimeException($dbcnx->error);
}
$st->bind_param('ii', $pipelineId, $parentRemote);
$st->execute();
$parent = $st->get_result()->fetch_assoc();
$st->close();
if (!$parent) {
    fwrite(STDERR, "ERROR: reconstruction parent not found\n");
    exit(1);
}

$st = $dbcnx->prepare(
    "SELECT
       id,remote_job_id,chunk_index,chunk_count,retry_count,
       status,progress_percent,message,updated_at
     FROM sfm_remote_jobs
     WHERE parent_remote_job_id=?
       AND job_type='COLMAP_DENSE_CHUNK'
     ORDER BY chunk_index,id"
);
if (!$st) {
    throw new RuntimeException($dbcnx->error);
}
$st->bind_param('i', $parentRemote);
$st->execute();
$rs = $st->get_result();
$chunks = [];
$doneIndexes = [];
$active = [];
$retryRows = [];
while ($row = $rs->fetch_assoc()) {
    $chunks[] = $row;
    $status = strtoupper((string)$row['status']);
    $index = (int)$row['chunk_index'];
    if ($status === 'DONE') {
        $doneIndexes[$index] = true;
    }
    if (in_array($status, ['QUEUED','RUNNING'], true)) {
        $active[] = $row;
    }
    if ((int)$row['retry_count'] > 0) {
        $retryRows[] = $row;
    }
}
$st->close();

if (!$chunks) {
    fwrite(STDERR, "ERROR: no dense chunks found\n");
    exit(1);
}
if (!$retryRows) {
    fwrite(STDERR, "ERROR: no automatic retry row found\n");
    exit(1);
}
$recoverableRetry = false;
foreach ($retryRows as $retryRow) {
    if (in_array(
        strtoupper((string)$retryRow['status']),
        ['DONE','QUEUED','RUNNING'],
        true
    )) {
        $recoverableRetry = true;
        break;
    }
}
if (!$recoverableRetry) {
    fwrite(
        STDERR,
        "ERROR: automatic retry is neither active nor completed; "
        . "do not recover parent automatically\n"
    );
    exit(1);
}

$chunkCount = max(array_map(
    static fn(array $row): int => max(
        (int)$row['chunk_count'],
        (int)$row['chunk_index'] + 1
    ),
    $chunks
));
$result = [
    'pipeline_run_id' => $pipelineId,
    'parent_remote_job_id' => $parentRemote,
    'parent_db_job_id' => (int)$parent['id'],
    'parent_status_before' => $parent['status'],
    'chunk_count' => $chunkCount,
    'done_unique_chunks' => count($doneIndexes),
    'active_chunks' => $active,
    'retry_rows' => $retryRows,
    'dry_run' => $dryRun,
];

if (!$dryRun) {
    $dbcnx->begin_transaction();
    try {
        $st = $dbcnx->prepare(
            "UPDATE sfm_remote_jobs
             SET status='RUNNING_CHUNKS',
                 message='Recovered parent after automatic dense retry',
                 updated_at=NOW(6)
             WHERE id=?"
        );
        if (!$st) {
            throw new RuntimeException($dbcnx->error);
        }
        $parentId = (int)$parent['id'];
        $st->bind_param('i', $parentId);
        $st->execute();
        $st->close();

        $st = $dbcnx->prepare(
            "UPDATE sfm_pipeline_runs
             SET status='RUNNING',
                 stage='DENSE',
                 message='Dense retry parent recovered',
                 finished_at=NULL,
                 error_json=NULL,
                 updated_at=NOW(6)
             WHERE id=?"
        );
        if (!$st) {
            throw new RuntimeException($dbcnx->error);
        }
        $st->bind_param('i', $pipelineId);
        $st->execute();
        $st->close();
        $dbcnx->commit();
        $result['status'] = 'RECOVERED';
    } catch (Throwable $e) {
        $dbcnx->rollback();
        throw $e;
    }
}

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . "\n";
