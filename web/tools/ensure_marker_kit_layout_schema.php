<?php
declare(strict_types=1);

require_once __DIR__ . '/../www/bootstrap.php';

$table = 'marker_kit_layout';
$database = (string)($dbcnx->query('SELECT DATABASE()')->fetch_row()[0] ?? '');
if ($database === '') {
    fwrite(STDERR, "No database selected\n");
    exit(1);
}

$columnDefs = [
    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY",
    'marker_kit_id' => "VARCHAR(64) NOT NULL DEFAULT 'maklertour_kit_v1'",
    'marker_dictionary' => "VARCHAR(64) NOT NULL DEFAULT 'APRILTAG_36H11'",
    'marker_id' => "INT NOT NULL",
    'marker_size_m' => "DECIMAL(8,4) NOT NULL DEFAULT 0.1600",
    'center_x_m' => "DECIMAL(10,4) NOT NULL DEFAULT 0.0000",
    'center_y_m' => "DECIMAL(10,4) NOT NULL DEFAULT 0.0000",
    'center_z_m' => "DECIMAL(10,4) NOT NULL DEFAULT 1.2000",
    'yaw_deg' => "DECIMAL(8,3) NULL DEFAULT 0",
    'pitch_deg' => "DECIMAL(8,3) NULL DEFAULT 0",
    'roll_deg' => "DECIMAL(8,3) NULL DEFAULT 0",
    'surface_type' => "VARCHAR(32) NULL DEFAULT NULL",
    'note' => "VARCHAR(255) NULL DEFAULT NULL",
    'created_at' => "DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)",
    'updated_at' => "DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)",
];

$existsStmt = $dbcnx->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
$existsStmt->bind_param('ss', $database, $table);
$existsStmt->execute();
$existsStmt->bind_result($tableExists);
$existsStmt->fetch();
$existsStmt->close();

if ((int)$tableExists === 0) {
    $sql = "CREATE TABLE `{$table}` (\n";
    $parts = [];
    foreach ($columnDefs as $name => $def) {
        $parts[] = "  `{$name}` {$def}";
    }
    $parts[] = "  UNIQUE KEY `uniq_marker` (`marker_kit_id`,`marker_dictionary`,`marker_id`)";
    $sql .= implode(",\n", $parts) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$dbcnx->query($sql)) {
        throw new RuntimeException('Create table failed: ' . $dbcnx->error);
    }
    echo "Created table {$table}\n";
    exit(0);
}

$colStmt = $dbcnx->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?');
$colStmt->bind_param('ss', $database, $table);
$colStmt->execute();
$rs = $colStmt->get_result();
$existingCols = [];
while ($row = $rs->fetch_assoc()) {
    $existingCols[(string)$row['column_name']] = true;
}
$colStmt->close();

foreach ($columnDefs as $name => $def) {
    if (!isset($existingCols[$name])) {
        $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$def}";
        if (!$dbcnx->query($sql)) {
            throw new RuntimeException("Add column {$name} failed: " . $dbcnx->error);
        }
        echo "Added column {$name}\n";
    }
}

$idxStmt = $dbcnx->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=? AND table_name=? AND index_name=?');
$indexName = 'uniq_marker';
$idxStmt->bind_param('sss', $database, $table, $indexName);
$idxStmt->execute();
$idxStmt->bind_result($idxCount);
$idxStmt->fetch();
$idxStmt->close();

if ((int)$idxCount === 0) {
    $sql = "ALTER TABLE `{$table}` ADD UNIQUE KEY `uniq_marker` (`marker_kit_id`,`marker_dictionary`,`marker_id`)";
    if (!$dbcnx->query($sql)) {
        throw new RuntimeException('Add unique key failed: ' . $dbcnx->error);
    }
    echo "Added unique key uniq_marker\n";
}

echo "Schema ensure done\n";

