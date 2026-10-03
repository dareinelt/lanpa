-- Snapshot-Speicher (Dateiversionen): Spiegel des Agent-Katalogs fuer den
-- Adminbereich, Zustand der Freigabe und Wiederherstellungsauftraege.

CREATE TABLE IF NOT EXISTS storage_snapshots (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid CHAR(40) NOT NULL,
    source VARCHAR(32) NOT NULL,
    path VARCHAR(1024) NOT NULL,
    path_hash CHAR(40) NOT NULL,
    user VARCHAR(100) NOT NULL DEFAULT '',
    version INT UNSIGNED NOT NULL DEFAULT 0,
    size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    file_mtime DATETIME NULL,
    sha256 CHAR(64) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    file_deleted TINYINT(1) NOT NULL DEFAULT 0,
    error VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    stored_at DATETIME NULL,
    restored_at DATETIME NULL,
    restored_by VARCHAR(100) NOT NULL DEFAULT '',
    synced_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_storage_snapshots_uid (uid),
    KEY idx_storage_snapshots_created (created_at),
    KEY idx_storage_snapshots_user (user),
    KEY idx_storage_snapshots_path (path_hash),
    KEY idx_storage_snapshots_status (status, file_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_snapshot_status (
    id TINYINT UNSIGNED NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'disabled',
    message VARCHAR(500) NOT NULL DEFAULT '',
    total_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    free_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    read_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
    write_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
    read_iops INT UNSIGNED NOT NULL DEFAULT 0,
    write_iops INT UNSIGNED NOT NULL DEFAULT 0,
    snapshots_total INT UNSIGNED NOT NULL DEFAULT 0,
    snapshots_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    pending INT UNSIGNED NOT NULL DEFAULT 0,
    failed INT UNSIGNED NOT NULL DEFAULT 0,
    last_snapshot_at DATETIME NULL,
    last_error VARCHAR(500) NOT NULL DEFAULT '',
    state_since DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zusatzangabe fuer Auftraege (z. B. Kennung der wiederherzustellenden Version).
ALTER TABLE storage_requests ADD COLUMN detail VARCHAR(190) NOT NULL DEFAULT '';
