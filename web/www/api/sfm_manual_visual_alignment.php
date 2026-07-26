<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2)
    . '/libs/sfm_manual_visual_alignment_lib.php';

auth_require_login();
set_time_limit(0);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function visual_alignment_reply(
    array $payload,
    int $status = 200
): never {
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    visual_alignment_reply(
        ['ok' => false, 'error' => 'POST required'],
        405
    );
}

$expected = (string)($_SESSION['secCode'] ?? '');
$provided = (string)(
    $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''
);
if (
    $expected === ''
    || $provided === ''
    || !hash_equals($expected, $provided)
) {
    visual_alignment_reply(
        ['ok' => false, 'error' => 'CSRF token mismatch'],
        403
    );
}

$orderId = max(0, (int)($_GET['order_id'] ?? 0));
$anchorKind = (string)(
    $_GET['anchor_kind'] ?? 'remote'
);
$anchorId = max(0, (int)($_GET['anchor_id'] ?? 0));
$sourceKind = (string)(
    $_GET['source_kind'] ?? 'remote'
);
$sourceId = max(0, (int)($_GET['source_id'] ?? 0));

if (
    $orderId <= 0
    || $anchorId <= 0
    || $sourceId <= 0
) {
    visual_alignment_reply(
        ['ok' => false, 'error' => 'Missing identifiers'],
        400
    );
}

$input = json_decode(
    (string)file_get_contents('php://input'),
    true
);
$transform = is_array($input['transform'] ?? null)
    ? $input['transform']
    : (is_array($input) ? $input : []);

try {
    $user = auth_current_user();
    $result = sfm_manual_visual_save(
        $dbcnx,
        $orderId,
        $anchorKind,
        $anchorId,
        $sourceKind,
        $sourceId,
        $transform,
        (int)($user['id'] ?? 0),
        (string)($user['role'] ?? 'BROKER')
    );

    $mergeId = (int)$result['merge_id'];
    $result['viewer_url'] =
        '/sfm_3d_viewer.php?order_id='
        . $orderId
        . '&merge_id='
        . $mergeId
        . '&artifact=dense';
    $result['download_url'] =
        '/api/sfm_generated_merge_file.php?merge_id='
        . $mergeId
        . '&file=ply';
    $result['result_url'] =
        '/api/sfm_generated_merge_file.php?merge_id='
        . $mergeId
        . '&file=result';
    $result['order_url'] =
        '/order_simple.php?id='
        . $orderId
        . '#simple-generated';

    visual_alignment_reply($result);
} catch (Throwable $error) {
    error_log(
        'manual visual alignment save failed: '
        . $error->getMessage()
    );
    visual_alignment_reply(
        ['ok' => false, 'error' => $error->getMessage()],
        400
    );
}
