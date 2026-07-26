<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$baseLib = $root . '/libs/sfm_manual_alignment_lib.php';
$visualLib =
    $root . '/libs/sfm_manual_visual_alignment_lib.php';
$workbench =
    $root . '/libs/sfm_assembly_workbench_lib.php';
$page = $root . '/www/sfm_manual_align.php';
$api =
    $root . '/www/api/sfm_manual_visual_alignment.php';
$script =
    $root
    . '/remote_station/scripts/'
    . 'manual_pointcloud_matrix_merge.py';

foreach (
    [$baseLib, $visualLib, $workbench, $page, $api, $script]
    as $path
) {
    if (!is_file($path)) {
        throw new RuntimeException(
            'required file not found: ' . $path
        );
    }
}

function visual_save_ok(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$base = (string)file_get_contents($baseLib);
foreach ([
    'manual_visual_sim3_dense_ply',
    'manual_visual_incremental_sim3_dense_ply',
] as $required) {
    visual_save_ok(
        str_contains($base, $required),
        'visual merge anchor support missing: ' . $required
    );
}

$library = (string)file_get_contents($visualLib);
foreach ([
    'sfm_manual_visual_normalize_matrix4',
    'sfm_manual_visual_transform_hash',
    'sfm_manual_visual_save',
    'manual_visual_merged_dense_cloud.ply',
    'leaf_source_jobs',
    'leaf_transforms',
    'parent_merge_id',
    'matrix4_to_assembly',
    'idempotency_key',
] as $required) {
    visual_save_ok(
        str_contains($library, $required),
        'visual save library missing: ' . $required
    );
}

$workbenchSource = (string)file_get_contents($workbench);
visual_save_ok(
    str_contains($workbenchSource, 'Визуальная сборка #'),
    'visual workbench label missing'
);
visual_save_ok(
    str_contains($workbenchSource, 'Визуальное дополнение #'),
    'visual incremental label missing'
);

$pageSource = (string)file_get_contents($page);
foreach ([
    'visualSaveAssembly',
    'Сохранить сборку',
    'sfm_manual_visual_alignment.php',
    'Добавить следующую модель',
    'visualViewer.state()',
] as $required) {
    visual_save_ok(
        str_contains($pageSource, $required),
        'visual save UI missing: ' . $required
    );
}

$apiSource = (string)file_get_contents($api);
foreach ([
    'sfm_manual_visual_save',
    'HTTP_X_CSRF_TOKEN',
    'viewer_url',
    'result_url',
    '#simple-generated',
] as $required) {
    visual_save_ok(
        str_contains($apiSource, $required),
        'visual save API missing: ' . $required
    );
}

$scriptSource = (string)file_get_contents($script);
foreach ([
    '--transform-json',
    'manual_visual_transform_sim3',
    'source_visual_aligned_to_anchor.ply',
    'manual_visual_merged_dense_cloud.ply',
    'rotation determinant',
] as $required) {
    visual_save_ok(
        str_contains($scriptSource, $required),
        'visual matrix script missing: ' . $required
    );
}

echo "OK\n";
