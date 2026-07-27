#!/usr/bin/env php
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
foreach ($connectCandidates as $connectFile) {
    if (is_file($connectFile)) {
        require_once $connectFile;
        break;
    }
}

$appCandidates = [
    '/home/makler/web/configs/app.php',
    __DIR__ . '/../configs/app.php',
];
foreach ($appCandidates as $appFile) {
    if (is_file($appFile)) {
        require_once $appFile;
        break;
    }
}

if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) {
    fwrite(STDERR, "ERROR: failed to initialize mysqli\n");
    exit(1);
}

require_once dirname(__DIR__) . '/remote_station/sfm_pipeline.php';
require_once dirname(__DIR__) . '/libs/sfm_remote_job_lib.php';

const RECOVERY_ACTIVE_JOB_STATUSES = [
    'QUEUED',
    'RUNNING',
    'PLANNING',
    'RUNNING_CHUNKS',
    'MERGING',
];

function recovery_usage(): void
{
    $usage = <<<'TXT'
Usage:
  php web/tools/sfm_recover_partial_dense.php \
    --pipeline-run-id=71 \
    --model-id=0 \
    [--target-images-per-chunk=12] \
    [--max-images-per-chunk=16] \
    [--overlap-images=4] \
    [--num-src-images=4] \
    [--ram-reserve-mb=6000] \
    [--dry-run] [--force]

The command restores the cached sparse job to GrafikStation and queues a new
dense preview for one failed component. It does not repeat frame extraction or
sparse reconstruction.
TXT;
    fwrite(STDERR, $usage . "\n");
}

function recovery_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, 'ERROR: ' . $message . "\n");
    exit($code);
}

function recovery_positive_int(
    array $options,
    string $name,
    ?int $default = null
): int {
    $value = $options[$name] ?? $default;
    if (is_array($value)) {
        $value = end($value);
    }
    if ($value === null || $value === false || $value === '') {
        recovery_fail('missing --' . $name);
    }
    if (!is_numeric((string)$value) || (int)$value <= 0) {
        recovery_fail('--' . $name . ' must be a positive integer');
    }
    return (int)$value;
}

function recovery_nonnegative_int(array $options, string $name): int
{
    $value = $options[$name] ?? null;
    if (is_array($value)) {
        $value = end($value);
    }
    if ($value === null || $value === false || $value === '') {
        recovery_fail('missing --' . $name);
    }
    if (!is_numeric((string)$value) || (int)$value < 0) {
        recovery_fail('--' . $name . ' must be a non-negative integer');
    }
    return (int)$value;
}

