<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../configs/secure.php';
require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');

$deviceUid = trim((string)($_GET['device_uid'] ?? ''));
if ($deviceUid === '') {
    print_json_response(['status' => 'error', 'message' => 'device_uid required'], 400);
}

$deviceToken = print_bearer_token();
if ($deviceToken === '') {
    $deviceToken = trim((string)($_GET['device_token'] ?? ''));
}

$auth = auth_device_by_token($deviceUid, $deviceToken, true);
if (!$auth['ok']) {
    print_json_response(['status' => 'error', 'message' => $auth['message'] ?? 'auth error'], (int)($auth['http_code'] ?? 403));
}

$timeout = (int)($_GET['timeout'] ?? 30);
if ($timeout < 1) {
    $timeout = 1;
}
if ($timeout > 40) {
    $timeout = 40;
}

$started = time();
do {
    $job = print_dequeue_for_device($deviceUid);
    if (is_array($job)) {
        print_json_response(['status' => 'ok', 'job' => $job], 200);
    }

    usleep(500000);
} while ((time() - $started) < $timeout);

http_response_code(204);
exit;
