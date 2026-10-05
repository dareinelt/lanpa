-- Orvanta-Langzeitarchiv: Richtliniengesteuerte Archivierung alter
-- Exchange-E-Mails in komprimierte Container (Chunks) im Nextcloud-Bereich
-- des Benutzers. Die Tabellen bilden Journal, Index und Sperren des
-- transaktionalen Archivierungsablaufs (Copy -> Verify -> Commit -> Delete).
CREATE TABLE orvanta_archives (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_uid VARCHAR(100) NOT NULL,
    mailbox VARCHAR(190) NOT NULL,
    storage_folder VARCHAR(190) NOT NULL DEFAULT 'Orvanta-Archiv',
    format_version INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('active', 'error') NOT NULL DEFAULT 'active',
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    message_count INT UNSIGNED NOT NULL DEFAULT 0,
    chunk_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_successful_run DATETIME NULL,
    last_notice TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orvanta_archives_user (user_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Abbild der Exchange-Ordnerstruktur im Archiv. exchange_folder_id ist die
-- opake EWS-FolderId; path der anzeigbare Pfad ("Posteingang/Kunde A").
CREATE TABLE orvanta_archive_folders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    archive_id INT UNSIGNED NOT NULL,
    exchange_folder_id VARCHAR(512) NOT NULL,
    folder_hash CHAR(40) NOT NULL,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    path VARCHAR(1024) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orvanta_archive_folder (archive_id, folder_hash),
    KEY idx_orvanta_archive_folders_archive (archive_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eine Zeile je archivierter Nachricht: Identitaet (InternetMessageId bzw.
-- EWS-ItemId), Fundstelle im Chunk, Pruefsumme des unkomprimierten Inhalts
-- und der Journal-Zustand. Erst der Zustand 'committed' erlaubt das Loeschen
-- aus Exchange; 'deleted' bestaetigt die erfolgte Loeschung.
CREATE TABLE orvanta_archive_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    archive_id INT UNSIGNED NOT NULL,
    folder_id INT UNSIGNED NOT NULL,
    exchange_item_id VARCHAR(1024) NOT NULL,
    item_hash CHAR(40) NOT NULL,
    change_key VARCHAR(255) NOT NULL DEFAULT '',
    internet_message_id VARCHAR(512) NOT NULL DEFAULT '',
    subject VARCHAR(512) NOT NULL DEFAULT '',
    from_name VARCHAR(255) NOT NULL DEFAULT '',
    from_email VARCHAR(255) NOT NULL DEFAULT '',
    recipients TEXT NULL,
    item_date DATETIME NULL,
    kind ENUM('mime', 'json') NOT NULL DEFAULT 'mime',
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    content_hash CHAR(64) NOT NULL DEFAULT '',
    chunk_name VARCHAR(100) NOT NULL DEFAULT '',
    chunk_offset BIGINT UNSIGNED NOT NULL DEFAULT 0,
    chunk_length BIGINT UNSIGNED NOT NULL DEFAULT 0,
    has_attachments TINYINT(1) NOT NULL DEFAULT 0,
    attachment_names TEXT NULL,
    search_text TEXT NULL,
    status ENUM('pending', 'committed', 'deleted', 'failed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    committed_at DATETIME NULL,
    UNIQUE KEY uq_orvanta_archive_item (archive_id, item_hash),
    KEY idx_orvanta_archive_items_folder (archive_id, folder_id, status),
    KEY idx_orvanta_archive_items_status (archive_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Archivierungslaeufe inkl. Sperre: Je Archiv darf hoechstens ein Lauf mit
-- status 'running' und gueltigem locked_until aktiv sein. Abgelaufene Sperren
-- (Absturz) duerfen vom naechsten Lauf uebernommen werden.
CREATE TABLE orvanta_archive_jobs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    archive_id INT UNSIGNED NOT NULL,
    status ENUM('running', 'completed', 'failed') NOT NULL DEFAULT 'running',
    locked_until DATETIME NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    last_error TEXT NULL,
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    committed_count INT UNSIGNED NOT NULL DEFAULT 0,
    deleted_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_orvanta_archive_jobs_archive (archive_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
