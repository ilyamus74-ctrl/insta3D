<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$documents = [
    'docs/llm/tasks/SFM-POST-WORKBENCH-ROADMAP.md' => [
        'SFM-ASSEMBLY-WORKBENCH-B',
        'SFM-VIEWER-FREE-ORBIT-A',
        'SFM-MANUAL-VISUAL-ALIGN-A',
        'SFM-MANUAL-VISUAL-ALIGN-B',
        'resume LightGlue bridge POC',
    ],
    'docs/llm/tasks/SFM-ASSEMBLY-WORKBENCH-B.md' => [
        'Anchor',
        'Moving source',
        'anchor_kind = remote | merge',
        'source_kind = remote',
        'Duplicate leaf jobs are removed',
    ],
    'docs/llm/tasks/SFM-VIEWER-FREE-ORBIT-A.md' => [
        'Horizon locked',
        'Free orbit 360',
        'must not change',
        'PLY coordinates',
    ],
    'docs/llm/tasks/SFM-MANUAL-VISUAL-ALIGN-A.md' => [
        'combined viewport',
        'uniform scale',
        'Moving source → Anchor',
        'manual_visual_sim3_dense_ply',
        'Point correspondence remains',
    ],
    'docs/llm/tasks/SFM-MANUAL-VISUAL-ALIGN-B.md' => [
        'local ICP',
        'initial_visual_matrix4',
        'refined_matrix4',
        'fitness',
        'inlier RMSE',
        'manual_visual_icp_dense_ply',
    ],
];

foreach ($documents as $relative => $requiredTerms) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('Missing roadmap document: ' . $relative);
    }

    $source = (string) file_get_contents($path);
    foreach ($requiredTerms as $term) {
        if (!str_contains($source, $term)) {
            throw new RuntimeException(
                $relative . ' is missing required contract: ' . $term
            );
        }
    }
}

echo "OK\n";
