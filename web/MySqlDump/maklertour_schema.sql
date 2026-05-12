SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(190) DEFAULT NULL,
    phone VARCHAR(64) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    role ENUM('ADMIN','BROKER','OPERATOR','CLIENT') NOT NULL DEFAULT 'BROKER',
    ui_lang VARCHAR(8) NOT NULL DEFAULT 'ru',
    ui_settings JSON DEFAULT NULL,
    last_login_at DATETIME(6) DEFAULT NULL,
    login_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_login_ip VARCHAR(64) DEFAULT NULL,
    last_user_agent TEXT DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_code VARCHAR(32) NOT NULL,
    permission_code VARCHAR(128) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_role_permission (role_code, permission_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS menu_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(64) NOT NULL,
    title VARCHAR(128) NOT NULL,
    icon VARCHAR(64) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_menu_groups_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS menu_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_code VARCHAR(64) NOT NULL,
    menu_key VARCHAR(128) NOT NULL,
    title VARCHAR(128) NOT NULL,
    icon VARCHAR(64) DEFAULT NULL,
    action VARCHAR(128) DEFAULT NULL,
    url VARCHAR(255) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_menu_items_key (menu_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_menu (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_code VARCHAR(32) NOT NULL,
    menu_key VARCHAR(128) NOT NULL,
    is_allowed TINYINT(1) NOT NULL DEFAULT 1,
    can_view TINYINT(1) NOT NULL DEFAULT 1,
    can_edit TINYINT(1) NOT NULL DEFAULT 0,
    can_delete TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_role_menu (role_code, menu_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid_created BIGINT UNSIGNED DEFAULT NULL,
    event_time DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    user_id BIGINT UNSIGNED DEFAULT NULL,
    event_type VARCHAR(64) NOT NULL,
    entity_type VARCHAR(64) DEFAULT NULL,
    entity_id BIGINT UNSIGNED DEFAULT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    description TEXT DEFAULT NULL,
    extra_data JSON DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_event_time (event_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tour_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    broker_id BIGINT UNSIGNED NOT NULL,
    operator_id BIGINT UNSIGNED DEFAULT NULL,
    title VARCHAR(190) NOT NULL,
    address TEXT NOT NULL,
    area_m2 DECIMAL(10,2) DEFAULT NULL,
    customer_name VARCHAR(190) DEFAULT NULL,
    customer_phone VARCHAR(64) DEFAULT NULL,
    customer_email VARCHAR(190) DEFAULT NULL,
    status ENUM(
        'NEW',
        'ASSIGNED',
        'IN_PROGRESS',
        'CAPTURED',
        'UPLOADING',
        'UPLOADED',
        'PROCESSING',
        'READY',
        'CANCELLED'
    ) NOT NULL DEFAULT 'NEW',
    public_token VARCHAR(64) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_tour_orders_broker (broker_id),
    KEY idx_tour_orders_operator (operator_id),
    KEY idx_tour_orders_status (status),
    UNIQUE KEY uq_tour_orders_public_token (public_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS capture_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    operator_id BIGINT UNSIGNED NOT NULL,
    app_session_uuid VARCHAR(64) NOT NULL,
    camera_model VARCHAR(128) DEFAULT NULL,
    status ENUM('LOCAL_ONLY','CAPTURED','UPLOADING','UPLOADED','PROCESSING','READY','FAILED') NOT NULL DEFAULT 'LOCAL_ONLY',
    started_at DATETIME(6) DEFAULT NULL,
    completed_at DATETIME(6) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_capture_session_app_uuid (app_session_uuid),
    KEY idx_capture_sessions_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS capture_points (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    app_point_uuid VARCHAR(64) NOT NULL,
    title VARCHAR(190) DEFAULT NULL,
    room_name VARCHAR(190) DEFAULT NULL,
    sequence_number INT NOT NULL DEFAULT 0,
    preview_path VARCHAR(500) DEFAULT NULL,
    original_path VARCHAR(500) DEFAULT NULL,
    upload_state ENUM('LOCAL_ONLY','QUEUED','UPLOADING','UPLOADED','FAILED') NOT NULL DEFAULT 'LOCAL_ONLY',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_capture_point_app_uuid (app_point_uuid),
    KEY idx_capture_points_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS video_scans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    app_scan_uuid VARCHAR(64) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    local_camera_url TEXT DEFAULT NULL,
    storage_path VARCHAR(500) DEFAULT NULL,
    size_bytes BIGINT UNSIGNED DEFAULT NULL,
    duration_sec INT DEFAULT NULL,
    upload_state ENUM('LOCAL_ONLY','QUEUED','UPLOADING','UPLOADED','FAILED') NOT NULL DEFAULT 'LOCAL_ONLY',
    processing_state ENUM('NOT_STARTED','PROCESSING','DONE','FAILED') NOT NULL DEFAULT 'NOT_STARTED',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_video_scan_app_uuid (app_scan_uuid),
    KEY idx_video_scans_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS uploads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED DEFAULT NULL,
    session_id BIGINT UNSIGNED DEFAULT NULL,
    entity_type ENUM('POINT_PREVIEW','POINT_ORIGINAL','VIDEO_SCAN') NOT NULL,
    entity_id BIGINT UNSIGNED DEFAULT NULL,
    original_filename VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) DEFAULT NULL,
    mime_type VARCHAR(128) DEFAULT NULL,
    size_bytes BIGINT UNSIGNED DEFAULT NULL,
    sha256 CHAR(64) DEFAULT NULL,
    state ENUM('INIT','UPLOADING','COMPLETED','FAILED') NOT NULL DEFAULT 'INIT',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_uploads_user (user_id),
    KEY idx_uploads_order (order_id),
    KEY idx_uploads_session (session_id),
    KEY idx_uploads_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO users
(username, email, password_hash, full_name, role, is_active, ui_lang)
VALUES
('admin', 'admin@maklertour.local', '$2y$10$wH8f5hYi4MJ1w9XXeU8Sw.7RpE7ZDJv4pSA4YLa45YO4E4OlvP2Jm', 'Administrator', 'ADMIN', 1, 'ru');

INSERT IGNORE INTO menu_groups (code, title, icon, sort_order)
VALUES
('main', 'MaklerTour', 'bi bi-house', 10),
('admin', 'Admin', 'bi bi-gear', 90);

INSERT IGNORE INTO menu_items (group_code, menu_key, title, icon, action, url, sort_order)
VALUES
('main', 'orders', 'Заявки', 'bi bi-list-task', 'view_orders', '/main.php?page=orders', 10),
('main', 'operator_orders', 'Биржа заявок', 'bi bi-camera-video', 'view_market', '/main.php?page=market', 20),
('admin', 'users', 'Пользователи', 'bi bi-people', 'view_users', '/users.php', 10);

INSERT IGNORE INTO role_menu (role_code, menu_key, is_allowed, can_view, can_edit, can_delete)
VALUES
('ADMIN', 'orders', 1, 1, 1, 1),
('ADMIN', 'operator_orders', 1, 1, 1, 1),
('ADMIN', 'users', 1, 1, 1, 1),
('BROKER', 'orders', 1, 1, 1, 0),
('OPERATOR', 'operator_orders', 1, 1, 1, 0);

INSERT IGNORE INTO role_permissions (role_code, permission_code)
VALUES
('ADMIN', '*'),
('BROKER', 'orders.create'),
('BROKER', 'orders.view_own'),
('OPERATOR', 'orders.take'),
('OPERATOR', 'orders.upload');
