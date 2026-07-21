<?php

declare(strict_types=1);

require_once __DIR__ . '/auto_photo_bundle_lib.php';

const AUTO_PHOTO_BUNDLE_MATERIALIZATION_SCHEMA_VERSION = 1;

function auto_photo_bundle_materialize_assert_test_option_allowed(string $option): void
{
    if (getenv('AUTO_PHOTO_BUNDLE_TEST_MODE') !== 'true') throw new RuntimeException($option . '_forbidden');
}

function auto_photo_bundle_materialize_validate_test_options(array $options): void
{
    foreach (['inject_lock_delay_ms','inject_materialization_publish_failure','inject_archive_change_after_scan','inject_partial_write_after_bytes'] as $option) {
        if (array_key_exists($option, $options)) auto_photo_bundle_materialize_assert_test_option_allowed($option);
    }
}

function auto_photo_bundle_materialization_path(array $bundleRow, string $archivePath): string
{
    return dirname(auto_photo_bundle_index_cache_path($bundleRow, $archivePath)) . '/materialization.json';
}

function auto_photo_bundle_materialization_base_dir(array $bundleRow, string $archivePath): string
{
    return dirname(auto_photo_bundle_index_cache_path($bundleRow, $archivePath));
}

function auto_photo_bundle_materialize_path_exists(string $path): bool
{
    return file_exists($path) || is_link($path);
}

function auto_photo_bundle_materialize_is_real_directory(string $path): bool
{
    $stat = @lstat($path);
    return !is_link($path) && $stat !== false && (($stat['mode'] & 0170000) === 0040000);
}

function auto_photo_bundle_materialize_validate_base_dir(array $bundleRow, string $archivePath): string
{
    $sessionDir = dirname(dirname($archivePath));
    if (!auto_photo_bundle_materialize_is_real_directory($sessionDir)) throw new RuntimeException('unsafe_materialization_base_dir');
    $sessionReal = realpath($sessionDir);
    if ($sessionReal === false || !auto_photo_bundle_materialize_is_real_directory($sessionReal)) throw new RuntimeException('unsafe_materialization_base_dir');
    $expected = $sessionReal . '/auto_photo_bundles/' . (int)$bundleRow['id'];
    if (!auto_photo_bundle_materialize_is_real_directory($expected) || is_link(dirname($expected))) throw new RuntimeException('unsafe_materialization_base_dir');
    $baseReal = realpath($expected);
    if ($baseReal === false || $baseReal !== $expected || !str_starts_with($baseReal, $sessionReal . DIRECTORY_SEPARATOR)) throw new RuntimeException('unsafe_materialization_base_dir');
    return $expected;
}

function auto_photo_bundle_materialize_load_index(array $bundleRow, string $archivePath, array $options = []): array
{
    if (array_key_exists('index', $options)) {
        if (getenv('AUTO_PHOTO_BUNDLE_TEST_MODE') !== 'true') throw new RuntimeException('index_option_forbidden');
        if (!is_array($options['index'])) throw new RuntimeException('index_option_invalid');
        return $options['index'];
    }
    $path = auto_photo_bundle_index_cache_path($bundleRow, $archivePath);
    $stat = @lstat($path);
    if (is_link($path) || $stat === false || (($stat['mode'] & 0170000) !== 0100000)) throw new RuntimeException('unsafe_index_file');
    $json = json_decode((string)file_get_contents($path), true);
    if (!is_array($json)) throw new RuntimeException('index_json_parse_error');
    return $json;
}

