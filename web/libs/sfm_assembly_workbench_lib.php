<?php
declare(strict_types=1);

function sfm_assembly_workbench_json_array(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }

    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : [];
}

function sfm_assembly_workbench_model_ids(mixed $items): array
{
    if (!is_array($items)) {
        return [];
    }

    $ids = [];
    foreach ($items as $item) {
        if (is_array($item) && array_key_exists('model_id', $item)) {
            $ids[] = (int) $item['model_id'];
        } elseif (is_numeric($item)) {
            $ids[] = (int) $item;
        }
    }

    return array_values(array_unique($ids));
}

function sfm_assembly_workbench_state(
    array $merge,
    array $meta,
    bool $selectable
): string {
    $status = strtoupper((string) ($merge['status'] ?? ''));
    $type = (string) ($merge['merge_type'] ?? '');
    $message = strtolower((string) ($merge['message'] ?? ''));

    if (
        in_array($status, ['FAILED', 'ERROR', 'REJECTED', 'CANCELLED'], true)
        || str_contains($message, 'rejected')
    ) {
        return 'failed';
    }

    $included = $meta['included'] ?? ($meta['included_models'] ?? null);
    if (
        str_contains($message, 'anchor only')
        || str_contains($type, 'anchor_only')
        || (
            $type === 'aligned_shared_images_dense_ply'
            && is_array($included)
            && count($included) < 2
        )
    ) {
        return 'anchor_only';
    }

    if ($selectable) {
        return 'accepted';
    }

    if ($type === 'diagnostic_concat_dense_ply') {
        return 'diagnostic';
    }

    return strtolower($status !== '' ? $status : 'unknown');
}

function sfm_assembly_workbench_label(string $type, int $mergeId): string
{
    return match ($type) {
        'aligned_shared_images_dense_ply' =>
            'Автоматическая сборка #' . $mergeId,
        'manual_correspondences_sim3_dense_ply' =>
            'Ручная сборка #' . $mergeId,
        'manual_incremental_sim3_dense_ply' =>
            'Ручное дополнение #' . $mergeId,
        'manual_visual_sim3_dense_ply' =>
            'Визуальная сборка #' . $mergeId,
        'manual_visual_incremental_sim3_dense_ply' =>
            'Визуальное дополнение #' . $mergeId,
        'automatic_incremental_shared_images_dense_ply' =>
            'Автоматическое дополнение #' . $mergeId,
        default => 'Диагностическая сборка #' . $mergeId,
    };
}

