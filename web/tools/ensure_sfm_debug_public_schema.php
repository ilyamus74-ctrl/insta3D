<?php
declare(strict_types=1);
require_once __DIR__ . '/../configs/secure.php';
require_once __DIR__ . '/../libs/sfm_debug_public_lib.php';
sfm_debug_public_ensure_schema($dbcnx);
echo "OK: sfm_debug_public_links ensured\n";