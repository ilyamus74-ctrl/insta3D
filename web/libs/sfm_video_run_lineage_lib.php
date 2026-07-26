<?php
declare(strict_types=1);

function sfm_video_lineage_json_array(mixed $value): array
{
    $decoded = is_array($value)
        ? $value
        : json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : [];
}

function sfm_video_lineage_merge_for_run(
    array $merges,
    int $runId,
    array $sourceDbJobIds,
    int $orderId
): ?array {
    $sourceLookup = array_fill_keys(array_map('intval', $sourceDbJobIds), true);

    foreach ($merges as $merge) {
        $resultPath = (string) ($merge['result_json_path'] ?? '');
        $meta = is_file($resultPath)
            ? sfm_video_lineage_json_array(file_get_contents($resultPath))
            : [];

        $metaRunId = (int) ($meta['pipeline_run_id'] ?? 0);
        $sources = $meta['source_jobs']
            ?? sfm_video_lineage_json_array($merge['source_jobs_json'] ?? '[]');

        $intersects = false;
        if (is_array($sources)) {
            foreach ($sources as $source) {
                if (
                    is_array($source)
                    && isset($sourceLookup[(int) ($source['db_job_id'] ?? 0)])
                ) {
                    $intersects = true;
                    break;
                }
            }
        }

        if ($metaRunId !== $runId && !$intersects) {
            continue;
        }

        $included = $meta['included'] ?? ($meta['included_models'] ?? []);
        $excluded = $meta['excluded'] ?? ($meta['excluded_models'] ?? []);

        $includedModels = sfm_video_lineage_model_ids($included);
        $excludedModels = sfm_video_lineage_model_ids($excluded);
        $type = (string) ($merge['merge_type'] ?? '');

        $alignedTypes = [
            'aligned_shared_images_dense_ply',
            'manual_correspondences_sim3_dense_ply',
            'manual_incremental_sim3_dense_ply',
            'automatic_incremental_shared_images_dense_ply',
        ];
        if (in_array($type, $alignedTypes, true)) {
            $state = count($includedModels) >= 2
                || $type === 'manual_correspondences_sim3_dense_ply'
                ? 'done'
                : 'anchor_only';
        } else {
            $state = 'diagnostic';
        }

        $mergeId = (int) ($merge['id'] ?? 0);
        return [
            'merge_id' => $mergeId,
            'merge_type' => $type,
            'status' => (string) ($merge['status'] ?? ''),
            'state' => $state,
            'created_at' => (string) ($merge['created_at'] ?? ''),
            'points' => (int) ($merge['total_points'] ?? 0),
            'included_models' => $includedModels,
            'excluded_models' => $excludedModels,
            'message' => (string) ($merge['message'] ?? ''),
            'open_url' => '/sfm_3d_viewer.php?order_id='
                . $orderId
                . '&merge_id='
                . $mergeId
                . '&artifact=dense',
            'download_url' => '/api/sfm_generated_merge_file.php?merge_id='
                . $mergeId
                . '&file=ply',
            'result_url' => '/api/sfm_generated_merge_file.php?merge_id='
                . $mergeId
                . '&file=result',
        ];
    }

    return null;
}

function sfm_video_lineage_model_ids(mixed $items): array
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

function sfm_video_run_lineage_build(
    array $sessions,
    array $merges,
    int $orderId,
    bool $canCreateMerge
): array {
    $rows = [];

    foreach ($sessions as $session) {
        foreach (($session['sfm_disk_videos'] ?? []) as $video) {
            foreach (($video['sfm_pipeline_cards'] ?? []) as $card) {
                $run = $card['run'] ?? null;
                if (!is_array($run)) {
                    continue;
                }

                $components = is_array($run['sparse_components'] ?? null)
                    ? $run['sparse_components']
                    : [];

                $mergeable = array_values(array_filter(
                    $components,
                    static fn(array $component): bool =>
                        !empty($component['has_dense'])
                        && strtoupper(
                            (string) ($component['dense_status'] ?? '')
                        ) === 'DONE'
                        && (int) ($component['dense_db_job_id'] ?? 0) > 0
                ));

                $sourceDbJobIds = array_values(array_map(
                    static fn(array $component): int =>
                        (int) $component['dense_db_job_id'],
                    $mergeable
                ));

                $sparseRemoteJobIds = array_values(array_unique(array_filter(
                    array_map(
                        static fn(array $component): int =>
                            (int) ($component['sparse_remote_job_id'] ?? 0),
                        $mergeable
                    ),
                    static fn(int $value): bool => $value > 0
                )));

                $primary = null;
                foreach ($mergeable as $component) {
                    if (!empty($component['is_primary'])) {
                        $primary = $component;
                        break;
                    }
                }
                if ($primary === null && $mergeable) {
                    $ranked = $mergeable;
                    usort(
                        $ranked,
                        static fn(array $left, array $right): int =>
                            ((int) $right['registered_images']
                                <=> (int) $left['registered_images'])
                            ?: ((int) $right['sparse_points']
                                <=> (int) $left['sparse_points'])
                    );
                    $primary = $ranked[0];
                }

                $params = sfm_video_lineage_json_array(
                    $run['parameters_json'] ?? '{}'
                );
                $auto = is_array($params['auto_components'] ?? null)
                    ? $params['auto_components']
                    : [];

                $runId = (int) $run['id'];
                $merge = sfm_video_lineage_merge_for_run(
                    $merges,
                    $runId,
                    $sourceDbJobIds,
                    $orderId
                );

                $rows[] = [
                    'order_id' => $orderId,
                    'session_id' => (int) $session['id'],
                    'video_scan_id' => (int) (
                        $run['video_scan_id'] ?? ($video['id'] ?? 0)
                    ),
                    'source_filename' => (string) (
                        (($run['source_filename'] ?? '') !== '')
                            ? $run['source_filename']
                            : ($video['filename'] ?? '')
                    ),
                    'run_id' => $runId,
                    'mode' => (string) (
                        $run['pipeline_mode'] ?? ($card['mode'] ?? '')
                    ),
                    'status' => (string) ($run['status'] ?? ''),
                    'created_at' => (string) ($run['created_at'] ?? ''),
                    'started_at' => (string) ($run['started_at'] ?? ''),
                    'finished_at' => (string) ($run['finished_at'] ?? ''),
                    'components' => $components,
                    'mergeable_count' => count($mergeable),
                    'source_job_ids' => $sourceDbJobIds,
                    'sparse_remote_job_id' =>
                        count($sparseRemoteJobIds) === 1
                            ? (int) $sparseRemoteJobIds[0]
                            : 0,
                    'anchor_model_id' => $primary !== null
                        ? (int) $primary['model_id']
                        : 0,
                    'can_aligned_merge' =>
                        $canCreateMerge
                        && count($mergeable) >= 2
                        && count($sparseRemoteJobIds) === 1,
                    'auto_components' => [
                        'aligned_merge' => (string) (
                            $auto['aligned_merge'] ?? 'not started'
                        ),
                        'combined_model_available' =>
                            !empty($auto['combined_model_available']),
                        'ready_models' => array_values(array_map(
                            'intval',
                            is_array($auto['ready_models'] ?? null)
                                ? $auto['ready_models']
                                : []
                        )),
                        'waiting_models' => array_values(array_map(
                            'intval',
                            is_array($auto['waiting_models'] ?? null)
                                ? $auto['waiting_models']
                                : []
                        )),
                        'last_error' => (string) ($auto['last_error'] ?? ''),
                    ],
                    'merge' => $merge,
                ];
            }
        }
    }

    return $rows;
}
