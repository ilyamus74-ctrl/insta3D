<?php

declare(strict_types=1);

const AUTO_PHOTO_BUNDLE_INDEX_SCHEMA_VERSION = 1;
const AUTO_PHOTO_BUNDLE_CAPTURE_TYPE = 'auto_photo_session';

function auto_photo_bundle_default_limits(): array
{
    return [
        'max_member_count' => 1000,
        'max_declared_unpacked_bytes' => 1024 * 1024 * 1024,
        'max_member_bytes' => 50 * 1024 * 1024,
        'max_single_jpeg_bytes' => 20 * 1024 * 1024,
        'max_json_bytes' => 2 * 1024 * 1024,
        'max_jsonl_bytes' => 4 * 1024 * 1024,
        'max_jpeg_count' => 1000,
    ];
}

function auto_photo_bundle_load_row(mysqli $db, int $captureBundleId): array
{
    $sql = 'SELECT id, order_id, capture_session_id, app_bundle_uuid, capture_type, filename, storage_path, size_bytes, status, created_at, updated_at FROM capture_bundles WHERE id=? LIMIT 1';
    $st = $db->prepare($sql);
    if (!$st) throw new RuntimeException('db_prepare_failed');
    $st->bind_param('i', $captureBundleId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) throw new RuntimeException('capture_bundle_not_found');
    if ((string)$row['capture_type'] !== AUTO_PHOTO_BUNDLE_CAPTURE_TYPE) throw new InvalidArgumentException('unsupported_capture_type');
    return $row;
}

function auto_photo_bundle_storage_root(): string
{
    if (!defined('APP_STORAGE_DIR') || !is_string(APP_STORAGE_DIR) || APP_STORAGE_DIR === '') throw new RuntimeException('app_storage_dir_not_defined');
    $root = realpath(rtrim(APP_STORAGE_DIR, '/') . '/orders');
    if ($root === false || !is_dir($root)) throw new RuntimeException('storage_orders_root_not_found');
    return rtrim($root, DIRECTORY_SEPARATOR);
}

function auto_photo_bundle_resolve_archive_path(array $bundleRow): string
{
    if ((string)($bundleRow['capture_type'] ?? '') !== AUTO_PHOTO_BUNDLE_CAPTURE_TYPE) throw new InvalidArgumentException('unsupported_capture_type');
    $storagePath = (string)($bundleRow['storage_path'] ?? '');
    if ($storagePath === '' || $storagePath[0] === '/' || str_contains($storagePath, "\0") || str_contains($storagePath, '..') || str_contains($storagePath, '\\')) throw new RuntimeException('unsafe_storage_path');
    if (!preg_match('/\.(tgz|tar\.gz)$/i', $storagePath)) throw new RuntimeException('unsupported_archive_extension');
    $full = rtrim(APP_STORAGE_DIR, '/') . '/' . ltrim($storagePath, '/');
    if (is_link($full)) throw new RuntimeException('archive_is_symlink');
    $real = realpath($full);
    if ($real === false || !is_file($real) || filesize($real) <= 0) throw new RuntimeException('archive_not_found');
    $root = auto_photo_bundle_storage_root();
    if ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('archive_outside_storage_root');
    return $real;
}

function auto_photo_bundle_normalize_photo_path(string $value): string
{
    $value = trim($value);
    if ($value === '' || str_contains($value, "\0") || str_contains($value, '\\') || str_starts_with($value, '/') || str_contains($value, '..')) throw new InvalidArgumentException('unsafe_photo_path');
    if (str_starts_with($value, 'capture/photos/')) $name = substr($value, strlen('capture/photos/'));
    elseif (str_starts_with($value, 'photos/')) $name = substr($value, strlen('photos/'));
    else $name = $value;
    if ($name === '' || str_contains($name, '/') || !preg_match('/^[A-Za-z0-9._-]+\.jpe?g$/i', $name)) throw new InvalidArgumentException('unsafe_photo_path');
    return 'capture/photos/' . $name;
}

function auto_photo_bundle_build_index(mysqli $db, int $captureBundleId, array $options = []): array
{
    $row = auto_photo_bundle_load_row($db, $captureBundleId);
    return auto_photo_bundle_build_index_from_row($row, []);
}

