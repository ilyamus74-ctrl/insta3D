<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/libs/sfm_manual_alignment_lib.php';
auth_require_login();
set_time_limit(0);

header('X-Content-Type-Options: nosniff');

$orderId = max(0, (int)($_GET['order_id'] ?? 0));
$anchorKind = (string)($_GET['anchor_kind'] ?? 'remote');
$anchorId = max(0, (int)($_GET['anchor_id'] ?? 0));
$sourceKind = (string)($_GET['source_kind'] ?? 'remote');
$sourceId = max(0, (int)($_GET['source_id'] ?? 0));
$action = (string)($_GET['action'] ?? 'meta');

if ($orderId <= 0 || $anchorId <= 0 || $sourceId <= 0) {
    json_response(['ok' => false, 'error' => 'Missing alignment identifiers'], 400);
}
if ($anchorKind === $sourceKind && $anchorId === $sourceId) {
    json_response(['ok' => false, 'error' => 'Anchor and source must be different models'], 400);
}
if (!in_array($anchorKind, ['remote','merge'], true) || $sourceKind !== 'remote') {
    json_response(['ok' => false, 'error' => 'Manual alignment supports anchor_kind=remote|merge and source_kind=remote'], 400);
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function require_csrf_token(): void
{
    $expected = (string)($_SESSION['secCode'] ?? '');
    $got = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['secCode'] ?? ''));
    if ($expected === '' || !hash_equals($expected, $got)) {
        json_response(['ok' => false, 'error' => 'CSRF token mismatch'], 403);
    }
}

function can_view_order(array $order, int $userId, string $role): bool
{
    return $role === 'ADMIN'
        || (int)($order['broker_id'] ?? 0) === $userId
        || ($role === 'OPERATOR' && (
            (int)($order['operator_id'] ?? 0) === $userId
            || (
                (int)($order['is_published'] ?? 0) === 1
                && (string)($order['status'] ?? '') === 'NEW'
                && ($order['operator_id'] ?? null) === null
            )
        ));
}

function ensure_order_access(mysqli $dbcnx, int $orderId): array
{
    $user = auth_current_user();
    $userId = (int)($user['id'] ?? 0);
    $role = (string)($user['role'] ?? 'BROKER');

    $stmt = $dbcnx->prepare(
        'SELECT id, broker_id, operator_id, is_published, status
         FROM tour_orders
         WHERE id=?
         LIMIT 1'
    );
    if (!$stmt) {
        json_response(['ok' => false, 'error' => 'DB prepare error'], 500);
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        json_response(['ok' => false, 'error' => 'Order not found'], 404);
    }
    if (!can_view_order($order, $userId, $role)) {
        json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    return $order;
}

function safe_existing_ply(array $candidates): string
{
    $base = realpath(dirname(__DIR__, 2) . '/remote_station/output');
    if ($base === false) {
        json_response(['ok' => false, 'error' => 'SfM output root not found'], 500);
    }

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }
        $real = realpath($candidate);
        if ($real === false || !is_file($real) || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'ply') {
            continue;
        }
        if ($real !== $base && !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            continue;
        }
        return $real;
    }

    json_response(['ok' => false, 'error' => 'PLY file not found in allowed SfM output tree'], 404);
}

