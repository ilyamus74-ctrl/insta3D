<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$connectCandidates = [
    '/home/makler/web/configs/connectDB.php',
    __DIR__ . '/../configs/connectDB.php',
];
foreach ($connectCandidates as $connectFile) {
    if (is_file($connectFile)) {
        require_once $connectFile;
        break;
    }
}
if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) {
    fwrite(STDERR, "ERROR: failed to initialize mysqli\n");
    exit(1);
}

$columns = [
    'camera_info_path' => 'VARCHAR(1024) NULL',
    'manifest_path' => 'VARCHAR(1024) NULL',
    'imu_path' => 'VARCHAR(1024) NULL',
    'tof_registered_path' => 'VARCHAR(1024) NULL',
];

foreach ($columns as $column => $definition) {
    $escaped = $dbcnx->real_escape_string($column);
    $result = $dbcnx->query(
        "SHOW COLUMNS FROM video_scans LIKE '" . $escaped . "'"
    );
    $exists = $result && $result->num_rows > 0;
    if ($result) {
        $result->close();
    }
    if ($exists) {
        echo "[OK] video_scans.$column already exists\n";
        continue;
    }

    $sql = 'ALTER TABLE video_scans ADD COLUMN `' .
        $column . '` ' . $definition;
    if (!$dbcnx->query($sql)) {
        fwrite(
            STDERR,
            "[FAIL] $column: " . $dbcnx->error . "\n"
        );
        exit(2);
    }
    echo "[ADD] video_scans.$column\n";
}

echo "Result: PASS\n";
