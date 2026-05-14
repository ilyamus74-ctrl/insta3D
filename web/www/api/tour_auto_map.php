<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_login();

function auto_map_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function can_view_order_auto(array $order, int $userId, string $role): bool {
    return $role === 'ADMIN'
        || ((int)$order['broker_id'] === $userId)
        || (
            $role === 'OPERATOR'
            && (
                (int)$order['operator_id'] === $userId
                || (
                    (int)$order['is_published'] === 1
                    && (string)$order['status'] === 'NEW'
                    && $order['operator_id'] === null
                )
            )
        );
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) auto_map_json(['ok' => false, 'error' => 'bad_json'], 400);

$sessionId = (int)($data['session_id'] ?? 0);
$overwrite = (bool)($data['overwrite'] ?? true);
$overwriteManual = (bool)($data['overwrite_manual'] ?? false);
if ($sessionId <= 0) auto_map_json(['ok' => false, 'error' => 'bad_session_id'], 400);

$user = auth_current_user();
$userId = (int)$user['id'];
$role = (string)($user['role'] ?? 'BROKER');

$stmt = $dbcnx->prepare("SELECT cs.id, cs.order_id, o.broker_id, o.operator_id, o.is_published, o.status FROM capture_sessions cs JOIN tour_orders o ON o.id = cs.order_id WHERE cs.id = ? LIMIT 1");
if (!$stmt) auto_map_json(['ok' => false, 'error' => 'db_prepare_session_failed'], 500);
$stmt->bind_param('i', $sessionId); $stmt->execute();
$session = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$session) auto_map_json(['ok' => false, 'error' => 'session_not_found'], 404);
if (!can_view_order_auto($session, $userId, $role)) auto_map_json(['ok' => false, 'error' => 'forbidden'], 403);

$photoPoints = [];
$photoPointIds = [];
$stmt = $dbcnx->prepare("SELECT id, name FROM photo_points WHERE session_id = ? ORDER BY COALESCE(sequence_number,999999), created_at, id");
$stmt->bind_param('i', $sessionId); $stmt->execute();
$rs = $stmt->get_result();
while ($row = $rs->fetch_assoc()) { $id = (int)$row['id']; $photoPoints[$id] = ['id'=>$id,'name'=>(string)($row['name'] ?: ('Point #'.$id))]; $photoPointIds[] = $id; }
$stmt->close();

$warnings = [];
$markersByPoint = [];
foreach ($photoPointIds as $id) $markersByPoint[$id] = [];
$detCount = [];
$confSum = [];

$stmt = $dbcnx->prepare("SELECT source_id, marker_id, confidence FROM marker_detections WHERE session_id = ? AND source_type = 'PHOTO_POINT'");
$stmt->bind_param('i', $sessionId); $stmt->execute();
$rs = $stmt->get_result();
while ($row = $rs->fetch_assoc()) {
    $pid = (int)$row['source_id'];
    if (!isset($photoPoints[$pid])) { $warnings[] = 'Ignored detection with unknown source_id=' . $pid; continue; }
    $mid = (int)$row['marker_id'];
    $markersByPoint[$pid][$mid] = true;
    $detCount[$pid] = ($detCount[$pid] ?? 0) + 1;
    $confSum[$pid] = ($confSum[$pid] ?? 0.0) + (float)$row['confidence'];
}
$stmt->close();

$signatures = [];
$markersUsed = [];
foreach ($photoPointIds as $pid) {
    $markerIds = array_map('intval', array_keys($markersByPoint[$pid]));
    sort($markerIds);
    foreach ($markerIds as $m) $markersUsed[$m] = true;
    $signatures[$pid] = [
        'photo_point_id' => $pid,
        'markers' => $markerIds,
        'detections_count' => (int)($detCount[$pid] ?? 0),
        'avg_confidence' => ($detCount[$pid] ?? 0) > 0 ? (float)(($confSum[$pid] ?? 0) / $detCount[$pid]) : 0.0,
    ];
}

