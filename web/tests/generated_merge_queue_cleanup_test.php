<?php
declare(strict_types=1);

$base = sys_get_temp_dir()
    . '/generated_merge_queue_'
    . bin2hex(random_bytes(5));
mkdir($base, 0775, true);
putenv('SFM_GENERATED_MERGE_OUTPUT_ROOT=' . $base);

require_once dirname(__DIR__)
    . '/remote_station/cleanup_generated_merge_queue.php';

function queue_test_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $target = $base . '/merged_order_31_test';
    mkdir($target . '/nested', 0755, true);
    file_put_contents($target . '/nested/model.ply', 'ply test');

    $queue = $base
        . '/.delete_merge_31_abcdef123456.queue.json';
    file_put_contents(
        $queue,
        json_encode(
            [
                'version' => 1,
                'merge_id' => 31,
                'order_id' => 31,
                'token' => 'abcdef123456',
                'created_at' => date(DATE_ATOM),
                'targets' => [
                    [
                        'kind' => 'directory',
                        'path' => $target,
                    ],
                ],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL
    );
    touch($queue, time() - 120);

    $dry = generated_merge_queue_sweep(30, true);
    queue_test_ok($dry['ok'], 'queue dry-run failed');
    queue_test_ok(
        $dry['would_delete'] === 1,
        'queue was not planned for deletion'
    );
    queue_test_ok(is_dir($target), 'dry-run deleted target');
    queue_test_ok(is_file($queue), 'dry-run deleted queue');

    $run = generated_merge_queue_sweep(30, false);
    queue_test_ok($run['ok'], 'queue cleanup failed');
    queue_test_ok($run['deleted'] === 1, 'queue was not processed');
    queue_test_ok(!file_exists($target), 'target still exists');
    queue_test_ok(!file_exists($queue), 'queue still exists');

    $legacy = $base . '/.delete_merge_44_123456abcdef';
    mkdir($legacy . '/nested', 0755, true);
    file_put_contents($legacy . '/nested/result.json', '{}');
    touch($legacy, time() - 120);

    $legacyRun = generated_merge_queue_sweep(30, false);
    queue_test_ok($legacyRun['ok'], 'legacy cleanup failed');
    queue_test_ok(
        $legacyRun['deleted'] === 1,
        'legacy quarantine was not deleted'
    );
    queue_test_ok(!file_exists($legacy), 'legacy quarantine remains');

    $invalid = $base . '/.delete_merge_bad';
    mkdir($invalid, 0755, true);
    touch($invalid, time() - 120);

    $invalidRun = generated_merge_queue_sweep(30, false);
    queue_test_ok(!$invalidRun['ok'], 'invalid entry must be reported');
    queue_test_ok(is_dir($invalid), 'invalid entry was deleted');

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
