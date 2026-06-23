<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/sfm_dense_merge_contract.php';

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

assert_same('preview', resolve_dense_merge_mode('COLMAP_RECONSTRUCTION_PREVIEW'), 'PREVIEW must resolve to preview');
assert_same('hq', resolve_dense_merge_mode('COLMAP_RECONSTRUCTION_HQ'), 'HQ must resolve to hq');
assert_true(resolve_dense_merge_mode('COLMAP_RECONSTRUCTION_PREVIEW') !== 'standard', 'standard must never be a merge mode for PREVIEW');
assert_true(resolve_dense_merge_mode('COLMAP_RECONSTRUCTION_HQ') !== 'standard', 'standard must never be a merge mode for HQ');

try {
    resolve_dense_merge_mode('COLMAP_RECONSTRUCTION_STANDARD');
    throw new RuntimeException('unknown job type did not fail');
} catch (RuntimeException $e) {
    assert_same('Unsupported dense merge job type: COLMAP_RECONSTRUCTION_STANDARD', $e->getMessage(), 'unknown job type error message');
}

$runtime = __DIR__ . '/.runtime_dense_merge_contract';
$parentOutputDir = $runtime . '/job_123';
@mkdir($parentOutputDir . '/chunks/chunk_0', 0775, true);
@mkdir($parentOutputDir . '/chunks/chunk_1', 0775, true);
file_put_contents($parentOutputDir . '/chunks/chunk_0/fused.ply', "ply\n");
file_put_contents($parentOutputDir . '/chunks/chunk_1/fused.ply', "ply\n");

$expectedPly = [
    $parentOutputDir . '/chunks/chunk_0/fused.ply',
    $parentOutputDir . '/chunks/chunk_1/fused.ply',
];
assert_same($expectedPly, dense_merge_input_ply_files($parentOutputDir, 2), 'requeue must reuse existing chunk PLY paths');
assert_same([
    $expectedPly[0] => 4,
    $expectedPly[1] => 4,
], dense_merge_input_ply_sizes($expectedPly), 'input PLY sizes must be reported');

unlink($expectedPly[0]);
unlink($expectedPly[1]);
rmdir($parentOutputDir . '/chunks/chunk_0');
rmdir($parentOutputDir . '/chunks/chunk_1');
rmdir($parentOutputDir . '/chunks');
rmdir($parentOutputDir);
rmdir($runtime);

echo "dense merge contract checks passed\n";