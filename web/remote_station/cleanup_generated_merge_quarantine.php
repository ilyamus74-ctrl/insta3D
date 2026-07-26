<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

const GENERATED_MERGE_CLEANUP_DEFAULT_ROOT =
    '/home/makler/web/remote_station/output';

function generated_merge_cleanup_root(): string
{
    $configured = getenv('SFM_GENERATED_MERGE_OUTPUT_ROOT');
    $root = is_string($configured) && $configured !== ''
        ? rtrim($configured, DIRECTORY_SEPARATOR)
        : GENERATED_MERGE_CLEANUP_DEFAULT_ROOT;

    if ($root === '' || $root[0] !== DIRECTORY_SEPARATOR) {
        throw new RuntimeException('Cleanup root must be absolute');
    }
    if (is_link($root) || !is_dir($root)) {
        throw new RuntimeException('Cleanup root is missing or unsafe');
    }

    $real = realpath($root);
    if ($real === false || $real !== $root) {
        throw new RuntimeException(
            'Cleanup root must be a canonical directory'
        );
    }
    return $root;
}

function generated_merge_cleanup_name_is_valid(string $name): bool
{
    return preg_match(
        '/^\.delete_merge_[1-9][0-9]*_[a-f0-9]{12}$/D',
        $name
    ) === 1;
}

function generated_merge_cleanup_tree_size(string $path): int
{
    $stat = @lstat($path);
    if ($stat === false) {
        return 0;
    }
    if (is_link($path) || !is_dir($path)) {
        return (int)($stat['size'] ?? 0);
    }

    $size = (int)($stat['size'] ?? 0);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $path,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $itemStat = @lstat($item->getPathname());
        if ($itemStat !== false) {
            $size += (int)($itemStat['size'] ?? 0);
        }
    }
    return $size;
}

function generated_merge_cleanup_delete_tree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        if (!@unlink($path)) {
            throw new RuntimeException(
                'Cannot remove quarantined file: ' . $path
            );
        }
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $path,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();
        if ($item->isLink() || $item->isFile()) {
            if (!@unlink($itemPath)) {
                throw new RuntimeException(
                    'Cannot remove quarantined file: ' . $itemPath
                );
            }
            continue;
        }
        if (!@rmdir($itemPath)) {
            throw new RuntimeException(
                'Cannot remove quarantined directory: ' . $itemPath
            );
        }
    }

    if (!@rmdir($path)) {
        throw new RuntimeException(
            'Cannot remove quarantine directory: ' . $path
        );
    }
}

function generated_merge_cleanup_sweep(
    int $minimumAgeSeconds,
    bool $dryRun = false
): array {
    $root = generated_merge_cleanup_root();
    $minimumAgeSeconds = max(0, $minimumAgeSeconds);
    $now = time();
    $result = [
        'ok' => true,
        'root' => $root,
        'dry_run' => $dryRun,
        'minimum_age_seconds' => $minimumAgeSeconds,
        'examined' => 0,
        'deleted' => 0,
        'would_delete' => 0,
        'freed_bytes' => 0,
        'would_free_bytes' => 0,
        'skipped' => [],
        'errors' => [],
        'paths' => [],
    ];

    foreach (glob($root . '/.delete_merge_*') ?: [] as $path) {
        $result['examined']++;
        $name = basename($path);
        $entry = [
            'name' => $name,
            'path' => $path,
            'deleted' => false,
        ];

        try {
            if (!generated_merge_cleanup_name_is_valid($name)) {
                throw new RuntimeException('invalid quarantine name');
            }
            if (dirname($path) !== $root) {
                throw new RuntimeException('path escaped cleanup root');
            }

            $stat = @lstat($path);
            if ($stat === false) {
                $entry['status'] = 'ALREADY_ABSENT';
                $result['paths'][] = $entry;
                continue;
            }
            if (is_link($path) || (($stat['mode'] & 0170000) !== 0040000)) {
                throw new RuntimeException(
                    'quarantine entry is not a real directory'
                );
            }

            $age = max(0, $now - (int)$stat['mtime']);
            $entry['age_seconds'] = $age;
            if ($age < $minimumAgeSeconds) {
                $entry['status'] = 'TOO_NEW';
                $result['skipped'][] = $entry;
                continue;
            }

            $bytes = generated_merge_cleanup_tree_size($path);
            $entry['size_bytes'] = $bytes;
            $result['would_free_bytes'] += $bytes;

            if ($dryRun) {
                $entry['status'] = 'WOULD_DELETE';
                $result['would_delete']++;
                $result['paths'][] = $entry;
                continue;
            }

            generated_merge_cleanup_delete_tree($path);
            $entry['status'] = 'DELETED';
            $entry['deleted'] = true;
            $result['deleted']++;
            $result['freed_bytes'] += $bytes;
            $result['paths'][] = $entry;
        } catch (Throwable $error) {
            $entry['status'] = 'ERROR';
            $entry['error'] = $error->getMessage();
            $result['errors'][] = $entry;
        }
    }

    $result['ok'] = $result['errors'] === [];
    return $result;
}

function generated_merge_cleanup_cli(array $argv): int
{
    $dryRun = in_array('--dry-run', $argv, true);
    $minimumAge = max(
        0,
        (int)(getenv(
            'SFM_GENERATED_MERGE_DELETE_MIN_AGE_SECONDS'
        ) ?: 30)
    );
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--min-age=')) {
            $minimumAge = max(
                0,
                (int)substr($argument, strlen('--min-age='))
            );
        }
    }

    try {
        $result = generated_merge_cleanup_sweep(
            $minimumAge,
            $dryRun
        );
        echo json_encode(
            $result,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        ) . PHP_EOL;
        return $result['ok'] ? 0 : 2;
    } catch (Throwable $error) {
        fwrite(
            STDERR,
            json_encode(
                ['ok' => false, 'error' => $error->getMessage()],
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            ) . PHP_EOL
        );
        return 1;
    }
}

if (
    realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))
    === __FILE__
) {
    exit(generated_merge_cleanup_cli($argv));
}