function resolve_model(mysqli $dbcnx, int $orderId, string $kind, int $id): array
{
    $root = dirname(__DIR__, 2);

    if ($kind === 'merge') {
        try {
            $resolved = sfm_manual_resolve_merge_anchor($dbcnx, $orderId, $id);
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        return [
            'kind' => 'merge',
            'id' => $id,
            'label' => 'merge #' . $id,
            'ply' => $resolved['ply'],
            'db' => $resolved['row'],
            'leaf_source_jobs' => $resolved['leaf_source_jobs'] ?? [],
            'leaf_transforms' => $resolved['leaf_transforms'] ?? [],
        ];
    }

    $stmt = $dbcnx->prepare(
        'SELECT id, order_id, capture_session_id, remote_job_id, output_path, status, job_type, parameters_json
         FROM sfm_remote_jobs
         WHERE remote_job_id=? AND order_id=?
           AND job_type IN (\'COLMAP_RECONSTRUCTION_PREVIEW\',\'COLMAP_RECONSTRUCTION_HQ\')
           AND status=\'DONE\'
         LIMIT 1'
    );
    if (!$stmt) {
        json_response(['ok' => false, 'error' => 'DB prepare error'], 500);
    }
    $stmt->bind_param('ii', $id, $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        json_response(['ok' => false, 'error' => "Remote DONE PREVIEW/HQ reconstruction job $id not found"], 404);
    }

    $outputPath = rtrim((string)($row['output_path'] ?? ''), '/');
    $localMirror = $root . '/remote_station/output/job_' . $id;

    $ply = safe_existing_ply([
        $localMirror . '/merged/merged_fused.ply',
        $outputPath . '/merged/merged_fused.ply',
        $localMirror . '/dense/fused.ply',
        $outputPath . '/dense/fused.ply',
    ]);

    return [
        'kind' => 'remote',
        'id' => $id,
        'label' => 'remote job ' . $id,
        'ply' => $ply,
        'db' => $row,
    ];
}

function draft_dir(
    int $orderId,
    string $anchorKind,
    int $anchorId,
    string $sourceKind,
    int $sourceId
): string {
    $root = dirname(__DIR__, 2) . '/remote_station/output';
    $name = sprintf(
        'manual_alignment_order_%d_anchor_%s_%d_source_%s_%d',
        $orderId,
        preg_replace('/[^a-z0-9_]+/i', '_', $anchorKind),
        $anchorId,
        preg_replace('/[^a-z0-9_]+/i', '_', $sourceKind),
        $sourceId
    );
    return $root . '/' . $name;
}

function stream_file(string $path, string $contentType, string $downloadName): never
{
    if (!is_file($path)) {
        json_response(['ok' => false, 'error' => 'File not found'], 404);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . addcslashes($downloadName, "\"\\") . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        json_response(['ok' => false, 'error' => 'Cannot open file'], 500);
    }
    fpassthru($fh);
    fclose($fh);
    exit;
}

ensure_order_access($dbcnx, $orderId);
$anchor = resolve_model($dbcnx, $orderId, $anchorKind, $anchorId);
$source = resolve_model($dbcnx, $orderId, $sourceKind, $sourceId);
if ($anchorKind === 'merge') { foreach (($anchor['leaf_source_jobs'] ?? []) as $leaf) { if ((int)($leaf['remote_job_id'] ?? 0) === (int)$sourceId) { json_response(['ok'=>false,'error'=>'Source model is already included in this assembly'],400); } } }
if ((int)($anchor['db']['capture_session_id'] ?? 0) !== (int)($source['db']['capture_session_id'] ?? 0)) {
    json_response(['ok' => false, 'error' => 'Anchor and source must belong to the same capture session'], 400);
}
$draftDir = draft_dir($orderId, $anchorKind, $anchorId, $sourceKind, $sourceId);

if ($action === 'file') {
    $file = (string)($_GET['file'] ?? '');
    if ($file === 'anchor') {
        stream_file($anchor['ply'], 'application/octet-stream', 'anchor.ply');
    }
    if ($file === 'source') {
        stream_file($source['ply'], 'application/octet-stream', 'source.ply');
    }
    if ($file === 'aligned') {
        stream_file($draftDir . '/source_aligned_to_anchor.ply', 'application/octet-stream', 'source_aligned_to_anchor.ply');
    }
    if ($file === 'merged') {
        stream_file($draftDir . '/manual_merged_dense_cloud.ply', 'application/octet-stream', 'manual_merged_dense_cloud.ply');
    }
    if ($file === 'result') {
        stream_file($draftDir . '/merge_result.json', 'application/json; charset=utf-8', 'merge_result.json');
    }
    json_response(['ok' => false, 'error' => 'Unsupported file selector'], 400);
}

if ($action === 'meta') {
    json_response([
        'ok' => true,
        'order_id' => $orderId,
        'anchor' => [
            'kind' => $anchor['kind'],
            'id' => $anchor['id'],
            'label' => $anchor['label'],
            'size_bytes' => filesize($anchor['ply']),
        ],
        'source' => [
            'kind' => $source['kind'],
            'id' => $source['id'],
            'label' => $source['label'],
            'size_bytes' => filesize($source['ply']),
        ],
        'draft_dir' => $draftDir,
        'saved_merge' => (function() use ($dbcnx, $orderId, $anchorKind, $anchorId, $sourceKind, $sourceId, $draftDir) {
            try {
                $pairs = $draftDir . '/correspondence_pairs.json';
                $out = $draftDir . '/manual_merged_dense_cloud.ply';
                if (is_file($pairs) && is_file($out)) {
                    $a = sfm_manual_resolve_alignment_input($dbcnx, $orderId, $anchorKind, $anchorId);
                    $s = sfm_manual_resolve_remote_model($dbcnx, $orderId, $sourceKind, $sourceId);
                    $pairsHash = sfm_manual_pairs_hash($pairs);
                    $fp = $anchorKind === 'merge'
                        ? sfm_manual_incremental_fingerprint($orderId, $a, $s, $pairsHash, md5_file($out), md5_file($a['ply']), md5_file($s['ply']))
                        : sfm_manual_fingerprint($orderId, $a, $s, $pairsHash, md5_file($out), md5_file($a['ply']), md5_file($s['ply']));
                    return sfm_manual_find_existing_merge($dbcnx, $orderId, $fp);
                }
            } catch (Throwable $e) {
                error_log('manual alignment meta saved lookup failed: '.$e->getMessage());
            }
            return sfm_manual_find_existing_merge($dbcnx, $orderId, '', $draftDir . '/manual_merged_dense_cloud.ply', $draftDir . '/merge_result.json');
        })(),
    ]);
}


if ($action === 'finalize') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_response(['ok'=>false,'error'=>'Finalize requires POST'],405); }
    require_csrf_token();
    try {
        $user = auth_current_user();
        $res = sfm_manual_finalize($dbcnx, $orderId, $anchorKind, $anchorId, $sourceKind, $sourceId, (int)($user['id'] ?? 0), (string)($user['role'] ?? 'BROKER'));
        $res['viewer_url'] = '/sfm_3d_viewer.php?order_id=' . $orderId . '&merge_id=' . (int)$res['merge_id'] . '&artifact=dense';
        $res['order_url'] = '/order_simple.php?id=' . $orderId . '#simple-video-sfm';
        json_response($res);
    } catch (Throwable $e) {
        error_log('manual alignment API finalize failed: '.$e->getMessage());
        json_response(['ok'=>false,'error'=>$e->getMessage()],400);
    }
}