function auto_photo_bundle_archive_path_for_row(array $row, array $options = []): string
{
    if (array_key_exists('archive_path', $options)) {
        // Test-only override; production callers must resolve the archive from DB storage_path.
        $testMode = getenv('AUTO_PHOTO_BUNDLE_TEST_MODE') === 'true';
        if (!$testMode) {
            throw new RuntimeException('archive_path_option_forbidden');
        }
        return (string)$options['archive_path'];
    }
    return auto_photo_bundle_resolve_archive_path($row);
}

function auto_photo_bundle_build_index_from_row(array $row, array $options = []): array
{
    $archivePath = auto_photo_bundle_archive_path_for_row($row, $options);
    $limits = array_replace(auto_photo_bundle_default_limits(), $options['limits'] ?? []);
    $warnings = [];
    $errors = [];
    $archive = auto_photo_bundle_read_tgz_stream($archivePath, $limits, $errors);
    $members = $archive['members'];
    $jpeg = $archive['jpeg'];

    foreach (['bundle_manifest.json', 'capture/manifest.json'] as $req) if (!isset($members[$req])) $errors[] = 'missing_required:' . $req;
    if (!$jpeg) $errors[] = 'no_jpeg';
    foreach (['capture/camera_info.json','capture/photos_metadata.jsonl','capture/imu.jsonl','capture/quality.jsonl','capture/events.jsonl'] as $opt) if (!isset($members[$opt])) $warnings[] = 'missing_optional:' . $opt;

    $bundle = isset($members['bundle_manifest.json']) ? auto_photo_bundle_decode_json_member($members['bundle_manifest.json'], $limits['max_json_bytes'], $errors, 'bundle_manifest') : [];
    $manifest = isset($members['capture/manifest.json']) ? auto_photo_bundle_decode_json_member($members['capture/manifest.json'], $limits['max_json_bytes'], $errors, 'capture_manifest') : [];
    $camera = isset($members['capture/camera_info.json']) ? auto_photo_bundle_decode_json_member($members['capture/camera_info.json'], $limits['max_json_bytes'], $errors, 'camera_info') : [];

    if ($bundle) {
        if (($bundle['bundle_schema_version'] ?? null) !== 1) $errors[] = 'bundle_schema_version_mismatch';
        if (($bundle['bundle_type'] ?? null) !== 'maklertour_capture_bundle') $errors[] = 'bundle_type_mismatch';
        if (($bundle['capture_type'] ?? null) !== AUTO_PHOTO_BUNDLE_CAPTURE_TYPE) $errors[] = 'capture_type_mismatch';
        if (($bundle['app_bundle_uuid'] ?? null) !== ($row['app_bundle_uuid'] ?? null)) $errors[] = 'app_bundle_uuid_mismatch';
        if (!array_key_exists('photos_count', $bundle) || !is_int($bundle['photos_count'])) $errors[] = 'bundle_photos_count_missing_or_invalid';
    }

    $manifestPhotos = [];
    if ($manifest) {
        if (($manifest['schema_version'] ?? null) !== 1) $errors[] = 'capture_schema_version_mismatch';
        if (($manifest['capture_type'] ?? null) !== AUTO_PHOTO_BUNDLE_CAPTURE_TYPE) $errors[] = 'capture_type_mismatch';
        if (($manifest['capture_uuid'] ?? null) !== ($row['app_bundle_uuid'] ?? null)) $errors[] = 'capture_uuid_mismatch';
        if (!array_key_exists('photos_count', $manifest) || !is_int($manifest['photos_count'])) $errors[] = 'capture_photos_count_missing_or_invalid';
        if (!array_key_exists('photos', $manifest) || !is_array($manifest['photos'])) $errors[] = 'capture_photos_missing_or_invalid';
        foreach (is_array($manifest['photos'] ?? null) ? $manifest['photos'] : [] as $p) {
            try { $manifestPhotos[auto_photo_bundle_photo_ref($p)] = true; } catch (Throwable $e) { $errors[] = 'invalid_manifest_photo_ref'; }
        }
    }

    foreach (array_keys($manifestPhotos) as $p) if (!isset($jpeg[$p])) $errors[] = 'missing_referenced_jpeg:' . $p;
    foreach (array_keys($jpeg) as $p) if ($manifestPhotos && !isset($manifestPhotos[$p])) $warnings[] = 'jpeg_absent_from_manifest:' . $p;
    if ($manifestPhotos && count($manifestPhotos) !== count($jpeg)) $errors[] = 'manifest_jpeg_count_mismatch';
    if (isset($bundle['photos_count']) && is_int($bundle['photos_count']) && $bundle['photos_count'] !== count($jpeg)) $errors[] = 'bundle_photos_count_mismatch';
    if (isset($manifest['photos_count']) && is_int($manifest['photos_count']) && $manifest['photos_count'] !== count($jpeg)) $errors[] = 'capture_photos_count_mismatch';

    $metaPresent = isset($members['capture/photos_metadata.jsonl']);
    $imuPresent = isset($members['capture/imu.jsonl']);
    $meta = auto_photo_bundle_decode_jsonl_map($members['capture/photos_metadata.jsonl'] ?? null, $limits['max_jsonl_bytes'], $errors, $warnings, 'metadata');
    $imu = auto_photo_bundle_decode_jsonl_map($members['capture/imu.jsonl'] ?? null, $limits['max_jsonl_bytes'], $errors, $warnings, 'imu');
    $qualityCount = auto_photo_bundle_count_jsonl_member($members['capture/quality.jsonl'] ?? null, $limits['max_jsonl_bytes'], $errors, 'quality');
    $eventsCount = auto_photo_bundle_count_jsonl_member($members['capture/events.jsonl'] ?? null, $limits['max_jsonl_bytes'], $errors, 'events');
    if ($metaPresent && $meta['count'] !== count($jpeg)) $warnings[] = 'metadata_count_mismatch';
    if ($imuPresent && $imu['count'] !== count($jpeg)) $warnings[] = 'imu_count_mismatch';
    auto_photo_bundle_validate_reference_coverage($meta['by_path'], $jpeg, $metaPresent, 'metadata', $errors, $warnings);
    auto_photo_bundle_validate_reference_coverage($imu['by_path'], $jpeg, $imuPresent, 'imu', $errors, $warnings);

    $photos = [];
    foreach ($jpeg as $path => $j) {
        $m = $meta['by_path'][$path] ?? [];
        $i = $imu['by_path'][$path] ?? [];
        $photos[] = [
            'sequence'=>$m['sequence'] ?? $i['sequence'] ?? null,
            'archive_path'=>$path,
            'filename'=>$j['filename'],
            'photo_uuid'=>$m['photo_uuid'] ?? $i['photo_uuid'] ?? null,
            'timestamp_utc'=>$m['timestamp_utc'] ?? $i['timestamp_utc'] ?? null,
            'timestamp_ms'=>$m['timestamp_ms'] ?? $i['timestamp_ms'] ?? null,
            'width'=>$m['image_width'] ?? $i['image_width'] ?? $j['width'] ?? null,
            'height'=>$m['image_height'] ?? $i['image_height'] ?? $j['height'] ?? null,
            'file_size_bytes'=>$j['size'],
            'sharpness'=>$m['sharpness'] ?? $i['sharpness'] ?? null,
            'duplicate_score'=>$m['duplicate_score'] ?? $i['duplicate_score'] ?? null,
            'angular_velocity_deg_sec'=>$m['angular_velocity_deg_sec'] ?? $i['angular_velocity_deg_sec'] ?? null,
            'physical_orientation'=>$m['physical_orientation'] ?? $i['physical_orientation'] ?? null,
            'exif_orientation'=>$m['exif_orientation'] ?? $i['exif_orientation'] ?? null,
            'image_rotation_degrees_applied'=>$m['image_rotation_degrees_applied'] ?? $i['image_rotation_degrees_applied'] ?? null,
        ];
    }
    usort($photos, fn($a, $b) => strcmp($a['archive_path'], $b['archive_path']));
    if ((string)($row['size_bytes'] ?? '') !== '' && (int)$row['size_bytes'] !== filesize($archivePath)) $warnings[] = 'db_size_mismatch';
    $status = $errors ? 'INVALID' : ($warnings ? 'WARNING' : 'VALID');

    return [
        'schema_version'=>AUTO_PHOTO_BUNDLE_INDEX_SCHEMA_VERSION,
        'capture_bundle_id'=>(int)$row['id'],
        'capture_type'=>AUTO_PHOTO_BUNDLE_CAPTURE_TYPE,
        'app_bundle_uuid'=>(string)$row['app_bundle_uuid'],
        'capture_uuid'=>$manifest['capture_uuid'] ?? null,
        'order_id'=>(int)$row['order_id'],
        'capture_session_id'=>(int)$row['capture_session_id'],
        'bundle_status'=>(string)$row['status'],
        'archive_filename'=>(string)$row['filename'],
        'archive_size_bytes'=>filesize($archivePath),
        'archive_sha256'=>hash_file('sha256', $archivePath),
        'storage_path'=>(string)$row['storage_path'],
        'photos_count_manifest'=>count($manifestPhotos),
        'photos_count_actual'=>count($jpeg),
        'metadata_records'=>$meta['count'],
        'imu_records'=>$imu['count'],
        'quality_records'=>$qualityCount,
        'events_records'=>$eventsCount,
        'started_at_utc'=>$manifest['started_at_utc'] ?? null,
        'finished_at_utc'=>$manifest['finished_at_utc'] ?? null,
        'camera_id'=>$camera['camera_id'] ?? null,
        'lens_label'=>$camera['lens_label'] ?? null,
        'zoom_ratio'=>$camera['zoom_ratio'] ?? null,
        'sensor_orientation'=>$camera['sensor_orientation'] ?? null,
        'focal_lengths_mm'=>$camera['focal_lengths_mm'] ?? null,
        'image_width'=>$photos[0]['width'] ?? null,
        'image_height'=>$photos[0]['height'] ?? null,
        'total_jpeg_bytes'=>array_sum(array_column($jpeg, 'size')),
        'validation_status'=>$status,
        'warnings'=>array_values(array_unique($warnings)),
        'blocking_errors'=>array_values(array_unique($errors)),
        'photos'=>$photos,
    ];
}


