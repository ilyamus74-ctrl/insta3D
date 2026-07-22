<?php
declare(strict_types=1);

/** Shared existing remote-job ID allocation algorithm; no AUTO-B02 dependency. */
if (!function_exists('sfm_job_id')) {
    function sfm_job_id(mysqli $db): int
    {
        do {
            $id = random_int(10000, 999999999);
            $st = $db->prepare(
                'SELECT id FROM sfm_remote_jobs WHERE remote_job_id=? LIMIT 1'
            );
            if (!$st) {
                return $id;
            }
            $st->bind_param('i', $id);
            $st->execute();
            $exists = $st->get_result()->fetch_assoc();
            $st->close();
        } while ($exists);
        return $id;
    }
}