if ($action !== 'compute' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Unsupported action'], 400);
}
require_csrf_token();
try {
    $u = auth_current_user();
    sfm_manual_ensure_order_write_access($dbcnx, $orderId, (int)($u['id'] ?? 0), (string)($u['role'] ?? 'BROKER'));
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 403);
}

$raw = file_get_contents('php://input');
$input = json_decode((string)$raw, true);
$pairs = is_array($input) && isset($input['pairs']) && is_array($input['pairs']) ? $input['pairs'] : [];

if (count($pairs) < 4 || count($pairs) > 100) {
    json_response(['ok' => false, 'error' => 'Expected 4–100 correspondence pairs'], 422);
}

$normalized = [];
foreach ($pairs as $index => $pair) {
    if (!is_array($pair)) {
        json_response(['ok' => false, 'error' => "Pair $index is invalid"], 422);
    }
    $entry = [];
    foreach (['anchor', 'source'] as $side) {
        $coords = $pair[$side] ?? null;
        if (!is_array($coords) || count($coords) !== 3) {
            json_response(['ok' => false, 'error' => "Pair $index $side must contain XYZ"], 422);
        }
        $values = array_map('floatval', array_values($coords));
        foreach ($values as $value) {
            if (!is_finite($value)) {
                json_response(['ok' => false, 'error' => "Pair $index $side contains non-finite value"], 422);
            }
        }
        $entry[$side] = $values;
    }
    $normalized[] = $entry;
}

if (!is_dir($draftDir) && !mkdir($draftDir, 0775, true) && !is_dir($draftDir)) {
    json_response(['ok' => false, 'error' => 'Cannot create draft directory'], 500);
}

$lockHandle = fopen($draftDir . '/compute.lock', 'c');
if ($lockHandle === false) {
    json_response(['ok' => false, 'error' => 'Cannot open compute lock'], 500);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fclose($lockHandle);
    json_response(['ok' => false, 'error' => 'Manual alignment compute is already running for this pair'], 409);
}

function cleanup_compute_staging(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (['correspondence_pairs.json', 'source_aligned_to_anchor.ply', 'manual_merged_dense_cloud.ply', 'merge_result.json'] as $name) {
        @unlink($dir . '/' . $name);
    }
    @rmdir($dir);
}

$stagingDir = $draftDir . '/.compute_' . getmypid() . '_' . bin2hex(random_bytes(4));
if (!mkdir($stagingDir, 0775, true) && !is_dir($stagingDir)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    json_response(['ok' => false, 'error' => 'Cannot create staging directory'], 500);
}

$stagingCorrespondencePath = $stagingDir . '/correspondence_pairs.json';
$publishedCorrespondencePath = $draftDir . '/correspondence_pairs.json';
$publishedAlignedPath = $draftDir . '/source_aligned_to_anchor.ply';
$publishedMergedPath = $draftDir . '/manual_merged_dense_cloud.ply';
$publishedResultPath = $draftDir . '/merge_result.json';
$payload = [
    'schema_version' => 1,
    'created_at' => date(DATE_ATOM),
    'order_id' => $orderId,
    'anchor' => ['kind' => $anchorKind, 'id' => $anchorId, 'ply' => $anchor['ply']],
    'source' => ['kind' => $sourceKind, 'id' => $sourceId, 'ply' => $source['ply']],
    'operation' => $anchorKind === 'merge' ? 'incremental_add_model' : 'base_manual_merge',
    'anchor_merge_id' => $anchorKind === 'merge' ? $anchorId : null,
    'source_remote_job_id' => $sourceId,
    'pairs' => $normalized,
];

