-- Orvanta (Mail- und Kalender-App, Exchange On-Premise ab 2019 via EWS).
-- Einstellungen des Exchange-Zugangs, lokal zwischengespeicherte Terminerinnerungen
-- und der Bestand des Zwischenspeichers im Nextcloud-Bereich der Benutzer (eigenes Quota).
CREATE TABLE orvanta_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orvanta_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orvanta_reminders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_uid VARCHAR(100) NOT NULL,
    item_id VARCHAR(512) NOT NULL,
    item_hash CHAR(40) NOT NULL,
    subject VARCHAR(255) NOT NULL DEFAULT '',
    location VARCHAR(255) NOT NULL DEFAULT '',
    starts_at DATETIME NOT NULL,
    remind_at DATETIME NOT NULL,
    state ENUM('pending', 'delivered', 'dismissed', 'snoozed') NOT NULL DEFAULT 'pending',
    delivered_at DATETIME NULL,
    dismissed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orvanta_reminders_item (user_uid, item_hash),
    KEY idx_orvanta_reminders_due (user_uid, state, remind_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orvanta_cache_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_uid VARCHAR(100) NOT NULL,
    kind ENUM('attachment', 'message') NOT NULL DEFAULT 'attachment',
    item_hash CHAR(40) NOT NULL,
    name VARCHAR(255) NOT NULL,
    path VARCHAR(512) NOT NULL,
    content_type VARCHAR(190) NOT NULL DEFAULT 'application/octet-stream',
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orvanta_cache_item (user_uid, item_hash),
    KEY idx_orvanta_cache_user (user_uid, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
