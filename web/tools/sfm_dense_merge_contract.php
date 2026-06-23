<?php
declare(strict_types=1);

function resolve_dense_merge_mode(string $jobType): string
{
    $mapping = [
        'COLMAP_RECONSTRUCTION_PREVIEW' => 'preview',
        'COLMAP_RECONSTRUCTION_HQ' => 'hq',
    ];

    if (!array_key_exists($jobType, $mapping)) {
        throw new RuntimeException('Unsupported dense merge job type: ' . $jobType);
    }

    return $mapping[$jobType];
}

function dense_merge_input_ply_files(string $parentOutputDir, int $chunkCount): array
{
    $files = [];
    for ($i = 0; $i < $chunkCount; $i++) {
        $files[] = rtrim($parentOutputDir, '/') . '/chunks/chunk_' . $i . '/fused.ply';
    }
    return $files;
}

function dense_merge_input_ply_sizes(array $inputPlyFiles): array
{
    $sizes = [];
    foreach ($inputPlyFiles as $inputPly) {
        $path = (string)$inputPly;
        if (!is_file($path)) {
            throw new RuntimeException('Dense merge input PLY is missing: ' . $path);
        }
        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('Dense merge input PLY is empty: ' . $path);
        }
        $sizes[$path] = $size;
    }
    return $sizes;
}