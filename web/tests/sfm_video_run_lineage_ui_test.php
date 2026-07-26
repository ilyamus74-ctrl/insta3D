<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$scope = $root . '/libs/auto_photo_dense_download_scope_lib.php';
$lineage = $root . '/libs/sfm_video_run_lineage_lib.php';
$simple = $root . '/www/order_simple.php';
$template = $root . '/templates/maklertour_order_simple.html';

foreach ([$scope, $lineage, $simple, $template] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('required file not found: ' . $path);
    }
}

require_once $scope;
require_once $lineage;

function lineage_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$videoComponent = [
    'job_type' => 'COLMAP_RECONSTRUCTION_PREVIEW',
    'pipeline_run_id' => 70,
    'parameters_json' => json_encode([
        'source_type' => 'video_sfm',
        'model_id' => 1,
        'sparse_remote_job_id' => 548847057,
    ]),
];
lineage_ok(
    !auto_photo_dense_download_is_candidate($videoComponent),
    'Video SfM component must not use standalone Photo 3D resolver'
);

$photoDense = [
    'job_type' => 'COLMAP_RECONSTRUCTION_PREVIEW',
    'pipeline_run_id' => null,
    'parameters_json' => json_encode([
        'source_type' => 'auto_photo_sparse',
        'standalone_auto_photo_dense' => true,
        'dense_only' => true,
        'model_id' => 0,
    ]),
];
lineage_ok(
    auto_photo_dense_download_is_candidate($photoDense),
    'Standalone Photo 3D dense job must keep its scoped resolver'
);

$sessions = [[
    'id' => 65,
    'sfm_disk_videos' => [[
        'id' => 72,
        'filename' => 'room_stairs.mp4',
        'sfm_pipeline_cards' => [[
            'mode' => 'preview',
            'run' => [
                'id' => 70,
                'video_scan_id' => 72,
                'source_filename' => 'room_stairs.mp4',
                'pipeline_mode' => 'preview',
                'status' => 'DONE',
                'started_at' => '2026-07-25 08:20:50',
                'finished_at' => '2026-07-25 09:10:00',
                'parameters_json' => json_encode([
                    'auto_components' => [
                        'aligned_merge' => 'anchor_only',
                        'combined_model_available' => false,
                    ],
                ]),
                'sparse_components' => [
                    [
                        'model_id' => 1,
                        'registered_images' => 229,
                        'sparse_points' => 52670,
                        'is_primary' => true,
                        'has_dense' => true,
                        'dense_status' => 'DONE',
                        'dense_db_job_id' => 1001,
                        'dense_remote_job_id' => 755136344,
                        'sparse_remote_job_id' => 548847057,
                        'viewer_url' => '/viewer-1',
                        'download_url' => '/download-1',
                    ],
                    [
                        'model_id' => 5,
                        'registered_images' => 63,
                        'sparse_points' => 3522,
                        'is_primary' => false,
                        'has_dense' => true,
                        'dense_status' => 'DONE',
                        'dense_db_job_id' => 1002,
                        'dense_remote_job_id' => 800172632,
                        'sparse_remote_job_id' => 548847057,
                        'viewer_url' => '/viewer-5',
                        'download_url' => '/download-5',
                    ],
                ],
            ],
        ]],
    ]],
]];

$rows = sfm_video_run_lineage_build($sessions, [], 31, true);
lineage_ok(count($rows) === 1, 'one run lineage row');
lineage_ok($rows[0]['run_id'] === 70, 'run id');
lineage_ok($rows[0]['can_aligned_merge'] === true, 'run merge enabled');
lineage_ok(
    $rows[0]['source_job_ids'] === [1001, 1002],
    'only current run source jobs'
);
lineage_ok($rows[0]['anchor_model_id'] === 1, 'primary anchor model');

$simpleSource = (string) file_get_contents($simple);
foreach ([
    'sfm_video_run_lineage_lib.php',
    "'dense_db_job_id'",
    "'sparse_remote_job_id'",
    "'is_primary'",
    "'sfmRunLineageJson'",
] as $required) {
    lineage_ok(
        str_contains($simpleSource, $required),
        'order_simple lineage wiring missing: ' . $required
    );
}

$templateSource = (string) file_get_contents($template);
foreach ([
    'id="sfm-run-lineage-json"',
    'Компоненты именно этого Run',
    'Сборка этого Run',
    'aligned_merge_generated_dense_clouds',
    'source_job_ids[]',
    'Собрать компоненты Run #',
] as $required) {
    lineage_ok(
        str_contains($templateSource, $required),
        'template lineage UI missing: ' . $required
    );
}

echo "OK\n";
