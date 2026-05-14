<?php
declare(strict_types=1);

require_once __DIR__ . '/../www/bootstrap.php';

$required = [
    'id' => "`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
    'session_id' => "`session_id` BIGINT UNSIGNED NOT NULL",
    'from_photo_point_id' => "`from_photo_point_id` BIGINT UNSIGNED NOT NULL",
    'to_photo_point_id' => "`to_photo_point_id` BIGINT UNSIGNED NOT NULL",
    'yaw_deg' => "`yaw_deg` DOUBLE NOT NULL DEFAULT 0",
    'pitch_deg' => "`pitch_deg` DOUBLE NOT NULL DEFAULT 0",
    'label' => "`label` VARCHAR(255) NULL",
    'created_at' => "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    'updated_at' => "`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
];

$res = $dbcnx->query('DESCRIBE tour_point_links');
if (!$res) {
    fwrite(STDERR, "Cannot DESCRIBE tour_point_links: " . $dbcnx->error . PHP_EOL);
    exit(1);
}
$existing = [];
while ($row = $res->fetch_assoc()) {
    $existing[(string)$row['Field']] = true;
}
$res->free();

foreach ($required as $name => $def) {
    if (isset($existing[$name])) {
        echo "OK: {$name} exists" . PHP_EOL;
        continue;
    }
    $sql = "ALTER TABLE tour_point_links ADD COLUMN {$def}";
    if (!$dbcnx->query($sql)) {
        fwrite(STDERR, "FAILED: {$name}: " . $dbcnx->error . PHP_EOL);
        exit(1);
    }
    echo "ADDED: {$name}" . PHP_EOL;
}

echo "Done." . PHP_EOL;
