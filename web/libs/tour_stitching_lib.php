<?php
declare(strict_types=1);

function tour_stitch_dualfisheye_to_equirect(string $rawAbs, string $stitchedAbs, array $profile = []): array
{
    $bin = '/home/makler/web/tools/dualfisheye_stitcher_cpp/build/dualfisheye_stitch';
    if (!is_file($bin) || !is_executable($bin)) {
        return ['ok'=>false,'error'=>'stitcher_binary_missing','raw_abs'=>$rawAbs,'stitched_abs'=>$stitchedAbs,'stdout'=>'','stderr'=>'','size_bytes'=>0];
    }

    $cfg = array_merge([
        'width' => 4096,
        'height' => 2048,
        'fov' => 197,
        'blend_width' => 22,
        'left_yaw' => 180,
        'right_yaw' => 0,
        'left_roll' => 0,
        'right_roll' => 0,
        'jpeg_quality' => 92,
    ], $profile);

    $dir = dirname($stitchedAbs);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok'=>false,'error'=>'failed_create_output_dir','raw_abs'=>$rawAbs,'stitched_abs'=>$stitchedAbs,'stdout'=>'','stderr'=>'','size_bytes'=>0];
    }

    $cmd = escapeshellarg($bin)
        . ' --input ' . escapeshellarg($rawAbs)
        . ' --output ' . escapeshellarg($stitchedAbs)
        . ' --width ' . escapeshellarg((string)$cfg['width'])
        . ' --height ' . escapeshellarg((string)$cfg['height'])
        . ' --fov ' . escapeshellarg((string)$cfg['fov'])
        . ' --blend-width ' . escapeshellarg((string)$cfg['blend_width'])
        . ' --left-yaw ' . escapeshellarg((string)$cfg['left_yaw'])
        . ' --right-yaw ' . escapeshellarg((string)$cfg['right_yaw'])
        . ' --left-roll ' . escapeshellarg((string)$cfg['left_roll'])
        . ' --right-roll ' . escapeshellarg((string)$cfg['right_roll'])
        . ' --jpeg-quality ' . escapeshellarg((string)$cfg['jpeg_quality'])
        . ' --json';

    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $spec, $pipes);
    if (!is_resource($proc)) {
        return ['ok'=>false,'error'=>'proc_open_failed','raw_abs'=>$rawAbs,'stitched_abs'=>$stitchedAbs,'stdout'=>'','stderr'=>'','size_bytes'=>0];
    }
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);

    clearstatcache(true, $stitchedAbs);
    $size = is_file($stitchedAbs) ? (int)filesize($stitchedAbs) : 0;
    return [
        'ok' => ($rc === 0 && $size > 0),
        'error' => ($rc === 0 && $size > 0) ? null : ('stitch_failed_rc_' . $rc),
        'raw_abs' => $rawAbs,
        'stitched_abs' => $stitchedAbs,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
        'size_bytes' => $size,
    ];
}