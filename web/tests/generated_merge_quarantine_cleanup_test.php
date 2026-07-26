<?php
declare(strict_types=1);

$base = sys_get_temp_dir()
    . '/generated_merge_cleanup_'
    . bin2hex(random_bytes(5));
mkdir($base, 0775, true);
putenv('SFM_GENERATED_MERGE_OUTPUT_ROOT=' . $base);

require_once dirname(__DIR__)
    . '/remote_station/cleanup_generated_merge_quarantine.php';

function cleanup_test_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $queue = $base . '/.delete_merge_31_abcdef123456';
    mkdir($queue . '/nested', 0755, true);
    file_put_contents($queue . '/nested/model.ply', 'ply test');
    touch($queue, time() - 120);

    $dry = generated_merge_cleanup_sweep(30, true);
    cleanup_test_ok($dry['ok'], 'dry-run failed');
    cleanup_test_ok(
        $dry['would_delete'] === 1,
        'valid quarantine was not planned'
    );
    cleanup_test_ok(is_dir($queue), 'dry-run deleted quarantine');

    $invalid = $base . '/.delete_merge_bad';
    mkdir($invalid, 0755, true);
    touch($invalid, time() - 120);

    $run = generated_merge_cleanup_sweep(30, false);
    cleanup_test_ok(!$run['ok'], 'invalid entry must be reported');
    cleanup_test_ok($run['deleted'] === 1, 'valid quarantine not deleted');
    cleanup_test_ok(!file_exists($queue), 'quarantine still exists');
    cleanup_test_ok(is_dir($invalid), 'invalid entry was deleted');

    echo "OK\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $base,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $item->isDir() ? @rmdir($path) : @unlink($path);
    }
    @rmdir($base);
}