function auto_photo_bundle_validate_reference_coverage(array $byPath, array $jpeg, bool $present, string $label, array &$errors, array &$warnings): void
{
    if (!$present) return;
    foreach (array_keys($byPath) as $path) {
        if (!isset($jpeg[$path])) $errors[] = $label . '_references_missing_jpeg:' . $path;
    }
    foreach (array_keys($jpeg) as $path) {
        if (!isset($byPath[$path])) $warnings[] = 'jpeg_missing_' . $label . ':' . $path;
    }
}

function auto_photo_bundle_read_tgz_stream(string $archivePath, array $limits, array &$errors): array
{
    $gz = gzopen($archivePath, 'rb');
    if (!$gz) {
        $errors[] = 'unreadable_tgz';
        return ['members'=>[], 'jpeg'=>[]];
    }
    $members = [];
    $jpeg = [];
    $memberCount = 0;
    $totalBytes = 0;
    $zeroBlocks = 0;
    try {
        while (!gzeof($gz)) {
            $header = auto_photo_bundle_gzread_exact($gz, 512);
            if ($header === '') break;
            if (strlen($header) !== 512) { $errors[] = 'truncated_tar_header'; break; }
            if ($header === str_repeat("\0", 512)) {
                $zeroBlocks++;
                if ($zeroBlocks >= 2) break;
                continue;
            }
            $zeroBlocks = 0;
            $memberCount++;
            if ($memberCount > (int)$limits['max_member_count']) { $errors[] = 'member_count_limit_exceeded'; break; }
            if (!auto_photo_bundle_tar_checksum_valid($header)) { $errors[] = 'invalid_tar_checksum'; break; }
            $name = auto_photo_bundle_tar_name($header);
            $size = auto_photo_bundle_tar_octal(substr($header, 124, 12));
            $type = substr($header, 156, 1);
            if ($size < 0) { $errors[] = 'invalid_tar_size:' . $name; break; }
            $totalBytes += $size;
            if ($totalBytes > (int)$limits['max_declared_unpacked_bytes']) { $errors[] = 'unpacked_bytes_limit_exceeded'; break; }
            if ($size > (int)$limits['max_member_bytes']) { $errors[] = 'member_size_limit_exceeded:' . $name; break; }
            $safe = auto_photo_bundle_validate_tar_member($name, $type, $members, $errors);
            $isJpeg = $safe && preg_match('#^capture/photos/[^/]+\.jpe?g$#i', $name);
            $capture = $safe && !$isJpeg && auto_photo_bundle_should_capture_member($name, $size, $limits, $errors);
            $content = '';
            $jpegInfo = null;
            if ($isJpeg) {
                $jpegInfo = auto_photo_bundle_read_jpeg_stream($gz, $name, $size, $limits, $errors);
                if ($jpegInfo === null) break;
            } elseif ($capture) {
                $content = auto_photo_bundle_gzread_exact($gz, $size);
                if (strlen($content) !== $size) { $errors[] = 'truncated_member:' . $name; break; }
            } else {
                if (!auto_photo_bundle_gzskip_exact($gz, $size)) { $errors[] = 'truncated_member:' . $name; break; }
            }
            $padding = (512 - ($size % 512)) % 512;
            if ($padding > 0 && !auto_photo_bundle_gzskip_exact($gz, $padding)) { $errors[] = 'truncated_member_padding:' . $name; break; }
            if (!$safe) continue;
            if ($isJpeg) {
                $jpeg[$name] = ['size'=>$size, 'filename'=>basename($name), 'width'=>$jpegInfo['width'], 'height'=>$jpegInfo['height']];
                $members[$name] = ['size'=>$size, 'jpeg'=>true, 'width'=>$jpegInfo['width'], 'height'=>$jpegInfo['height']];
                if (count($jpeg) > (int)$limits['max_jpeg_count']) $errors[] = 'jpeg_count_limit_exceeded';
            } else {
                $members[$name] = ['size'=>$size, 'content'=>$content];
            }
        }
    } finally {
        gzclose($gz);
    }
    return ['members'=>$members, 'jpeg'=>$jpeg];
}


