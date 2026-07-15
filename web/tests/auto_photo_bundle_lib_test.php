<?php

declare(strict_types=1);

$storageRoot = sys_get_temp_dir() . '/apb_storage_' . bin2hex(random_bytes(4));
mkdir($storageRoot . '/orders', 0775, true);
define('APP_STORAGE_DIR', $storageRoot);
putenv('AUTO_PHOTO_BUNDLE_TEST_MODE=true');
require_once __DIR__ . '/../libs/auto_photo_bundle_lib.php';
require_once __DIR__ . '/../tools/auto_photo_bundle_index.php';

$failures = 0;
function tassert(bool $ok, string $msg): void { global $failures; if (!$ok) { $failures++; echo "FAIL: $msg\n"; } }
function jpg(int $size = 64): string { $base = "\xFF\xD8\xFF\xC0\x00\x11\x08\x0C\x00\x10\x00\x03\x01\x11\x00\x02\x11\x00\x03\x11\x00\xFF\xD9"; return $base . str_repeat('A', max(0, $size - strlen($base))); }
function trow(string $tgz, ?int $size = null): array { return ['id'=>7,'order_id'=>30,'capture_session_id'=>63,'app_bundle_uuid'=>'uuid-1','capture_type'=>'auto_photo_session','filename'=>basename($tgz),'storage_path'=>'orders/o/sessions/s/capture_bundles/'.basename($tgz),'size_bytes'=>$size ?? filesize($tgz),'status'=>'UPLOADED','created_at'=>null,'updated_at'=>null]; }
function tar_octal(int $n, int $len): string { return str_pad(decoct($n), $len - 1, '0', STR_PAD_LEFT) . "\0"; }
function tar_header(string $name, int $size, string $type = '0', bool $badChecksum = false): string {
    $h = str_pad(substr($name, 0, 100), 100, "\0");
    $h .= tar_octal(0644, 8) . tar_octal(0, 8) . tar_octal(0, 8) . tar_octal($size, 12) . tar_octal(0, 12);
    $h .= str_repeat(' ', 8) . $type;
    $h .= str_repeat("\0", 100) . "ustar\0" . "00" . str_repeat("\0", 32) . str_repeat("\0", 32) . str_repeat("\0", 8) . str_repeat("\0", 8) . str_repeat("\0", 155) . str_repeat("\0", 12);
    $sum = 0; for ($i = 0; $i < 512; $i++) $sum += ord($h[$i]);
    if ($badChecksum) $sum++;
    return substr_replace($h, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
}
function make_tgz(array $opts = []): string {
    $bundle = ['bundle_schema_version'=>1,'bundle_type'=>'maklertour_capture_bundle','capture_type'=>$opts['bundle_capture_type'] ?? 'auto_photo_session','app_bundle_uuid'=>$opts['bundle_uuid'] ?? 'uuid-1','photos_count'=>$opts['bundle_photos_count'] ?? 2];
    if (($opts['missing_bundle_photos_count'] ?? false)) unset($bundle['photos_count']);
    $photos = $opts['manifest_photos'] ?? ['photos/frame_000001.jpg','photos/frame_000002.jpg'];
    $manifest = ['schema_version'=>1,'capture_type'=>$opts['capture_type'] ?? 'auto_photo_session','capture_uuid'=>$opts['capture_uuid'] ?? 'uuid-1','photos_count'=>$opts['capture_photos_count'] ?? count($photos),'photos'=>$photos];
    if (($opts['missing_capture_photos_count'] ?? false)) unset($manifest['photos_count']);
    if (($opts['missing_capture_photos'] ?? false)) unset($manifest['photos']);
    $camera = ['camera_id'=>'0','lens_label'=>'Main camera 1x','zoom_ratio'=>1.0,'sensor_orientation'=>90,'focal_lengths_mm'=>[5.56]];
    $members = [];
    if (!($opts['missing_bundle'] ?? false)) $members[] = ['bundle_manifest.json', json_encode($bundle), '0', (($opts['bad_checksum_member'] ?? '') === 'bundle_manifest.json')];
    if (!($opts['missing_manifest'] ?? false)) $members[] = ['capture/manifest.json', json_encode($manifest), '0', false];
    if (!($opts['no_camera'] ?? false)) $members[] = ['capture/camera_info.json', json_encode($camera), '0', false];
    $jpgs = $opts['jpgs'] ?? ['capture/photos/frame_000001.jpg'=>jpg(), 'capture/photos/frame_000002.jpg'=>jpg()];
    foreach ($jpgs as $n=>$c) $members[] = [$n, $c, '0', false];
    if (!($opts['no_optional'] ?? false)) {
        $meta = $opts['meta'] ?? [['sequence'=>1,'file'=>'photos/frame_000001.jpg','photo_uuid'=>'p1','image_width'=>4096,'image_height'=>3072,'angular_velocity_deg_sec'=>9,'physical_orientation'=>'portrait','exif_orientation'=>6,'image_rotation_degrees_applied'=>0],['sequence'=>2,'file'=>'photos/frame_000002.jpg','photo_uuid'=>'p2','image_width'=>4096,'image_height'=>3072]];
        $metaText = array_key_exists('meta_text', $opts) ? $opts['meta_text'] : implode("\n", array_map('json_encode', $meta)) . "\n";
        $imuText = array_key_exists('imu_text', $opts) ? $opts['imu_text'] : $metaText;
        $members[] = ['capture/photos_metadata.jsonl', $metaText, '0', false];
        $members[] = ['capture/imu.jsonl', $imuText, '0', false];
        $members[] = ['capture/quality.jsonl', json_encode(['x'=>1])."\n", '0', false];
        $members[] = ['capture/events.jsonl', json_encode(['x'=>1])."\n", '0', false];
    }
    foreach (($opts['extra_members'] ?? []) as $m) $members[] = $m;
    $tar = '';
    foreach ($members as $m) {
        [$name, $body, $type, $bad] = [$m[0], $m[1] ?? '', $m[2] ?? '0', $m[3] ?? false];
        $declared = $m[4] ?? strlen($body);
        $tar .= tar_header($name, $declared, $type, $bad) . $body;
        if (!($m[5] ?? false)) $tar .= str_repeat("\0", (512 - ($declared % 512)) % 512);
    }
    $tar .= str_repeat("\0", 1024);
    $dir = sys_get_temp_dir() . '/apbt_' . bin2hex(random_bytes(4)); mkdir($dir);
    $tgz = "$dir/bundle.tgz"; file_put_contents($tgz, gzencode($tar)); return $tgz;
}
function idx(array $opts = [], array $limits = []): array { $tgz = make_tgz($opts); return auto_photo_bundle_build_index_from_row(trow($tgz), ['archive_path'=>$tgz, 'limits'=>$limits]); }

function has_error(array $idx, string $needle): bool { foreach ($idx['blocking_errors'] as $e) if (str_contains($e, $needle)) return true; return false; }
function has_warning(array $idx, string $needle): bool { foreach ($idx['warnings'] as $e) if (str_contains($e, $needle)) return true; return false; }
function invalid_with(array $case, string $needle, array $limits = []): void { $i = idx($case, $limits); tassert($i['validation_status']==='INVALID' && has_error($i, $needle), $needle); }

function make_truncated_header_tgz(): string { $dir = sys_get_temp_dir() . '/apbt_trunch_' . bin2hex(random_bytes(4)); mkdir($dir); $tgz = "$dir/bundle.tgz"; file_put_contents($tgz, gzencode(str_repeat('X', 100))); return $tgz; }
function make_truncated_payload_tgz(): string { $dir = sys_get_temp_dir() . '/apbt_trunc_' . bin2hex(random_bytes(4)); mkdir($dir); $tgz = "$dir/bundle.tgz"; $tar = tar_header('capture/photos/truncated.jpg', 100, '0', false) . jpg(32); file_put_contents($tgz, gzencode($tar)); return $tgz; }

function make_large_tgz(int $count, int $jpegSize): string {
    $dir = sys_get_temp_dir() . '/apbt_large_' . bin2hex(random_bytes(4)); mkdir($dir); $tgz = "$dir/bundle.tgz"; $gz = gzopen($tgz, 'wb9');
    $photos = [];
    for ($i=1; $i<=$count; $i++) $photos[] = sprintf('photos/frame_%06d.jpg', $i);
    $bundle = json_encode(['bundle_schema_version'=>1,'bundle_type'=>'maklertour_capture_bundle','capture_type'=>'auto_photo_session','app_bundle_uuid'=>'uuid-1','photos_count'=>$count]);
    $manifest = json_encode(['schema_version'=>1,'capture_type'=>'auto_photo_session','capture_uuid'=>'uuid-1','photos_count'=>$count,'photos'=>$photos]);
    foreach ([['bundle_manifest.json',$bundle], ['capture/manifest.json',$manifest], ['capture/camera_info.json', json_encode(['camera_id'=>'0'])]] as $m) {
        gzwrite($gz, tar_header($m[0], strlen($m[1]), '0', false)); gzwrite($gz, $m[1]); gzwrite($gz, str_repeat("\0", (512 - (strlen($m[1]) % 512)) % 512));
    }
    $prefix = jpg(64); $remainingBase = $jpegSize - strlen($prefix);
    for ($i=1; $i<=$count; $i++) {
        $name = sprintf('capture/photos/frame_%06d.jpg', $i); gzwrite($gz, tar_header($name, $jpegSize, '0', false)); gzwrite($gz, $prefix);
        $left = $remainingBase; $chunk = str_repeat('B', 8192); while ($left > 0) { $n = min($left, 8192); gzwrite($gz, substr($chunk, 0, $n)); $left -= $n; }
        gzwrite($gz, str_repeat("\0", (512 - ($jpegSize % 512)) % 512));
    }
    gzwrite($gz, str_repeat("\0", 1024)); gzclose($gz); return $tgz;
}

$i = idx(); tassert($i['validation_status']==='VALID', 'valid string manifest');
tassert($i['camera_id']==='0' && $i['lens_label']==='Main camera 1x' && $i['zoom_ratio']==1.0 && $i['sensor_orientation']===90 && $i['focal_lengths_mm']===[5.56], 'camera_info fields copied into index');
tassert($i['photos'][0]['angular_velocity_deg_sec']===9 && $i['photos'][0]['physical_orientation']==='portrait' && $i['photos'][0]['exif_orientation']===6 && $i['photos'][0]['image_rotation_degrees_applied']===0, 'metadata orientation fallback before imu');
tassert(idx(['meta'=>[['sequence'=>1,'file'=>'frame_000001.jpg'],['sequence'=>2,'file'=>'frame_000002.jpg']]])['validation_status']==='VALID', 'bare metadata filename');
tassert(idx(['manifest_photos'=>[['file'=>'photos/frame_000001.jpg'],['filename'=>'frame_000002.jpg']]])['validation_status']==='VALID', 'object manifest photos');

invalid_with(['extra_members'=>[['../evil.php','x','0',false]]], 'unsafe_archive_member');
invalid_with(['extra_members'=>[['/absolute/path','x','0',false]]], 'unsafe_archive_member');
invalid_with(['extra_members'=>[['capture\\evil.php','x','0',false]]], 'unsafe_archive_member');
invalid_with(['extra_members'=>[['capture/photos/link.jpg','','2',false]]], 'unsupported_tar_type:symlink');
invalid_with(['extra_members'=>[['capture/photos/hard.jpg','','1',false]]], 'unsupported_tar_type:hardlink');
invalid_with(['extra_members'=>[['capture/photos/char.jpg','','3',false]]], 'unsupported_tar_type:character_device');
invalid_with(['extra_members'=>[['capture/photos/block.jpg','','4',false]]], 'unsupported_tar_type:block_device');
invalid_with(['extra_members'=>[['capture/photos/fifo.jpg','','6',false]]], 'unsupported_tar_type:fifo');
invalid_with(['extra_members'=>[['capture/photos/dir','','5',false]]], 'unsupported_tar_type:directory');
invalid_with(['extra_members'=>[['capture/photos/frame_000001.jpg',jpg(),'0',false]]], 'duplicate_member');
invalid_with(['bad_checksum_member'=>'bundle_manifest.json'], 'invalid_tar_checksum');
$truncHeader = make_truncated_header_tgz(); $truncHeaderIdx = auto_photo_bundle_build_index_from_row(trow($truncHeader), ['archive_path'=>$truncHeader]); tassert($truncHeaderIdx['validation_status']==='INVALID' && has_error($truncHeaderIdx, 'truncated_tar_header'), 'truncated_tar_header');
$trunc = make_truncated_payload_tgz(); $truncIdx = auto_photo_bundle_build_index_from_row(trow($trunc), ['archive_path'=>$trunc]); tassert($truncIdx['validation_status']==='INVALID' && has_error($truncIdx, 'truncated_member'), 'truncated_member');
invalid_with(['missing_bundle_photos_count'=>true], 'bundle_photos_count_missing_or_invalid');
invalid_with(['missing_capture_photos_count'=>true], 'capture_photos_count_missing_or_invalid');
invalid_with(['missing_capture_photos'=>true], 'capture_photos_missing_or_invalid');
invalid_with(['extra_members'=>[['capture/photos/empty.jpg','','0',false]]], 'zero_size_jpeg');
invalid_with(['extra_members'=>[['capture/photos/bad.jpg','bad','0',false]]], 'invalid_jpeg_header');
invalid_with(['bundle_uuid'=>'other'], 'app_bundle_uuid_mismatch');
invalid_with(['capture_type'=>'other'], 'capture_type_mismatch');
invalid_with(['manifest_photos'=>['photos/missing.jpg']], 'missing_referenced_jpeg');
invalid_with(['meta'=>[['sequence'=>1,'file'=>'photos/frame_000001.jpg'],['sequence'=>1,'file'=>'photos/frame_000002.jpg']]], 'duplicate_sequence');
invalid_with(['meta_text'=>"{bad
"], 'metadata_jsonl_parse_error');
invalid_with(['extra_members'=>[['capture/photos/large.jpg',jpg(64),'0',false,100000]]], 'single_jpeg_limit_exceeded', ['max_single_jpeg_bytes'=>10, 'max_member_bytes'=>200000]);


invalid_with([], 'unpacked_bytes_limit_exceeded', ['max_declared_unpacked_bytes'=>10]);
invalid_with([], 'member_count_limit_exceeded', ['max_member_count'=>1]);
tassert(idx(['meta'=>[['sequence'=>1,'file'=>'photos/frame_000001.jpg'],['sequence'=>3,'file'=>'photos/frame_000002.jpg']]])['validation_status']==='WARNING', 'sequence gap warning');
tassert(in_array('metadata_count_mismatch', idx(['meta_text'=>''])['warnings'], true), 'empty metadata member count warning');
tassert(in_array('imu_count_mismatch', idx(['imu_text'=>''])['warnings'], true), 'empty IMU member count warning');
tassert(idx(['no_optional'=>true])['validation_status']==='WARNING', 'optional absent warning');
$bidir = idx(['meta'=>[['sequence'=>1,'file'=>'photos/frame_000001.jpg'],['sequence'=>2,'file'=>'photos/frame_999999.jpg']]]);
tassert($bidir['validation_status']==='INVALID' && has_error($bidir, 'metadata_references_missing_jpeg') && has_warning($bidir, 'jpeg_missing_metadata'), 'bidirectional metadata references');
$large = make_large_tgz(100, 1024 * 1024); $largeIdx = auto_photo_bundle_build_index_from_row(trow($large), ['archive_path'=>$large, 'limits'=>['max_jpeg_count'=>150, 'max_single_jpeg_bytes'=>2 * 1024 * 1024, 'max_member_bytes'=>2 * 1024 * 1024]]);
tassert($largeIdx['photos_count_actual']===100 && $largeIdx['validation_status']==='WARNING', '100x1MiB JPEG memory regression');
try { putenv('AUTO_PHOTO_BUNDLE_TEST_MODE=false'); $small = make_tgz(); auto_photo_bundle_build_index_from_row(trow($small), ['archive_path'=>$small]); tassert(false, 'archive_path forbidden outside test mode'); } catch (Throwable $e) { tassert($e->getMessage()==='archive_path_option_forbidden', 'archive_path forbidden outside test mode'); } finally { putenv('AUTO_PHOTO_BUNDLE_TEST_MODE=true'); }

$prodSource = make_tgz();
$prodRel = 'orders/o/sessions/s/capture_bundles/prod_bundle.tgz';
$prodFullDir = APP_STORAGE_DIR . '/orders/o/sessions/s/capture_bundles';
mkdir($prodFullDir, 0775, true);
copy($prodSource, APP_STORAGE_DIR . '/' . $prodRel);
try {
    putenv('AUTO_PHOTO_BUNDLE_TEST_MODE=false');
    $prodIndex = auto_photo_bundle_build_index_from_row(trow(APP_STORAGE_DIR . '/' . $prodRel, filesize(APP_STORAGE_DIR . '/' . $prodRel)) + ['storage_path'=>$prodRel]);
    tassert($prodIndex['validation_status']==='VALID', 'production path resolves without archive_path option');
} catch (Throwable $e) {
    tassert($e->getMessage() !== 'archive_path_option_forbidden', 'production path does not hit archive_path_option_forbidden');
    tassert(false, 'production path resolves without exception: ' . $e->getMessage());
} finally {
    putenv('AUTO_PHOTO_BUNDLE_TEST_MODE=true');
}


$target = sys_get_temp_dir().'/apb_index_'.bin2hex(random_bytes(4)).'/index.json'; auto_photo_bundle_write_index_atomic(['a'=>1], $target); auto_photo_bundle_write_index_atomic(['a'=>2], $target); tassert(json_decode(file_get_contents($target), true)['a']===2, 'atomic replacement');
$failDir = sys_get_temp_dir().'/apb_index_fail_'.bin2hex(random_bytes(4)); mkdir($failDir); mkdir($failDir.'/index.json'); try { auto_photo_bundle_write_index_atomic(['a'=>1], $failDir.'/index.json'); } catch (Throwable $e) {}
tassert(glob($failDir.'/index.json.tmp.*') === [], 'failed atomic write does not leave tmp');
$tgz = make_tgz(); $one = auto_photo_bundle_build_index_from_row(trow($tgz), ['archive_path'=>$tgz]); $two = auto_photo_bundle_build_index_from_row(trow($tgz), ['archive_path'=>$tgz]); tassert($one['archive_sha256']===$two['archive_sha256'], 'idempotent second run');
try { auto_photo_bundle_normalize_photo_path('photos/../x.jpg'); tassert(false, 'normalize rejects traversal'); } catch (Throwable $e) { tassert(true, 'normalize rejects traversal'); }

$dryDir = sys_get_temp_dir().'/apb_dry_'.bin2hex(random_bytes(4)); $dryTarget = $dryDir.'/index.json'; if (auto_photo_bundle_cli_should_write(['dry-run'=>false])) auto_photo_bundle_write_index_atomic(['a'=>1], $dryTarget);
tassert(!file_exists($dryTarget) && !file_exists($dryDir.'/.index.lock'), '--dry-run does not create index/cache/lock');

echo $failures === 0 ? "PASS\n" : "FAILURES: $failures\n"; exit($failures === 0 ? 0 : 1);