function auto_photo_bundle_validate_materialization_index(array $row, array $index, string $archivePath): array
{
    if (($index['schema_version'] ?? null) !== AUTO_PHOTO_BUNDLE_INDEX_SCHEMA_VERSION) throw new RuntimeException('index_schema_version_mismatch');
    if (($index['validation_status'] ?? null) !== 'VALID') throw new RuntimeException('index_not_valid');
    if ((int)($index['capture_bundle_id'] ?? 0) !== (int)$row['id']) throw new RuntimeException('index_capture_bundle_id_mismatch');
    if ((string)($index['app_bundle_uuid'] ?? '') !== (string)($row['app_bundle_uuid'] ?? '')) throw new RuntimeException('index_app_bundle_uuid_mismatch');
    if ((string)($index['archive_sha256'] ?? '') !== hash_file('sha256', $archivePath)) throw new RuntimeException('index_archive_sha256_mismatch');
    if (!empty($index['blocking_errors'])) throw new RuntimeException('index_blocking_errors_present');
    if (!isset($index['photos']) || !is_array($index['photos'])) throw new RuntimeException('index_photos_invalid');
    if ((int)($index['photos_count_actual'] ?? -1) !== count($index['photos'])) throw new RuntimeException('index_photos_count_mismatch');
    $paths = []; $names = []; $total = 0; $expected = [];
    foreach ($index['photos'] as $p) {
        if (!is_array($p)) throw new RuntimeException('index_photo_invalid');
        $path = auto_photo_bundle_normalize_photo_path((string)($p['archive_path'] ?? ''));
        if ($path !== (string)($p['archive_path'] ?? '')) throw new RuntimeException('index_photo_path_not_canonical');
        $filename = basename($path);
        if (($p['filename'] ?? '') !== $filename) throw new RuntimeException('index_photo_filename_mismatch');
        if (isset($paths[$path])) throw new RuntimeException('index_duplicate_archive_path');
        if (isset($names[$filename])) throw new RuntimeException('index_duplicate_filename');
        $size = (int)($p['file_size_bytes'] ?? 0);
        $width = (int)($p['width'] ?? 0);
        $height = (int)($p['height'] ?? 0);
        if ($size <= 0) throw new RuntimeException('index_photo_size_invalid');
        if ($width <= 0 || $height <= 0) throw new RuntimeException('index_photo_dimensions_invalid');
        $paths[$path] = true; $names[$filename] = true; $total += $size;
        $expected[$path] = ['filename'=>$filename,'archive_path'=>$path,'size_bytes'=>$size,'width'=>$width,'height'=>$height];
    }
    if ((int)($index['total_jpeg_bytes'] ?? -1) !== $total) throw new RuntimeException('index_total_jpeg_bytes_mismatch');
    return $expected;
}

function auto_photo_bundle_materialize_from_row(array $row, array $options = []): array
{
    auto_photo_bundle_materialize_validate_test_options($options);
    $archivePath = auto_photo_bundle_archive_path_for_row($row, $options);
    $limits = array_replace(auto_photo_bundle_default_limits(), $options['limits'] ?? []);
    $baseDir = auto_photo_bundle_materialize_validate_base_dir($row, $archivePath);
    $index = auto_photo_bundle_materialize_load_index($row, $archivePath, $options);
    $expected = auto_photo_bundle_validate_materialization_index($row, $index, $archivePath);
    if (!empty($options['dry_run'])) {
        $photos = auto_photo_bundle_scan_or_extract($archivePath, $expected, null, $limits, $options);
        auto_photo_bundle_maybe_mutate_archive_for_test($archivePath, $options);
        auto_photo_bundle_verify_archive_unchanged($archivePath, $index);
        $existing = auto_photo_bundle_check_existing_materialization($baseDir, $expected, $index);
        if ($existing === false) throw new RuntimeException('existing_materialization_mismatch');
        return ['status'=>'DRY_RUN','idempotent'=>$existing === true,'capture_bundle_id'=>(int)$row['id'],'photos_count'=>count($photos)];
    }
    $lockPath = $baseDir . '/.materialize.lock';
    if (auto_photo_bundle_materialize_path_exists($lockPath)) {
        $lockStat = @lstat($lockPath);
        if (is_link($lockPath) || $lockStat === false || (($lockStat['mode'] & 0170000) !== 0100000)) throw new RuntimeException('unsafe_materialization_lock');
    }
    $lock = fopen($lockPath, 'c');
    if (!$lock) throw new RuntimeException('materialization_lock_open_failed');
    $staging = $baseDir . '/.materialization.stage.' . bin2hex(random_bytes(8));
    $photosTmp = $staging . '/photos';
    $matTmp = $staging . '/materialization.json';
    $stagingCreated = false;
    $photosPublishedByCurrentRun = false;
    $materializationPublishedByCurrentRun = false;
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('materialization_lock_failed');
        if (!empty($options['inject_lock_delay_ms'])) { auto_photo_bundle_materialize_assert_test_option_allowed('inject_lock_delay_ms'); usleep((int)$options['inject_lock_delay_ms'] * 1000); }
        $existing = auto_photo_bundle_check_existing_materialization($baseDir, $expected, $index);
        if ($existing === true) return ['status'=>'READY','idempotent'=>true,'capture_bundle_id'=>(int)$row['id'],'photos_count'=>count($expected)];
        if ($existing === false) throw new RuntimeException('existing_materialization_mismatch');
        $photosTmpCreated = mkdir($photosTmp, 0775, true);
        $stagingCreated = auto_photo_bundle_materialize_path_exists($staging);
        if (!$photosTmpCreated) throw new RuntimeException('materialization_tmp_create_failed');
        $photos = auto_photo_bundle_scan_or_extract($archivePath, $expected, $photosTmp, $limits, $options);
        auto_photo_bundle_maybe_mutate_archive_for_test($archivePath, $options);
        auto_photo_bundle_verify_archive_unchanged($archivePath, $index);
        $mat = auto_photo_bundle_build_materialization_json($row, $index, $photos);
        auto_photo_bundle_write_json_file_strict($matTmp, $mat, $options);
        if (!@rename($photosTmp, $baseDir . '/photos')) throw new RuntimeException('photos_rename_failed');
        $photosPublishedByCurrentRun = true;
        if (!empty($options['inject_materialization_publish_failure'])) { auto_photo_bundle_materialize_assert_test_option_allowed('inject_materialization_publish_failure'); throw new RuntimeException('materialization_rename_failed'); }
        if (!@rename($matTmp, $baseDir . '/materialization.json')) throw new RuntimeException('materialization_rename_failed');
        $materializationPublishedByCurrentRun = true;
        return ['status'=>'READY','idempotent'=>false,'capture_bundle_id'=>(int)$row['id'],'photos_count'=>count($photos)];
    } catch (Throwable $e) {
        if ($photosPublishedByCurrentRun && auto_photo_bundle_materialize_path_exists($baseDir . '/photos')) auto_photo_bundle_rrmdir($baseDir . '/photos');
        if ($materializationPublishedByCurrentRun && auto_photo_bundle_materialize_path_exists($baseDir . '/materialization.json')) @unlink($baseDir . '/materialization.json');
        throw $e;
    } finally {
        if ($stagingCreated && auto_photo_bundle_materialize_path_exists($staging)) auto_photo_bundle_rrmdir($staging);
        flock($lock, LOCK_UN); fclose($lock);
    }
}

