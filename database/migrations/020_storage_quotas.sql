-- Speicherplatz-Kontingente (Quota) je Benutzer fuer Nextcloud.
--
-- Wirksames Kontingent eines Benutzers (Reihenfolge):
--   1. individuelles Kontingent (storage_quota_overrides, Begruendung Pflicht)
--   2. groesstes Kontingent der AD-Gruppen, in denen er Mitglied ist
--      (storage_quota_groups, Mitgliedschaft aus ad_group_members)
--   3. Standard (Einstellung office_quota_default_mb, 500 MB)
-- Jede Aenderung wird in storage_quota_history protokolliert (wer, wem,
-- wie viel, warum). Benutzer werden ueber ihre Nextcloud-Kennung
-- (SamAccountName bzw. "name@kennung" weiterer Identitaetsquellen) gefuehrt,
-- damit Zuordnungen eine Neusynchronisation des Telefonbuchs ueberstehen.

CREATE TABLE IF NOT EXISTS storage_quota_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_name VARCHAR(190) NOT NULL,
    quota_mb INT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL DEFAULT '',
    updated_by VARCHAR(190) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_storage_quota_groups_name (group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_quota_overrides (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_uid VARCHAR(100) NOT NULL,
    display_name VARCHAR(255) NOT NULL DEFAULT '',
    quota_mb INT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    created_by VARCHAR(190) NOT NULL DEFAULT '',
    updated_by VARCHAR(190) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_storage_quota_overrides_uid (user_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_quota_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_type VARCHAR(16) NOT NULL,
    subject VARCHAR(190) NOT NULL DEFAULT '',
    subject_label VARCHAR(255) NOT NULL DEFAULT '',
    action VARCHAR(16) NOT NULL,
    old_quota_mb INT UNSIGNED NULL,
    new_quota_mb INT UNSIGNED NULL,
    reason VARCHAR(1000) NOT NULL DEFAULT '',
    admin_username VARCHAR(190) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_storage_quota_history_subject (subject_type, subject),
    KEY idx_storage_quota_history_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
