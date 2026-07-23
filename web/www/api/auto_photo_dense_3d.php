<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/libs/auto_photo_dense_viewer_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
auth_require_login();

function auto_photo_dense_3d_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$jobId = auto_photo_dense_download_positive_int($_GET['job_id'] ?? null);
if ($jobId === null) {
    auto_photo_dense_3d_json(['ok' => false, 'error' => 'bad_job_id'], 400);
}

$statement = $dbcnx->prepare(
    'SELECT j.*, o.broker_id, o.operator_id, o.is_published, '
    . 'o.status AS order_status '
    . 'FROM sfm_remote_jobs j '
    . 'JOIN tour_orders o ON o.id=j.order_id '
    . 'WHERE j.id=? LIMIT 1'
);
if (
    !$statement
    || !$statement->bind_param('i', $jobId)
    || !$statement->execute()
) {
    auto_photo_dense_3d_json(['ok' => false, 'error' => 'db_error'], 500);
}
$result = $statement->get_result();
$job = $result ? $result->fetch_assoc() : null;
$statement->close();

if (!is_array($job)) {
    auto_photo_dense_3d_json(['ok' => false, 'error' => 'job_not_found'], 404);
}

$requestedOrderId = (int) ($_GET['order_id'] ?? 0);
$requestedSessionId = (int) ($_GET['session_id'] ?? 0);
if (
    ($requestedOrderId > 0 && $requestedOrderId !== (int) $job['order_id'])
    || (
        $requestedSessionId > 0
        && $requestedSessionId !== (int) $job['capture_session_id']
    )
) {
    auto_photo_dense_3d_json(
        ['ok' => false, 'error' => 'scope_mismatch'],
        400
    );
}

$user = auth_current_user();
$userId = (int) ($user['id'] ?? 0);
$role = (string) ($user['role'] ?? 'BROKER');
if (!auto_photo_dense_viewer_can_view($job, $userId, $role)) {
    auto_photo_dense_3d_json(['ok' => false, 'error' => 'forbidden'], 403);
}

$resolved = auto_photo_dense_download_resolve($dbcnx, $job);
if ($resolved === null) {
    auto_photo_dense_3d_json(
        ['ok' => false, 'error' => 'dense_artifact_not_ready'],
        404
    );
}

$payload = auto_photo_dense_viewer_payload($job, $resolved);
if ($payload === null) {
    auto_photo_dense_3d_json(
        ['ok' => false, 'error' => 'dense_viewer_payload_failed'],
        500
    );
}

auto_photo_dense_3d_json($payload);
