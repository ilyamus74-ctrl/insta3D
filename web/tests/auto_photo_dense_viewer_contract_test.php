<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/auto_photo_dense_viewer_lib.php';

function adv_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/auto_photo_dense_viewer_'
    . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
$ply = $root . '/merged_fused.ply';
file_put_contents(
    $ply,
    "ply\nformat ascii 1.0\nelement vertex 157417\n"
    . "property float x\nproperty float y\nproperty float z\n"
    . "end_header\n0 0 0\n"
);

$job = [
    'id' => 759,
    'order_id' => 30,
    'capture_session_id' => 63,
    'remote_job_id' => 897481444,
    'status' => 'DONE',
];
$resolved = [
    'path' => $ply,
    'sparse_remote_job_id' => 658883972,
    'model_id' => 0,
];
$payload = auto_photo_dense_viewer_payload($job, $resolved);
adv_ok(is_array($payload), 'viewer payload');
adv_ok(($payload['is_auto_photo_dense'] ?? false) === true, 'viewer mode');
adv_ok(($payload['summary']['points_count'] ?? 0) === 157417, 'vertex count');
adv_ok(($payload['dense']['db_job_id'] ?? 0) === 759, 'db job id');
adv_ok(($payload['dense']['remote_job_id'] ?? 0) === 897481444, 'remote job id');
adv_ok(($payload['dense']['model_id'] ?? -1) === 0, 'model zero');
adv_ok(
    ($payload['dense']['fused_ply_url'] ?? '') ===
        '/api/sfm_remote_job_status.php?job_id=759&file=ply',
    'authenticated PLY URL'
);
adv_ok(!array_key_exists('path', $payload), 'filesystem path not exposed');

$access = [
    'broker_id' => 12,
    'operator_id' => 24,
    'is_published' => 1,
    'order_status' => 'NEW',
];
adv_ok(auto_photo_dense_viewer_can_view($access, 1, 'ADMIN'), 'admin access');
adv_ok(auto_photo_dense_viewer_can_view($access, 12, 'BROKER'), 'broker access');
adv_ok(auto_photo_dense_viewer_can_view($access, 24, 'OPERATOR'), 'assigned operator');
adv_ok(!auto_photo_dense_viewer_can_view($access, 99, 'BROKER'), 'foreign broker denied');
$access['operator_id'] = null;
adv_ok(auto_photo_dense_viewer_can_view($access, 99, 'OPERATOR'), 'published pool operator');
$access['order_status'] = 'DONE';
adv_ok(!auto_photo_dense_viewer_can_view($access, 99, 'OPERATOR'), 'closed pool denied');

$api = (string) file_get_contents(
    __DIR__ . '/../www/api/auto_photo_dense_3d.php'
);
foreach ([
    'auth_require_login',
    'auto_photo_dense_viewer_can_view',
    'auto_photo_dense_download_resolve',
    'auto_photo_dense_viewer_payload',
    'scope_mismatch',
] as $needle) {
    adv_ok(str_contains($api, $needle), 'API wiring ' . $needle);
}
adv_ok(
    strpos($api, 'auto_photo_dense_viewer_can_view')
        < strpos($api, 'auto_photo_dense_download_resolve'),
    'permission checked before filesystem resolve'
);

$viewer = (string) file_get_contents(__DIR__ . '/../www/sfm_3d_viewer.php');
foreach ([
    'auto_photo_dense_job_id',
    '/api/auto_photo_dense_3d.php?job_id=',
    'data.is_auto_photo_dense',
    '← Назад в «Фото 3D»',
] as $needle) {
    adv_ok(str_contains($viewer, $needle), 'viewer wiring ' . $needle);
}

$ui = (string) file_get_contents(
    __DIR__ . '/../libs/auto_photo_sparse_ui_render_lib.php'
);
foreach ([
    'Создать 3D-модель',
    'Открыть 3D',
    'Дополнительно',
] as $needle) {
    adv_ok(str_contains($ui, $needle), 'UI contract ' . $needle);
}

unlink($ply);
rmdir($root);
echo "OK\n";
