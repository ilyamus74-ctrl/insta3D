<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
auth_require_login();
$user = auth_current_user(); $uid = (int)$user['id']; $role = (string)($user['role'] ?? 'BROKER');
function out_json(array $p, int $c=200): void { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT); exit; }
function can_view_order(array $o, int $uid, string $role): bool { return $role === 'ADMIN' || (int)$o['broker_id'] === $uid || ($role === 'OPERATOR' && ((int)$o['operator_id'] === $uid || ((int)$o['is_published'] === 1 && (string)$o['status'] === 'NEW' && $o['operator_id'] === null))); }
function safe_file(string $base, string $rel): ?string { $rb = realpath($base); if ($rb === false || !is_dir($rb)) return null; $rp = realpath($rb . '/' . ltrim($rel, '/')); if ($rp === false || !is_file($rp)) return null; return str_starts_with($rp, $rb . '/') ? $rp : null; }
function tail_file(string $file, int $lines=200): string { $data = @file($file); if ($data === false) return ''; return implode('', array_slice($data, -$lines)); }
$jobId = (int)($_GET['job_id'] ?? 0); $type = (string)($_GET['type'] ?? '');
if ($jobId <= 0 || !in_array($type, ['status','result','logs'], true)) out_json(['ok'=>false,'message'=>'bad request'], 400);
$st = $dbcnx->prepare('SELECT * FROM sfm_remote_jobs WHERE id=? LIMIT 1'); if (!$st) out_json(['ok'=>false,'message'=>'job table unavailable'], 500);
$st->bind_param('i', $jobId); $st->execute(); $job = $st->get_result()->fetch_assoc(); $st->close(); if (!$job) out_json(['ok'=>false,'message'=>'job not found'], 404);
$oid = (int)$job['order_id']; $st = $dbcnx->prepare('SELECT id,broker_id,operator_id,is_published,status FROM tour_orders WHERE id=? LIMIT 1'); $st->bind_param('i', $oid); $st->execute(); $order = $st->get_result()->fetch_assoc(); $st->close(); if (!$order || !can_view_order($order, $uid, $role)) out_json(['ok'=>false,'message'=>'forbidden'], 403);
$remote = (int)$job['remote_job_id']; if ($remote <= 0) out_json(['ok'=>false,'message'=>'bad remote job'], 400);
$base = '/home/makler/web/remote_station/output/job_' . $remote;
if ($type === 'status') {
  $file = safe_file($base, 'status.json') ?: safe_file($base, 'remote_status.json');
  if (!$file) out_json(['ok'=>false,'message'=>'File not available yet'], 404);
  header('Content-Type: application/json; charset=utf-8'); readfile($file); exit;
}
if ($type === 'logs') {
  $logs=[]; foreach (['logs/*.log','logs/*.txt','colmap/logs/*.log','colmap/logs/*.txt','dense/logs/*.log','dense/logs/*.txt','chunks/chunk_*/logs/*.log','chunks/chunk_*/logs/*.txt','merged/*.json'] as $pat) { foreach (glob($base.'/'.$pat) ?: [] as $lf) { $rp = safe_file($base, substr($lf, strlen($base)+1)); if ($rp) $logs[] = ['file'=>substr($rp, strlen((string)realpath($base))+1), 'tail'=>tail_file($rp, 200)]; } }
  if (!$logs) out_json(['ok'=>false,'message'=>'File not available yet'], 404);
  out_json(['ok'=>true,'logs'=>$logs]);
}
$jt = (string)$job['job_type']; $candidates=[];
if ($jt === 'EXTRACT_FRAMES') $candidates[]='frames/result.json';
elseif ($jt === 'COLMAP_SPARSE') $candidates[]='colmap/result.json';
elseif ($jt === 'COLMAP_DENSE') $candidates[]='dense/result.json';
elseif ($jt === 'COLMAP_RECONSTRUCTION_PREVIEW' || $jt === 'COLMAP_RECONSTRUCTION_HQ') { $candidates[]='merged/result.json'; $candidates[]='chunk_plan.json'; }
elseif ($jt === 'EXPORT_PLY') {
  $parent = (int)($job['parent_remote_job_id'] ?? 0); $out = (string)($job['output_path'] ?? ''); $model = null;
  if (preg_match('/sparse_(\d+)\.ply|model[_-]?(\d+)/', $out, $m)) $model = (int)($m[1] !== '' ? $m[1] : $m[2]);
  if ($parent > 0 && $model !== null) { $base = '/home/makler/web/remote_station/output/job_' . $parent; $candidates[]='colmap/sparse/'.$model.'/export_ply_result.json'; }
}
foreach ($candidates as $rel) { $file = safe_file($base, $rel); if ($file) { header('Content-Type: application/json; charset=utf-8'); readfile($file); exit; } }
$rb = realpath($base); if ($rb) { $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rb, FilesystemIterator::SKIP_DOTS)); foreach ($it as $f) { if ($it->getDepth() > 5) continue; if ($f->isFile() && $f->getFilename() === 'result.json') { $rp=$f->getRealPath(); if ($rp && str_starts_with($rp, $rb.'/')) { header('Content-Type: application/json; charset=utf-8'); readfile($rp); exit; } } } }
out_json(['ok'=>false,'message'=>'File not available yet'], 404);