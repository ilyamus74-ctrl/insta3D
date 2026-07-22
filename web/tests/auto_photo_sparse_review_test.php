<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/auto_photo_sparse_lib.php';
require_once __DIR__ . '/../libs/auto_photo_sparse_web_lib.php';

$functions = [
    'auto_photo_sparse_parse_model_id',
    'auto_photo_sparse_validate_model_id',
    'auto_photo_sparse_validate_job_scope',
    'auto_photo_sparse_validate_prepare_chain',
];
foreach ($functions as $function) {
    if (!function_exists($function)) {
        throw new RuntimeException('missing_' . $function);
    }
}

function apsr_expect(callable $callback, string $expected): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception->getMessage() === $expected) {
            return;
        }
        throw $exception;
    }

    throw new RuntimeException('missing_' . $expected);
}

if (auto_photo_sparse_selected_model([
    'parameters_json' => '{}',
]) !== null) {
    throw new RuntimeException('selected_model_missing');
}

$models = [
    ['model_id' => 0, 'registered_images' => 10, 'points3D_count' => 1],
    ['model_id' => 1, 'registered_images' => 9, 'points3D_count' => 999],
];
if (auto_photo_sparse_recommended_model($models)['model_id'] !== 0) {
    throw new RuntimeException('recommended_registered_images');
}
$models = [
    ['model_id' => 1, 'registered_images' => 10, 'sparse_points' => 5],
    ['model_id' => 2, 'registered_images' => 10, 'points3D_count' => 6],
    ['model_id' => 0, 'registered_images' => 10, 'points3D_count' => 6],
    ['model_id' => '01', 'registered_images' => 100, 'points3D_count' => 100],
];
if (auto_photo_sparse_recommended_model($models)['model_id'] !== 0) {
    throw new RuntimeException('recommended_points_or_model_id');
}
if (auto_photo_sparse_recommended_model([]) !== null) {
    throw new RuntimeException('recommended_empty');
}

$components = ['models' => [
    ['model_id' => 0, 'registered_images' => 2],
    ['model_id' => 3, 'registered_images' => 3],
]];
if (auto_photo_sparse_resolve_model_id(
    ['parameters_json' => '{"selected_model_id":3}'],
    $components,
    0
) !== 0) {
    throw new RuntimeException('resolver_explicit');
}
if (auto_photo_sparse_resolve_model_id(
    ['parameters_json' => '{"selected_model_id":0}'],
    $components,
    null
) !== 0) {
    throw new RuntimeException('resolver_selected');
}
if (auto_photo_sparse_resolve_model_id(
    ['parameters_json' => '{'],
    $components,
    null
) !== 3) {
    throw new RuntimeException('resolver_recommended');
}
apsr_expect(
    static fn() => auto_photo_sparse_resolve_model_id(
        ['parameters_json' => '{"selected_model_id":9}'], $components, null
    ),
    'sparse_model_not_found'
);
apsr_expect(
    static fn() => auto_photo_sparse_resolve_model_id(
        ['parameters_json' => '{}'], $components, 9
    ),
    'sparse_model_not_found'
);
apsr_expect(
    static fn() => auto_photo_sparse_resolve_model_id(
        ['parameters_json' => '{}'], ['models' => []], null
    ),
    'sparse_models_missing'
);

foreach ([1, 2] as $sharedCount) {
    if (!auto_photo_sparse_has_merge_warning(['models' => [
        ['model_id' => 0, 'shared_images_with' => [1 => $sharedCount]],
        ['model_id' => 1, 'shared_images_with' => [0 => $sharedCount]],
    ]])) {
        throw new RuntimeException('merge_warning_' . $sharedCount);
    }
}
if (auto_photo_sparse_has_merge_warning(['models' => [
    ['model_id' => 0, 'shared_images_with' => [1 => 3]],
    ['model_id' => 1, 'shared_images_with' => [0 => 3]],
]]) || !auto_photo_sparse_has_merge_warning(['models' => [
    ['model_id' => 0], ['model_id' => 1],
]]) || auto_photo_sparse_has_merge_warning(['models' => [['model_id' => 0]]])) {
    throw new RuntimeException('merge_warning_contract');
}
foreach ([
    [
        ['model_id' => 0, 'shared_images_with' => [1 => 3]],
        ['model_id' => 1, 'shared_images_with' => []],
    ],
    [
        ['model_id' => 1, 'shared_images_with' => []],
        ['model_id' => 0, 'shared_images_with' => [1 => 3]],
    ],
    [
        ['model_id' => 0, 'shared_images_with' => []],
        ['model_id' => 1, 'shared_images_with' => [0 => 3]],
    ],
    [
        ['model_id' => 1, 'shared_images_with' => [0 => 3]],
        ['model_id' => 0, 'shared_images_with' => []],
    ],
] as $models) {
    if (!auto_photo_sparse_has_merge_warning(['models' => $models])) {
        throw new RuntimeException('merge_warning_asymmetric');
    }
}

