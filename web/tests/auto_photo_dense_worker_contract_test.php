<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/sfm_dense_worker_contract_lib.php';

function adwc_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$previewSettings = [
    'dense' => [
        'max_image_size' => 640,
        'num_src_images' => 6,
        'target_images_per_chunk' => 50,
        'max_images_per_chunk' => 70,
        'chunk_overlap' => 15,
    ],
];
$fallbackCalls = 0;
$fallback = static function () use (&$fallbackCalls): array {
    $fallbackCalls++;
    return ['dense' => ['max_image_size' => 1600]];
};

$normal = sfm_dense_worker_child_parameters(
    ['settings' => $previewSettings],
    $fallback,
    9002,
    0,
    '/tmp/chunk_0.txt'
);
$retry = sfm_dense_worker_child_parameters(
    ['settings' => $previewSettings],
    $fallback,
    9002,
    0,
    '/tmp/chunk_0_retry1.txt'
);

adwc_assert($fallbackCalls === 0, 'parent snapshot must avoid fallback lookup');
adwc_assert($normal['settings'] === $previewSettings, 'normal chunk keeps parent settings');
adwc_assert($retry['settings'] === $previewSettings, 'retry chunk keeps parent settings');
adwc_assert($normal['settings'] === $retry['settings'], 'normal and retry settings are identical');
adwc_assert($normal['sparse_job_id'] === 9002 && $normal['model_id'] === 0, 'model zero contract');
adwc_assert($normal['image_list_path'] === '/tmp/chunk_0.txt', 'normal image list');
adwc_assert($retry['image_list_path'] === '/tmp/chunk_0_retry1.txt', 'retry image list');

$videoFallback = ['dense' => ['max_image_size' => 1920, 'num_src_images' => 8]];
$videoCalls = 0;
$video = sfm_dense_worker_child_parameters(
    ['pipeline_run_id' => 77],
    static function () use (&$videoCalls, $videoFallback): array {
        $videoCalls++;
        return $videoFallback;
    },
    7001,
    2,
    '/tmp/video_chunk.txt'
);
adwc_assert($videoCalls === 1, 'missing parent snapshot resolves fallback once');
adwc_assert($video['settings'] === $videoFallback, 'Video SfM fallback settings preserved');

$invalidCalls = 0;
$invalid = sfm_dense_worker_child_parameters(
    ['settings' => 'invalid'],
    static function () use (&$invalidCalls): array {
        $invalidCalls++;
        return ['dense' => ['max_image_size' => 640]];
    },
    9002,
    0,
    '/tmp/invalid_parent_settings.txt'
);
adwc_assert($invalidCalls === 1, 'invalid parent settings use fallback');
adwc_assert(($invalid['settings']['dense']['max_image_size'] ?? null) === 640, 'invalid settings fallback result');

adwc_assert(sfm_dense_worker_skip_automatic_mesh([
    'standalone_auto_photo_dense' => true,
    'dense_only' => true,
]), 'both markers skip mesh');
adwc_assert(!sfm_dense_worker_skip_automatic_mesh([
    'standalone_auto_photo_dense' => true,
]), 'standalone marker alone must not skip mesh');
adwc_assert(!sfm_dense_worker_skip_automatic_mesh([
    'dense_only' => true,
]), 'dense-only marker alone must not skip mesh');
adwc_assert(!sfm_dense_worker_skip_automatic_mesh([
    'standalone_auto_photo_dense' => 'true',
    'dense_only' => true,
]), 'string marker must not skip mesh');
adwc_assert(!sfm_dense_worker_skip_automatic_mesh([
    'pipeline_run_id' => 77,
    'pipeline_mode' => 'preview',
]), 'ordinary Video SfM preview keeps mesh flow');

$worker = (string)file_get_contents(__DIR__ . '/../tools/sfm_remote_worker.php');
adwc_assert(substr_count($worker, 'sfm_dense_worker_child_parameters(') >= 2, 'worker uses helper for normal and retry chunks');
adwc_assert(str_contains($worker, 'sfm_dense_worker_skip_automatic_mesh($denseMarkers)'), 'worker uses strict mesh helper');

echo "OK\n";
