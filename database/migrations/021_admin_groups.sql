-- Administratoren aus AD-Gruppen.
--
-- target = 'intranet':  Mitglieder (Stand der AD-Synchronisation, verschachtelt
--                       aufgeloest) duerfen sich per Windows-Anmeldung (SSO)
--                       am Adminbereich anmelden (Rolle admin).
-- target = 'nextcloud': Mitglieder werden in Nextcloud Mitglied der Gruppe
--                       "admin" (signierte Uebergabe an intranet_integration).
-- Lokale Administrationskonten (admin_users) bleiben davon unberuehrt.

CREATE TABLE IF NOT EXISTS admin_group_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    target VARCHAR(16) NOT NULL,
    group_name VARCHAR(190) NOT NULL,
    created_by VARCHAR(190) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_admin_group_rules (target, group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