function recovery_json(string $raw): array
{
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function recovery_fetch_pipeline(mysqli $db, int $pipelineRunId): array
{
    $st = $db->prepare(
        'SELECT * FROM sfm_pipeline_runs WHERE id=? LIMIT 1'
    );
    if (!$st) {
        recovery_fail('pipeline query prepare failed: ' . $db->error);
    }
    $st->bind_param('i', $pipelineRunId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        recovery_fail('pipeline run not found: ' . $pipelineRunId);
    }
    return $row;
}

function recovery_find_sparse_job(
    mysqli $db,
    int $pipelineRunId,
    array $runParameters
): int {
    $auto = is_array($runParameters['auto_components'] ?? null)
        ? $runParameters['auto_components']
        : [];
    $sparse = (int)($auto['sparse_remote_job_id'] ?? 0);
    if ($sparse > 0) {
        return $sparse;
    }

    $st = $db->prepare(
        "SELECT remote_job_id
         FROM sfm_remote_jobs
         WHERE pipeline_run_id=? AND job_type='COLMAP_SPARSE'
         ORDER BY id DESC LIMIT 1"
    );
    if (!$st) {
        recovery_fail('sparse job query prepare failed: ' . $db->error);
    }
    $st->bind_param('i', $pipelineRunId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    $sparse = (int)($row['remote_job_id'] ?? 0);
    if ($sparse <= 0) {
        recovery_fail('COLMAP_SPARSE job was not found for pipeline');
    }
    return $sparse;
}

function recovery_model_is_complete(string $modelDir): bool
{
    $bin = ['cameras.bin', 'images.bin', 'points3D.bin'];
    $txt = ['cameras.txt', 'images.txt', 'points3D.txt'];
    foreach ([$bin, $txt] as $set) {
        $complete = true;
        foreach ($set as $name) {
            if (!is_file($modelDir . '/' . $name)) {
                $complete = false;
                break;
            }
        }
        if ($complete) {
            return true;
        }
    }
    return false;
}

function recovery_dense_parent_matches(
    array $row,
    int $sparseRemoteJobId,
    int $modelId
): bool {
    $params = recovery_json((string)($row['parameters_json'] ?? '{}'));
    $rowSparse = (int)(
        $params['sparse_job_id']
        ?? $params['sparse_remote_job_id']
        ?? $row['parent_remote_job_id']
        ?? 0
    );
    return $rowSparse === $sparseRemoteJobId
        && (int)($params['model_id'] ?? -1) === $modelId;
}

function recovery_find_dense_parents(
    mysqli $db,
    int $pipelineRunId,
    int $sparseRemoteJobId,
    int $modelId
): array {
    $st = $db->prepare(
        "SELECT * FROM sfm_remote_jobs
         WHERE pipeline_run_id=?
           AND job_type IN (
             'COLMAP_RECONSTRUCTION_PREVIEW',
             'COLMAP_RECONSTRUCTION_HQ'
           )
         ORDER BY id DESC"
    );
    if (!$st) {
        recovery_fail('dense parent query prepare failed: ' . $db->error);
    }
    $st->bind_param('i', $pipelineRunId);
    $st->execute();
    $rs = $st->get_result();
    $matches = [];
    while ($row = $rs->fetch_assoc()) {
        if (recovery_dense_parent_matches(
            $row,
            $sparseRemoteJobId,
            $modelId
        )) {
            $matches[] = $row;
        }
    }
    $st->close();
    return $matches;
}

function recovery_copy_apriltag_report(
    string $localSparseJobDir,
    string $pipelineDir
): ?array {
    $candidates = [
        $localSparseJobDir . '/colmap/apriltag_assist.json',
        $localSparseJobDir . '/apriltag_assist.json',
    ];
    foreach ($candidates as $source) {
        if (!is_file($source) || filesize($source) <= 0) {
            continue;
        }
        if (!is_dir($pipelineDir)
            && !mkdir($pipelineDir, 0775, true)
            && !is_dir($pipelineDir)
        ) {
            recovery_fail('cannot create pipeline output: ' . $pipelineDir);
        }
        $destination = $pipelineDir . '/apriltag_assist.json';
        if (!copy($source, $destination)) {
            recovery_fail('cannot preserve AprilTag report: ' . $source);
        }
        $payload = recovery_json((string)file_get_contents($source));
        return [
            'path' => $destination,
            'status' => $payload['status'] ?? null,
            'sim3_applied' => (bool)($payload['sim3_applied'] ?? false),
            'models_before' => $payload['models_before'] ?? null,
            'models_after' => $payload['models_after'] ?? null,
            'aligned_components' => $payload['aligned_components'] ?? [],
            'unaligned_components' => $payload['unaligned_components'] ?? [],
            'components_stitched' => $payload['components_stitched'] ?? null,
            'warning_code' => $payload['warning_code'] ?? null,
        ];
    }
    return null;
}

function recovery_run_command(array $args): array
{
    $command = implode(' ', array_map('escapeshellarg', $args));
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    return [$code, implode("\n", $output), $command];
}

$options = getopt('', [
    'pipeline-run-id:',
    'model-id:',
    'target-images-per-chunk::',
    'max-images-per-chunk::',
    'overlap-images::',
    'num-src-images::',
    'ram-reserve-mb::',
    'dry-run',
    'force',
    'help',
]);
if (isset($options['help'])) {
    recovery_usage();
    exit(0);
}

$pipelineRunId = recovery_positive_int($options, 'pipeline-run-id');
$modelId = recovery_nonnegative_int($options, 'model-id');
$targetImages = recovery_positive_int(
    $options,
    'target-images-per-chunk',
    12
);
$maxImages = recovery_positive_int(
    $options,
    'max-images-per-chunk',
    16
);
$overlapImages = recovery_positive_int($options, 'overlap-images', 4);
$numSrcImages = recovery_positive_int($options, 'num-src-images', 4);
$ramReserveMb = recovery_positive_int($options, 'ram-reserve-mb', 6000);
$dryRun = isset($options['dry-run']);
$force = isset($options['force']);

if ($targetImages > $maxImages) {
    recovery_fail('target-images-per-chunk cannot exceed max-images-per-chunk');
}
if ($overlapImages >= $targetImages) {
    recovery_fail('overlap-images must be smaller than target-images-per-chunk');
}

ensure_sfm_pipeline_tables($dbcnx);
$run = recovery_fetch_pipeline($dbcnx, $pipelineRunId);
$runParameters = recovery_json((string)($run['parameters_json'] ?? '{}'));
$sparseRemoteJobId = recovery_find_sparse_job(
    $dbcnx,
    $pipelineRunId,
    $runParameters
);

$webRoot = dirname(__DIR__);
$outputBase = is_dir('/home/makler/web/remote_station/output')
    ? '/home/makler/web/remote_station/output'
    : $webRoot . '/remote_station/output';
$stationConfig = is_file('/home/makler/web/remote_station/stations.conf')
    ? '/home/makler/web/remote_station/stations.conf'
    : $webRoot . '/remote_station/stations.conf';
$restoreScript = $webRoot . '/remote_station/restore_job_to_station.sh';
$localSparseJobDir = $outputBase . '/job_' . $sparseRemoteJobId;
$localModelDir = $localSparseJobDir
    . '/colmap/sparse/'
    . $modelId;
$sparseResultPath = $localSparseJobDir . '/colmap/result.json';
if (!is_file($sparseResultPath)) {
    recovery_fail('cached sparse result is missing: ' . $sparseResultPath);
}
$sparseResult = recovery_json((string)file_get_contents($sparseResultPath));
$framesRemotePath = (string)($sparseResult['frames_dir'] ?? '');
if (!preg_match('#/output/job_([0-9]+)/frames(?:/|$)#', $framesRemotePath, $match)) {
    recovery_fail(
        'cannot resolve source frame job from sparse frames_dir: ' .
        $framesRemotePath
    );
}
$framesRemoteJobId = (int)$match[1];
$localFramesJobDir = $outputBase . '/job_' . $framesRemoteJobId;
$localFramesDir = $localFramesJobDir . '/frames';

if (!recovery_model_is_complete($localModelDir)) {
    recovery_fail(
        'cached sparse model is incomplete or missing: ' . $localModelDir
    );
}
if (!is_dir($localFramesDir)) {
    recovery_fail('cached source frames are missing: ' . $localFramesDir);
}
if (!is_file($stationConfig)) {
    recovery_fail('station config not found: ' . $stationConfig);
}
if (!is_file($restoreScript) || !is_executable($restoreScript)) {
    recovery_fail('restore helper is not executable: ' . $restoreScript);
}

$parents = recovery_find_dense_parents(
    $dbcnx,
    $pipelineRunId,
    $sparseRemoteJobId,
    $modelId
);
foreach ($parents as $parent) {
    if (in_array(
        strtoupper((string)($parent['status'] ?? '')),
        RECOVERY_ACTIVE_JOB_STATUSES,
        true
    ) && !$force) {
        recovery_fail(
            'an active dense parent already exists: remote_job_id=' .
            (int)$parent['remote_job_id'] .
            ' status=' . (string)$parent['status'] .
            '; use --force only after verifying it is stale'
        );
    }
}

$previousParent = $parents[0] ?? null;
$previousParameters = is_array($previousParent)
    ? recovery_json((string)($previousParent['parameters_json'] ?? '{}'))
    : [];
$mode = (string)(
    $previousParent['reconstruction_mode']
    ?? $run['pipeline_mode']
    ?? 'preview'
);

$settings = is_array($previousParameters['settings'] ?? null)
    ? $previousParameters['settings']
    : (
        is_array($runParameters['mode_parameters'] ?? null)
        ? $runParameters['mode_parameters']
        : []
    );
$dense = is_array($settings['dense'] ?? null) ? $settings['dense'] : [];
$dense['target_images_per_chunk'] = $targetImages;
$dense['max_images_per_chunk'] = $maxImages;
$dense['chunk_overlap'] = $overlapImages;
$dense['num_src_images'] = $numSrcImages;
$dense['max_image_size'] = min(640, (int)($dense['max_image_size'] ?? 640));
$dense['patchmatch_cache_size'] = 1;
$dense['fusion_cache_size'] = 1;
$settings['dense'] = $dense;

$newRemoteJobId = sfm_job_id($dbcnx);
$parentParameters = $previousParameters;
$parentParameters['sparse_job_id'] = $sparseRemoteJobId;
$parentParameters['sparse_remote_job_id'] = $sparseRemoteJobId;
$parentParameters['model_id'] = $modelId;
$parentParameters['settings'] = $settings;
$parentParameters['target_images_per_chunk'] = $targetImages;
$parentParameters['max_images_per_chunk'] = $maxImages;
$parentParameters['overlap_images'] = $overlapImages;
$parentParameters['ram_reserve_mb'] = $ramReserveMb;
$parentParameters['recovery'] = [
    'pipeline_run_id' => $pipelineRunId,
    'previous_parent_remote_job_id' => (int)(
        $previousParent['remote_job_id'] ?? 0
    ),
    'queued_at' => date(DATE_ATOM),
    'reason' => 'partial_dense_component_recovery',
];

$pipelineDir = $outputBase . '/pipeline_' . $pipelineRunId;
$apriltagSummary = null;
if (!$dryRun) {
    $apriltagSummary = recovery_copy_apriltag_report(
        $localSparseJobDir,
        $pipelineDir
    );
    if ($apriltagSummary !== null) {
        $runParameters['apriltag_assist'] = $apriltagSummary;
    }
}

$runParameters['mode_parameters'] = $settings;
$auto = is_array($runParameters['auto_components'] ?? null)
    ? $runParameters['auto_components']
    : [];
$selected = array_values(array_unique(array_map(
    'intval',
    is_array($auto['selected_model_ids'] ?? null)
        ? $auto['selected_model_ids']
        : [$modelId]
)));
if (!in_array($modelId, $selected, true)) {
    $selected[] = $modelId;
}
$ready = array_values(array_filter(
    array_map('intval', is_array($auto['ready_models'] ?? null)
        ? $auto['ready_models']
        : []),
    static fn(int $value): bool => $value !== $modelId
));
$waiting = array_values(array_unique(array_merge(
    array_map('intval', is_array($auto['waiting_models'] ?? null)
        ? $auto['waiting_models']
        : []),
    [$modelId]
)));
$auto['sparse_remote_job_id'] = $sparseRemoteJobId;
$auto['selected_model_ids'] = $selected;
$auto['ready_models'] = $ready;
$auto['waiting_models'] = $waiting;
$auto['previews_done'] = count($ready)
    + count(is_array($auto['excluded_tiny_models'] ?? null)
        ? $auto['excluded_tiny_models']
        : []);
$auto['aligned_merge'] = 'not started';
$auto['combined_model_available'] = false;
$auto['recovery'] = [
    'model_id' => $modelId,
    'new_parent_remote_job_id' => $newRemoteJobId,
    'previous_parent_remote_job_id' => (int)(
        $previousParent['remote_job_id'] ?? 0
    ),
    'safe_chunk_profile' => [
        'target_images_per_chunk' => $targetImages,
        'max_images_per_chunk' => $maxImages,
        'overlap_images' => $overlapImages,
        'num_src_images' => $numSrcImages,
        'ram_reserve_mb' => $ramReserveMb,
    ],
];
$runParameters['auto_components'] = $auto;
$runParameters['recovery_history'][] = $auto['recovery'] + [
    'created_at' => date(DATE_ATOM),
];

$plan = [
    'pipeline_run_id' => $pipelineRunId,
    'model_id' => $modelId,
    'sparse_remote_job_id' => $sparseRemoteJobId,
    'frames_remote_job_id' => $framesRemoteJobId,
    'cached_frames_dir' => $localFramesDir,
    'cached_sparse_model' => $localModelDir,
    'previous_parent_remote_job_id' => (int)(
        $previousParent['remote_job_id'] ?? 0
    ),
    'new_parent_remote_job_id' => $newRemoteJobId,
    'mode' => $mode,
    'safe_chunk_profile' => $auto['recovery']['safe_chunk_profile'],
    'apriltag_report_preserved' => $apriltagSummary !== null,
    'dry_run' => $dryRun,
];

if ($dryRun) {
    echo json_encode(
        $plan,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . "\n";
    exit(0);
}

[$framesRestoreCode, $framesRestoreOutput, $framesRestoreCommand] =
    recovery_run_command([
        $restoreScript,
        $stationConfig,
        (string)$framesRemoteJobId,
        $outputBase,
    ]);
if ($framesRestoreCode !== 0) {
    recovery_fail(
        "failed to restore source frame job to station\n" .
        'Command: ' . $framesRestoreCommand . "\n" .
        $framesRestoreOutput
    );
}

[$restoreCode, $restoreOutput, $restoreCommand] = recovery_run_command([
    $restoreScript,
    $stationConfig,
    (string)$sparseRemoteJobId,
    $outputBase,
]);
if ($restoreCode !== 0) {
    recovery_fail(
        "failed to restore sparse job to station\n" .
        'Command: ' . $restoreCommand . "\n" .
        $restoreOutput
    );
}

$orderId = (int)$run['order_id'];
$sessionId = (int)$run['capture_session_id'];
$jobType = $mode === 'hq'
    ? 'COLMAP_RECONSTRUCTION_HQ'
    : 'COLMAP_RECONSTRUCTION_PREVIEW';
$outputPath = $outputBase
    . '/job_' . $newRemoteJobId
    . '/merged/merged_fused.ply';
$resultPath = $outputBase
    . '/job_' . $newRemoteJobId
    . '/merged/result.json';
$logPath = $outputBase . '/job_' . $newRemoteJobId . '/logs';
$message = 'Recovery dense preview queued for sparse model ' . $modelId;
$parametersJson = json_encode(
    $parentParameters,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
$runParametersJson = json_encode(
    $runParameters,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
if ($parametersJson === false || $runParametersJson === false) {
    recovery_fail('failed to encode recovery parameters');
}

$dbcnx->begin_transaction();
try {
    $st = $dbcnx->prepare(
        "INSERT INTO sfm_remote_jobs (
            order_id,
            capture_session_id,
            pipeline_run_id,
            job_type,
            remote_job_id,
            parent_remote_job_id,
            output_path,
            status,
            progress_percent,
            message,
            result_json_path,
            log_path,
            reconstruction_mode,
            parameters_json
        ) VALUES (?,?,?,?,?,?,?,'QUEUED',0,?,?,?,?,?)"
    );
    if (!$st) {
        throw new RuntimeException(
            'recovery job insert prepare failed: ' . $dbcnx->error
        );
    }
    $st->bind_param(
        'iiisiissssss',
        $orderId,
        $sessionId,
        $pipelineRunId,
        $jobType,
        $newRemoteJobId,
        $sparseRemoteJobId,
        $outputPath,
        $message,
        $resultPath,
        $logPath,
        $mode,
        $parametersJson
    );
    if (!$st->execute()) {
        throw new RuntimeException(
            'recovery job insert failed: ' . $st->error
        );
    }
    $st->close();

    $st = $dbcnx->prepare(
        "UPDATE sfm_pipeline_runs
         SET status='RUNNING',
             stage='DENSE_PLAN',
             progress_percent=35,
             message=?,
             parameters_json=?,
             finished_at=NULL,
             error_json=NULL,
             updated_at=NOW(6)
         WHERE id=?"
    );
    if (!$st) {
        throw new RuntimeException(
            'pipeline recovery update prepare failed: ' . $dbcnx->error
        );
    }
    $st->bind_param(
        'ssi',
        $message,
        $runParametersJson,
        $pipelineRunId
    );
    if (!$st->execute()) {
        throw new RuntimeException(
            'pipeline recovery update failed: ' . $st->error
        );
    }
    $st->close();

    $cleanupTable = $dbcnx->query(
        "SHOW TABLES LIKE 'sfm_remote_cleanup_runs'"
    );
    $hasCleanupTable = $cleanupTable
        && $cleanupTable->num_rows > 0;
    if ($cleanupTable) {
        $cleanupTable->close();
    }
    if ($hasCleanupTable) {
        $st = $dbcnx->prepare(
            'DELETE FROM sfm_remote_cleanup_runs WHERE pipeline_run_id=?'
        );
        if ($st) {
            $st->bind_param('i', $pipelineRunId);
            $st->execute();
            $st->close();
        }
    }

    $dbcnx->commit();
} catch (Throwable $error) {
    $dbcnx->rollback();
    recovery_fail($error->getMessage());
}

pipeline_log(
    $pipelineRunId,
    'WARNING',
    'DENSE_RECOVERY',
    sprintf(
        'Queued safe recovery for model %d: parent %d -> %d, chunks target=%d max=%d overlap=%d src=%d reserve=%dMB',
        $modelId,
        (int)($previousParent['remote_job_id'] ?? 0),
        $newRemoteJobId,
        $targetImages,
        $maxImages,
        $overlapImages,
        $numSrcImages,
        $ramReserveMb
    )
);

$plan['status'] = 'QUEUED';
$plan['frames_restore_output'] = $framesRestoreOutput;
$plan['sparse_restore_output'] = $restoreOutput;
echo json_encode(
    $plan,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
