<?php
declare(strict_types=1);

require_once __DIR__ . '/../www/bootstrap.php';

$kit = 'maklertour_kit_v1';
$dict = 'APRILTAG_36H11';

$sql = "INSERT INTO marker_kit_layout
(marker_kit_id, marker_dictionary, marker_id, marker_size_m, center_x_m, center_y_m, center_z_m, yaw_deg, pitch_deg, roll_deg, surface_type, note)
VALUES (?, ?, ?, 0.1600, 0.0000, 0.0000, 1.2000, 0, 0, 0, NULL, NULL)
ON DUPLICATE KEY UPDATE
marker_size_m = CASE WHEN marker_size_m IS NULL OR marker_size_m = 0 THEN VALUES(marker_size_m) ELSE marker_size_m END";
$stmt = $dbcnx->prepare($sql);
if (!$stmt) {
    throw new RuntimeException('Prepare failed: ' . $dbcnx->error);
}

for ($i = 1; $i <= 30; $i++) {
    $stmt->bind_param('ssi', $kit, $dict, $i);
    $stmt->execute();
}
$stmt->close();

echo "Seeded MT-001..MT-030\n";
