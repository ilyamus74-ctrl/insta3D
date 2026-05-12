<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../configs/secure.php';
require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');

$data = print_read_json_body();
$deviceUid = trim((string)($data['device_uid'] ?? ''));
$labelUrl = trim((string)($data['label_url'] ?? ''));
$labelBase64 = trim((string)($data['label_base64'] ?? ''));
$fileName = trim((string)($data['file_name'] ?? 'label.pdf'));
$labelWidthCm = isset($data['label_width_cm']) ? (float)$data['label_width_cm'] : 0.0;
$labelHeightCm = isset($data['label_height_cm']) ? (float)$data['label_height_cm'] : 0.0;
$rotate = isset($data['rotate']) ? (int)$data['rotate'] : (int)($data['print_rotate'] ?? 0);

if ($deviceUid === '') {
    print_json_response(['status' => 'error', 'message' => 'device_uid required'], 400);
}
if ($labelUrl === '' && $labelBase64 === '') {
    print_json_response(['status' => 'error', 'message' => 'label_url or label_base64 required'], 400);
}

$token = trim((string)($data['device_token'] ?? ''));
if ($token === '') {
    $token = print_bearer_token();
}

$auth = auth_device_by_token($deviceUid, $token, true);
if (!$auth['ok']) {
    print_json_response(['status' => 'error', 'message' => $auth['message'] ?? 'auth error'], (int)($auth['http_code'] ?? 403));
}

$queue = print_read_queue($deviceUid);

$jobId = (string)($data['job_id'] ?? '');
if ($jobId === '') {
    $jobId = 'job-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
}

$job = [
    'job_id' => $jobId,
    'status' => 'queued',
    'created_at' => gmdate('c'),
    'file_name' => $fileName,
];

if ($labelWidthCm > 0) {
    $job['label_width_cm'] = $labelWidthCm;
}
if ($labelHeightCm > 0) {
    $job['label_height_cm'] = $labelHeightCm;
}
if (in_array($rotate, [0, 90, 180, 270], true)) {
    $job['rotate'] = $rotate;
}
if ($labelUrl !== '') {
    $job['label_url'] = $labelUrl;
}
if ($labelBase64 !== '') {
    $job['label_base64'] = $labelBase64;
}

$queue[] = $job;
if (!print_write_queue($deviceUid, $queue)) {
    print_json_response(['status' => 'error', 'message' => 'failed to save queue'], 500);
}

print_json_response(['status' => 'ok', 'job_id' => $jobId], 200);
