<?php

declare(strict_types=1);

/*
 * order.php still declares the historical sfm_job_id() helper before loading
 * the shared remote-job library through the Auto Photo prepare service.
 * Requiring the shared library must preserve that existing definition.
 */
function sfm_job_id(mysqli $db): int
{
    return 4242;
}

require_once __DIR__ . '/../libs/sfm_remote_job_lib.php';

$db = new class extends mysqli {
};

if (sfm_job_id($db) !== 4242) {
    throw new RuntimeException('existing_sfm_job_id_was_replaced');
}

echo "OK\n";