function auto_photo_bundle_read_jpeg_stream($gz, string $name, int $size, array $limits, array &$errors): ?array
{
    $info = ['width'=>null, 'height'=>null];
    if ($size <= 0) $errors[] = 'zero_size_jpeg:' . $name;
    $sizeLimitExceeded = $size > (int)$limits['max_single_jpeg_bytes'];
    if ($sizeLimitExceeded) $errors[] = 'single_jpeg_limit_exceeded:' . $name;

    $read = 0;
    $buffer = '';
    $scanLimit = min($size, 131072);
    while ($read < $size) {
        $chunkSize = min(8192, $size - $read);
        $chunk = gzread($gz, $chunkSize);
        if ($chunk === false || $chunk === '') {
            $errors[] = 'truncated_member:' . $name;
            return null;
        }
        $read += strlen($chunk);
        if (!$sizeLimitExceeded && strlen($buffer) < $scanLimit) {
            $need = $scanLimit - strlen($buffer);
            $buffer .= substr($chunk, 0, $need);
        }
    }

    if ($sizeLimitExceeded) return $info;
    if (substr($buffer, 0, 3) !== "\xFF\xD8\xFF") {
        $errors[] = 'invalid_jpeg_header:' . $name;
        return $info;
    }
    $sof = auto_photo_bundle_parse_jpeg_sof($buffer);
    if ($sof === null) {
        $errors[] = 'jpeg_sof_not_found:' . $name;
        return $info;
    }
    return $sof;
}

