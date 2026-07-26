<?php
declare(strict_types=1);

$page = dirname(__DIR__) . '/www/sfm_manual_align.php';
$docPath = dirname(__DIR__, 2)
    . '/docs/llm/tasks/SFM-MANUAL-VISUAL-ALIGN-A1-UI-CORE.md';

foreach ([$page, $docPath] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('required file not found: ' . $path);
    }
}

function visual_ui_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = (string)file_get_contents($page);
foreach ([
    'window.sfmManualClouds',
    "card.id = 'visualAlignmentCard'",
    'id="combinedVisualViewer"',
    'Visual alignment: Anchor + Moving source',
    'TransformControls',
    'class VisualAlignmentViewer',
    'enforceUniformScale()',
    'this.movingObject.scale.setScalar(uniform)',
    'matrixRows()',
    'uniform_scale',
    'visualStorageKey',
    'visualCopyMatrix',
    'visualExportTransform',
    "visualViewer.setMode('translate')",
    "visualViewer.setMode('rotate')",
    "visualViewer.setMode('scale')",
] as $required) {
    visual_ui_ok(
        str_contains($source, $required),
        'visual UI contract missing: ' . $required
    );
}

visual_ui_ok(
    !str_contains($source, 'sfm_manual_visual_alignment.php'),
    'A1 must not change the server alignment contract'
);

$doc = (string)file_get_contents($docPath);
visual_ui_ok(
    str_contains($doc, 'SFM-MANUAL-VISUAL-ALIGN-A2'),
    'next server stage is not documented'
);

echo "OK\n";
