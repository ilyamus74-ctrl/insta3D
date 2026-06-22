<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

auth_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$user = auth_current_user();
$userId = (int)$user['id'];
$role = (string)($user['role'] ?? 'BROKER');

$pipelineRunId = (int)($_GET['pipeline_run_id'] ?? 0);

if ($pipelineRunId <= 0) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Bad pipeline_run_id',
    ]);
    exit;
}

$stmt = $dbcnx->prepare(
    'SELECT
        r.id,
        r.order_id,
        r.capture_session_id,
        r.pipeline_mode,
        r.status,
        r.stage,
        r.progress_percent,
        r.message,
        r.updated_at,
        o.broker_id,
        o.operator_id
     FROM sfm_pipeline_runs r
     JOIN tour_orders o ON o.id = r.order_id
     WHERE r.id = ?
     LIMIT 1'
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'DB prepare error',
    ]);
    exit;
}

$stmt->bind_param('i', $pipelineRunId);
$stmt->execute();

$run = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$run) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => 'Pipeline run not found',
    ]);
    exit;
}

$canView =
    $role === 'ADMIN'
    || (int)$run['broker_id'] === $userId
    || (
        $role === 'OPERATOR'
        && (int)$run['operator_id'] === $userId
    );

if (!$canView) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Forbidden',
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'id' => (int)$run['id'],
    'pipeline_mode' => (string)$run['pipeline_mode'],
    'status' => (string)$run['status'],
    'stage' => (string)$run['stage'],
    'progress_percent' => (int)$run['progress_percent'],
    'message' => (string)($run['message'] ?? ''),
    'updated_at' => (string)$run['updated_at'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
