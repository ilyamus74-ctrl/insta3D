<?php
declare(strict_types=1);

function tour_storage_root_dir(): string
{
    if (defined('APP_STORAGE_DIR') && is_string(APP_STORAGE_DIR) && APP_STORAGE_DIR !== '') {
        return rtrim(APP_STORAGE_DIR, '/');
    }
    return '/home/makler/web/storage';
}

function tour_is_safe_relative_path(string $path): bool
{
    if ($path === '' || str_starts_with($path, '/')) return false;
    if (str_contains($path, "\0") || str_contains($path, '..') || str_contains($path, '\\')) return false;
    return str_starts_with($path, 'orders/');
}

function tour_storage_abs_path(string $relativePath): string
{
    if (!tour_is_safe_relative_path($relativePath)) {
        throw new InvalidArgumentException('Unsafe storage path');
    return tour_storage_root_dir() . '/' . ltrim($relativePath, '/');
}

function tour_viewer_variant_path_from_original(string $originalPath, string $variant): string
{
    if (!in_array($variant, ['viewer_light', 'viewer_hd'], true)) return '';
    $originalPath = trim($originalPath);
    if ($originalPath === '' || !str_contains($originalPath, '/photos/originals/')) return '';
    return str_replace('/photos/originals/', '/photos/' . $variant . '/', $originalPath);
}

function tour_preview_path_from_original(string $originalPath): string
{
    $originalPath = trim($originalPath);
    if ($originalPath === '' || !str_contains($originalPath, '/photos/originals/')) return '';
    return str_replace('/photos/originals/', '/photos/previews/', $originalPath);
}

function tour_generate_equirect_jpeg_derivative(string $sourceAbs, string $targetAbs, int $width, int $height, int $quality): array
{
    if (!is_file($sourceAbs) || !is_readable($sourceAbs)) {
        return ['ok' => false, 'error' => 'source_missing_or_unreadable', 'target_abs' => $targetAbs, 'size_bytes' => 0];
    }
    $dir = dirname($targetAbs);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'error' => 'failed_create_target_dir', 'target_abs' => $targetAbs, 'size_bytes' => 0];
    }

    $cmd = 'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($sourceAbs)
        . ' -vf ' . escapeshellarg('scale=' . $width . ':' . $height)
        . ' -q:v ' . escapeshellarg((string)$quality) . ' ' . escapeshellarg($targetAbs) . ' 2>&1';
    $output = [];
    $rc = 0;
    exec($cmd, $output, $rc);

    clearstatcache(true, $targetAbs);
    $size = is_file($targetAbs) ? (int)filesize($targetAbs) : 0;
    if ($size > 0) {
        @chmod($targetAbs, 0664);
        return ['ok' => true, 'error' => null, 'target_abs' => $targetAbs, 'size_bytes' => $size];
    }
    return ['ok' => false, 'error' => trim(implode("\n", $output)) ?: ('ffmpeg_failed_rc_' . $rc), 'target_abs' => $targetAbs, 'size_bytes' => 0];
}

function tour_ensure_photo_preview_from_original(string $originalPath, bool $overwrite = false): array
{
    $previewPath = tour_preview_path_from_original($originalPath);
    if ($previewPath === '') return ['ok'=>false,'created'=>false,'existing'=>false,'preview_path'=>'','preview_size_bytes'=>0,'error'=>'invalid_original_path'];
    $previewAbs = tour_storage_abs_path($previewPath);
    if (!$overwrite && is_file($previewAbs) && (int)filesize($previewAbs) > 0) {
        return ['ok'=>true,'created'=>false,'existing'=>true,'preview_path'=>$previewPath,'preview_size_bytes'=>(int)filesize($previewAbs),'error'=>null];
    }
    $res = tour_generate_equirect_jpeg_derivative(tour_storage_abs_path($originalPath), $previewAbs, 1024, 512, 6);
    return ['ok'=>$res['ok'],'created'=>$res['ok'],'existing'=>false,'preview_path'=>$previewPath,'preview_size_bytes'=>(int)($res['size_bytes']??0),'error'=>$res['error']];
}

