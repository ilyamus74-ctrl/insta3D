<?php
declare(strict_types=1);

function print_json_response(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function print_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

function print_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }

    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) === 1) {
        return trim($m[1]);
    }

    return '';
}

function print_queue_file(string $deviceUid): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $deviceUid);
    return __DIR__ . '/../_tmp/print_queue_' . $safe . '.json';
}

function print_read_queue(string $deviceUid): array
{
    $path = print_queue_file($deviceUid);
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function print_write_queue(string $deviceUid, array $queue): bool
{
    $dir = __DIR__ . '/../_tmp';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $json = json_encode(array_values($queue), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    return file_put_contents(print_queue_file($deviceUid), $json, LOCK_EX) !== false;
}

function print_dequeue_for_device(string $deviceUid, int $ttlSeconds = 90): ?array
{
    $queue = print_read_queue($deviceUid);
    if (!$queue) {
        return null;
    }

    $now = time();

    foreach ($queue as &$job) {
        $status = (string)($job['status'] ?? 'queued');

        if ($status === 'delivered') {
            $deliveredAt = isset($job['delivered_at']) ? strtotime((string)$job['delivered_at']) : null;
            if ($deliveredAt !== false && $deliveredAt !== null && ($now - $deliveredAt) > $ttlSeconds) {
                $job['status'] = 'queued';
                unset($job['delivered_at']);
            }
        }

        if (($job['status'] ?? 'queued') !== 'queued') {
            continue;
        }

        $job['status'] = 'delivered';
        $job['delivered_at'] = gmdate('c');

        if (!print_write_queue($deviceUid, $queue)) {
            return null;
        }

        return $job;
    }
    unset($job);

    if (!print_write_queue($deviceUid, $queue)) {
        return null;
    }

    return null;
}

function print_update_job_status(string $deviceUid, string $jobId, string $status, string $message = ''): bool
{
    $queue = print_read_queue($deviceUid);
    if (!$queue) {
        return false;
    }

    $updated = false;

    foreach ($queue as &$job) {
        if ((string)($job['job_id'] ?? '') !== $jobId) {
            continue;
        }

        $job['status'] = $status;
        $job['updated_at'] = gmdate('c');
        if ($message !== '') {
            $job['last_message'] = print_safe_truncate($message, 500);
        }

        $history = is_array($job['history'] ?? null) ? $job['history'] : [];
        $history[] = [
            'status' => $status,
            'message' => print_safe_truncate($message, 500),
            'at' => gmdate('c'),
        ];
        $job['history'] = $history;

        $updated = true;
        break;
    }
    unset($job);

    if (!$updated) {
        return false;
    }

    return print_write_queue($deviceUid, $queue);
}

function print_safe_truncate(string $value, int $length): string
{
    if ($length <= 0) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return (string)mb_substr($value, 0, $length);
    }

    return substr($value, 0, $length);
}
