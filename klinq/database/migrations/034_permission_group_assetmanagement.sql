-- 034_permission_group_assetmanagement.sql – Berechtigungsgruppe „Asset-Management“
-- Das Intranet vergibt nur die höchste Rolle. Ein Help-Desk-Agent, der zugleich in der
-- AD-Gruppe „Asset-Management“ ist, erhielt deshalb nur die Agentenrolle und konnte z. B.
-- Standorte, Hersteller und Artikel nicht pflegen. Die Gruppe „assetmanagement“ wird nun
-- zusätzlich übergeben und gewährt die Rechte des Asset-Managements (config/permissions.php).
-- Idempotent; eine vorhandene Bezeichnung bleibt unverändert.

SET NAMES utf8mb4;

INSERT INTO permission_groups (name, label) VALUES
    ('assetmanagement', 'Asset-Management')
ON DUPLICATE KEY UPDATE name = name;
