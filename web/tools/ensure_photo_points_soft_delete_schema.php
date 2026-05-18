<?php
declare(strict_types=1);

require_once __DIR__ . '/../www/bootstrap.php';

$columns = [];
$res = $dbcnx->query("DESCRIBE photo_points");
if (!$res) {
    fwrite(STDERR, "ERROR: DESCRIBE failed: {$dbcnx->error}\n");
    exit(1);
}
while ($row = $res->fetch_assoc()) {
    $columns[(string)$row['Field']] = true;
}
$res->close();

$need = [
    'deleted_at' => "ALTER TABLE photo_points ADD COLUMN deleted_at DATETIME(6) NULL DEFAULT NULL",
    'deleted_by' => "ALTER TABLE photo_points ADD COLUMN deleted_by BIGINT UNSIGNED NULL DEFAULT NULL",
    'delete_reason' => "ALTER TABLE photo_points ADD COLUMN delete_reason VARCHAR(255) NULL DEFAULT NULL",
];

foreach ($need as $name => $sql) {
    if (isset($columns[$name])) {
        echo "OK column {$name} exists\n";
        continue;
    }
    if ($dbcnx->query($sql)) {
        echo "ADDED column {$name}\n";
    } else {
        fwrite(STDERR, "ERROR adding column {$name}: {$dbcnx->error}\n");
        exit(1);
    }
}

$indexes = [];
$res = $dbcnx->query("SHOW INDEX FROM photo_points");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $indexes[(string)$row['Key_name']] = true;
    }
    $res->close();
}

if (isset($indexes['idx_photo_points_deleted_at'])) {
    echo "OK index idx_photo_points_deleted_at exists\n";
} else {
    if ($dbcnx->query("ALTER TABLE photo_points ADD INDEX idx_photo_points_deleted_at (deleted_at)")) {
        echo "ADDED index idx_photo_points_deleted_at\n";
    } else {
        fwrite(STDERR, "ERROR adding index idx_photo_points_deleted_at: {$dbcnx->error}\n");
        exit(1);
    }
}