function auto_photo_bundle_parse_jpeg_sof(string $data): ?array
{
    $len = strlen($data);
    if ($len < 4 || substr($data, 0, 2) !== "\xFF\xD8") return null;
    $i = 2;
    while ($i + 3 < $len) {
        if ($data[$i] !== "\xFF") { $i++; continue; }
        while ($i < $len && $data[$i] === "\xFF") $i++;
        if ($i >= $len) return null;
        $marker = ord($data[$i]);
        $i++;
        if ($marker === 0xD9 || $marker === 0xDA) return null;
        if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) continue;
        if ($i + 1 >= $len) return null;
        $segLen = (ord($data[$i]) << 8) + ord($data[$i + 1]);
        if ($segLen < 2 || $i + $segLen > $len) return null;
        if (($marker >= 0xC0 && $marker <= 0xC3) || ($marker >= 0xC5 && $marker <= 0xC7) || ($marker >= 0xC9 && $marker <= 0xCB) || ($marker >= 0xCD && $marker <= 0xCF)) {
            if ($segLen < 7) return null;
            $height = (ord($data[$i + 3]) << 8) + ord($data[$i + 4]);
            $width = (ord($data[$i + 5]) << 8) + ord($data[$i + 6]);
            return ['width'=>$width, 'height'=>$height];
        }
        $i += $segLen;
    }
    return null;
}

