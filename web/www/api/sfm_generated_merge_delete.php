<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

auth_require_login();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function merge_delete_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function merge_delete_inside(string $path, string $root): bool
{
    $real = realpath($path);
    return $real !== false
        && (
            $real === $root
            || str_starts_with(
                $real,
                rtrim($root, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR
            )
        );
}

function merge_delete_can(array $row, int $uid, string $role): bool
{
    return $role === 'ADMIN'
        || (int)$row['broker_id'] === $uid
        || (
            $role === 'OPERATOR'
            && (int)$row['operator_id'] === $uid
        );
}

function merge_delete_payload_references(
    mixed $value,
    int $mergeId
): bool {
    if (!is_array($value)) {
        return false;
    }

    foreach (
        ['parent_merge_id', 'anchor_merge_id', 'source_merge_id']
        as $field
    ) {
        if (
            array_key_exists($field, $value)
            && (int)$value[$field] === $mergeId
        ) {
            return true;
        }
    }

    if (
        isset($value['kind'], $value['merge_id'])
        && in_array(
            strtolower((string)$value['kind']),
            ['merge', 'assembly'],
            true
        )
        && (int)$value['merge_id'] === $mergeId
    ) {
        return true;
    }

    foreach ($value as $child) {
        if (
            is_array($child)
            && merge_delete_payload_references($child, $mergeId)
        ) {
            return true;
        }
    }

    return false;
}

function merge_delete_recursive(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        if (!@unlink($path)) {
            throw new RuntimeException(
                'Cannot delete quarantined file: ' . $path
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
                    'Cannot delete quarantined file: ' . $itemPath
                );
            }
        } elseif (!@rmdir($itemPath)) {
            throw new RuntimeException(
                'Cannot delete quarantined directory: ' . $itemPath
            );
        }
    }

    if (!@rmdir($path)) {
        throw new RuntimeException(
            'Cannot delete quarantine directory: ' . $path
        );
    }
}

