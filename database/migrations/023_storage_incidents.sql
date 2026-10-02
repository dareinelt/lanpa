-- Sicherheitsvorfaelle des Speicher-Tierings (Container storage-sync).
--
-- storage-sync erkennt auffaelliges Ueberschreiben (z. B. Ransomware:
-- massenhaft geaenderte Dateien, verschluesselte Inhalte, Dateien mit
-- bekannten Ransomware-Endungen). Solange ein Vorfall offen ist,
-- - darf der ausloesende Benutzer in Nextcloud nur noch lesen,
-- - bleibt ein Speicherziel des Cold-Tiers schreibgeschuetzt (read-only)
--   und wird nicht synchronisiert (unveraenderter Datenbestand).
-- "Erledigt" im Adminbereich (Vorfaelle) hebt beides wieder auf.

CREATE TABLE IF NOT EXISTS storage_incidents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    uid VARCHAR(191) NOT NULL DEFAULT '',
    attribution VARCHAR(16) NOT NULL DEFAULT 'owner',
    rules VARCHAR(100) NOT NULL DEFAULT '',
    summary VARCHAR(500) NOT NULL DEFAULT '',
    source VARCHAR(32) NOT NULL DEFAULT 'nextcloud-data',
    files_changed INT UNSIGNED NOT NULL DEFAULT 0,
    files_suspicious INT UNSIGNED NOT NULL DEFAULT 0,
    files_extension INT UNSIGNED NOT NULL DEFAULT 0,
    bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    first_seen DATETIME NULL,
    last_seen DATETIME NULL,
    details TEXT NULL,
    user_restricted TINYINT(1) NOT NULL DEFAULT 0,
    frozen_target_id INT UNSIGNED NULL,
    frozen_target_label VARCHAR(100) NOT NULL DEFAULT '',
    resolved_at DATETIME NULL,
    resolved_by VARCHAR(100) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_storage_incidents_status (status, id),
    KEY idx_storage_incidents_uid (uid, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
