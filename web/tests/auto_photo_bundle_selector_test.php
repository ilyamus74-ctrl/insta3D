<?php
declare(strict_types=1);

define('APP_STORAGE_DIR', sys_get_temp_dir() . '/auto_photo_bundle_selector_missing');
require_once __DIR__ . '/../libs/auto_photo_sparse_ui_web_lib.php';
require_once __DIR__ . '/../libs/auto_photo_sparse_ui_render_lib.php';

function apbs_ok(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function apbs_bundle(int $id): array
{
    return [
        'id' => $id,
        'order_id' => 31,
        'capture_session_id' => 65,
        'app_bundle_uuid' => 'bundle-' . $id,
        'capture_type' => AUTO_PHOTO_BUNDLE_CAPTURE_TYPE,
        'filename' => 'bundle-' . $id . '.tgz',
        'storage_path' => 'missing/bundle-' . $id . '.tgz',
        'size_bytes' => 100,
        'status' => 'UPLOADED',
    ];
}

function apbs_prepare(): array
{
    return [
        'id' => 749,
        'order_id' => 31,
        'capture_session_id' => 65,
        'job_type' => AUTO_PHOTO_PREPARE_JOB_TYPE,
        'remote_job_id' => 104939820,
        'status' => 'DONE',
        'progress_percent' => 100,
        'message' => 'done',
        'parameters_json' => json_encode([
            'source_type' => 'auto_photo_bundle',
            'pipeline_mode' => 'prepare',
            'capture_bundle_id' => 8,
            'app_bundle_uuid' => 'bundle-8',
            'input_images' => 87,
        ], JSON_THROW_ON_ERROR),
    ];
}

function apbs_sparse(): array
{
    return [
        'id' => 752,
        'order_id' => 31,
        'capture_session_id' => 65,
        'job_type' => 'COLMAP_SPARSE',
        'remote_job_id' => 658883972,
        'parent_remote_job_id' => 104939820,
        'status' => 'DONE',
        'progress_percent' => 100,
        'message' => 'done',
        'pipeline_run_id' => null,
        'parameters_json' => json_encode([
            'source_type' => 'auto_photo_prepare',
            'standalone_sparse' => true,
            'prepare_job_id' => 749,
            'prepare_remote_job_id' => 104939820,
            'capture_bundle_id' => 8,
            'app_bundle_uuid' => 'bundle-8',
            'input_images' => 87,
        ], JSON_THROW_ON_ERROR),
    ];
}

$bundles = [apbs_bundle(9), apbs_bundle(8)];
$prepares = [apbs_prepare()];
$sparseRows = [apbs_sparse()];

apbs_ok(
    auto_photo_sparse_ui_web_select_bundle(
        $bundles,
        $sparseRows,
        $prepares,
        31
    )['id'] === 8,
    'default ranking keeps developed bundle'
);
apbs_ok(
    auto_photo_sparse_ui_web_select_bundle(
        $bundles,
        $sparseRows,
        $prepares,
        31,
        9
    )['id'] === 9,
    'explicit valid bundle wins'
);
apbs_ok(
    auto_photo_sparse_ui_web_select_bundle(
        $bundles,
        $sparseRows,
        $prepares,
        31,
        999
    )['id'] === 8,
    'missing preference falls back safely'
);

$dto = auto_photo_sparse_ui_web_build_from_rows(
    31,
    true,
    $bundles,
    $sparseRows,
    $prepares,
    [],
    static fn(int $unused): array => ['models' => []],
    [],
    9
);
apbs_ok(($dto['bundle']['id'] ?? 0) === 9, 'selected DTO bundle');
apbs_ok(($dto['bundle']['can_prepare'] ?? false) === true, 'new bundle can prepare');
apbs_ok(count($dto['bundle_options'] ?? []) === 2, 'all auto photo bundles visible');
apbs_ok(($dto['bundle_options'][0]['id'] ?? 0) === 9, 'newest bundle first');
apbs_ok(($dto['bundle_options'][0]['selected'] ?? false) === true, 'selected option marked');
apbs_ok(($dto['bundle_options'][1]['stage'] ?? '') === 'Sparse DONE', 'old chain stage');

$html = auto_photo_sparse_ui_render_pane($dto, [
    'post_url' => '/order.php?id=31',
    'csrf_name' => 'secCode',
    'csrf_value' => 'token',
]);
foreach ([
    'Пакеты Auto Photo',
    'Пакет #9',
    'Пакет #8',
    'Не обработан',
    'Sparse DONE',
    'Подготовить и запустить обработку',
    'auto_photo_bundle_id=8',
] as $needle) {
    apbs_ok(str_contains($html, $needle), 'render ' . $needle);
}

$order = (string)file_get_contents(__DIR__ . '/../www/order_simple.php');
foreach ([
    'auto_photo_bundle_id',
    'auto_photo_sparse_ui_web_load($dbcnx,$orderId,$canDeleteMedia,',
    'stereo_capture_bundles',
    "==='synced_depth_frames'",
] as $needle) {
    apbs_ok(str_contains($order, $needle), 'order wiring ' . $needle);
}

$template = (string)file_get_contents(
    __DIR__ . '/../templates/maklertour_order_simple.html'
);
apbs_ok(
    str_contains($template, 'from=$s.stereo_capture_bundles item=cb'),
    'stereo uses filtered collection'
);
apbs_ok(
    !str_contains(
        $template,
        'from=$s.capture_bundles item=cb'
        . '<div class="border rounded p-3 mb-3">'
    ),
    'stereo no longer iterates every bundle'
);

echo "OK\n";