function auto_photo_bundle_validate_tar_member(string $name, string $type, array $members, array &$errors): bool
{
    $ok = true;
    if ($name === '' || str_starts_with($name, '/') || str_contains($name, '..') || str_contains($name, '\\') || str_contains($name, "\0")) { $errors[] = 'unsafe_archive_member:' . $name; $ok = false; }
    if (isset($members[$name])) { $errors[] = 'duplicate_member:' . $name; $ok = false; }
    if ($type !== "\0" && $type !== '0') { $errors[] = 'unsupported_tar_type:' . auto_photo_bundle_type_label($type) . ':' . $name; $ok = false; }
    if (!preg_match('#^(bundle_manifest\.json|capture/(manifest\.json|camera_info\.json|photos_metadata\.jsonl|imu\.jsonl|quality\.jsonl|events\.jsonl|photos/[^/]+\.jpe?g))$#i', $name)) { $errors[] = 'unexpected_archive_member:' . $name; $ok = false; }
    return $ok;
}

function auto_photo_bundle_type_label(string $type): string
{
    return match ($type) {
        '1' => 'hardlink', '2' => 'symlink', '3' => 'character_device', '4' => 'block_device', '5' => 'directory', '6' => 'fifo', 'L' => 'gnu_longname', 'x', 'g' => 'pax', "\0", '0' => 'regular', default => 'unknown_' . bin2hex($type),
    };
}

function auto_photo_bundle_should_capture_member(string $name, int $size, array $limits, array &$errors): bool
{
    if ($name === 'bundle_manifest.json' || $name === 'capture/manifest.json' || $name === 'capture/camera_info.json') {
        if ($size > (int)$limits['max_json_bytes']) $errors[] = 'json_limit_exceeded:' . $name;
        return $size <= (int)$limits['max_json_bytes'];
    }
    if (preg_match('#^capture/(photos_metadata|imu|quality|events)\.jsonl$#', $name)) {
        if ($size > (int)$limits['max_jsonl_bytes']) $errors[] = 'jsonl_limit_exceeded:' . $name;
        return $size <= (int)$limits['max_jsonl_bytes'];
    }
    if (preg_match('#^capture/photos/[^/]+\.jpe?g$#i', $name)) return true;
    return false;
}

function auto_photo_bundle_gzread_exact($gz, int $bytes): string
{
    $out = '';
    while (strlen($out) < $bytes && !gzeof($gz)) {
        $chunk = gzread($gz, min(8192, $bytes - strlen($out)));
        if ($chunk === false || $chunk === '') break;
        $out .= $chunk;
    }
    return $out;
}

function auto_photo_bundle_gzskip_exact($gz, int $bytes): bool
{
    $left = $bytes;
    while ($left > 0 && !gzeof($gz)) {
        $chunk = gzread($gz, min(8192, $left));
        if ($chunk === false || $chunk === '') return false;
        $left -= strlen($chunk);
    }
    return $left === 0;
}

function auto_photo_bundle_tar_checksum_valid(string $header): bool
{
    $stored = auto_photo_bundle_tar_octal(substr($header, 148, 8));
    if ($stored < 0) return false;
    $calc = 0;
    for ($i = 0; $i < 512; $i++) $calc += ($i >= 148 && $i < 156) ? 32 : ord($header[$i]);
    return $calc === $stored;
}

function auto_photo_bundle_tar_octal(string $value): int
{
    $value = trim($value, " \0");
    if ($value === '') return 0;
    if (!preg_match('/^[0-7]+$/', $value)) return -1;
    return intval($value, 8);
}

function auto_photo_bundle_tar_name(string $header): string
{
    $name = rtrim(substr($header, 0, 100), "\0");
    $prefix = rtrim(substr($header, 345, 155), "\0");
    return $prefix !== '' ? $prefix . '/' . $name : $name;
}

