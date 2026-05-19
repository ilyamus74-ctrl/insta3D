<?php
declare(strict_types=1);

require_once '/home/makler/web/configs/connectDB.php';

if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) {
    fwrite(STDERR, "DB connection is not available\n");
    exit(1);
}

$commit = in_array('--commit', $argv, true);
$storageRoot = '/home/makler/web/storage';

function log_action(string $type, string $message): void {
    echo sprintf("[%s] %s\n", $type, $message);
}

function normalize_target_path(string $oldPath, int $realOrderId, string $safeSessionUuid): ?string {
    $newPrefix = 'orders/' . $realOrderId . '/sessions/' . $safeSessionUuid . '/';
    $newPath = preg_replace('~^orders/[0-9]+/sessions/[^/]+/~', $newPrefix, $oldPath, 1);
    return is_string($newPath) ? $newPath : null;
}

function copy_if_needed(string $storageRoot, string $srcRel, string $dstRel, bool $commit): void {
    $src = $storageRoot . '/' . $srcRel;
    $dst = $storageRoot . '/' . $dstRel;

    if (!is_file($src)) {
        log_action('MISSING_SOURCE', $srcRel);
        return;
    }

    if (is_file($dst)) {
        log_action('EXISTS', $dstRel);
        return;
    }

    log_action('COPY', $srcRel . ' -> ' . $dstRel);
    if (!$commit) {
        return;
    }

    $dstDir = dirname($dst);
    if (!is_dir($dstDir) && !mkdir($dstDir, 0775, true) && !is_dir($dstDir)) {
        log_action('SKIP', 'Failed to create dir: ' . $dstDir);
        return;
    }

    if (!@copy($src, $dst)) {
        log_action('SKIP', 'Copy failed: ' . $srcRel . ' -> ' . $dstRel);
        return;
    }

    @chmod($dst, 0664);
}

$sql = "
SELECT
  pp.id AS photo_point_id,
  pp.session_id,
  cs.order_id AS real_order_id,
  cs.app_session_uuid,
  pp.preview_storage_path,
  pp.original_storage_path
FROM photo_points pp
JOIN capture_sessions cs ON cs.id = pp.session_id
WHERE pp.deleted_at IS NULL
  AND (
    pp.preview_storage_path NOT LIKE CONCAT('orders/', cs.order_id, '/%')
    OR pp.original_storage_path NOT LIKE CONCAT('orders/', cs.order_id, '/%')
  )
ORDER BY pp.id ASC
";

$res = $dbcnx->query($sql);
if (!$res) {
    fwrite(STDERR, "Query failed: {$dbcnx->error}\n");
    exit(1);
}

while ($row = $res->fetch_assoc()) {
    $photoPointId = (int)$row['photo_point_id'];
    $realOrderId = (int)$row['real_order_id'];
    $sessionId = (int)$row['session_id'];
    $safeSessionUuid = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)$row['app_session_uuid']);
    if (!is_string($safeSessionUuid) || $safeSessionUuid === '') {
        $safeSessionUuid = 'session_' . $sessionId;
    }

    $oldPreview = (string)($row['preview_storage_path'] ?? '');
    $oldOriginal = (string)($row['original_storage_path'] ?? '');

    $newPreview = normalize_target_path($oldPreview, $realOrderId, $safeSessionUuid);
    $newOriginal = normalize_target_path($oldOriginal, $realOrderId, $safeSessionUuid);

    if (!$newPreview || !$newOriginal || $newPreview === '' || $newOriginal === '') {
        log_action('SKIP', "photo_point_id={$photoPointId} invalid target path");
        continue;
    }

    if ($oldPreview === $newPreview && $oldOriginal === $newOriginal) {
        log_action('SKIP', "photo_point_id={$photoPointId} already aligned");
        continue;
    }

    copy_if_needed($storageRoot, $oldPreview, $newPreview, $commit);
    copy_if_needed($storageRoot, $oldOriginal, $newOriginal, $commit);

    $filename = basename($oldOriginal);
    $oldBasePrefix = preg_replace('~/photos/[^/]+/[^/]+$~', '', $oldOriginal);
    $newBasePrefix = preg_replace('~/photos/[^/]+/[^/]+$~', '', $newOriginal);

    if (is_string($oldBasePrefix) && is_string($newBasePrefix) && $filename !== '') {
        foreach (['raw_dualfisheye', 'viewer_light', 'viewer_hd', 'previews', 'originals'] as $sub) {
            $srcRel = $oldBasePrefix . '/photos/' . $sub . '/' . $filename;
            $dstRel = $newBasePrefix . '/photos/' . $sub . '/' . $filename;
            copy_if_needed($storageRoot, $srcRel, $dstRel, $commit);
        }
    }

    if ($commit) {
        $stmt = $dbcnx->prepare("UPDATE photo_points SET preview_storage_path = ?, original_storage_path = ?, updated_at = NOW(6) WHERE id = ?");
        if (!$stmt) {
            log_action('SKIP', "photo_point_id={$photoPointId} db prepare failed: {$dbcnx->error}");
            continue;
        }
        $stmt->bind_param('ssi', $newPreview, $newOriginal, $photoPointId);
        if ($stmt->execute()) {
            log_action('UPDATE_DB', "photo_point_id={$photoPointId}");
        } else {
            log_action('SKIP', "photo_point_id={$photoPointId} db execute failed: {$stmt->error}");
        }
        $stmt->close();
    } else {
        log_action('UPDATE_DB', "photo_point_id={$photoPointId} (dry-run)");
    }
}

log_action('SKIP', $commit ? 'Completed in commit mode' : 'Completed in dry-run mode');