$runs = auto_photo_sparse_recommend_runs([
    ['job' => ['id' => 99, 'status' => 'RUNNING'], 'models' => [['model_id' => 0]], 'largest_registered_images' => 100, 'largest_points' => 100, 'models_count' => 1],
    ['job' => ['id' => 4, 'status' => 'DONE'], 'models' => [], 'largest_registered_images' => 90, 'largest_points' => 90, 'models_count' => 0],
    ['job' => ['id' => 5, 'status' => 'DONE'], 'models' => [['model_id' => 0]], 'largest_registered_images' => 10, 'largest_points' => 1, 'models_count' => 1],
    ['job' => ['id' => 6, 'status' => 'DONE'], 'models' => [['model_id' => 0]], 'largest_registered_images' => 10, 'largest_points' => 1, 'models_count' => 1],
]);
if (($runs[2]['recommended_run'] ?? false) || !($runs[3]['recommended_run'] ?? false)) {
    throw new RuntimeException('run_recommendation');
}
$ineligibleRuns = auto_photo_sparse_recommend_runs([
    ['job' => ['id' => 1, 'status' => 'RUNNING'], 'models' => [['model_id' => 0]]],
    ['job' => ['id' => 2, 'status' => 'DONE'], 'models' => []],
]);
if (($ineligibleRuns[0]['recommended_run'] ?? false)
    || ($ineligibleRuns[1]['recommended_run'] ?? false)) {
    throw new RuntimeException('run_recommendation_ineligible');
}

$exports = auto_photo_sparse_export_priority([
    ['id' => 1, 'status' => 'DONE'],
    ['id' => 9, 'status' => 'RUNNING'],
    ['id' => 10, 'status' => 'QUEUED'],
    ['id' => 11, 'status' => 'ERROR'],
]);
if ($exports['id'] !== 1
    || auto_photo_sparse_export_priority([
        ['id' => 1, 'status' => 'DONE'], ['id' => 2, 'status' => 'DONE'],
    ])['id'] !== 2
    || auto_photo_sparse_export_priority([
        ['id' => 1, 'status' => 'ERROR'], ['id' => 2, 'status' => 'RUNNING'],
    ])['id'] !== 2
    || auto_photo_sparse_export_priority([]) !== null) {
    throw new RuntimeException('export_priority');
}
if (auto_photo_sparse_selected_model([
    'parameters_json' => '{"selected_model_id":0}',
]) !== 0) {
    throw new RuntimeException('selected_model_zero');
}
if (auto_photo_sparse_selected_model([
    'parameters_json' => '{"selected_model_id":3}',
]) !== 3) {
    throw new RuntimeException('selected_model_three');
}
if (auto_photo_sparse_selected_model([
    'parameters_json' => '{',
]) !== null) {
    throw new RuntimeException('selected_model_malformed_json');
}
foreach ([
    '{"selected_model_id":-1}',
    '{"selected_model_id":"01"}',
    '{"selected_model_id":"abc"}',
    '{"selected_model_id":true}',
    '{"selected_model_id":false}',
    '{"selected_model_id":[]}',
] as $parametersJson) {
    if (auto_photo_sparse_selected_model([
        'parameters_json' => $parametersJson,
    ]) !== null) {
        throw new RuntimeException('selected_model_invalid_value');
    }
}

echo "OK\n";
