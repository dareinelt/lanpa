-- Grundschema der Intranet-Landingpage
CREATE TABLE IF NOT EXISTS navigation_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(120) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    type ENUM('external', 'internal') NOT NULL DEFAULT 'external',
    icon VARCHAR(32) NULL,
    short_description VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_navigation_active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(64) NOT NULL,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS phonebook (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    external_id VARCHAR(190) NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    phone VARCHAR(64) NULL,
    phone_digits VARCHAR(64) NULL,
    mobile VARCHAR(64) NULL,
    email VARCHAR(190) NULL,
    department VARCHAR(120) NULL,
    ad_modified DATETIME NULL,
    synced_at DATETIME NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_phonebook_external (external_id),
    KEY idx_phonebook_last_name (active, last_name),
    KEY idx_phonebook_first_name (active, first_name),
    KEY idx_phonebook_display_name (active, display_name),
    KEY idx_phonebook_department (active, department),
    KEY idx_phonebook_phone (active, phone_digits)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS click_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    navigation_id INT UNSIGNED NULL,
    clicked_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_click_events_time (clicked_at),
    KEY idx_click_events_nav_time (navigation_id, clicked_at),
    CONSTRAINT fk_click_events_navigation FOREIGN KEY (navigation_id)
        REFERENCES navigation_items (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    status ENUM('running', 'success', 'error') NOT NULL DEFAULT 'running',
    processed INT UNSIGNED NOT NULL DEFAULT 0,
    deactivated INT UNSIGNED NOT NULL DEFAULT 0,
    message VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    KEY idx_sync_log_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
