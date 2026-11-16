-- 047_local_user_overrides_and_supplier_group.sql – Lokale Rollen-/Gruppenvergabe in KLINQ und Berechtigungsgruppe „lieferanten“
-- 1. Lokal unter „Administration → Benutzer“ gesetzte Rollen (role_override = 1, Vergleich gegen sso_role_id)
--    und manuell zugewiesene Berechtigungsgruppen (source = 'manual') bleiben beim erneuten Einstieg
--    über das Intranet (LANPA-SSO) erhalten; AD-/SSO-Gruppen (source = 'sso') werden getrennt abgeglichen.
-- 2. Neue additive Berechtigungsgruppe „lieferanten“ für das Anlegen und Verwalten von Lieferanten.

SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN sso_role_id INT UNSIGNED NULL DEFAULT NULL AFTER role_id,
    ADD COLUMN role_override TINYINT(1) NOT NULL DEFAULT 0 AFTER sso_role_id,
    ADD CONSTRAINT fk_users_sso_role FOREIGN KEY (sso_role_id) REFERENCES roles (id);

ALTER TABLE user_permission_groups
    ADD COLUMN source ENUM('manual','sso') NOT NULL DEFAULT 'manual' AFTER group_id;

UPDATE user_permission_groups upg
INNER JOIN users u ON u.id = upg.user_id
SET upg.source = 'sso'
WHERE u.auth_source = 'ldap';

INSERT INTO permission_groups (name, label) VALUES
    ('lieferanten', 'Darf Lieferanten anlegen')
ON DUPLICATE KEY UPDATE name = name;
