<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }

require_once __DIR__ . '/../www/bootstrap.php';
require_once __DIR__ . '/../libs/tour_media_derivatives_lib.php';
require_once __DIR__ . '/../libs/tour_stitching_lib.php';

$args = getopt('', ['session-id:', 'commit', 'overwrite']);
$sessionId = isset($args['session-id']) ? (int)$args['session-id'] : 0;
$commit = array_key_exists('commit', $args);
$overwrite = array_key_exists('overwrite', $args);

if ($sessionId <= 0) {
    echo json_encode(['ok'=>false,'error'=>'missing_session_id'], JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

$db = $dbcnx;
$stmt = $db->prepare('SELECT id, original_storage_path FROM photo_points WHERE session_id=? ORDER BY id');
$stmt->bind_param('i', $sessionId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$summary = ['ok'=>true,'session_id'=>$sessionId,'processed'=>0,'stitched'=>0,'errors'=>[],'dry_run'=>!$commit];
foreach ($rows as $row) {
    $summary['processed']++;
    $pid = (int)$row['id'];
    $orig = trim((string)$row['original_storage_path']);
    if ($orig === '' || !str_contains($orig, '/photos/originals/')) { $summary['ok']=false; $summary['errors'][]=['photo_point_id'=>$pid,'error'=>'invalid_original_path']; continue; }

    $rawRel = str_replace('/photos/originals/', '/photos/raw_dualfisheye/', $orig);
    $origAbs = tour_storage_abs_path($orig);
    $rawAbs = tour_storage_abs_path($rawRel);

    if (!$commit) { continue; }

    if (!is_file($origAbs)) { $summary['ok']=false; $summary['errors'][]=['photo_point_id'=>$pid,'error'=>'original_missing']; continue; }
    if (!is_dir(dirname($rawAbs)) && !@mkdir(dirname($rawAbs), 0775, true)) { $summary['ok']=false; $summary['errors'][]=['photo_point_id'=>$pid,'error'=>'raw_dir_create_failed']; continue; }
    if (($overwrite || !is_file($rawAbs)) && !@copy($origAbs, $rawAbs)) { $summary['ok']=false; $summary['errors'][]=['photo_point_id'=>$pid,'error'=>'raw_copy_failed']; continue; }

    $st = tour_stitch_dualfisheye_to_equirect($rawAbs, $origAbs);
    if (!$st['ok']) { $summary['ok']=false; $summary['errors'][]=['photo_point_id'=>$pid,'error'=>'stitch_failed','details'=>$st]; continue; }

    $size = (int)$st['size_bytes'];
    $u = $db->prepare('UPDATE photo_points SET original_size_bytes=?, updated_at=NOW(6) WHERE id=?');
    $u->bind_param('ii', $size, $pid);
    $u->execute();
    $u->close();

    $v = tour_ensure_photo_viewer_derivatives($orig, true);
    $p = tour_ensure_photo_preview_from_original($orig, true);
    if (!$v['ok'] || !$p['ok']) {
        $summary['ok'] = false;
        $summary['errors'][] = ['photo_point_id'=>$pid,'error'=>'derivatives_failed','viewer'=>$v,'preview'=>$p];
        continue;
    }
    $summary['stitched']++;
}

echo json_encode($summary, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n";
exit($summary['ok'] ? 0 : 1);