function tour_ensure_photo_viewer_derivatives(string $originalPath, bool $overwrite = false): array
{
    $lightPath = tour_viewer_variant_path_from_original($originalPath, 'viewer_light');
    $hdPath = tour_viewer_variant_path_from_original($originalPath, 'viewer_hd');
    $out = ['ok'=>true,'viewer_light_path'=>$lightPath,'viewer_hd_path'=>$hdPath,'viewer_light_exists'=>false,'viewer_hd_exists'=>false,'viewer_light_created'=>false,'viewer_hd_created'=>false,'errors'=>[]];
    foreach ([['k'=>'light','path'=>$lightPath,'w'=>2048,'h'=>1024,'q'=>5],['k'=>'hd','path'=>$hdPath,'w'=>4096,'h'=>2048,'q'=>4]] as $v) {
        if ($v['path'] === '') { $out['ok']=false; $out['errors'][]='invalid_original_path'; continue; }
        $abs = tour_storage_abs_path($v['path']);
        if (!$overwrite && is_file($abs) && (int)filesize($abs) > 0) {
            $out['viewer_'.$v['k'].'_exists'] = true;
            continue;
        }
        $res = tour_generate_equirect_jpeg_derivative(tour_storage_abs_path($originalPath), $abs, $v['w'], $v['h'], $v['q']);
        if ($res['ok']) {
            $out['viewer_'.$v['k'].'_exists'] = true;
            $out['viewer_'.$v['k'].'_created'] = true;
        } else {
            $out['ok'] = false;
            $out['errors'][] = 'viewer_' . $v['k'] . ': ' . ($res['error'] ?? 'unknown_error');
        }
    }
    return $out;
}

function tour_ensure_session_media_derivatives(mysqli $dbcnx, int $sessionId, bool $withPreview = false, bool $overwrite = false): array
{
    $summary = ['ok'=>true,'session_id'=>$sessionId,'processed'=>0,'created_light'=>0,'created_hd'=>0,'created_preview'=>0,'existing_light'=>0,'existing_hd'=>0,'existing_preview'=>0,'errors'=>[]];
    $stmt = $dbcnx->prepare('SELECT id, original_storage_path, preview_storage_path FROM photo_points WHERE session_id=? ORDER BY id');
    if (!$stmt) return ['ok'=>false,'session_id'=>$sessionId,'processed'=>0,'created_light'=>0,'created_hd'=>0,'created_preview'=>0,'existing_light'=>0,'existing_hd'=>0,'existing_preview'=>0,'errors'=>['db_prepare_failed']];
    $stmt->bind_param('i', $sessionId); $stmt->execute(); $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $summary['processed']++;
        $pid = (int)$row['id'];
        $orig = trim((string)($row['original_storage_path'] ?? ''));
        if ($orig === '') { $summary['ok']=false; $summary['errors'][]=['photo_point_id'=>$pid,'error'=>'missing_original_storage_path']; continue; }
        $v = tour_ensure_photo_viewer_derivatives($orig, $overwrite);
        $summary[$v['viewer_light_created'] ? 'created_light':'existing_light']++;
        $summary[$v['viewer_hd_created'] ? 'created_hd':'existing_hd']++;
        if (!$v['ok']) { $summary['ok']=false; $summary['errors'][]=['photo_point_id'=>$pid,'error'=>'viewer_derivatives_failed','details'=>$v['errors']]; }
        if ($withPreview) {
            $p = tour_ensure_photo_preview_from_original($orig, $overwrite);
            if ($p['ok']) {
                $summary[$p['created'] ? 'created_preview':'existing_preview']++;
                if ($p['created']) { $u=$dbcnx->prepare('UPDATE photo_points SET preview_storage_path=?, preview_size_bytes=?, updated_at=NOW(6) WHERE id=?'); if($u){$u->bind_param('sii',$p['preview_path'],$p['preview_size_bytes'],$pid);$u->execute();$u->close();} }
            } else { $summary['ok']=false; $summary['errors'][]=['photo_point_id'=>$pid,'error'=>'preview_failed','details'=>$p['error']]; }
        }
    }
    $stmt->close();
    return $summary;
}
