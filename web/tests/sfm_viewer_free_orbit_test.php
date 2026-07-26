<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$viewer = $root . '/www/sfm_3d_viewer.php';
$manual = $root . '/www/sfm_manual_align.php';
$document = dirname(__DIR__, 2)
    . '/docs/llm/tasks/SFM-VIEWER-FREE-ORBIT-A.md';

foreach ([$viewer, $manual, $document] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('required file not found: ' . $path);
    }
}

function free_orbit_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$viewerSource = (string) file_get_contents($viewer);
foreach ([
    'id="navigationMode"',
    'Horizon locked',
    'Free orbit 360°',
    'TrackballControls',
    'next.noRoll=false',
    'function setNavigationMode(mode,persist=true)',
    "navigationMode==='horizon'",
    'localStorage.setItem(navigationStorageKey,navigationMode)',
    "typeof controls.handleResize==='function'",
] as $required) {
    free_orbit_ok(
        str_contains($viewerSource, $required),
        'main viewer contract missing: ' . $required
    );
}

free_orbit_ok(
    !str_contains(
        $viewerSource,
        'const controls=new OrbitControls(camera, renderer.domElement);'
    ),
    'main viewer controls must be replaceable'
);

$manualSource = (string) file_get_contents($manual);
foreach ([
    'id="anchorNavigationMode"',
    'id="sourceNavigationMode"',
    'id="syncNavigationModes"',
    'Apply mode to both viewers',
    'TrackballControls',
    'setNavigationMode(mode)',
    'this.controls.noRoll = false',
    'navigationMode: navigationState.anchor',
    'navigationMode: navigationState.source',
    'function applyNavigationMode(side, mode)',
    'Cloud transforms are unchanged',
] as $required) {
    free_orbit_ok(
        str_contains($manualSource, $required),
        'manual viewer contract missing: ' . $required
    );
}

free_orbit_ok(
    !str_contains(
        $manualSource,
        'this.controls = new OrbitControls(this.camera, this.renderer.domElement);'
    ),
    'manual viewer controls must be replaceable'
);

$documentSource = (string) file_get_contents($document);
free_orbit_ok(
    str_contains($documentSource, 'IMPLEMENTED'),
    'documentation status not updated'
);
free_orbit_ok(
    str_contains($documentSource, 'No PLY reload'),
    'documentation does not preserve no-reload contract'
);

echo "OK\n";