$edges = [];
$adj = [];
foreach ($photoPointIds as $a) $adj[$a] = [];
for ($i = 0; $i < count($photoPointIds); $i++) {
    for ($j = $i + 1; $j < count($photoPointIds); $j++) {
        $a = $photoPointIds[$i]; $b = $photoPointIds[$j];
        $am = $signatures[$a]['markers']; $bm = $signatures[$b]['markers'];
        $inter = array_values(array_intersect($am, $bm));
        if (!$inter) continue;
        $union = array_values(array_unique(array_merge($am, $bm)));
        $sim = count($inter) / max(count($union), 1);
        $edges[] = ['from_photo_point_id'=>$a,'to_photo_point_id'=>$b,'shared_markers'=>$inter,'similarity'=>$sim];
        $adj[$a][] = ['id'=>$b,'sim'=>$sim];
        $adj[$b][] = ['id'=>$a,'sim'=>$sim];
    }
}

$positions = [];
$visited = [];
$baseDistance = 1.5;
$rowIndex = 0;
$clusterGap = 6.0;
foreach ($photoPointIds as $start) {
    if (isset($visited[$start]) || count($signatures[$start]['markers']) === 0) continue;
    $queue = [$start];
    $visited[$start] = true;
    $order = [];
    while ($queue) {
        $cur = array_shift($queue); $order[] = $cur;
        usort($adj[$cur], static fn($x,$y) => $y['sim'] <=> $x['sim']);
        foreach ($adj[$cur] as $n) if (!isset($visited[$n['id']])) { $visited[$n['id']] = true; $queue[] = $n['id']; }
    }
    $x = 0.0; $y = -$rowIndex * $clusterGap;
    $prev = null;
    foreach ($order as $pid) {
        if ($prev !== null) {
            $sim = 0.0;
            foreach ($adj[$prev] as $n) if ($n['id'] === $pid) { $sim = (float)$n['sim']; break; }
            $x += $baseDistance / max($sim, 0.2);
        }
        $positions[$pid] = ['photo_point_id'=>$pid,'x_m'=>round($x,3),'y_m'=>round($y,3),'source'=>'MARKER_COVISIBILITY','markers'=>$signatures[$pid]['markers']];
        $prev = $pid;
    }
    $rowIndex++;
}
$noMarkerX = 0.0;
foreach ($photoPointIds as $pid) {
    if (isset($positions[$pid])) continue;
    $positions[$pid] = ['photo_point_id'=>$pid,'x_m'=>round($noMarkerX,3),'y_m'=>round(-$rowIndex * $clusterGap,3),'source'=>'AUTO_COVISIBILITY_NO_MARKERS','markers'=>[]];
    $noMarkerX += $baseDistance;
}

$dbcnx->begin_transaction();
try {
    if ($overwrite) {
        $sources = "('MARKER_COVISIBILITY','AUTO_COVISIBILITY_NO_MARKERS'" . ($overwriteManual ? ",'MANUAL'" : '') . ')';
        $sql = "DELETE FROM tour_point_positions WHERE session_id = ? AND source IN $sources";
        $stmt = $dbcnx->prepare($sql); $stmt->bind_param('i', $sessionId); $stmt->execute(); $stmt->close();
    }
    $stmt = $dbcnx->prepare("INSERT INTO tour_point_positions (session_id, photo_point_id, x_m, y_m, z_m, yaw_deg, source) VALUES (?,?,?,?,0,0,?) ON DUPLICATE KEY UPDATE x_m=VALUES(x_m), y_m=VALUES(y_m), z_m=0, yaw_deg=0, source=VALUES(source)");
    foreach ($positions as $p) {
        if (!$overwrite && !$overwriteManual) {
            $check = $dbcnx->prepare("SELECT source FROM tour_point_positions WHERE session_id=? AND photo_point_id=? LIMIT 1");
            $check->bind_param('ii', $sessionId, $p['photo_point_id']); $check->execute();
            $existing = $check->get_result()->fetch_assoc(); $check->close();
            if ($existing && (string)$existing['source'] === 'MANUAL') continue;
        }
        $stmt->bind_param('iidds', $sessionId, $p['photo_point_id'], $p['x_m'], $p['y_m'], $p['source']);
        $stmt->execute();
    }
    $stmt->close();
    $dbcnx->commit();
} catch (Throwable $e) {
    $dbcnx->rollback();
    auto_map_json(['ok'=>false,'error'=>'db_write_failed','message'=>$e->getMessage()],500);
}

ksort($positions);
auto_map_json(['ok'=>true,'session_id'=>$sessionId,'photo_points_count'=>count($photoPointIds),'positioned_count'=>count($positions),'markers_used'=>array_map('intval', array_keys($markersUsed)),'edges_count'=>count($edges),'warnings'=>$warnings,'positions'=>$positions,'edges'=>$edges]);