function merge_delete_restore(array $moves): void
{
    foreach (array_reverse($moves) as $move) {
        [$original, $quarantined] = $move;
        if (
            (file_exists($quarantined) || is_link($quarantined))
            && !file_exists($original)
            && !is_link($original)
        ) {
            @mkdir(dirname($original), 0775, true);
            @rename($quarantined, $original);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    merge_delete_reply(
        ['ok' => false, 'error' => 'POST required'],
        405
    );
}

$expected = (string)($_SESSION['secCode'] ?? '');
$provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (
    $expected === ''
    || $provided === ''
    || !hash_equals($expected, $provided)
) {
    merge_delete_reply(
        ['ok' => false, 'error' => 'CSRF token mismatch'],
        403
    );
}

$input = json_decode(
    (string)file_get_contents('php://input'),
    true
);
$orderId = max(0, (int)($input['order_id'] ?? 0));
$mergeId = max(0, (int)($input['merge_id'] ?? 0));
$confirmation = max(
    0,
    (int)($input['confirm_merge_id'] ?? 0)
);

if (
    $orderId <= 0
    || $mergeId <= 0
    || $confirmation !== $mergeId
) {
    merge_delete_reply(
        ['ok' => false, 'error' => 'Invalid confirmation'],
        400
    );
}

$user = auth_current_user();
$uid = (int)$user['id'];
$role = (string)($user['role'] ?? 'BROKER');
$outputRoot = realpath(
    '/home/makler/web/remote_station/output'
);
if ($outputRoot === false) {
    merge_delete_reply(
        ['ok' => false, 'error' => 'Output root not found'],
        500
    );
}

$moves = [];
$quarantine = '';
$quarantineTemp = '';
$cleanupTargets = [];
$committed = false;

try {
    $dbcnx->begin_transaction();

    $statement = $dbcnx->prepare(
        'SELECT m.*,o.broker_id,o.operator_id '
        . 'FROM sfm_generated_model_merges m '
        . 'JOIN tour_orders o ON o.id=m.order_id '
        . 'WHERE m.id=? AND m.order_id=? '
        . 'LIMIT 1 FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException(
            'DB prepare error: ' . $dbcnx->error
        );
    }
    $statement->bind_param('ii', $mergeId, $orderId);
    $statement->execute();
    $merge = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!$merge) {
        throw new RuntimeException('Assembly not found');
    }
    if (!merge_delete_can($merge, $uid, $role)) {
        throw new RuntimeException('Forbidden');
    }

    $dependents = [];
    $statement = $dbcnx->prepare(
        'SELECT id,source_jobs_json,result_json_path '
        . 'FROM sfm_generated_model_merges '
        . 'WHERE order_id=? AND id<>?'
    );
    if (!$statement) {
        throw new RuntimeException(
            'DB prepare error: ' . $dbcnx->error
        );
    }
    $statement->bind_param('ii', $orderId, $mergeId);
    $statement->execute();
    $result = $statement->get_result();

    while ($candidate = $result->fetch_assoc()) {
        $references = false;
        $sourcePayload = json_decode(
            (string)($candidate['source_jobs_json'] ?? ''),
            true
        );
        if (
            is_array($sourcePayload)
            && merge_delete_payload_references(
                $sourcePayload,
                $mergeId
            )
        ) {
            $references = true;
        }

        $resultPath = (string)(
            $candidate['result_json_path'] ?? ''
        );
        if (
            !$references
            && $resultPath !== ''
            && merge_delete_inside($resultPath, $outputRoot)
            && is_file($resultPath)
        ) {
            $resultPayload = json_decode(
                (string)file_get_contents($resultPath),
                true
            );
            if (
                is_array($resultPayload)
                && merge_delete_payload_references(
                    $resultPayload,
                    $mergeId
                )
            ) {
                $references = true;
            }
        }

        if ($references) {
            $dependents[] = (int)$candidate['id'];
        }
    }
    $statement->close();

    if ($dependents) {
        throw new RuntimeException(
            'Assembly #' . $mergeId
            . ' используется сборками: '
            . implode(', ', $dependents)
        );
    }

    $paths = array_values(array_unique(array_filter([
        (string)($merge['output_path'] ?? ''),
        (string)($merge['result_json_path'] ?? ''),
    ])));

    foreach ($paths as $path) {
        if (
            file_exists($path)
            && !merge_delete_inside($path, $outputRoot)
        ) {
            throw new RuntimeException(
                'Assembly path is outside output root: ' . $path
            );
        }
    }

    $dedicated = [];
    foreach ($paths as $path) {
        if (!file_exists($path)) {
            continue;
        }
        $directory = realpath(dirname($path));
        if (
            $directory === false
            || !merge_delete_inside($directory, $outputRoot)
        ) {
            continue;
        }

        $basename = basename($directory);
        $legacy = str_starts_with(
            $basename,
            'merged_order_' . $orderId . '_'
        );
        $accepted =
            $basename === 'merge_' . $mergeId
            && str_contains(
                $directory,
                DIRECTORY_SEPARATOR
                    . 'accepted_manual_alignments'
                    . DIRECTORY_SEPARATOR
                    . 'order_' . $orderId
                    . DIRECTORY_SEPARATOR
            );

        if ($legacy || $accepted) {
            $dedicated[$directory] = true;
        }
    }

    foreach (array_keys($dedicated) as $directory) {
        $cleanupTargets[] = [
            'kind' => 'directory',
            'path' => $directory,
        ];
    }

    foreach ($paths as $path) {
        if (!file_exists($path) && !is_link($path)) {
            continue;
        }

        $covered = false;
        foreach (array_keys($dedicated) as $directory) {
            if (
                $path === $directory
                || str_starts_with(
                    $path,
                    rtrim($directory, DIRECTORY_SEPARATOR)
                        . DIRECTORY_SEPARATOR
                )
            ) {
                $covered = true;
                break;
            }
        }
        if ($covered) {
            continue;
        }

        $real = realpath($path);
        if (
            $real === false
            || !merge_delete_inside($real, $outputRoot)
            || !is_file($real)
        ) {
            throw new RuntimeException(
                'Cannot queue assembly file for deletion: ' . $path
            );
        }

        $cleanupTargets[] = [
            'kind' => 'file',
            'path' => $real,
        ];
    }

    $quarantineToken = bin2hex(random_bytes(6));
    $quarantine = $outputRoot
        . '/.delete_merge_'
        . $mergeId
        . '_'
        . $quarantineToken
        . '.queue.json';
    $quarantineTemp = $quarantine . '.tmp';
    $queuePayload = [
        'version' => 1,
        'merge_id' => $mergeId,
        'order_id' => $orderId,
        'token' => $quarantineToken,
        'created_at' => date(DATE_ATOM),
        'targets' => $cleanupTargets,
    ];
    $queueJson = json_encode(
        $queuePayload,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    if (
        @file_put_contents($quarantineTemp, $queueJson, LOCK_EX)
        === false
    ) {
        throw new RuntimeException('Cannot create deletion queue');
    }
    @chmod($quarantineTemp, 0600);

    if (!$cleanupTargets) {
        error_log(
            'Generated merge deletion queued without filesystem targets: '
            . $mergeId
        );
    }

    $statement = $dbcnx->prepare(
        'DELETE FROM sfm_generated_model_merges '
        . 'WHERE id=? AND order_id=?'
    );
    if (!$statement) {
        throw new RuntimeException(
            'DB prepare error: ' . $dbcnx->error
        );
    }
    $statement->bind_param('ii', $mergeId, $orderId);
    $statement->execute();
    if ($statement->affected_rows !== 1) {
        $statement->close();
        throw new RuntimeException(
            'Assembly row was not deleted'
        );
    }
    $statement->close();
    $dbcnx->commit();
    $committed = true;

    // Publish the queue only after the database commit. The root-side timer
    // validates and removes the original root-owned paths asynchronously.
    $cleanupQueued = @rename($quarantineTemp, $quarantine);
    if ($cleanupQueued) {
        $quarantineTemp = '';
    }
    $cleanupWarning = $cleanupQueued
        ? null
        : 'Assembly row was deleted, but the filesystem cleanup queue '
            . 'could not be published';
    if ($cleanupWarning !== null) {
        error_log($cleanupWarning . ': ' . $quarantineTemp);
    }

    if (function_exists('audit_log')) {
        audit_log(
            $uid,
            'SFM_GENERATED_MERGE_DELETED',
            'TOUR_ORDER',
            $orderId,
            'Generated assembly deleted',
            [
                'merge_id' => $mergeId,
                'merge_type' => (string)$merge['merge_type'],
                'cleanup_warning' => $cleanupWarning,
                'cleanup_queued' => $cleanupQueued,
                'quarantine_name' => basename($quarantine),
            ]
        );
    }

    merge_delete_reply([
        'ok' => true,
        'merge_id' => $mergeId,
        'deleted_paths' => count($cleanupTargets),
        'cleanup_warning' => $cleanupWarning,
        'cleanup_queued' => $cleanupQueued,
        'quarantine_name' => basename($quarantine),
    ]);
} catch (Throwable $error) {
    if (!$committed) {
        try {
            $dbcnx->rollback();
        } catch (Throwable) {
        }

        merge_delete_restore($moves);
        $quarantineTemp !== '' && @unlink($quarantineTemp);
        $quarantine !== '' && @unlink($quarantine);
    }

    $message = $error->getMessage();
    $status = str_contains(
        $message,
        'используется сборками'
    ) ? 409 : ($message === 'Forbidden' ? 403 : 400);

    merge_delete_reply(
        ['ok' => false, 'error' => $message],
        $status
    );
}
