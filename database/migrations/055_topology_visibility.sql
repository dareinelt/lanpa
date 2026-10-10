-- Persoenliche Anzeigeeinstellungen je Administrationskonto.
--
-- Die Tabelle haelt kleine Einstellungen, die nur fuer eine Person gelten.
-- Erster Verwendungszweck sind die im Entwurfsmodus der Topologie-Ansicht
-- ausgeblendeten Bausteine (Schluessel topology_hidden, Wert als JSON:
-- {"nodes":[...],"groups":[...]}). Sie ist bewusst generisch angelegt, damit
-- weitere persoenliche Einstellungen ohne neue Migration ergaenzt werden
-- koennen.
--
--   username         Kennung des Kontos. Bewusst ohne Fremdschluessel auf
--                    admin_users: neben lokalen Konten melden sich Konten der
--                    Windows-Anmeldung (AD-Gruppen, siehe 021_admin_groups.sql)
--                    am Administrationsbereich an und haben dort keine Zeile.
--   preference_key   Name der Einstellung
--                    (siehe App\Services\Topology\TopologyVisibilityService)
--   preference_value Wert; bei Anzeigeeinstellungen JSON, sonst Klartext
--
-- Globale Einstellungen liegen weiterhin in der Tabelle settings (dort
-- Schluessel topology_hidden, siehe App\Services\SettingsService).
CREATE TABLE IF NOT EXISTS admin_user_preferences (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(190) NOT NULL,
    preference_key VARCHAR(64) NOT NULL,
    preference_value TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_admin_user_preference (username, preference_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