function auto_photo_bundle_maybe_mutate_archive_for_test(string $archivePath, array $options): void
{
    if (empty($options['inject_archive_change_after_scan'])) return;
    auto_photo_bundle_materialize_assert_test_option_allowed('inject_archive_change_after_scan');
    file_put_contents($archivePath, 'changed', FILE_APPEND);
}

function auto_photo_bundle_verify_archive_unchanged(string $archivePath, array $index): void
{
    if ((string)($index['archive_sha256'] ?? '') !== hash_file('sha256', $archivePath)) throw new RuntimeException('archive_changed_during_materialization');
}

function auto_photo_bundle_build_materialization_json(array $row, array $index, array $photos): array
{
    return ['schema_version'=>AUTO_PHOTO_BUNDLE_MATERIALIZATION_SCHEMA_VERSION,'capture_bundle_id'=>(int)$row['id'],'app_bundle_uuid'=>(string)$row['app_bundle_uuid'],'archive_sha256'=>(string)$index['archive_sha256'],'photos_count'=>count($photos),'total_bytes'=>array_sum(array_column($photos, 'size_bytes')),'status'=>'READY','photos'=>$photos];
}

function auto_photo_bundle_check_existing_materialization(string $baseDir, array $expected, array $index): ?bool
{
    $photosDir = $baseDir . '/photos';
    $matPath = $baseDir . '/materialization.json';
    $hasPhotos = auto_photo_bundle_materialize_path_exists($photosDir);
    $hasMaterialization = auto_photo_bundle_materialize_path_exists($matPath);
    if (!$hasPhotos && !$hasMaterialization) return null;
    if (!$hasPhotos || !$hasMaterialization || is_link($photosDir) || !is_dir($photosDir) || is_link($matPath)) return false;
    $matStat = @lstat($matPath);
    if ($matStat === false || (($matStat['mode'] & 0170000) !== 0100000)) return false;
    $dirStat = lstat($photosDir);
    if ($dirStat === false || (($dirStat['mode'] & 0170000) !== 0040000)) return false;
    $mat = json_decode((string)file_get_contents($matPath), true);
    if (!is_array($mat) || ($mat['schema_version'] ?? null) !== 1 || ($mat['status'] ?? null) !== 'READY') return false;
    foreach (['capture_bundle_id','app_bundle_uuid','archive_sha256'] as $k) if (($mat[$k] ?? null) !== ($index[$k] ?? null)) return false;
    if (!isset($mat['photos']) || !is_array($mat['photos'])) return false;
    if ((int)($mat['photos_count'] ?? -1) !== count($mat['photos'])) return false;
    if ((int)($mat['photos_count'] ?? -1) !== count($expected)) return false;
    if ((int)($mat['total_bytes'] ?? -1) !== array_sum(array_column($expected, 'size_bytes'))) return false;
    $entries = array_values(array_diff(scandir($photosDir) ?: [], ['.','..'])); sort($entries);
    $wanted = array_map(fn($e) => $e['filename'], array_values($expected)); sort($wanted);
    if ($entries !== $wanted) return false;
    $matPhotos = []; $matNames = [];
    foreach ($mat['photos'] as $p) {
        if (!is_array($p)) return false;
        $path = (string)($p['archive_path'] ?? '');
        $filename = (string)($p['filename'] ?? '');
        if ($path === '' || $filename === '' || isset($matPhotos[$path]) || isset($matNames[$filename])) return false;
        $matPhotos[$path] = $p;
        $matNames[$filename] = true;
    }
    $expectedPaths = array_keys($expected); sort($expectedPaths);
    $matPaths = array_keys($matPhotos); sort($matPaths);
    if ($matPaths !== $expectedPaths) return false;
    foreach ($expected as $path=>$e) {
        $p = $matPhotos[$path] ?? null; if (!is_array($p)) return false;
        $file = $photosDir . '/' . $e['filename'];
        if (($p['filename'] ?? '') !== $e['filename'] || (int)($p['size_bytes'] ?? -1) !== $e['size_bytes'] || (int)($p['width'] ?? -1) !== $e['width'] || (int)($p['height'] ?? -1) !== $e['height']) return false;
        if (is_link($file)) return false;
        $st = lstat($file);
        if ($st === false || (($st['mode'] & 0170000) !== 0100000) || filesize($file) !== $e['size_bytes']) return false;
        $disk = auto_photo_bundle_validate_jpeg_file($file);
        if ($disk['width'] !== $e['width'] || $disk['height'] !== $e['height'] || $disk['sha256'] !== (string)($p['sha256'] ?? '')) return false;
    }
    return true;
}

