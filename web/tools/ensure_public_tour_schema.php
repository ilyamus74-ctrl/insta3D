<?php
declare(strict_types=1);

require_once __DIR__ . '/../www/bootstrap.php';

$createSql = "CREATE TABLE IF NOT EXISTS public_tour_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    token VARCHAR(128) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    expires_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    KEY idx_order_id (order_id),
    KEY idx_session_id (session_id),
    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$dbcnx->query($createSql)) {
    fwrite(STDERR, "FAILED create table public_tour_links: {$dbcnx->error}\n");
    exit(1);
}

echo "OK: public_tour_links ensured\n";
