<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/auto_photo_sparse_ui_lib.php';
require_once __DIR__ . '/../libs/auto_photo_sparse_ui_render_lib.php';

function adui_ok(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function adui_sparse(): array
{
    return [
        'id' => 746,
        'remote_job_id' => 9002,
        'parent_remote_job_id' => 9001,
        'job_type' => 'COLMAP_SPARSE',
        'status' => 'DONE',
        'progress_percent' => 100,
        'message' => 'sparse done',
        'parameters_json' => json_encode([
            'input_images' => 20,
            'settings' => ['sparse' => ['matcher' => 'sequential']],
        ]),
    ];
}

function adui_models(): array
{
    return [
        'models' => [
            [
                'model_id' => 0,
                'registered_images' => 12,
                'points3D_count' => 1200,
                'first_image' => 'frame_000001.jpg',
                'last_image' => 'frame_000020.jpg',
            ],
            [
                'model_id' => 1,
                'registered_images' => 9,
                'points3D_count' => 900,
            ],
        ],
    ];
}

function adui_dense(string $status = 'RUNNING'): array
{
    return [
        'id' => 981,
        'order_id' => 30,
        'capture_session_id' => 63,
        'pipeline_run_id' => null,
        'job_type' => 'COLMAP_RECONSTRUCTION_PREVIEW',
        'remote_job_id' => 9200,
        'parent_remote_job_id' => 9002,
        'output_path' => '/tmp/job_9200/merged/merged_fused.ply',
        'status' => $status,
        'progress_percent' => $status === 'DONE' ? 100 : 37,
        'message' => 'dense preview',
        'reconstruction_mode' => 'preview',
        'parameters_json' => json_encode([
            'source_type' => 'auto_photo_sparse',
            'standalone_auto_photo_dense' => true,
            'dense_only' => true,
            'sparse_db_job_id' => 746,
            'sparse_job_id' => 9002,
            'sparse_remote_job_id' => 9002,
            'model_id' => 0,
        ]),
    ];
}

function adui_build(array $dense, bool $canManage = true): array
{
    return auto_photo_sparse_ui_build(
        [
            'id' => 7,
            'capture_session_id' => 63,
            'app_bundle_uuid' => 'bundle-uuid',
            'photos_count' => 20,
            'photos_count_known' => true,
            'status' => 'UPLOADED',
        ],
        null,
        [[
            'job' => adui_sparse(),
            'components' => adui_models(),
            'exports' => [],
            'dense' => $dense,
        ]],
        $canManage
    );
}

$context = [
    'post_url' => '/order.php?id=30',
    'csrf_name' => 'secCode',
    'csrf_value' => 'csrf-token',
];

$running = adui_build([adui_dense()]);
$runningModel0 = $running['runs'][0]['models'][0];
$runningModel1 = $running['runs'][0]['models'][1];
adui_ok($running['active_jobs'] === true, 'active dense contributes to active_jobs');
adui_ok(($runningModel0['dense']['status'] ?? '') === 'RUNNING', 'running dense is attached to model 0');
adui_ok(($runningModel0['dense']['model_id'] ?? -1) === 0, 'model 0 dense identity');
adui_ok($runningModel0['can_dense_preview'] === false, 'active dense blocks duplicate action');
adui_ok($runningModel1['can_dense_preview'] === false, 'fewer than ten registered images blocks action');
$runningPane = auto_photo_sparse_ui_render_pane($running, $context);
adui_ok(str_contains($runningPane, 'Обработка Auto Photo выполняется'), 'active dense alert rendered');
adui_ok(str_contains($runningPane, 'Dense points: 0'), 'running dense metadata rendered');
adui_ok(!str_contains($runningPane, 'Скачать merged_fused.ply'), 'running dense has no download');
adui_ok(!str_contains($runningPane, 'auto_photo_sparse_build_dense_preview'), 'blocked models have no dense action');

$eligible = adui_build([]);
$eligibleModel0 = $eligible['runs'][0]['models'][0];
$eligibleModel1 = $eligible['runs'][0]['models'][1];
adui_ok($eligible['active_jobs'] === false, 'no dense means no active dense state');
adui_ok($eligibleModel0['can_dense_preview'] === true, 'model 0 with twelve images is eligible');
adui_ok($eligibleModel1['can_dense_preview'] === false, 'model 1 with nine images is ineligible');
$eligiblePane = auto_photo_sparse_ui_render_pane($eligible, $context);
adui_ok(substr_count($eligiblePane, 'auto_photo_sparse_build_dense_preview') === 1, 'one dense action rendered');
foreach ([
    'name="sparse_db_job_id" value="746"',
    'name="model_id" value="0"',
    'Создать 3D-модель',
] as $needle) {
    adui_ok(str_contains($eligiblePane, $needle), 'eligible form ' . $needle);
}

$denied = adui_build([], false);
adui_ok($denied['runs'][0]['models'][0]['can_dense_preview'] === false, 'manage permission required');
adui_ok(!str_contains(
    auto_photo_sparse_ui_render_pane($denied, $context),
    'auto_photo_sparse_build_dense_preview'
), 'denied user has no dense form');

$doneRow = adui_dense('DONE');
$doneRow['_dense_points'] = 12345;
$doneRow['_dense_file_size'] = 67890;
$doneRow['_dense_download_ready'] = true;
$done = adui_build([$doneRow]);
$doneModel = $done['runs'][0]['models'][0];
adui_ok($doneModel['can_dense_preview'] === false, 'done dense blocks duplicate action');
adui_ok(($doneModel['dense']['dense_points'] ?? 0) === 12345, 'done dense points');
adui_ok(($doneModel['dense']['file_size_bytes'] ?? 0) === 67890, 'done dense size');
adui_ok(
    ($doneModel['dense']['download_url'] ?? '') === '/api/sfm_remote_job_status.php?job_id=981&file=ply',
    'done dense download URL'
);
adui_ok(
    ($doneModel['dense']['viewer_url'] ?? '') ===
        '/sfm_3d_viewer.php?order_id=30&session_id=63'
        . '&auto_photo_dense_job_id=981&artifact=dense',
    'done dense viewer URL'
);
$donePane = auto_photo_sparse_ui_render_pane($done, $context);
foreach ([
    'Dense points: 12 345',
    'Размер: 67890 B',
    'Открыть 3D',
    'Скачать PLY',
    'auto_photo_dense_job_id=981',
    'href="/api/sfm_remote_job_status.php?job_id=981&amp;file=ply"',
] as $needle) {
    adui_ok(str_contains($donePane, $needle), 'done render ' . $needle);
}

$wrongMarkers = adui_dense();
$wrongMarkers['parameters_json'] = json_encode([
    'source_type' => 'auto_photo_sparse',
    'standalone_auto_photo_dense' => true,
    'dense_only' => false,
    'sparse_remote_job_id' => 9002,
    'model_id' => 0,
]);
$ignored = adui_build([$wrongMarkers]);
adui_ok($ignored['active_jobs'] === false, 'invalid dense markers are ignored');
adui_ok($ignored['runs'][0]['models'][0]['dense'] === null, 'invalid dense is not rendered');
adui_ok($ignored['runs'][0]['models'][0]['can_dense_preview'] === true, 'invalid dense does not block valid action');

$route = (string) file_get_contents(__DIR__ . '/../www/order.php');
$start = strpos($route, "\$action === 'auto_photo_sparse_build_dense_preview'");
$end = $start === false
    ? false
    : strpos($route, 'create_capture_bundle_dense_job', $start + 1);
adui_ok(
    $start !== false && $end !== false && $end > $start,
    'dense route boundaries'
);
$fragment = substr($route, $start, $end - $start);
foreach ([
    '$canDeleteMedia',
    'order_auto_photo_sparse_require_csrf',
    'auto_photo_sparse_web_enqueue_dense_preview',
    "\$_POST['sparse_db_job_id']",
    "\$_POST['model_id']",
    'photo_dense_queued=1#simple-photo-sfm',
    'photo_dense_exists=1#simple-photo-sfm',
] as $needle) {
    adui_ok(str_contains($fragment, $needle), 'route ' . $needle);
}
adui_ok(
    strpos($fragment, 'order_auto_photo_sparse_require_csrf')
        < strpos($fragment, 'auto_photo_sparse_web_enqueue_dense_preview'),
    'route checks CSRF before enqueue'
);
foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'FOR UPDATE', 'begin_transaction'] as $forbidden) {
    adui_ok(!str_contains($fragment, $forbidden), 'route has no inline ' . $forbidden);
}

echo "OK\n";
