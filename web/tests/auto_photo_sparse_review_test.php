<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/auto_photo_sparse_lib.php';
require_once __DIR__ . '/../libs/auto_photo_sparse_web_lib.php';

$functions = [
    'auto_photo_sparse_parse_model_id',
    'auto_photo_sparse_validate_model_id',
    'auto_photo_sparse_validate_job_scope',
    'auto_photo_sparse_validate_prepare_chain',
];
foreach ($functions as $function) {
    if (!function_exists($function)) {
        throw new RuntimeException('missing_' . $function);
    }
}

echo "OK\n";
