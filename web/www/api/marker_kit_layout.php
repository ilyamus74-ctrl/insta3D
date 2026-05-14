<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function out(array $p, int $c = 200): void { http_response_code($c); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function label_id(int $id): string { return 'MT-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT); }

auth_require_login();
$user = auth_current_user();
if (($user['role'] ?? '') !== 'ADMIN') out(['ok' => false, 'error' => 'forbidden'], 403);

$kit = 'maklertour_kit_v1';
$dict = 'APRILTAG_36H11';
$allowedSurface = ['', 'WALL', 'FLOOR', 'CEILING', 'OBJECT', 'CALIBRATION_RIG'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $markers = [];
    $stmt = $dbcnx->prepare('SELECT marker_kit_id, marker_dictionary, marker_id, marker_size_m, center_x_m, center_y_m, center_z_m, yaw_deg, pitch_deg, roll_deg, surface_type, note FROM marker_kit_layout WHERE marker_kit_id=? AND marker_dictionary=? ORDER BY marker_id ASC');
    if (!$stmt) out(['ok' => false, 'error' => 'db_prepare_failed'], 500);
    $stmt->bind_param('ss', $kit, $dict);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) $markers[] = $row;
    $stmt->close();
    out(['ok' => true, 'marker_kit_id' => $kit, 'marker_dictionary' => $dict, 'markers' => $markers]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'method_not_allowed'], 405);
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) out(['ok' => false, 'error' => 'bad_json'], 400);

$markerId = (int)($input['marker_id'] ?? 0);
if ($markerId < 1 || $markerId > 9999) out(['ok' => false, 'error' => 'bad_marker_id'], 400);
$size = (float)($input['marker_size_m'] ?? 0);
if ($size <= 0 || $size > 10) out(['ok' => false, 'error' => 'bad_marker_size_m'], 400);

$cx = $input['center_x_m'] ?? null; $cy = $input['center_y_m'] ?? null; $cz = $input['center_z_m'] ?? null;
$yaw = $input['yaw_deg'] ?? 0; $pitch = $input['pitch_deg'] ?? 0; $roll = $input['roll_deg'] ?? 0;
foreach (['center_x_m'=>$cx,'center_y_m'=>$cy,'center_z_m'=>$cz,'yaw_deg'=>$yaw,'pitch_deg'=>$pitch,'roll_deg'=>$roll] as $k => $v) {
    if (!is_numeric($v)) out(['ok' => false, 'error' => 'bad_' . $k], 400);
}
$surface = isset($input['surface_type']) ? trim((string)$input['surface_type']) : '';
if (!in_array($surface, $allowedSurface, true)) out(['ok' => false, 'error' => 'bad_surface_type'], 400);
$surfaceDb = $surface === '' ? null : $surface;
$note = isset($input['note']) ? mb_substr(trim((string)$input['note']), 0, 255) : null;
if ($note === '') $note = null;

$sql = "INSERT INTO marker_kit_layout (marker_kit_id, marker_dictionary, marker_id, marker_size_m, center_x_m, center_y_m, center_z_m, yaw_deg, pitch_deg, roll_deg, surface_type, note)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE marker_size_m=VALUES(marker_size_m), center_x_m=VALUES(center_x_m), center_y_m=VALUES(center_y_m), center_z_m=VALUES(center_z_m), yaw_deg=VALUES(yaw_deg), pitch_deg=VALUES(pitch_deg), roll_deg=VALUES(roll_deg), surface_type=VALUES(surface_type), note=VALUES(note)";
$stmt = $dbcnx->prepare($sql);
if (!$stmt) out(['ok' => false, 'error' => 'db_prepare_failed'], 500);
$stmt->bind_param('ssidddddddss', $kit, $dict, $markerId, $size, $cx, $cy, $cz, $yaw, $pitch, $roll, $surfaceDb, $note);
$stmt->execute();
$stmt->close();

out(['ok' => true, 'marker' => ['marker_kit_id'=>$kit,'marker_dictionary'=>$dict,'marker_id'=>$markerId,'marker_label'=>label_id($markerId),'marker_size_m'=>$size,'center_x_m'=>(float)$cx,'center_y_m'=>(float)$cy,'center_z_m'=>(float)$cz,'yaw_deg'=>(float)$yaw,'pitch_deg'=>(float)$pitch,'roll_deg'=>(float)$roll,'surface_type'=>$surfaceDb,'note'=>$note]]);