function auto_photo_bundle_decode_json_member(array $member, int $limit, array &$errors, string $label): array
{
    if ($member['size'] > $limit) { $errors[] = $label . '_json_limit_exceeded'; return []; }
    $j = json_decode((string)$member['content'], true);
    if (!is_array($j)) { $errors[] = $label . '_json_parse_error'; return []; }
    return $j;
}

function auto_photo_bundle_photo_ref($p): string
{
    if (is_string($p)) return auto_photo_bundle_normalize_photo_path($p);
    if (is_array($p) && isset($p['file'])) return auto_photo_bundle_normalize_photo_path((string)$p['file']);
    if (is_array($p) && isset($p['filename'])) return auto_photo_bundle_normalize_photo_path((string)$p['filename']);
    throw new InvalidArgumentException('invalid_photo_ref');
}

function auto_photo_bundle_decode_jsonl_map(?array $member, int $limit, array &$errors, array &$warnings, string $label): array
{
    $out = ['count'=>0, 'by_path'=>[]];
    if (!$member) return $out;
    if ($member['size'] > $limit) { $errors[] = $label . '_jsonl_limit_exceeded'; return $out; }
    $seq = [];
    foreach (preg_split('/\r\n|\n|\r/', (string)$member['content']) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $row = json_decode($line, true);
        if (!is_array($row)) { $errors[] = $label . '_jsonl_parse_error'; continue; }
        $out['count']++;
        if (isset($row['sequence'])) {
            if (isset($seq[(string)$row['sequence']])) $errors[] = 'duplicate_sequence';
            $seq[(string)$row['sequence']] = true;
        }
        try { $path = auto_photo_bundle_normalize_photo_path((string)($row['file'] ?? $row['filename'] ?? '')); } catch (Throwable $e) { $errors[] = $label . '_invalid_photo_ref'; continue; }
        if (isset($out['by_path'][$path])) $errors[] = $label . '_duplicate_filename';
        $out['by_path'][$path] = $row;
    }
    $nums = array_map('intval', array_keys($seq)); sort($nums);
    for ($i = 1; $i <= count($nums); $i++) if (($nums[$i - 1] ?? $i) !== $i) { $warnings[] = 'sequence_gap'; break; }
    return $out;
}

function auto_photo_bundle_count_jsonl_member(?array $member, int $limit, array &$errors, string $label): int
{
    if (!$member) return 0;
    if ($member['size'] > $limit) { $errors[] = $label . '_jsonl_limit_exceeded'; return 0; }
    $n = 0;
    foreach (preg_split('/\r\n|\n|\r/', (string)$member['content']) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $n++;
        if (json_decode($line, true) === null) $errors[] = $label . '_jsonl_parse_error';
    }
    return $n;
}

function auto_photo_bundle_write_index_atomic(array $index, string $targetPath): void
{
    $dir = dirname($targetPath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('index_dir_create_failed');
    $lockPath = $dir . '/.index.lock';
    $lock = fopen($lockPath, 'c');
    if (!$lock) throw new RuntimeException('index_lock_open_failed');
    $tmp = $targetPath . '.tmp.' . bin2hex(random_bytes(8));
    $tmpHandle = null;
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('index_lock_failed');
        $json = json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) throw new RuntimeException('index_json_encode_failed');
        $tmpHandle = fopen($tmp, 'wb');
        if (!$tmpHandle) throw new RuntimeException('index_tmp_open_failed');
        $written = fwrite($tmpHandle, $json);
        if ($written === false || $written !== strlen($json)) throw new RuntimeException('index_write_failed');
        if (!fflush($tmpHandle)) throw new RuntimeException('index_flush_failed');
        if (function_exists('fsync') && !fsync($tmpHandle)) throw new RuntimeException('index_fsync_failed');
        if (!fclose($tmpHandle)) { $tmpHandle = null; throw new RuntimeException('index_close_failed'); }
        $tmpHandle = null;
        if (!@rename($tmp, $targetPath)) throw new RuntimeException('index_rename_failed');
    } finally {
        if (is_resource($tmpHandle)) fclose($tmpHandle);
        if (is_file($tmp)) @unlink($tmp);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function auto_photo_bundle_index_cache_path(array $bundleRow, string $archivePath): string
{
    $sessionDir = dirname(dirname($archivePath));
    return $sessionDir . '/auto_photo_bundles/' . (int)$bundleRow['id'] . '/index.json';
}
