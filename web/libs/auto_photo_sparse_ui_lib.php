<?php
declare(strict_types=1);

require_once __DIR__ . '/auto_photo_sparse_lib.php';

function auto_photo_sparse_ui_status(mixed $value): string
{
    return strtoupper(trim((string) $value));
}

function auto_photo_sparse_ui_progress(mixed $value): int
{
    return max(0, min(100, (int) $value));
}

function auto_photo_sparse_ui_active(string $status): bool
{
    return in_array(
        $status,
        ['QUEUED', 'RUNNING', 'PLANNING', 'RUNNING_CHUNKS', 'MERGING'],
        true
    );
}

function auto_photo_sparse_ui_job(array $job): array
{
    return [
        'db_job_id' => (int) ($job['id'] ?? 0),
        'remote_job_id' => (int) ($job['remote_job_id'] ?? 0),
        'status' => auto_photo_sparse_ui_status($job['status'] ?? ''),
        'progress_percent' => auto_photo_sparse_ui_progress(
            $job['progress_percent'] ?? 0
        ),
        'message' => (string) ($job['message'] ?? ''),
    ];
}

function auto_photo_sparse_ui_parameters(array $job): array
{
    $parameters = json_decode((string) ($job['parameters_json'] ?? ''), true);
    return is_array($parameters) ? $parameters : [];
}

