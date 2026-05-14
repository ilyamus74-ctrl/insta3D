<?php
declare(strict_types=1);

require_once __DIR__ . '/../www/bootstrap.php';

$required = [
    'initial_yaw_deg' => "ALTER TABLE photo_points ADD COLUMN initial_yaw_deg DECIMAL(8,3) NULL DEFAULT 0 AFTER upload_state",
    'initial_pitch_deg' => "ALTER TABLE photo_points ADD COLUMN initial_pitch_deg DECIMAL(8,3) NULL DEFAULT 0 AFTER initial_yaw_deg",
    'initial_hfov' => "ALTER TABLE photo_points ADD COLUMN initial_hfov DECIMAL(8,3) NULL DEFAULT 100 AFTER initial_pitch_deg",
];

$existing = [];
$res = $dbcnx->query("SHOW COLUMNS FROM photo_points");
while ($row = $res->fetch_assoc()) {
    $existing[(string)$row['Field']] = true;
}

foreach ($required as $col => $sql) {
    if (isset($existing[$col])) {
        echo "exists: {$col}\n";
        continue;
    }
    if ($dbcnx->query($sql)) {
        echo "added: {$col}\n";
    } else {
        echo "failed: {$col} :: " . $dbcnx->error . "\n";
    }
}