function auto_photo_bundle_scan_or_extract(string $archivePath, array $expected, ?string $targetDir, array $limits, array $options = []): array
{
    $seen = []; $photos = []; $jpegCount = 0;
    auto_photo_bundle_stream_tgz_members($archivePath, $limits, function($name, $size, $type, $gz) use (&$seen, &$photos, &$jpegCount, $expected, $targetDir, $limits, $options) {
        $isJpeg = preg_match('#^capture/photos/[^/]+\.jpe?g$#i', $name) === 1;
        if (!$isJpeg) return false;
        $jpegCount++;
        if ($jpegCount > (int)$limits['max_jpeg_count']) throw new RuntimeException('jpeg_count_limit_exceeded');
        if (!isset($expected[$name])) throw new RuntimeException('unexpected_jpeg:' . $name);
        if ($size > (int)$limits['max_single_jpeg_bytes']) throw new RuntimeException('single_jpeg_limit_exceeded:' . $name);
        $e = $expected[$name];
        if ($size !== $e['size_bytes']) throw new RuntimeException('jpeg_size_mismatch:' . $name);
        $info = $targetDir === null
            ? auto_photo_bundle_read_and_validate_jpeg_member($gz, $name, $size)
            : auto_photo_bundle_write_and_validate_jpeg_member($gz, $name, $size, $targetDir . '/' . $e['filename'], $options);
        if ($info['width'] !== $e['width'] || $info['height'] !== $e['height']) throw new RuntimeException('jpeg_dimensions_mismatch:' . $name);
        if (isset($seen[$name])) throw new RuntimeException('duplicate_member:' . $name);
        $seen[$name] = true;
        $photos[] = ['filename'=>$e['filename'],'archive_path'=>$name,'size_bytes'=>$size,'width'=>$info['width'],'height'=>$info['height'],'sha256'=>$info['sha256']];
        return true;
    });
    foreach (array_keys($expected) as $path) if (!isset($seen[$path])) throw new RuntimeException('missing_indexed_jpeg:' . $path);
    usort($photos, fn($a,$b) => strcmp($a['archive_path'],$b['archive_path']));
    return $photos;
}

function auto_photo_bundle_read_and_validate_jpeg_member($gz, string $name, int $size): array
{
    $ctx = hash_init('sha256'); $read = 0; $buffer = '';
    while ($read < $size) {
        $chunk = gzread($gz, min(8192, $size - $read));
        if ($chunk === false || $chunk === '') throw new RuntimeException('truncated_member:' . $name);
        $read += strlen($chunk); hash_update($ctx, $chunk); $buffer .= $chunk;
    }
    return auto_photo_bundle_validate_jpeg_bytes($buffer, $name) + ['sha256'=>hash_final($ctx)];
}