function auto_photo_sparse_ui_build(
    array $bundle,
    ?array $prepareJob,
    array $runSources,
    bool $canManage
): array {
    $bundleDto = [
        'id' => (int) ($bundle['id'] ?? 0),
        'capture_session_id' => (int) ($bundle['capture_session_id'] ?? 0),
        'app_bundle_uuid' => (string) ($bundle['app_bundle_uuid'] ?? ''),
        'photos_count' => (int) ($bundle['photos_count'] ?? 0),
        'photos_count_known' => ($bundle['photos_count_known'] ?? false) === true,
        'can_prepare' => ($bundle['can_prepare'] ?? false) === true,
        'status' => auto_photo_sparse_ui_status($bundle['status'] ?? ''),
    ];
    $prepare = is_array($prepareJob) ? auto_photo_sparse_ui_job($prepareJob) : null;
    $activeJobs = $prepare !== null && auto_photo_sparse_ui_active($prepare['status']);
    $runs = [];
    $recommendationRuns = [];

    foreach ($runSources as $source) {
        if (!is_array($source) || !is_array($source['job'] ?? null)) {
            continue;
        }

        $job = $source['job'];
        $parameters = auto_photo_sparse_ui_parameters($job);
        $inputImages = (int) ($parameters['input_images'] ?? 0);
        $selectedModelId = auto_photo_sparse_selected_model($job);
        $matcher = (string) ($parameters['settings']['sparse']['matcher'] ?? '');
        $retryMode = (string) ($parameters['retry_mode'] ?? '');
        $components = is_array($source['components'] ?? null) ? $source['components'] : [];
        $rawModels = is_array($components['models'] ?? null) ? $components['models'] : [];
        $validModels = [];
        $modelsById = [];

        foreach ($rawModels as $model) {
            if (!is_array($model)) {
                continue;
            }
            $modelId = auto_photo_sparse_manifest_model_id($model['model_id'] ?? null);
            if ($modelId === null) {
                continue;
            }

            $registeredImages = (int) ($model['registered_images'] ?? 0);
            $points = (int) ($model['points3D_count'] ?? ($model['sparse_points'] ?? 0));
            $frameRanges = is_array($model['frame_ranges'] ?? null)
                ? $model['frame_ranges']
                : [];
            $shared = [];
            foreach ((is_array($model['shared_images_with'] ?? null)
                ? $model['shared_images_with'] : []) as $otherId => $count) {
                $validOtherId = auto_photo_sparse_manifest_model_id($otherId);
                if ($validOtherId !== null) {
                    $shared[$validOtherId] = (int) $count;
                }
            }
            ksort($shared, SORT_NUMERIC);

            $validModels[] = $model;
            $modelsById[$modelId] = [
                'model_id' => $modelId,
                'registered_images' => $registeredImages,
                'registered_percent' => $inputImages > 0
                    ? round($registeredImages / $inputImages * 100, 1)
                    : 0.0,
                'points3D_count' => $points,
                'first_image' => (string) (
                    $model['first_image']
                    ?? $model['first_frame']
                    ?? ''
                ),
                'last_image' => (string) (
                    $model['last_image']
                    ?? $model['last_frame']
                    ?? ''
                ),
                'frame_ranges' => $frameRanges,
                'frame_ranges_label' => implode(', ', array_map('strval', $frameRanges)),
                'shared_images_with' => $shared,
                'shared_images_label' => implode(', ', array_map(
                    static fn(int $id, int $count): string => $id . ': ' . $count,
                    array_keys($shared),
                    $shared
                )),
                'selected' => $selectedModelId === $modelId,
                'recommended' => false,
                'can_select' => $canManage
                    && auto_photo_sparse_ui_status($job['status'] ?? '') === 'DONE'
                    && $selectedModelId !== $modelId,
                'can_export' => false,
                'export' => null,
            ];
        }

        $recommendedModel = auto_photo_sparse_recommended_model($validModels);
        $recommendedModelId = is_array($recommendedModel)
            ? auto_photo_sparse_manifest_model_id($recommendedModel['model_id'] ?? null)
            : null;
        foreach ($modelsById as $modelId => &$modelDto) {
            $modelDto['recommended'] = $recommendedModelId === $modelId;
        }
        unset($modelDto);

        $allExports = is_array($source['exports'] ?? null) ? $source['exports'] : [];
        foreach ($allExports as $export) {
            if (is_array($export) && auto_photo_sparse_ui_active(
                auto_photo_sparse_ui_status($export['status'] ?? '')
            )) {
                $activeJobs = true;
            }
        }
        $exportsByModel = [];
        foreach ($allExports as $export) {
            if (!is_array($export)
                || (string) ($export['job_type'] ?? '') !== 'EXPORT_PLY'
                || (int) ($export['parent_remote_job_id'] ?? 0)
                    !== (int) ($job['remote_job_id'] ?? 0)) {
                continue;
            }
            $exportParameters = auto_photo_sparse_ui_parameters($export);
            $modelId = auto_photo_sparse_manifest_model_id(
                $exportParameters['model_id'] ?? null
            );
            if ($modelId !== null && isset($modelsById[$modelId])) {
                $exportsByModel[$modelId][] = $export;
            }
        }

        $status = auto_photo_sparse_ui_status($job['status'] ?? '');
        $activeJobs = $activeJobs || auto_photo_sparse_ui_active($status);
        foreach ($modelsById as $modelId => &$modelDto) {
            $relatedExports = $exportsByModel[$modelId] ?? [];
            $chosenExport = auto_photo_sparse_export_priority($relatedExports);
            if (is_array($chosenExport)) {
                $exportDto = auto_photo_sparse_ui_job($chosenExport);
                $exportDto['download_url'] = $exportDto['status'] === 'DONE'
                    ? '/api/sfm_remote_job_status.php?job_id='
                        . $exportDto['db_job_id'] . '&file=ply'
                    : '';
                $modelDto['export'] = $exportDto;
            }
            $exportBlocks = false;
            foreach ($relatedExports as $export) {
                $exportBlocks = $exportBlocks || in_array(
                    auto_photo_sparse_ui_status($export['status'] ?? ''),
                    ['QUEUED', 'RUNNING', 'DONE'],
                    true
                );
            }
            $modelDto['can_export'] = $canManage && $status === 'DONE' && !$exportBlocks;
        }
        unset($modelDto);

        $models = array_values($modelsById);
        usort($models, static fn(array $left, array $right): int => [
            $right['registered_images'],
            $right['points3D_count'],
            $left['model_id'],
        ] <=> [
            $left['registered_images'],
            $left['points3D_count'],
            $right['model_id'],
        ]);
        $largest = $models[0] ?? ['registered_images' => 0, 'points3D_count' => 0];
        $runDto = [
            'sparse_db_job_id' => (int) ($job['id'] ?? 0),
            'sparse_remote_job_id' => (int) ($job['remote_job_id'] ?? 0),
            'status' => $status,
            'progress_percent' => auto_photo_sparse_ui_progress($job['progress_percent'] ?? 0),
            'message' => (string) ($job['message'] ?? ''),
            'input_images' => $inputImages,
            'matcher' => $matcher,
            'retry_mode' => $retryMode,
            'selected_model_id' => $selectedModelId,
            'recommended_model_id' => $recommendedModelId,
            'recommended_run' => false,
            'models_count' => count($models),
            'merge_warning' => auto_photo_sparse_has_merge_warning([
                'models' => $validModels,
            ]),
            'can_retry_exhaustive' => false,
            'models' => $models,
            '_parent_remote_job_id' => (int) ($job['parent_remote_job_id'] ?? 0),
            '_best_registered_images' => (int) $largest['registered_images'],
            '_best_points3D_count' => (int) $largest['points3D_count'],
            '_job' => $job,
        ];
        $runs[] = $runDto;
        $recommendationRuns[] = [
            'job' => $job,
            'models' => $validModels,
            'largest_registered_images' => $runDto['_best_registered_images'],
            'largest_points' => $runDto['_best_points3D_count'],
            'models_count' => $runDto['models_count'],
        ];
    }

    $recommendedSparseDbJobId = null;
    foreach (auto_photo_sparse_recommend_runs($recommendationRuns) as $recommended) {
        if (($recommended['recommended_run'] ?? false) === true) {
            $recommendedSparseDbJobId = (int) ($recommended['job']['id'] ?? 0);
            break;
        }
    }

    foreach ($runs as &$run) {
        $run['recommended_run'] = $recommendedSparseDbJobId !== null
            && $run['sparse_db_job_id'] === $recommendedSparseDbJobId;
        $relatedJobs = [];
        foreach ($runs as $otherRun) {
            if ($otherRun['_parent_remote_job_id'] === $run['_parent_remote_job_id']) {
                $relatedJobs[] = $otherRun['_job'];
            }
        }
        $policy = auto_photo_sparse_retry_policy($run['_job'], $relatedJobs);
        $planningExhaustive = false;
        foreach ($relatedJobs as $relatedJob) {
            $relatedParameters = auto_photo_sparse_ui_parameters($relatedJob);
            $planningExhaustive = $planningExhaustive || (
                ($relatedParameters['retry_mode'] ?? null) === 'exhaustive'
                && auto_photo_sparse_ui_status($relatedJob['status'] ?? '') === 'PLANNING'
            );
        }
        $run['can_retry_exhaustive'] = $canManage
            && $run['status'] === 'DONE'
            && $run['matcher'] !== 'exhaustive'
            && $run['retry_mode'] !== 'exhaustive'
            && ($policy['allowed'] ?? false) === true
            && !$planningExhaustive;
    }
    unset($run);

    usort($runs, static fn(array $left, array $right): int => [
        $right['recommended_run'] ? 1 : 0,
        $right['_best_registered_images'],
        $right['_best_points3D_count'],
        $left['models_count'],
        $right['sparse_db_job_id'],
    ] <=> [
        $left['recommended_run'] ? 1 : 0,
        $left['_best_registered_images'],
        $left['_best_points3D_count'],
        $right['models_count'],
        $left['sparse_db_job_id'],
    ]);
    foreach ($runs as &$run) {
        unset(
            $run['_parent_remote_job_id'],
            $run['_best_registered_images'],
            $run['_best_points3D_count'],
            $run['_job']
        );
    }
    unset($run);

    return [
        'visible' => ($bundleDto['id'] > 0 && $bundleDto['app_bundle_uuid'] !== '')
            || $runs !== [],
        'bundle' => $bundleDto,
        'prepare' => $prepare,
        'runs' => $runs,
        'recommended_sparse_db_job_id' => $recommendedSparseDbJobId,
        'active_jobs' => $activeJobs,
    ];
}