function sfm_assembly_workbench_build(
    mysqli $db,
    int $orderId,
    array $merges
): array {
    $jobsById = [];
    $jobsByRemote = [];

    $statement = $db->prepare(
        "SELECT id,remote_job_id,pipeline_run_id,capture_session_id,"
        . "parent_remote_job_id,parameters_json "
        . "FROM sfm_remote_jobs "
        . "WHERE order_id=? "
        . "AND status='DONE' "
        . "AND job_type IN "
        . "('COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ')"
    );
    if (!$statement) {
        throw new RuntimeException('DB prepare error: ' . $db->error);
    }

    $statement->bind_param('i', $orderId);
    $statement->execute();
    $result = $statement->get_result();
    while ($job = $result->fetch_assoc()) {
        $jobsById[(int) $job['id']] = $job;
        $jobsByRemote[(int) $job['remote_job_id']] = $job;
    }
    $statement->close();

    $rows = [];

    foreach ($merges as $merge) {
        $mergeId = (int) ($merge['id'] ?? 0);
        if ($mergeId <= 0) {
            continue;
        }

        $resultPath = (string) ($merge['result_json_path'] ?? '');
        $meta = is_file($resultPath)
            ? sfm_assembly_workbench_json_array(
                file_get_contents($resultPath)
            )
            : [];

        $selectable = false;
        $selectError = '';
        $leaf = [];

        try {
            $resolved = sfm_manual_resolve_merge_anchor(
                $db,
                $orderId,
                $mergeId
            );
            $leaf = is_array($resolved['leaf_source_jobs'] ?? null)
                ? $resolved['leaf_source_jobs']
                : [];
            $selectable = true;
        } catch (Throwable $error) {
            $selectError = $error->getMessage();
            $leaf = $meta['leaf_source_jobs']
                ?? ($meta['source_jobs']
                ?? sfm_assembly_workbench_json_array(
                    $merge['source_jobs_json'] ?? '[]'
                ));
        }

        if (!is_array($leaf)) {
            $leaf = [];
        }

        $leafRows = [];
        $leafJobIds = [];
        $leafRemoteIds = [];
        $leafModelIds = [];
        $sparseRemoteIds = [];
        $leafCaptureSessionIds = [];

        foreach ($leaf as $item) {
            if (!is_array($item)) {
                continue;
            }

            $dbJobId = (int) (
                $item['db_job_id'] ?? ($item['job'] ?? 0)
            );
            $remoteJobId = (int) ($item['remote_job_id'] ?? 0);

            $job = $dbJobId > 0
                ? ($jobsById[$dbJobId] ?? null)
                : null;
            if ($job === null && $remoteJobId > 0) {
                $job = $jobsByRemote[$remoteJobId] ?? null;
            }

            if ($job !== null) {
                $dbJobId = (int) $job['id'];
                $remoteJobId = (int) $job['remote_job_id'];
            }

            $parameters = $job !== null
                ? sfm_assembly_workbench_json_array(
                    $job['parameters_json'] ?? '{}'
                )
                : [];

            $modelId = array_key_exists('model_id', $item)
                ? (int) $item['model_id']
                : (
                    array_key_exists('model', $item)
                        ? (int) $item['model']
                        : (
                            array_key_exists('model_id', $parameters)
                                ? (int) $parameters['model_id']
                                : null
                        )
                );

            $sparseRemoteId = (int) (
                $item['sparse_remote_job_id']
                ?? ($parameters['sparse_remote_job_id']
                ?? ($parameters['sparse_job_id']
                ?? ($job['parent_remote_job_id'] ?? 0)))
            );

            if ($dbJobId > 0) {
                $leafJobIds[$dbJobId] = true;
            }
            if ($remoteJobId > 0) {
                $leafRemoteIds[$remoteJobId] = true;
            }
            if ($modelId !== null) {
                $leafModelIds[(int) $modelId] = true;
            }
            if ($sparseRemoteId > 0) {
                $sparseRemoteIds[$sparseRemoteId] = true;
            }

            $captureSessionId = (int) (
                $job['capture_session_id'] ?? 0
            );
            if ($captureSessionId > 0) {
                $leafCaptureSessionIds[$captureSessionId] = true;
            }

            $leafRows[] = [
                'db_job_id' => $dbJobId,
                'remote_job_id' => $remoteJobId,
                'pipeline_run_id' => (int) (
                    $job['pipeline_run_id'] ?? 0
                ),
                'capture_session_id' => $captureSessionId,
                'model_id' => $modelId,
                'sparse_remote_job_id' => $sparseRemoteId,
            ];
        }

        $type = (string) ($merge['merge_type'] ?? '');
        $includedModels = sfm_assembly_workbench_model_ids(
            $meta['included'] ?? ($meta['included_models'] ?? [])
        );
        $excludedModels = sfm_assembly_workbench_model_ids(
            $meta['excluded'] ?? ($meta['excluded_models'] ?? [])
        );
        $state = sfm_assembly_workbench_state(
            $merge,
            $meta,
            $selectable
        );

        $rows[] = [
            'merge_id' => $mergeId,
            'label' => sfm_assembly_workbench_label($type, $mergeId),
            'merge_type' => $type,
            'status' => (string) ($merge['status'] ?? ''),
            'state' => $state,
            'created_at' => (string) ($merge['created_at'] ?? ''),
            'capture_session_id' => (int) (
                $merge['capture_session_id'] ?? 0
            ),
            'leaf_capture_session_ids' => array_map(
                'intval',
                array_keys($leafCaptureSessionIds)
            ),
            'points' => (int) ($merge['total_points'] ?? 0),
            'message' => (string) ($merge['message'] ?? ''),
            'included_models' => $includedModels,
            'excluded_models' => $excludedModels,
            'leaf_source_jobs' => $leafRows,
            'leaf_source_job_ids' => array_map(
                'intval',
                array_keys($leafJobIds)
            ),
            'leaf_remote_job_ids' => array_map(
                'intval',
                array_keys($leafRemoteIds)
            ),
            'leaf_model_ids' => array_map(
                'intval',
                array_keys($leafModelIds)
            ),
            'sparse_remote_job_ids' => array_map(
                'intval',
                array_keys($sparseRemoteIds)
            ),
            'selectable_as_source' => $selectable,
            'select_error' => $selectError,
            'open_url' => '/sfm_3d_viewer.php?order_id='
                . $orderId
                . '&merge_id='
                . $mergeId
                . '&artifact=dense',
            'download_url' =>
                '/api/sfm_generated_merge_file.php?merge_id='
                . $mergeId
                . '&file=ply',
            'result_url' =>
                '/api/sfm_generated_merge_file.php?merge_id='
                . $mergeId
                . '&file=result',
        ];
    }

    usort(
        $rows,
        static fn(array $left, array $right): int =>
            strcmp(
                (string) ($right['created_at'] ?? ''),
                (string) ($left['created_at'] ?? '')
            )
            ?: (
                (int) ($right['merge_id'] ?? 0)
                <=> (int) ($left['merge_id'] ?? 0)
            )
    );

    return $rows;
}