function auto_photo_bundle_write_and_validate_jpeg_member($gz, string $name, int $size, string $target, array $options = []): array
{
    $h = fopen($target, 'xb'); if (!$h) throw new RuntimeException('photo_tmp_open_failed:' . basename($name));
    $ctx = hash_init('sha256'); $read = 0; $buffer = ''; $writeLimit = $options['inject_partial_write_after_bytes'] ?? null; if ($writeLimit !== null) auto_photo_bundle_materialize_assert_test_option_allowed('inject_partial_write_after_bytes'); $ok = false;
    try {
        while ($read < $size) {
            $chunk = gzread($gz, min(8192, $size - $read));
            if ($chunk === false || $chunk === '') throw new RuntimeException('truncated_member:' . $name);
            $read += strlen($chunk); hash_update($ctx, $chunk); $buffer .= $chunk;
            if ($writeLimit !== null && ftell($h) + strlen($chunk) > (int)$writeLimit) $chunk = substr($chunk, 0, max(0, (int)$writeLimit - ftell($h)));
            $written = fwrite($h, $chunk);
            if ($written === false || $written !== strlen($chunk)) throw new RuntimeException('photo_write_failed:' . basename($name));
            if ($writeLimit !== null && ftell($h) >= (int)$writeLimit) throw new RuntimeException('photo_write_failed:' . basename($name));
        }
        if (!fflush($h)) throw new RuntimeException('photo_flush_failed:' . basename($name));
        if (function_exists('fsync') && !fsync($h)) throw new RuntimeException('photo_fsync_failed:' . basename($name));
        if (!fclose($h)) { $h = null; throw new RuntimeException('photo_close_failed:' . basename($name)); }
        $h = null;
        if (filesize($target) !== $size) throw new RuntimeException('photo_filesize_mismatch:' . basename($name));
        $info = auto_photo_bundle_validate_jpeg_bytes($buffer, $name);
        $sha = hash_final($ctx);
        if (hash_file('sha256', $target) !== $sha) throw new RuntimeException('photo_sha256_mismatch:' . basename($name));
        $ok = true;
        return $info + ['sha256'=>$sha];
    } finally {
        if (is_resource($h)) fclose($h);
        if (!$ok && is_file($target)) @unlink($target);
    }
}

function auto_photo_bundle_validate_jpeg_file(string $path): array
{
    $bytes = (string)file_get_contents($path);
    return auto_photo_bundle_validate_jpeg_bytes($bytes, basename($path)) + ['sha256'=>hash_file('sha256', $path)];
}

function auto_photo_bundle_validate_jpeg_bytes(string $bytes, string $name): array
{
    if (strlen($bytes) < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") throw new RuntimeException('invalid_jpeg_soi:' . $name);
    $lastEoi = strrpos($bytes, "\xFF\xD9");
    if ($lastEoi === false) throw new RuntimeException('missing_jpeg_eoi:' . $name);
    $trailing = substr($bytes, $lastEoi + 2);
    if (strlen($trailing) > 16) throw new RuntimeException('jpeg_trailing_padding_exceeded:' . $name);
    if ($trailing !== '' && trim($trailing, "\0") !== '') throw new RuntimeException('invalid_jpeg_trailing_bytes:' . $name);
    $sof = auto_photo_bundle_parse_jpeg_sof($bytes);
    if ($sof === null) throw new RuntimeException('jpeg_sof_not_found:' . $name);
    if ((int)$sof['width'] <= 0 || (int)$sof['height'] <= 0) throw new RuntimeException('jpeg_dimensions_invalid:' . $name);
    return ['width'=>(int)$sof['width'],'height'=>(int)$sof['height']];
}

function auto_photo_bundle_write_json_file_strict(string $path, array $data, array $options = []): void
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) throw new RuntimeException('materialization_json_encode_failed');
    $h = fopen($path, 'xb'); if (!$h) throw new RuntimeException('materialization_tmp_open_failed');
    $ok = false;
    try {
        $written = fwrite($h, $json);
        if ($written === false || $written !== strlen($json)) throw new RuntimeException('materialization_tmp_write_failed');
        if (!fflush($h)) throw new RuntimeException('materialization_tmp_flush_failed');
        if (function_exists('fsync') && !fsync($h)) throw new RuntimeException('materialization_tmp_fsync_failed');
        if (!fclose($h)) { $h = null; throw new RuntimeException('materialization_tmp_close_failed'); }
        $h = null; $ok = true;
    } finally {
        if (is_resource($h)) fclose($h);
        if (!$ok && is_file($path)) @unlink($path);
    }
}

function auto_photo_bundle_rrmdir(string $dir): void
{
    foreach (array_diff(scandir($dir) ?: [], ['.','..']) as $e) {
        $p = $dir . '/' . $e;
        if (is_dir($p) && !is_link($p)) auto_photo_bundle_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}
