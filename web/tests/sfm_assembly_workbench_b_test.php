<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$library = $root . '/libs/sfm_assembly_workbench_lib.php';
$template = $root . '/templates/maklertour_order_simple.html';
$api = $root . '/www/api/sfm_manual_alignment.php';

foreach ([$library, $template, $api] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('required file not found: ' . $path);
    }
}

function workbench_b_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$librarySource = (string) file_get_contents($library);
foreach ([
    "'capture_session_id' => $captureSessionId",
    "'leaf_capture_session_ids'",
    "'capture_session_id' => (int) (",
] as $required) {
    workbench_b_ok(
        str_contains($librarySource, $required),
        'library contract missing: ' . $required
    );
}

$templateSource = (string) file_get_contents($template);
foreach ([
    'automaticAnchorSelect',
    'manualAnchorSelect',
    'manualMovingSelect',
    'swapManualButton',
    'function manualPairReason',
    'function duplicateLogicalSource',
    'Assembly нельзя использовать как Moving source',
    'относятся к разным capture sessions',
    'уже входит в выбранную assembly',
    'Направление не зависит от порядка установки галочек',
    'captureSessionId: Number(run.session_id || 0)',
] as $required) {
    workbench_b_ok(
        str_contains($templateSource, $required),
        'template contract missing: ' . $required
    );
}

workbench_b_ok(
    !str_contains($templateSource, 'var anchorKey = records[0].key;'),
    'manual direction must not use checkbox insertion order'
);

$apiSource = (string) file_get_contents($api);
foreach ([
    'source_kind=remote',
    'Source model is already included in this assembly',
    'Anchor and source must belong to the same capture session',
] as $required) {
    workbench_b_ok(
        str_contains($apiSource, $required),
        'backend safety contract missing: ' . $required
    );
}

echo "OK\n";
