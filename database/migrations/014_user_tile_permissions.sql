-- SSO-/NTLM-Authentifizierung und Kachel-Berechtigungen.
--
-- 1. `samaccount_name` auf dem Telefonbuch: verknuepft den vom auth-Container
--    uebermittelten Windows-Benutzernamen (SamAccountName) mit dem lokalen,
--    per AD-Synchronisation importierten Eintrag.
-- 2. `navigation_item_permissions`: legt fest, welche Benutzer bzw. Gruppen
--    eine Kachel sehen duerfen. Eine Kachel ohne Berechtigungseintrag gilt als
--    oeffentlich/global und ist fuer alle sichtbar.

ALTER TABLE phonebook
    ADD COLUMN samaccount_name VARCHAR(64) NULL AFTER external_id;

ALTER TABLE phonebook
    ADD KEY idx_phonebook_samaccount (samaccount_name);

CREATE TABLE IF NOT EXISTS navigation_item_permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    navigation_id INT UNSIGNED NOT NULL,
    identity_type ENUM('user', 'group') NOT NULL DEFAULT 'user',
    user_id INT UNSIGNED NULL,
    group_name VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_nav_perm_user (navigation_id, user_id),
    UNIQUE KEY uniq_nav_perm_group (navigation_id, group_name),
    KEY idx_nav_perm_user (user_id),
    KEY idx_nav_perm_group (group_name),
    CONSTRAINT fk_nav_perm_navigation FOREIGN KEY (navigation_id)
        REFERENCES navigation_items (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_nav_perm_user FOREIGN KEY (user_id)
        REFERENCES phonebook (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
