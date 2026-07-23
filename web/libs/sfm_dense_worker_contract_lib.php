<?php
declare(strict_types=1);

/**
 * Build the immutable part of a COLMAP_DENSE_CHUNK payload.
 * Parent settings win; the fallback is evaluated only when the snapshot is absent or invalid.
 */
function sfm_dense_worker_child_parameters(
    array $parentParameters,
    callable $fallbackSettings,
    int $sparseJobId,
    int $modelId,
    string $imageListPath
): array {
    $settings = $parentParameters['settings'] ?? null;
    if (!is_array($settings)) {
        $settings = $fallbackSettings();
    }
    if (!is_array($settings)) {
        $settings = [];
    }

    return [
        'sparse_job_id' => $sparseJobId,
        'model_id' => $modelId,
        'image_list_path' => $imageListPath,
        'settings' => $settings,
    ];
}

/** Only the complete standalone dense-only marker pair suppresses automatic mesh. */
function sfm_dense_worker_skip_automatic_mesh(array $parameters): bool
{
    return ($parameters['standalone_auto_photo_dense'] ?? null) === true
        && ($parameters['dense_only'] ?? null) === true;
}
