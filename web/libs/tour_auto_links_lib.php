<?php
declare(strict_types=1);

const TOUR_AUTO_LINKS_ALGORITHM = 'AUTO_MARKER_BEARING_V1';
function normalize_yaw(float $yaw): float {
    while ($yaw > 180.0) $yaw -= 360.0;
    while ($yaw < -180.0) $yaw += 360.0;
    return $yaw;
}

function run_tour_auto_links(mysqli $dbcnx, int $sessionId, bool $overwriteAuto = true, bool $overwriteManual = false): array {
    $MIN_CONFIDENCE = 30.0;

    $photoPoints = [];
    $stmt = $dbcnx->prepare("SELECT id, name, sequence_number FROM photo_points WHERE session_id = ? ORDER BY id ASC");
    if (!$stmt) throw new RuntimeException('db_prepare_photo_points_failed');
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $name = (string)($row['name'] ?: ('Point #' . (int)$row['id']));
        $fallback = null;
        if (preg_match('/(\d+)/u', $name, $m)) $fallback = (int)$m[1];
        $photoPoints[] = ['id'=>(int)$row['id'], 'name'=>$name, 'sequence_number'=>$row['sequence_number']!==null?(int)$row['sequence_number']:null, 'fallback_order'=>$fallback];
    }
    $stmt->close();

    usort($photoPoints, static function(array $a, array $b): int {
        if ($a['sequence_number'] !== null && $b['sequence_number'] !== null && $a['sequence_number'] !== $b['sequence_number']) return $a['sequence_number'] <=> $b['sequence_number'];
        if ($a['sequence_number'] !== null && $b['sequence_number'] === null) return -1;
        if ($a['sequence_number'] === null && $b['sequence_number'] !== null) return 1;
        if ($a['fallback_order'] !== null && $b['fallback_order'] !== null && $a['fallback_order'] !== $b['fallback_order']) return $a['fallback_order'] <=> $b['fallback_order'];
        if ($a['fallback_order'] !== null && $b['fallback_order'] === null) return -1;
        if ($a['fallback_order'] === null && $b['fallback_order'] !== null) return 1;
        return $a['id'] <=> $b['id'];
    });

    if (count($photoPoints) < 2) return ['ok'=>true,'algorithm'=>TOUR_AUTO_LINKS_ALGORITHM,'created_count'=>0,'updated_count'=>0,'skipped_count'=>0,'warnings'=>['Not enough photo points'],'links'=>[]];

    $pointById = []; foreach ($photoPoints as $p) $pointById[$p['id']] = $p;
    $sizeCache = []; $bestDet = []; $warnings = [];

    $stmt = $dbcnx->prepare("SELECT source_id, marker_id, center_x, center_y, confidence, source_path FROM marker_detections WHERE session_id=? AND source_type='PHOTO_POINT' AND confidence >= ?");
    if (!$stmt) throw new RuntimeException('db_prepare_detections_failed');
    $stmt->bind_param('id', $sessionId, $MIN_CONFIDENCE);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $pid = (int)$row['source_id']; $mid = (int)$row['marker_id'];
        if (!isset($pointById[$pid])) continue;
        $sourcePath = trim((string)($row['source_path'] ?? ''));
        if ($sourcePath === '') { $warnings[] = "Empty source_path for point {$pid}, marker {$mid}"; continue; }
        if (!array_key_exists($sourcePath, $sizeCache)) {
            $full = APP_STORAGE_DIR . '/' . ltrim($sourcePath, '/');
            $sz = @getimagesize($full);
            $sizeCache[$sourcePath] = (is_array($sz) && !empty($sz[0]) && !empty($sz[1])) ? [(float)$sz[0], (float)$sz[1]] : null;
        }
        if (!is_array($sizeCache[$sourcePath])) { $warnings[] = "Cannot get image size for {$sourcePath}"; continue; }
        [$w, $h] = $sizeCache[$sourcePath];
        $yaw = (((float)$row['center_x']) / $w) * 360.0 - 180.0;
        $pitch = 90.0 - (((float)$row['center_y']) / $h) * 180.0;
        $conf = (float)$row['confidence'];
        $prev = $bestDet[$pid][$mid] ?? null;
        if ($prev !== null && (float)$prev['confidence'] >= $conf) continue;
        $bestDet[$pid][$mid] = ['confidence'=>$conf,'yaw_deg'=>$yaw,'pitch_deg'=>$pitch];
    }
    $stmt->close();

    $dbcnx->begin_transaction();
    $created = 0; $updated = 0; $skipped = 0; $outLinks = [];
    try {
        if ($overwriteAuto) {
            $del = $dbcnx->prepare("DELETE FROM tour_point_links WHERE session_id = ? AND source = 'AUTO_MARKER_BEARING'");
            $del->bind_param('i', $sessionId); $del->execute(); $del->close();
        }

        for ($i = 0; $i < count($photoPoints) - 1; $i++) {
            $a = $photoPoints[$i]; $b = $photoPoints[$i + 1];
            $markersA = array_keys($bestDet[$a['id']] ?? []);
            $markersB = array_keys($bestDet[$b['id']] ?? []);
            $common = array_values(array_intersect($markersA, $markersB));
            if (!$common) { $warnings[] = "No common markers for point {$a['name']} -> {$b['name']}"; $warnings[] = "No common markers for point {$b['name']} -> {$a['name']}"; $skipped += 2; continue; }

            foreach ([[$a,$b],[$b,$a]] as [$from,$to]) {
                $bestMid = null; $bestConf = -1;
                foreach ($common as $mid) {
                    $conf = (float)($bestDet[$from['id']][$mid]['confidence'] ?? -1);
                    if ($conf > $bestConf) { $bestConf = $conf; $bestMid = (int)$mid; }
                }
                if ($bestMid === null) { $skipped++; continue; }

                $manualStmt = $dbcnx->prepare("SELECT id FROM tour_point_links WHERE session_id=? AND from_photo_point_id=? AND to_photo_point_id=? AND source='MANUAL' LIMIT 1");
                $manualStmt->bind_param('iii', $sessionId, $from['id'], $to['id']); $manualStmt->execute();
                $manual = $manualStmt->get_result()->fetch_assoc(); $manualStmt->close();
                if ($manual && !$overwriteManual) { $warnings[] = "Manual link exists for {$from['name']} -> {$to['name']}, auto skipped"; $skipped++; continue; }

                $det = $bestDet[$from['id']][$bestMid];
                $detTo = $bestDet[$to['id']][$bestMid];
                $sharedJson = json_encode(array_values(array_map('intval', $common)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $label = (string)$to['name'];
                $yaw = (float)$det['yaw_deg']; $pitch = (float)$det['pitch_deg']; $confidence = (float)$det['confidence'];
                $targetYaw = normalize_yaw((float)$detTo['yaw_deg'] + 180.0);
                $targetPitch = 0.0;
                $targetHfov = 100.0;

                $up = $dbcnx->prepare("INSERT INTO tour_point_links (session_id, from_photo_point_id, to_photo_point_id, yaw_deg, pitch_deg, target_yaw_deg, target_pitch_deg, target_hfov, label, source, shared_markers_json, confidence) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE yaw_deg=VALUES(yaw_deg), pitch_deg=VALUES(pitch_deg), target_yaw_deg=VALUES(target_yaw_deg), target_pitch_deg=VALUES(target_pitch_deg), target_hfov=VALUES(target_hfov), label=VALUES(label), source=VALUES(source), shared_markers_json=VALUES(shared_markers_json), confidence=VALUES(confidence)");
                $source = 'AUTO_MARKER_BEARING';

                $up->bind_param(
                    'iiidddddsssd',
                    $sessionId,
                    $from['id'],
                    $to['id'],
                    $yaw,
                    $pitch,
                    $targetYaw,
                    $targetPitch,
                    $targetHfov,
                    $label,
                    $source,
                    $sharedJson,
                    $confidence
                );
                $up->execute();
                $aff = $up->affected_rows;
                $up->close();
                if ($aff === 1) $created++; else $updated++;
                $outLinks[] = ['from_photo_point_id'=>$from['id'],'to_photo_point_id'=>$to['id'],'yaw_deg'=>round($yaw,2),'pitch_deg'=>round($pitch,2),'target_yaw_deg'=>round($targetYaw,2),'target_pitch_deg'=>round($targetPitch,2),'target_hfov'=>round($targetHfov,2),'label'=>$label,'source'=>'AUTO_MARKER_BEARING','shared_markers'=>json_decode($sharedJson,true) ?: [],'confidence'=>round($confidence,2)];
            }
        }
        $dbcnx->commit();
    } catch (Throwable $e) {
        $dbcnx->rollback(); throw $e;
    }

    return ['ok'=>true,'algorithm'=>TOUR_AUTO_LINKS_ALGORITHM,'created_count'=>$created,'updated_count'=>$updated,'skipped_count'=>$skipped,'warnings'=>$warnings,'links'=>$outLinks];
}
