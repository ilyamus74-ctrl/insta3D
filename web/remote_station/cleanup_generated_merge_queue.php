<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/cleanup_generated_merge_quarantine.php';

function generated_merge_queue_name_parse(string $name): ?array
{
    if (
        preg_match(
            '/^\.delete_merge_([1-9][0-9]*)_([a-f0-9]{12})\.queue\.json$/D',
            $name,
            $matches
        ) !== 1
    ) {
        return null;
    }

    return [
        'merge_id' => (int)$matches[1],
        'token' => $matches[2],
    ];
}

function generated_merge_queue_inside(string $path, string $root): bool
{
    $real = realpath($path);
    return $real !== false
        && $real !== $root
        && str_starts_with(
            $real,
            rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        );
}

function generated_merge_queue_load_targets(
    string $queuePath,
    string $root,
    array $queueName
): array {
    if (is_link($queuePath) || !is_file($queuePath)) {
        throw new RuntimeException('queue entry is not a regular file');
    }

    $payload = json_decode(
        (string)file_get_contents($queuePath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($payload)) {
        throw new RuntimeException('queue payload must be an object');
    }

    $mergeId = (int)($payload['merge_id'] ?? 0);
    $orderId = (int)($payload['order_id'] ?? 0);
    $token = (string)($payload['token'] ?? '');
    $targets = $payload['targets'] ?? null;

    if ((int)($payload['version'] ?? 0) !== 1) {
        throw new RuntimeException('unsupported queue version');
    }
    if ($mergeId !== (int)$queueName['merge_id']) {
        throw new RuntimeException('queue merge id mismatch');
    }
    if ($orderId <= 0) {
        throw new RuntimeException('queue order id is invalid');
    }
    if ($token !== (string)$queueName['token']) {
        throw new RuntimeException('queue token mismatch');
    }
    if (!is_array($targets)) {
        throw new RuntimeException('queue targets must be an array');
    }

    $validated = [];
    $seen = [];
    foreach ($targets as $index => $target) {
        if (!is_array($target)) {
            throw new RuntimeException(
                'queue target #' . $index . ' must be an object'
            );
        }

        $kind = (string)($target['kind'] ?? '');
        $path = (string)($target['path'] ?? '');
        if (!in_array($kind, ['directory', 'file'], true)) {
            throw new RuntimeException(
                'queue target #' . $index . ' has invalid kind'
            );
        }
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR) {
            throw new RuntimeException(
                'queue target #' . $index . ' is not absolute'
            );
        }

        if (!file_exists($path) && !is_link($path)) {
            $validated[] = [
                'kind' => $kind,
                'path' => $path,
                'exists' => false,
            ];
            continue;
        }

        $real = realpath($path);
        if (
            $real === false
            || $real !== $path
            || !generated_merge_queue_inside($real, $root)
        ) {
            throw new RuntimeException(
                'queue target #' . $index . ' escaped cleanup root'
            );
        }
        if (isset($seen[$real])) {
            continue;
        }
        $seen[$real] = true;

        if ($kind === 'directory') {
            if (is_link($real) || !is_dir($real)) {
                throw new RuntimeException(
                    'queue target #' . $index . ' is not a real directory'
                );
            }

            $basename = basename($real);
            $legacy = str_starts_with(
                $basename,
                'merged_order_' . $orderId . '_'
            );
            $accepted =
                $basename === 'merge_' . $mergeId
                && str_contains(
                    $real,
                    DIRECTORY_SEPARATOR
                        . 'accepted_manual_alignments'
                        . DIRECTORY_SEPARATOR
                        . 'order_' . $orderId
                        . DIRECTORY_SEPARATOR
                        . 'merge_' . $mergeId
                );
            if (!$legacy && !$accepted) {
                throw new RuntimeException(
                    'queue target #' . $index
                    . ' is not an allowed assembly directory'
                );
            }
        } elseif (is_link($real) || !is_file($real)) {
            throw new RuntimeException(
                'queue target #' . $index . ' is not a regular file'
            );
        }

        $validated[] = [
            'kind' => $kind,
            'path' => $real,
            'exists' => true,
        ];
    }

    return $validated;
}

function generated_merge_queue_sweep(
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

    foreach (glob($root . '/.delete_merge_*') ?: [] as $entryPath) {
        $result['examined']++;
        $name = basename($entryPath);
        $entry = [
            'name' => $name,
            'path' => $entryPath,
            'deleted' => false,
        ];

        try {
            if (str_ends_with($name, '.queue.json.tmp')) {
                $entry['status'] = 'PENDING_QUEUE';
                $result['skipped'][] = $entry;
                continue;
            }

            $stat = @lstat($entryPath);
            if ($stat === false) {
                $entry['status'] = 'ALREADY_ABSENT';
                $result['paths'][] = $entry;
                continue;
            }

            $age = max(0, $now - (int)$stat['mtime']);
            $entry['age_seconds'] = $age;
            if ($age < $minimumAgeSeconds) {
                $entry['status'] = 'TOO_NEW';
                $result['skipped'][] = $entry;
                continue;
            }

            $queueName = generated_merge_queue_name_parse($name);
            if ($queueName !== null) {
                $targets = generated_merge_queue_load_targets(
                    $entryPath,
                    $root,
                    $queueName
                );
                $bytes = 0;
                foreach ($targets as $target) {
                    if ($target['exists']) {
                        $bytes += generated_merge_cleanup_tree_size(
                            $target['path']
                        );
                    }
                }
                $entry['targets'] = $targets;
                $entry['size_bytes'] = $bytes;
                $result['would_free_bytes'] += $bytes;

                if ($dryRun) {
                    $entry['status'] = 'WOULD_DELETE';
                    $result['would_delete']++;
                    $result['paths'][] = $entry;
                    continue;
                }

                foreach ($targets as $target) {
                    if ($target['exists']) {
                        generated_merge_cleanup_delete_tree(
                            $target['path']
                        );
                    }
                }
                if (!@unlink($entryPath)) {
                    throw new RuntimeException('cannot remove processed queue');
                }

                $entry['status'] = 'DELETED';
                $entry['deleted'] = true;
                $result['deleted']++;
                $result['freed_bytes'] += $bytes;
                $result['paths'][] = $entry;
                continue;
            }

            if (
                generated_merge_cleanup_name_is_valid($name)
                && is_dir($entryPath)
                && !is_link($entryPath)
            ) {
                $bytes = generated_merge_cleanup_tree_size($entryPath);
                $entry['size_bytes'] = $bytes;
                $result['would_free_bytes'] += $bytes;

                if ($dryRun) {
                    $entry['status'] = 'WOULD_DELETE_LEGACY';
                    $result['would_delete']++;
                    $result['paths'][] = $entry;
                    continue;
                }

                generated_merge_cleanup_delete_tree($entryPath);
                $entry['status'] = 'DELETED_LEGACY';
                $entry['deleted'] = true;
                $result['deleted']++;
                $result['freed_bytes'] += $bytes;
                $result['paths'][] = $entry;
                continue;
            }

            throw new RuntimeException('invalid cleanup queue entry');
        } catch (Throwable $error) {
            $entry['status'] = 'ERROR';
            $entry['error'] = $error->getMessage();
            $result['errors'][] = $entry;
        }
    }

    $result['ok'] = $result['errors'] === [];
    return $result;
}

function generated_merge_queue_cli(array $argv): int
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
        $result = generated_merge_queue_sweep(
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
    exit(generated_merge_queue_cli($argv));
}
