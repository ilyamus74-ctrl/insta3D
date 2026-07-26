<?php
declare(strict_types=1);

$page = dirname(__DIR__) . '/www/sfm_manual_align.php';
$document = dirname(__DIR__, 2)
    . '/docs/llm/tasks/'
    . 'SFM-MANUAL-VISUAL-ALIGN-A1-READY-SYNC-HOTFIX.md';

foreach ([$page, $document] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException(
            'required file not found: ' . $path
        );
    }
}

function ready_sync_ok(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = (string)file_get_contents($page);

foreach ([
    'window.sfmManualCloudsReady = new Promise',
    'window.sfmManualCloudsResolve = resolve',
    'window.sfmManualCloudsReject = reject',
    'window.sfmManualCloudsResolve(readyClouds)',
    'window.sfmManualCloudsReject(error)',
    'await window.sfmManualCloudsReady',
    "errorCard.id = 'visualAlignmentError'",
    'Visual alignment UI startup failed',
] as $required) {
    ready_sync_ok(
        str_contains($source, $required),
        'ready synchronization missing: ' . $required
    );
}

ready_sync_ok(
    !str_contains(
        $source,
        "const clouds = window.sfmManualClouds;\n"
        . "if (!clouds?.anchorViewer?.geometry"
    ),
    'old eager geometry check is still present'
);

$doc = (string)file_get_contents($document);
ready_sync_ok(
    str_contains($doc, 'top-level `await`'),
    'race condition is not documented'
);

echo "OK\n";
