<?php
declare(strict_types=1);

require_once __DIR__
    . '/../libs/auto_photo_sparse_ui_lib.php';

function check(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException($name);
    }
}

function job(int $id, int $remoteId, string $status, array $parameters = []): array
{
    return [
        'id' => $id,
        'remote_job_id' => $remoteId,
        'parent_remote_job_id' => 99,
        'status' => $status,
        'progress_percent' => 50,
        'message' => 'message',
        'parameters_json' => json_encode($parameters),
    ];
}

function components(array $models): array
{
    return ['models' => $models];
}

$modelZero = [
    'model_id' => 0,
    'registered_images' => 118,
    'points3D_count' => 23230,
    'first_frame' => 56,
    'last_frame' => 178,
    'frame_ranges' => ['056-079', '085-178'],
    'shared_images_with' => [1 => 1],
];
$modelOne = [
    'model_id' => 1,
    'registered_images' => 39,
    'points3D_count' => 11784,
    'first_frame' => 4,
    'last_frame' => 56,
    'frame_ranges' => ['004-009', '024-056'],
    'shared_images_with' => [0 => 1],
];
$parameters = [
    'input_images' => 178,
    'selected_model_id' => 1,
    'settings' => ['sparse' => ['matcher' => 'sequential']],
];
$sourceJob = job(746, 434136404, 'DONE', $parameters);
$sourceJob['progress_percent'] = 101;
$doneOld = job(50, 51, 'DONE', ['model_id' => 0]);
$doneNew = job(51, 52, 'DONE', ['model_id' => 0]);
$running = job(52, 53, 'RUNNING', ['model_id' => 1]);
$error = job(53, 54, 'ERROR', ['model_id' => 1]);
foreach (['doneOld', 'doneNew', 'running', 'error'] as $name) {
    ${$name}['job_type'] = 'EXPORT_PLY';
    ${$name}['parent_remote_job_id'] = 434136404;
}

$dto = auto_photo_sparse_ui_build(
    ['id' => 7, 'capture_session_id' => 8, 'app_bundle_uuid' => 'uuid',
        'photos_count' => 178, 'status' => 'ready'],
    array_replace(job(10, 99, 'RUNNING'), ['progress_percent' => -1]),
    [['job' => $sourceJob, 'components' => components([$modelOne, $modelZero, ['model_id' => 'bad']]),
        'exports' => [$doneOld, $doneNew, $running, $error]]],
    true
);
$models = $dto['runs'][0]['models'];
$model0 = $models[0];
$model1 = $models[1];
check($dto['visible'], 'visible bundle');
check($dto['bundle']['id'] === 7 && $dto['bundle']['app_bundle_uuid'] === 'uuid', 'bundle dto');
check($dto['prepare']['db_job_id'] === 10 && $dto['prepare']['status'] === 'RUNNING', 'prepare dto');
check($dto['runs'][0]['sparse_db_job_id'] === 746, 'sparse ids');
check($dto['runs'][0]['progress_percent'] === 100 && $dto['prepare']['progress_percent'] === 0, 'progress clamped');
check($model0['model_id'] === 0 && $model0['recommended'], 'model zero recommended');
check($model1['model_id'] === 1 && $model1['selected'], 'model one selected');
check($model0['can_select'] && !$model1['can_select'], 'select flags');
check($model0['registered_percent'] === 66.3 && $model1['registered_percent'] === 21.9, 'percent');
check($model0['points3D_count'] === 23230 && $model1['points3D_count'] === 11784, 'points');
check($dto['runs'][0]['can_retry_exhaustive'], 'positive retry');
check($model0['first_image'] === '56' && $model0['last_image'] === '178', 'station frame fallback');
check($model1['first_image'] === '4' && $model1['last_image'] === '56', 'station frame fallback model one');
check($model0['frame_ranges_label'] === '056-079, 085-178' && $model0['shared_images_label'] === '1: 1', 'labels');
check(count($models) === 2, 'malformed model excluded');
check($model0['export']['db_job_id'] === 51 && $model0['export']['download_url'] === '/api/sfm_remote_job_status.php?job_id=51&file=ply', 'done export priority');
check(!$model1['can_export'], 'running export blocks');
check($dto['active_jobs'], 'active prepare or export');

$selectedZero = auto_photo_sparse_ui_build(
    [],
    null,
    [[
        'job' => job(747, 434136405, 'DONE', [
            'input_images' => 178,
            'selected_model_id' => 0,
            'settings' => ['sparse' => ['matcher' => 'sequential']],
        ]),
        'components' => components([$modelZero, $modelOne]),
        'exports' => [],
    ]],
    true
);
check($selectedZero['runs'][0]['models'][0]['model_id'] === 0, 'selected zero retained');
check($selectedZero['runs'][0]['models'][0]['selected'], 'selected zero flagged');
check(!$selectedZero['runs'][0]['models'][0]['can_select'], 'selected zero cannot select');
check(!$selectedZero['runs'][0]['models'][1]['selected'] && $selectedZero['runs'][0]['models'][1]['can_select'], 'model one selectable after selected zero');

