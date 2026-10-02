-- Speicher-Tiering und HA-Synchronisation der Office-Daten (Container storage-sync).
--
-- Nextcloud- und Euro-Office-Daten werden auf SMB-Freigaben (UNC) gespiegelt.
-- Alle aktiven Ziele halten denselben vollstaendigen Stand; das lokale Volume
-- haelt nur haeufig genutzte und juengere Daten vor (Einstellungen storage_*
-- in der Tabelle settings). Die Zugangsdaten stehen verschluesselt (SecretBox)
-- in storage_targets.password.

CREATE TABLE IF NOT EXISTS storage_targets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    label VARCHAR(100) NOT NULL,
    unc_path VARCHAR(400) NOT NULL,
    username VARCHAR(128) NOT NULL DEFAULT '',
    password TEXT NULL,
    domain VARCHAR(128) NOT NULL DEFAULT '',
    smb_version VARCHAR(8) NOT NULL DEFAULT 'auto',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_storage_targets_unc (unc_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zustand je Ziel. Der Monitor (Einbindung, Fuellstand, Datenrate) und die
-- Synchronisation (Rueckstand) schreiben jeweils eigene Spalten.
CREATE TABLE IF NOT EXISTS storage_target_status (
    target_id INT UNSIGNED NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'unknown',
    message VARCHAR(500) NOT NULL DEFAULT '',
    total_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    free_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    read_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
    write_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
    read_iops DECIMAL(12,2) NOT NULL DEFAULT 0,
    write_iops DECIMAL(12,2) NOT NULL DEFAULT 0,
    in_sync TINYINT(1) NOT NULL DEFAULT 0,
    pending_files INT UNSIGNED NOT NULL DEFAULT 0,
    pending_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lag_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    synced_files INT UNSIGNED NOT NULL DEFAULT 0,
    synced_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    state_since DATETIME NULL,
    last_sync_at DATETIME NULL,
    updated_at DATETIME NULL,
    sync_updated_at DATETIME NULL,
    PRIMARY KEY (target_id),
    CONSTRAINT fk_storage_target_status_target FOREIGN KEY (target_id)
        REFERENCES storage_targets (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gesamtzustand (genau eine Zeile, id = 1).
CREATE TABLE IF NOT EXISTS storage_status (
    id TINYINT UNSIGNED NOT NULL,
    heartbeat_at DATETIME NULL,
    sync_heartbeat_at DATETIME NULL,
    ha_state VARCHAR(16) NOT NULL DEFAULT 'disabled',
    ha_message VARCHAR(500) NOT NULL DEFAULT '',
    sync_state VARCHAR(16) NOT NULL DEFAULT 'disabled',
    sync_message VARCHAR(500) NOT NULL DEFAULT '',
    mode VARCHAR(16) NOT NULL DEFAULT 'normal',
    mode_reason VARCHAR(500) NOT NULL DEFAULT '',
    mode_since DATETIME NULL,
    local_total_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    local_free_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    local_limit_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    local_read_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
    local_write_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
    local_read_iops DECIMAL(12,2) NOT NULL DEFAULT 0,
    local_write_iops DECIMAL(12,2) NOT NULL DEFAULT 0,
    metrics_source VARCHAR(16) NOT NULL DEFAULT '',
    files_total INT UNSIGNED NOT NULL DEFAULT 0,
    bytes_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
    files_local INT UNSIGNED NOT NULL DEFAULT 0,
    bytes_local BIGINT UNSIGNED NOT NULL DEFAULT 0,
    files_evicted INT UNSIGNED NOT NULL DEFAULT 0,
    bytes_evicted BIGINT UNSIGNED NOT NULL DEFAULT 0,
    pending_files INT UNSIGNED NOT NULL DEFAULT 0,
    pending_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lag_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    sparse_supported TINYINT(1) NOT NULL DEFAULT 1,
    recalls_active INT UNSIGNED NOT NULL DEFAULT 0,
    recalls_total INT UNSIGNED NOT NULL DEFAULT 0,
    recalls_failed INT UNSIGNED NOT NULL DEFAULT 0,
    last_recall_at DATETIME NULL,
    last_scan_at DATETIME NULL,
    last_full_scan_at DATETIME NULL,
    last_sync_at DATETIME NULL,
    last_db_dump_at DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO storage_status (id) VALUES (1);

-- Verlauf fuer die Hochrechnung (alle 5 Minuten, 35 Tage).
CREATE TABLE IF NOT EXISTS storage_usage_samples (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sampled_at DATETIME NOT NULL,
    local_used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    local_free_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    data_total_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    data_local_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    targets_online TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_storage_usage_samples_at (sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL,
    level VARCHAR(8) NOT NULL DEFAULT 'info',
    category VARCHAR(16) NOT NULL DEFAULT 'sync',
    target_id INT UNSIGNED NULL,
    message VARCHAR(500) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_storage_events_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auftraege aus dem Adminbereich an storage-sync (sync_now, full_scan, remount).
CREATE TABLE IF NOT EXISTS storage_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    action VARCHAR(32) NOT NULL,
    target_id INT UNSIGNED NULL,
    requested_by VARCHAR(100) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    picked_at DATETIME NULL,
    finished_at DATETIME NULL,
    result VARCHAR(500) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_storage_requests_open (picked_at, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
