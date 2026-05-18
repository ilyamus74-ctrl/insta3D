<?php
declare(strict_types=1);

require_once __DIR__ . '/../www/bootstrap.php';

function table_exists(mysqli $db, string $table): bool {
    $t = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$t}'");
    $ok = $res && $res->num_rows > 0;
    if ($res) { $res->close(); }
    return $ok;
}

function column_exists(mysqli $db, string $table, string $column): bool {
    $t = $db->real_escape_string($table);
    $c = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    $ok = $res && $res->num_rows > 0;
    if ($res) { $res->close(); }
    return $ok;
}

function index_exists(mysqli $db, string $table, string $index): bool {
    $t = $db->real_escape_string($table);
    $i = $db->real_escape_string($index);
    $res = $db->query("SHOW INDEX FROM `{$t}` WHERE Key_name = '{$i}'");
    $ok = $res && $res->num_rows > 0;
    if ($res) { $res->close(); }
    return $ok;
}

$tables = [
    'capture_sessions' => 'idx_capture_sessions_deleted_at',
    'photo_points' => 'idx_photo_points_deleted_at',
    'video_scans' => 'idx_video_scans_deleted_at',
];

foreach ($tables as $table => $indexName) {
    if (!table_exists($dbcnx, $table)) {
        echo "skip {$table}: table missing\n";
        continue;
    }

    if (!column_exists($dbcnx, $table, 'deleted_at')) {
        $dbcnx->query("ALTER TABLE `{$table}` ADD COLUMN `deleted_at` DATETIME(6) NULL DEFAULT NULL");
        echo "{$table}: added deleted_at\n";
    }
    if (!column_exists($dbcnx, $table, 'deleted_by')) {
        $dbcnx->query("ALTER TABLE `{$table}` ADD COLUMN `deleted_by` BIGINT UNSIGNED NULL DEFAULT NULL");
        echo "{$table}: added deleted_by\n";
    }
    if (!column_exists($dbcnx, $table, 'delete_reason')) {
        $dbcnx->query("ALTER TABLE `{$table}` ADD COLUMN `delete_reason` VARCHAR(255) NULL DEFAULT NULL");
        echo "{$table}: added delete_reason\n";
    }

    if (!index_exists($dbcnx, $table, $indexName)) {
        $dbcnx->query("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` (`deleted_at`)");
        echo "{$table}: added {$indexName}\n";
    }
}

echo "done\n";