$legacy = auto_photo_sparse_ui_build([], null, [[
    'job' => job(745, 434136403, 'DONE', ['input_images' => 1]),
    'components' => components([[
        'model_id' => 0,
        'first_image' => 'legacy-first',
        'last_image' => 'legacy-last',
    ]]),
    'exports' => [],
]], true);
check($legacy['runs'][0]['models'][0]['first_image'] === 'legacy-first', 'legacy first image');
check($legacy['runs'][0]['models'][0]['last_image'] === 'legacy-last', 'legacy last image');

$sharedThree = auto_photo_sparse_ui_build([], null, [[
    'job' => job(744, 434136402, 'DONE', ['input_images' => 2]),
    'components' => components([
        ['model_id' => 0, 'registered_images' => 1, 'shared_images_with' => [1 => 3]],
        ['model_id' => 1, 'registered_images' => 1, 'shared_images_with' => [0 => 3]],
    ]),
    'exports' => [],
]], true);
check($dto['runs'][0]['merge_warning'], 'single shared image merge warning');
check(!$sharedThree['runs'][0]['merge_warning'], 'three shared images no merge warning');

$tieModels = components([
    ['model_id' => 2, 'registered_images' => 10, 'points3D_count' => 5],
    ['model_id' => 0, 'registered_images' => 10, 'points3D_count' => 5],
]);
$tie = auto_photo_sparse_ui_build([], null, [['job' => job(1, 2, 'DONE', ['input_images' => 10]), 'components' => $tieModels, 'exports' => []]], true);
check($tie['runs'][0]['models'][0]['model_id'] === 0, 'model tie id ascending');

$best = job(700, 7000, 'DONE', ['input_images' => 100]);
$olderTie = job(600, 6000, 'DONE', ['input_images' => 100]);
$runningBest = job(800, 8000, 'RUNNING', ['input_images' => 100]);
$bestComponents = components([['model_id' => 0, 'registered_images' => 90, 'points3D_count' => 9]]);
$tieComponents = components([['model_id' => 0, 'registered_images' => 50, 'points3D_count' => 5]]);
$runs = auto_photo_sparse_ui_build([], null, [
    ['job' => $runningBest, 'components' => $bestComponents, 'exports' => []],
    ['job' => $olderTie, 'components' => $tieComponents, 'exports' => []],
    ['job' => $best, 'components' => $bestComponents, 'exports' => []],
], true);
check($runs['recommended_sparse_db_job_id'] === 700 && $runs['runs'][0]['sparse_db_job_id'] === 700, 'best done recommended from unordered input');
$fullTie = auto_photo_sparse_ui_build([], null, [
    ['job' => $olderTie, 'components' => $tieComponents, 'exports' => []],
    ['job' => job(601, 6001, 'DONE', ['input_images' => 100]), 'components' => $tieComponents, 'exports' => []],
], true);
check($fullTie['recommended_sparse_db_job_id'] === 601, 'newer full tie wins');
$noDone = auto_photo_sparse_ui_build([], null, [['job' => $runningBest, 'components' => $bestComponents, 'exports' => []]], true);
check($noDone['recommended_sparse_db_job_id'] === null, 'no eligible recommendation');

$malformedActive = job(88, 89, 'RUNNING');
$malformedActive['job_type'] = 'EXPORT_PLY';
$malformedActive['parent_remote_job_id'] = 434136404;
$malformedActive['parameters_json'] = '{bad';
$malformed = auto_photo_sparse_ui_build([], null, [['job' => $sourceJob, 'components' => components([$modelZero]), 'exports' => [$malformedActive]]], true);
check($malformed['active_jobs'] && $malformed['runs'][0]['models'][0]['export'] === null, 'malformed active export');
$errorOnly = auto_photo_sparse_ui_build([], null, [['job' => $sourceJob, 'components' => components([$modelOne]), 'exports' => [$error]]], true);
check($errorOnly['runs'][0]['models'][0]['can_export'], 'error export does not block');
$noManage = auto_photo_sparse_ui_build([], null, [['job' => $sourceJob, 'components' => components([$modelZero]), 'exports' => []]], false);
check(!$noManage['runs'][0]['models'][0]['can_select'] && !$noManage['runs'][0]['models'][0]['can_export'] && !$noManage['runs'][0]['can_retry_exhaustive'], 'manage disabled');
$exhaustive = job(900, 9000, 'RUNNING', ['retry_mode' => 'exhaustive']);
$planningExhaustive = job(901, 9001, 'PLANNING', ['retry_mode' => 'exhaustive']);
$retryBlocked = auto_photo_sparse_ui_build([], null, [
    ['job' => $sourceJob, 'components' => components([$modelZero]), 'exports' => []],
    ['job' => $exhaustive, 'components' => components([]), 'exports' => []],
], true);
check(!$retryBlocked['runs'][0]['can_retry_exhaustive'], 'exhaustive duplicate blocked');
$planningBlocked = auto_photo_sparse_ui_build([], null, [
    ['job' => $sourceJob, 'components' => components([$modelZero]), 'exports' => []],
    ['job' => $planningExhaustive, 'components' => components([]), 'exports' => []],
], true);
check(!$planningBlocked['runs'][0]['can_retry_exhaustive'], 'planning exhaustive blocked');
check(auto_photo_sparse_ui_build([], null, [], true)['visible'] === false, 'empty invisible');

echo "OK\n";
