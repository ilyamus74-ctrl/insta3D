<?php
declare(strict_types=1);

require_once '/workspace/insta3D/web/configs/connectDB.php';
require_once '/workspace/insta3D/web/libs/tour_media_derivatives_lib.php';

$args = getopt('', ['session-id:', 'with-preview', 'overwrite']);
$sessionId = isset($args['session-id']) ? (int)$args['session-id'] : 0;
if ($sessionId <= 0) {
    fwrite(STDERR, "--session-id is required\n");
    exit(1);
}
if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) {
    fwrite(STDERR, "DB connection is not available\n");
    exit(1);
}

$res = tour_ensure_session_media_derivatives($dbcnx, $sessionId, isset($args['with-preview']), isset($args['overwrite']));
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
exit(0);
