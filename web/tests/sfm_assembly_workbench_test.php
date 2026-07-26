<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$library = $root . '/libs/sfm_assembly_workbench_lib.php';
$simple = $root . '/www/order_simple.php';
$template = $root . '/templates/maklertour_order_simple.html';

foreach ([$library, $simple, $template] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('required file not found: ' . $path);
    }
}

require_once $library;

function workbench_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

workbench_ok(
    sfm_assembly_workbench_model_ids([
        ['model_id' => 1],
        ['model_id' => 5],
        5,
        8,
    ]) === [1, 5, 8],
    'model ID normalization'
);

workbench_ok(
    sfm_assembly_workbench_label(
        'aligned_shared_images_dense_ply',
        16
    ) === 'Автоматическая сборка #16',
    'aligned label'
);

workbench_ok(
    sfm_assembly_workbench_state(
        [
            'status' => 'DONE',
            'merge_type' => 'aligned_shared_images_dense_ply',
            'message' => '',
        ],
        ['included_models' => [1]],
        false
    ) === 'anchor_only',
    'anchor-only state'
);

$simpleSource = (string) file_get_contents($simple);
foreach ([
    'sfm_assembly_workbench_lib.php',
    'sfm_assembly_workbench_build(',
    "'sfmAssemblyWorkbenchJson'",
] as $required) {
    workbench_ok(
        str_contains($simpleSource, $required),
        'order_simple wiring missing: ' . $required
    );
}

$templateSource = (string) file_get_contents($template);
foreach ([
    'id="sfm-assembly-workbench-json"',
    'function renderAssemblyWorkbench',
    'Исходные модели Video SfM',
    'Создать новую сборку',
    'Автоматическая склейка',
    'Ручная склейка выбранной пары',
    'Результаты сборок',
    'Использовать как источник',
    'Legacy / дополнительные инструменты',
    "renderAssemblyWorkbench(runs, workbench);",
] as $required) {
    workbench_ok(
        str_contains($templateSource, $required),
        'template workbench missing: ' . $required
    );
}

workbench_ok(
    !str_contains(
        $templateSource,
        "'Собрать все модели Run #' + run.run_id + ' в одну модель'"
    ),
    'duplicate per-run merge button must be removed'
);

echo "OK\n";
