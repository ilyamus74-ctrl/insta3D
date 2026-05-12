<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../configs/secure.php';
require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');

$data = print_read_json_body();
$deviceUid = trim((string)($data['device_uid'] ?? ''));
$jobId = trim((string)($data['job_id'] ?? ''));
$status = trim((string)($data['status'] ?? ''));
$message = trim((string)($data['message'] ?? ''));

if ($deviceUid === '' || $jobId === '' || $status === '') {
    print_json_response(['status' => 'error', 'message' => 'device_uid, job_id, status required'], 400);
}

$allowed = ['received', 'printed', 'failed'];
if (!in_array($status, $allowed, true)) {
    print_json_response(['status' => 'error', 'message' => 'invalid status'], 400);
}

$deviceToken = print_bearer_token();
if ($deviceToken === '') {
    $deviceToken = trim((string)($data['device_token'] ?? ''));
}

$auth = auth_device_by_token($deviceUid, $deviceToken, true);
if (!$auth['ok']) {
    print_json_response(['status' => 'error', 'message' => $auth['message'] ?? 'auth error'], (int)($auth['http_code'] ?? 403));
}

$ok = print_update_job_status($deviceUid, $jobId, $status, $message);
if (!$ok) {
    print_json_response(['status' => 'error', 'message' => 'job not found or update failed'], 404);
}

print_json_response(['status' => 'ok'], 200);
