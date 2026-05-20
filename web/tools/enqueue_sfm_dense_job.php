<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
$connectCandidates = ['/home/makler/web/configs/connectDB.php', __DIR__ . '/../configs/connectDB.php'];
foreach ($connectCandidates as $f) { if (is_file($f)) { require_once $f; break; } }
if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) { fwrite(STDERR, "DB init failed\n"); exit(1); }

$opts = getopt('', ['order-id:', 'session-id:', 'quality::', 'max-image-size::']);
$orderId = (int)($opts['order-id'] ?? 0);
$sessionId = (int)($opts['session-id'] ?? 0);
$quality = strtoupper(trim((string)($opts['quality'] ?? 'LOW')));
$maxImageSize = (int)($opts['max-image-size'] ?? 1024);
if ($orderId <= 0 || $sessionId <= 0) { fwrite(STDERR, "--order-id and --session-id are required\n"); exit(2); }
if (!in_array($quality, ['LOW', 'MEDIUM', 'HIGH'], true)) { fwrite(STDERR, "quality must be LOW|MEDIUM|HIGH\n"); exit(2); }
if ($maxImageSize <= 0) $maxImageSize = 1024;

$activeList = "'NOT_STARTED','QUEUED','PENDING','RUNNING'";
$st = $dbcnx->prepare("SELECT id,status FROM processing_jobs WHERE order_id=? AND session_id=? AND job_type='SFM_DENSE_MODEL' AND status IN ($activeList) ORDER BY id DESC LIMIT 1");
$st->bind_param('ii', $orderId, $sessionId); $st->execute(); $existing = $st->get_result()->fetch_assoc(); $st->close();
if ($existing) {
    echo json_encode(['ok'=>true,'job_id'=>(int)$existing['id'],'status'=>(string)$existing['status'],'duplicate'=>true], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$payload = json_encode(['quality'=>$quality,'max_image_size'=>$maxImageSize,'source'=>'colmap_sparse'], JSON_UNESCAPED_SLASHES);
$jobType='SFM_DENSE_MODEL'; $status='QUEUED'; $metric='NOT_READY';
$in = $dbcnx->prepare('INSERT INTO processing_jobs (session_id,order_id,job_type,status,metric_status,warning_text,error_text,created_at,updated_at) VALUES (?,?,?,?,?,?,NULL,NOW(6),NOW(6))');
$in->bind_param('iissss', $sessionId, $orderId, $jobType, $status, $metric, $payload);
if (!$in->execute()) { fwrite(STDERR, "insert failed\n"); exit(1); }
$jobId = (int)$in->insert_id; $in->close();
echo json_encode(['ok'=>true,'job_id'=>$jobId,'status'=>'QUEUED'], JSON_UNESCAPED_SLASHES) . "\n";
