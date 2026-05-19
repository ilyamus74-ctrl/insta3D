<?php
declare(strict_types=1);

require_once '/home/makler/web/configs/connectDB.php';

if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) {
    fwrite(STDERR, "DB connection is not available\n");
    exit(1);
}

$commit = in_array('--commit', $argv, true);
$storageRoot = '/home/makler/web/storage';

function vlog_action(string $type, string $message): void {
    echo sprintf("[%s] %s\n", $type, $message);
}

$res = $dbcnx->query("SELECT vs.id, vs.session_id, vs.storage_path, cs.order_id AS real_order_id, cs.app_session_uuid FROM video_scans vs JOIN capture_sessions cs ON cs.id=vs.session_id WHERE vs.deleted_at IS NULL AND vs.storage_path NOT LIKE CONCAT('orders/', cs.order_id, '/%') ORDER BY vs.id ASC");
if (!$res) {
    fwrite(STDERR, "Query failed: {$dbcnx->error}\n");
    exit(1);
}

while ($row = $res->fetch_assoc()) {
    $id = (int)$row['id'];
    $sessionId = (int)$row['session_id'];
    $realOrderId = (int)$row['real_order_id'];
    $safeSessionUuid = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)$row['app_session_uuid']);
    if (!is_string($safeSessionUuid) || $safeSessionUuid === '') {
        $safeSessionUuid = 'session_' . $sessionId;
    }

    $oldRel = (string)$row['storage_path'];
    $filename = basename($oldRel);
    $newRel = 'orders/' . $realOrderId . '/sessions/' . $safeSessionUuid . '/videos/' . $filename;

    $src = $storageRoot . '/' . $oldRel;
    $dst = $storageRoot . '/' . $newRel;

    if (!is_file($src)) {
        vlog_action('MISSING_SOURCE', "video_scan_id={$id} {$oldRel}");
    } elseif (is_file($dst)) {
        vlog_action('EXISTS', "video_scan_id={$id} {$newRel}");
    } else {
        vlog_action('COPY', "video_scan_id={$id} {$oldRel} -> {$newRel}");
        if ($commit) {
            $dir = dirname($dst);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            if (@copy($src, $dst)) {
                @chmod($dst, 0664);
            } else {
                vlog_action('SKIP', "video_scan_id={$id} copy failed");
            }
        }
    }

    if ($commit) {
        $stmt = $dbcnx->prepare('UPDATE video_scans SET storage_path = ?, updated_at = NOW(6) WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $newRel, $id);
            if ($stmt->execute()) {
                vlog_action('UPDATE_DB', "video_scan_id={$id}");
            } else {
                vlog_action('SKIP', "video_scan_id={$id} db execute failed: {$stmt->error}");
            }
            $stmt->close();
        }
    } else {
        vlog_action('UPDATE_DB', "video_scan_id={$id} (dry-run)");
    }
}

vlog_action('SKIP', $commit ? 'Completed in commit mode' : 'Completed in dry-run mode');