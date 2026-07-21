<?php

declare(strict_types=1);

function auto_photo_bundle_materialize_cli_main(array $args): int
{
    require_once __DIR__ . '/../www/bootstrap.php';
    require_once __DIR__ . '/../libs/auto_photo_bundle_materialize_lib.php';
    $id = isset($args['capture-bundle-id']) ? (int)$args['capture-bundle-id'] : 0;
    if ($id <= 0) { fwrite(STDERR, "usage: php web/tools/auto_photo_bundle_materialize.php --capture-bundle-id=ID [--dry-run]\n"); return 1; }
    if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) { fwrite(STDERR, "DB connection not found\n"); return 1; }
    try {
        $row = auto_photo_bundle_load_row($dbcnx, $id);
        $result = auto_photo_bundle_materialize_from_row($row, ['dry_run'=>array_key_exists('dry-run', $args)]);
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return 0;
    } catch (RuntimeException|InvalidArgumentException $e) {
        fwrite(STDERR, json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        return 3;
    } catch (Throwable $e) {
        fwrite(STDERR, json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        return 1;
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(auto_photo_bundle_materialize_cli_main(getopt('', ['capture-bundle-id:', 'dry-run'])));
}
