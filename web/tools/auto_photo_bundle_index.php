<?php

declare(strict_types=1);

function auto_photo_bundle_cli_should_write(array $args): bool
{
    return !array_key_exists('dry-run', $args);
}

function auto_photo_bundle_cli_main(array $args): int
{
    require_once __DIR__ . '/../www/bootstrap.php';
    require_once __DIR__ . '/../libs/auto_photo_bundle_lib.php';

    $id = isset($args['capture-bundle-id']) ? (int)$args['capture-bundle-id'] : 0;
    if ($id <= 0) {
        fwrite(STDERR, "usage: php web/tools/auto_photo_bundle_index.php --capture-bundle-id=ID [--dry-run]\n");
        return 1;
    }
if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) {
    fwrite(STDERR, "DB connection not found\n");
    return 1;
}
    try {
        $row = auto_photo_bundle_load_row($dbcnx, $id);
        $archive = auto_photo_bundle_resolve_archive_path($row);
        $index = auto_photo_bundle_build_index_from_row($row);
        if (auto_photo_bundle_cli_should_write($args)) {
            auto_photo_bundle_write_index_atomic($index, auto_photo_bundle_index_cache_path($row, $archive));
        }
        echo json_encode(['validation_status'=>$index['validation_status'],'capture_bundle_id'=>$index['capture_bundle_id'],'photos_count_actual'=>$index['photos_count_actual'],'warnings'=>$index['warnings'],'blocking_errors'=>$index['blocking_errors']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return $index['validation_status'] === 'VALID' ? 0 : ($index['validation_status'] === 'WARNING' ? 2 : 3);
    } catch (Throwable $e) {
        fwrite(STDERR, json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        return 1;
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(auto_photo_bundle_cli_main(getopt('', ['capture-bundle-id:', 'dry-run'])));
}
