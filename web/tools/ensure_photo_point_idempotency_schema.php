<?php
declare(strict_types=1);

$connectCandidates = [
    '/home/makler/web/configs/connectDB.php',
    __DIR__ . '/../configs/connectDB.php',
];

$connected = false;
foreach ($connectCandidates as $connectFile) {
    if (is_file($connectFile)) {
        require_once $connectFile;
        $connected = true;
        break;
    }
}

if (!$connected || !isset($dbcnx) || !($dbcnx instanceof mysqli)) {
    fwrite(STDERR, "ERROR: failed to initialize mysqli via connectDB.php\n");
    exit(1);
}

if (!$dbcnx->query("UPDATE photo_points SET app_point_uuid = NULL WHERE app_point_uuid = ''")) {
    fwrite(STDERR, "ERROR: failed to normalize empty app_point_uuid values: {$dbcnx->error}\n");
    exit(1);
}
echo "OK: normalized empty app_point_uuid values to NULL\n";

$duplicates = [];
$dupSql = "
    SELECT session_id, app_point_uuid, COUNT(*) AS c
    FROM photo_points
    WHERE app_point_uuid IS NOT NULL
    GROUP BY session_id, app_point_uuid
    HAVING COUNT(*) > 1
";
$dupRes = $dbcnx->query($dupSql);
if (!$dupRes) {
    fwrite(STDERR, "ERROR: duplicate detection failed: {$dbcnx->error}\n");
    exit(1);
}
while ($row = $dupRes->fetch_assoc()) {
    $duplicates[] = $row;
}
$dupRes->close();

if ($duplicates !== []) {
    fwrite(STDERR, "ERROR: duplicate (session_id, app_point_uuid) rows found; schema not changed.\n");
    foreach ($duplicates as $d) {
        fwrite(STDERR, sprintf(
            "  session_id=%s app_point_uuid=%s count=%s\n",
            (string)$d['session_id'],
            (string)$d['app_point_uuid'],
            (string)$d['c']
        ));
    }
    exit(1);
}
echo "OK: no duplicate (session_id, app_point_uuid) rows\n";

$indexes = [];
$indexRes = $dbcnx->query("SHOW INDEX FROM photo_points");
if (!$indexRes) {
    fwrite(STDERR, "ERROR: unable to inspect indexes: {$dbcnx->error}\n");
    exit(1);
}
while ($row = $indexRes->fetch_assoc()) {
    $key = (string)$row['Key_name'];
    if (!isset($indexes[$key])) {
        $indexes[$key] = [];
    }
    $indexes[$key][(int)$row['Seq_in_index']] = (string)$row['Column_name'];
}
$indexRes->close();

if (isset($indexes['uq_photo_points_app_uuid'])) {
    if (!$dbcnx->query("ALTER TABLE photo_points DROP INDEX uq_photo_points_app_uuid")) {
        fwrite(STDERR, "ERROR: failed to drop uq_photo_points_app_uuid: {$dbcnx->error}\n");
        exit(1);
    }
    echo "CHANGED: dropped uq_photo_points_app_uuid\n";
} else {
    echo "OK: uq_photo_points_app_uuid does not exist\n";
}

$hasComposite = false;
if (isset($indexes['uq_photo_points_session_app_uuid'])) {
    ksort($indexes['uq_photo_points_session_app_uuid']);
    $hasComposite = array_values($indexes['uq_photo_points_session_app_uuid']) === ['session_id', 'app_point_uuid'];
}

if ($hasComposite) {
    echo "OK: uq_photo_points_session_app_uuid(session_id, app_point_uuid) already exists\n";
} else {
    if (!$dbcnx->query("ALTER TABLE photo_points ADD UNIQUE INDEX uq_photo_points_session_app_uuid (session_id, app_point_uuid)")) {
        fwrite(STDERR, "ERROR: failed to create uq_photo_points_session_app_uuid: {$dbcnx->error}\n");
        exit(1);
    }
    echo "CHANGED: added uq_photo_points_session_app_uuid(session_id, app_point_uuid)\n";
}

echo "DONE\n";
