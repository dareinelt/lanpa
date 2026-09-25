-- AD-Gruppen fuer die Rechtevergabe.
--
-- Die AD-Synchronisation importiert die Gruppen unterhalb der konfigurierten
-- Gruppen-Pfade (Einstellung "ldap_group_base_dn") samt ihrer - auch
-- verschachtelten - Mitglieder. Daraus ermittelt die Anwendung die Gruppen des
-- per NTLM angemeldeten Benutzers; ausserdem dienen die Namen als Vorschlaege
-- bei der Vergabe von Kachel-Berechtigungen (ohne Live-Abfrage des AD).

CREATE TABLE IF NOT EXISTS ad_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dn_hash CHAR(64) NOT NULL,
    dn VARCHAR(1024) NOT NULL,
    name VARCHAR(190) NOT NULL,
    description VARCHAR(255) NULL,
    member_count INT UNSIGNED NOT NULL DEFAULT 0,
    synced_at DATETIME NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ad_groups_dn (dn_hash),
    KEY idx_ad_groups_name (active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_group_members (
    group_id INT UNSIGNED NOT NULL,
    phonebook_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, phonebook_id),
    KEY idx_ad_group_members_user (phonebook_id),
    CONSTRAINT fk_ad_group_members_group FOREIGN KEY (group_id)
        REFERENCES ad_groups (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ad_group_members_user FOREIGN KEY (phonebook_id)
        REFERENCES phonebook (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
