<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$manual = $root . '/www/sfm_manual_align.php';
$orderSimple = $root . '/www/order_simple.php';
$template = $root . '/templates/maklertour_order_simple.html';
$deleteApi = $root . '/www/api/sfm_generated_merge_delete.php';

foreach ([$manual, $orderSimple, $template, $deleteApi] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('required file not found: ' . $path);
    }
}

function scale_delete_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$manualSource = (string)file_get_contents($manual);
foreach ([
    'window.sfmVisualAlignmentViewer = visualViewer',
    'function syncTopViewerCameraScale()',
    'Одинаковый масштаб двух окон',
    'visualMatchMovingScale',
    'anchorRadius / movingRadius',
    'movingObject.scale.setScalar(matchedScale)',
] as $required) {
    scale_delete_ok(
        str_contains($manualSource, $required),
        'visual scale contract missing: ' . $required
    );
}

$orderSource = (string)file_get_contents($orderSimple);
foreach ([
    "'can_delete_merge'=>\$canCreateGeneratedMerge",
    "'csrf_token'=>(string)(\$_SESSION['secCode'] ?? '')",
] as $required) {
    scale_delete_ok(
        str_contains($orderSource, $required),
        'workbench payload missing: ' . $required
    );
}

$templateSource = (string)file_get_contents($template);
foreach ([
    'canDeleteMerge',
    'workbenchCsrfToken',
    'Удалить сборку',
    'sfm_generated_merge_delete.php',
    'confirm_merge_id',
    'registry.delete(registryKey)',
] as $required) {
    scale_delete_ok(
        str_contains($templateSource, $required),
        'delete UI contract missing: ' . $required
    );
}

$apiSource = (string)file_get_contents($deleteApi);
foreach ([
    'merge_delete_payload_references',
    'merge_delete_recursive',
    'merge_delete_restore',
    'LIMIT 1 FOR UPDATE',
    'используется сборками',
    'SFM_GENERATED_MERGE_DELETED',
    '.delete_merge_',
] as $required) {
    scale_delete_ok(
        str_contains($apiSource, $required),
        'delete API contract missing: ' . $required
    );
}

echo "OK\n";