if (file_put_contents(
    $stagingCorrespondencePath,
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
) === false) {
    cleanup_compute_staging($stagingDir);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    json_response(['ok' => false, 'error' => 'Cannot save correspondence file'], 500);
}

$script = dirname(__DIR__, 2) . '/remote_station/scripts/manual_pointcloud_correspondence_merge.py';
if (!is_file($script)) {
    cleanup_compute_staging($stagingDir);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    json_response(['ok' => false, 'error' => 'Alignment script not found'], 500);
}

$command = implode(' ', array_map('escapeshellarg', [
    '/usr/bin/python3',
    $script,
    '--anchor',
    $anchor['ply'],
    '--source',
    $source['ply'],
    '--correspondences',
    $stagingCorrespondencePath,
    '--output-dir',
    $stagingDir,
]));

$output = [];
$code = 0;
exec($command . ' 2>&1', $output, $code);

if ($code !== 0) {
    cleanup_compute_staging($stagingDir);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    json_response([
        'ok' => false,
        'error' => 'Manual alignment failed',
        'exit_code' => $code,
        'log' => implode("\n", array_slice($output, -100)),
    ], 500);
}

$stagingResultPath = $stagingDir . '/merge_result.json';
$stagingAlignedPath = $stagingDir . '/source_aligned_to_anchor.ply';
$stagingMergedPath = $stagingDir . '/manual_merged_dense_cloud.ply';
$result = is_file($stagingResultPath)
    ? json_decode((string)file_get_contents($stagingResultPath), true)
    : null;

if (!is_array($result) || !is_file($stagingAlignedPath) || !is_file($stagingMergedPath)) {
    cleanup_compute_staging($stagingDir);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    json_response(['ok' => false, 'error' => 'Complete compute result was not produced'], 500);
}

$pairsHash = sfm_manual_pairs_hash($stagingCorrespondencePath);
$result['correspondence_sha256'] = $pairsHash;
$result['correspondence_path'] = $publishedCorrespondencePath;
$result['aligned_source_path'] = $publishedAlignedPath;
$result['merged_path'] = $publishedMergedPath;
$result['anchor_md5'] = md5_file($anchor['ply']);
$result['source_md5'] = md5_file($source['ply']);
$result['merged_md5'] = md5_file($stagingMergedPath);
$result['pairs_count'] = count($normalized);
$result['operation'] = $anchorKind === 'merge' ? 'incremental_add_model' : 'base_manual_merge';
$result['anchor_kind'] = $anchorKind;
$result['anchor_merge_id'] = $anchorKind === 'merge' ? $anchorId : null;
$result['source_kind'] = $sourceKind;
$result['source_remote_job_id'] = $sourceId;
try {
    sfm_manual_atomic_write_json($stagingResultPath, $result);
    @unlink($publishedResultPath);
    if (!rename($stagingCorrespondencePath, $publishedCorrespondencePath)) {
        throw new RuntimeException('Cannot publish correspondence file');
    }
    if (!rename($stagingAlignedPath, $publishedAlignedPath)) {
        throw new RuntimeException('Cannot publish aligned source PLY');
    }
    if (!rename($stagingMergedPath, $publishedMergedPath)) {
        throw new RuntimeException('Cannot publish merged PLY');
    }
    if (!rename($stagingResultPath, $publishedResultPath)) {
        throw new RuntimeException('Cannot publish result JSON');
    }
    @rmdir($stagingDir);
} catch (Throwable $e) {
    cleanup_compute_staging($stagingDir);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

$query = http_build_query([
    'order_id' => $orderId,
    'anchor_kind' => $anchorKind,
    'anchor_id' => $anchorId,
    'source_kind' => $sourceKind,
    'source_id' => $sourceId,
    'action' => 'file',
]);

$result['ok'] = true;
$result['aligned_url'] = '/api/sfm_manual_alignment.php?' . $query . '&file=aligned';
$result['merged_url'] = '/api/sfm_manual_alignment.php?' . $query . '&file=merged';
$result['result_url'] = '/api/sfm_manual_alignment.php?' . $query . '&file=result';
$result['draft_dir'] = $draftDir;
json_response($result);