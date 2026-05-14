<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
const AUTO_ALGORITHM = 'MARKER_SEQUENCE_COVISIBILITY_V1';
$MIN_CONFIDENCE = 30.0;

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
$stmt = $dbcnx->prepare("SELECT id, name, sequence_number FROM photo_points WHERE session_id = ? ORDER BY id ASC");
$stmt->bind_param('i', $sessionId); $stmt->execute();
$rs = $stmt->get_result();
while ($row = $rs->fetch_assoc()) {
    $id = (int)$row['id'];
    $name = (string)($row['name'] ?: ('Point #' . $id));
    $fallbackOrder = null;
    if (preg_match('/(\d+)/u', $name, $m)) $fallbackOrder = (int)$m[1];
    $photoPoints[$id] = ['id' => $id, 'name' => $name, 'sequence_number' => $row['sequence_number'] !== null ? (int)$row['sequence_number'] : null, 'fallback_order' => $fallbackOrder];
}
$stmt->close();
if (!$photoPoints) auto_map_json(['ok' => true, 'session_id' => $sessionId, 'algorithm' => AUTO_ALGORITHM, 'confidence_threshold' => $MIN_CONFIDENCE, 'photo_points_count' => 0, 'positioned_count' => 0, 'markers_used' => [], 'marker_weights' => [], 'positions' => [], 'edges' => [], 'warnings' => ['No photo points found']]);

$ordered = array_values($photoPoints);
usort($ordered, static function (array $a, array $b): int {
    $aSeq = $a['sequence_number']; $bSeq = $b['sequence_number'];
    if ($aSeq !== null && $bSeq !== null && $aSeq !== $bSeq) return $aSeq <=> $bSeq;
    if ($aSeq !== null && $bSeq === null) return -1;
    if ($aSeq === null && $bSeq !== null) return 1;
    $aF = $a['fallback_order']; $bF = $b['fallback_order'];
    if ($aF !== null && $bF !== null && $aF !== $bF) return $aF <=> $bF;
    if ($aF !== null && $bF === null) return -1;
    if ($aF === null && $bF !== null) return 1;
    return $a['id'] <=> $b['id'];
});
$photoPointIds = array_map(static fn(array $p): int => (int)$p['id'], $ordered);

$warnings = [];
$markersByPoint = [];
foreach ($photoPointIds as $id) $markersByPoint[$id] = [];
$detCount = [];
$confSum = [];

$stmt = $dbcnx->prepare("SELECT source_id, marker_id, confidence FROM marker_detections WHERE session_id = ? AND source_type = 'PHOTO_POINT' AND confidence >= ?");
$stmt->bind_param('id', $sessionId, $MIN_CONFIDENCE); $stmt->execute();
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
$df = [];
foreach ($photoPointIds as $pid) foreach ($signatures[$pid]['markers'] as $mid) $df[$mid] = (int)($df[$mid] ?? 0) + 1;
$totalPoints = count($photoPointIds);
$idf = [];
foreach ($df as $mid => $freq) $idf[$mid] = log((1 + $totalPoints) / (1 + $freq)) + 1.0;
$weightedSimilarity = static function (array $aMarkers, array $bMarkers, array $idfWeights): array {
    $inter = array_values(array_intersect($aMarkers, $bMarkers));
    $union = array_values(array_unique(array_merge($aMarkers, $bMarkers)));
    $wInter = 0.0; foreach ($inter as $m) $wInter += (float)($idfWeights[$m] ?? 1.0);
    $wUnion = 0.0; foreach ($union as $m) $wUnion += (float)($idfWeights[$m] ?? 1.0);
    return ['sim' => $wUnion > 0 ? $wInter / $wUnion : 0.0, 'shared' => $inter];
};

$positions = []; $edges = [];
$baseSpacing = 1.5; $minSpacing = 0.7; $maxSpacing = 2.2; $x = 0.0;
for ($i = 0; $i < $totalPoints; $i++) {
    $pid = $photoPointIds[$i];
    $markers = $signatures[$pid]['markers'];
    $source = count($markers) > 0 ? 'MARKER_SEQUENCE_COVISIBILITY' : 'AUTO_SEQUENCE_NO_MARKERS';
    $y = 0.0;
    if ($i > 0) {
        $prev = $photoPointIds[$i - 1];
        $simData = $weightedSimilarity($signatures[$prev]['markers'], $markers, $idf);
        $sim = (float)$simData['sim'];
        $spacing = $baseSpacing * (1.4 - min($sim, 0.8));
        $spacing = max($minSpacing, min($maxSpacing, $spacing));
        $x += $spacing;
        $edges[] = ['from_photo_point_id' => $prev, 'to_photo_point_id' => $pid, 'similarity' => round($sim, 4), 'shared_markers' => array_values($simData['shared'])];
        if ($sim < 0.2) $y = ($i % 2 === 0) ? 0.35 : -0.35;
    }
    $positions[$pid] = ['photo_point_id' => $pid, 'x_m' => round($x, 3), 'y_m' => $y, 'source' => $source, 'markers' => $markers, 'order_index' => $i];
}

$dbcnx->begin_transaction();
try {
    if ($overwrite) {
        $sources = "('MARKER_COVISIBILITY','AUTO_COVISIBILITY_NO_MARKERS','MARKER_SEQUENCE_COVISIBILITY','AUTO_SEQUENCE_NO_MARKERS'" . ($overwriteManual ? ",'MANUAL'" : '') . ')';
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
$markerWeights = []; foreach ($idf as $mid => $w) $markerWeights[(string)$mid] = round($w, 4);
auto_map_json(['ok'=>true,'session_id'=>$sessionId,'algorithm'=>AUTO_ALGORITHM,'confidence_threshold'=>$MIN_CONFIDENCE,'photo_points_count'=>count($photoPointIds),'positioned_count'=>count($positions),'markers_used'=>array_map('intval', array_keys($markersUsed)),'marker_weights'=>$markerWeights,'warnings'=>$warnings,'positions'=>$positions,'edges'=>$edges]);
