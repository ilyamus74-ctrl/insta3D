<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$simple = $root . '/www/order_simple.php';
$template = $root . '/templates/maklertour_order_simple.html';

foreach ([$simple, $template] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('required file not found: ' . $path);
    }
}

function lineage_b_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$simpleSource = (string) file_get_contents($simple);
foreach ([
    "'created_at'=>",
    "'finished_at'=>",
    "'updated_at'=>",
    '$canCreateGeneratedMerge',
    'sfm_video_run_lineage_build($captureSessions,$generatedMerges,$orderId,$canCreateGeneratedMerge)',
] as $required) {
    lineage_b_ok(
        str_contains($simpleSource, $required),
        'order_simple missing: ' . $required
    );
}

$templateSource = (string) file_get_contents($template);
foreach ([
    'Создано / готово',
    'function renderGeneratedRunModels',
    'Video SfM models by processing Run',
    'Последняя обработка',
    'Собрать все модели Run #',
    'renderGeneratedRunModels(runs);',
    "</script>\n{/literal}",
] as $required) {
    lineage_b_ok(
        str_contains($templateSource, $required),
        'template missing: ' . $required
    );
}

echo "OK\n";
