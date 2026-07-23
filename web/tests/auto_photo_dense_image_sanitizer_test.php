<?php
declare(strict_types=1);

function adis_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function adis_segment(int $marker, string $payload): string
{
    return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
}

function adis_run(array $arguments, ?string &$output = null): int
{
    $command = implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
    $lines = [];
    exec($command, $lines, $code);
    $output = implode("\n", $lines);
    return $code;
}

$root = sys_get_temp_dir() . '/auto_photo_dense_sanitize_' . bin2hex(random_bytes(6));
$source = $root . '/source';
$output = $root . '/output';
$logs = $root . '/logs';
mkdir($source . '/nested', 0777, true);
mkdir($logs, 0777, true);

$helper = realpath(__DIR__ . '/../remote_station/scripts/sanitize_dense_images.py');
$chunk = realpath(__DIR__ . '/../remote_station/scripts/process_colmap_dense_chunk.sh');
$deploy = realpath(__DIR__ . '/../remote_station/deploy_station.sh');
adis_ok(is_string($helper) && $helper !== '', 'sanitizer helper exists');
adis_ok(is_string($chunk) && $chunk !== '', 'dense chunk script exists');
adis_ok(is_string($deploy) && $deploy !== '', 'deploy script exists');

$app0 = adis_segment(0xE0, "JFIF\x00fixture");
$app1 = adis_segment(0xE1, "http://ns.adobe.com/xap/1.0/\x00iptc-from-xmp");
$app2 = adis_segment(0xE2, "ICC_PROFILE\x00preserved");
$app13 = adis_segment(0xED, "Photoshop 3.0\x00broken-iptc");
$comment = adis_segment(0xFE, 'comment-kept');
$dqt = adis_segment(0xDB, str_repeat("\x01", 64));
$sosHeader = "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00";
$scan = "\x11\x22\xFF\x00\x33\x44\xFF\xD9";
$jpeg = "\xFF\xD8" . $app0 . $app1 . $app2 . $app13 . $comment . $dqt . $sosHeader . $scan;

$jpegPath = $source . '/nested/photo.jpg';
$textPath = $source . '/note.txt';
file_put_contents($jpegPath, $jpeg);
file_put_contents($textPath, "unchanged\n");
$listPath = $root . '/images.txt';
$statsPath = $logs . '/stats.json';
file_put_contents($listPath, "nested/photo.jpg\nnote.txt\nnested/photo.jpg\n");

$commandOutput = '';
$code = adis_run(
    ['python3', $helper, $source, $listPath, $output, $statsPath],
    $commandOutput
);
adis_ok($code === 0, 'sanitizer succeeds: ' . $commandOutput);

$sanitized = file_get_contents($output . '/nested/photo.jpg');
adis_ok(is_string($sanitized), 'sanitized jpeg exists');
adis_ok($sanitized !== $jpeg, 'jpeg changed');
adis_ok(str_contains($sanitized, "JFIF\x00fixture"), 'APP0 preserved');
adis_ok(!str_contains($sanitized, 'iptc-from-xmp'), 'APP1 EXIF/XMP removed');
adis_ok(str_contains($sanitized, "ICC_PROFILE\x00preserved"), 'APP2 ICC preserved');
adis_ok(!str_contains($sanitized, 'broken-iptc'), 'APP13 removed');
adis_ok(!str_contains($sanitized, 'comment-kept'), 'COM removed');
adis_ok(substr($sanitized, -strlen($sosHeader . $scan)) === $sosHeader . $scan, 'JPEG scan bytes preserved');
adis_ok(file_get_contents($jpegPath) === $jpeg, 'source jpeg unchanged');
adis_ok(file_get_contents($output . '/note.txt') === "unchanged\n", 'non-jpeg copied unchanged');

$stats = json_decode((string) file_get_contents($statsPath), true);
adis_ok(is_array($stats), 'stats json');
adis_ok(($stats['images_total'] ?? null) === 2, 'deduplicated image count');
adis_ok(($stats['jpeg_images'] ?? null) === 1, 'jpeg count');
adis_ok(($stats['jpeg_images_sanitized'] ?? null) === 1, 'sanitized jpeg count');
adis_ok(($stats['metadata_segments_removed'] ?? null) === 3, 'removed metadata count');
adis_ok(($stats['app1_segments_removed'] ?? null) === 1, 'removed APP1 count');
adis_ok(($stats['app13_segments_removed'] ?? null) === 1, 'removed APP13 count');
adis_ok(($stats['comment_segments_removed'] ?? null) === 1, 'removed COM count');

$unsafeList = $root . '/unsafe.txt';
file_put_contents($unsafeList, "../escape.jpg\n");
$unsafeOutput = '';
$unsafeCode = adis_run(
    ['python3', $helper, $source, $unsafeList, $root . '/unsafe-output', $logs . '/unsafe.json'],
    $unsafeOutput
);
adis_ok($unsafeCode !== 0, 'path traversal rejected');
adis_ok(str_contains($unsafeOutput, 'unsafe image path'), 'path traversal message');

$chunkSource = (string) file_get_contents($chunk);
$sanitizePosition = strpos($chunkSource, 'sanitize_dense_images.py');
$undistortPosition = strpos($chunkSource, 'run_colmap image_undistorter');
adis_ok($sanitizePosition !== false, 'chunk wires sanitizer');
adis_ok($undistortPosition !== false && $sanitizePosition < $undistortPosition, 'sanitizer runs before undistorter');
adis_ok(
    str_contains($chunkSource, '--image_path "$SANITIZED_IMAGES_DIR"'),
    'undistorter uses sanitized image root'
);
adis_ok(
    str_contains($chunkSource, 'status RUNNING 3 "Sanitizing dense chunk $CHUNK_ID images"'),
    'sanitizing status'
);
adis_ok(str_contains($chunkSource, 'ERROR_KILLED'), 'killed status is not misclassified as OOM');
adis_ok(!str_contains($chunkSource, '[[ $ec -eq 137 ]] && st=ERROR_OOM'), 'legacy OOM classification removed');

$deploySource = (string) file_get_contents($deploy);
adis_ok(
    str_contains($deploySource, "test -x '\$STATION_BASE/scripts/sanitize_dense_images.py'"),
    'station deploy verifies sanitizer'
);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $item) {
    if ($item->isDir() && !$item->isLink()) {
        rmdir($item->getPathname());
    } else {
        unlink($item->getPathname());
    }
}
rmdir($root);

echo "OK\n";
