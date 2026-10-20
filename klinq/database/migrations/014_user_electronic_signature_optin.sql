-- 014_user_electronic_signature_optin.sql – Opt-in je Benutzer: Digitale Signatur (Passwort-Bestätigung)
-- für die Desktop-Erfassung von Entnahme/Rückgabe darf nur von Benutzern verwendet werden, denen dies
-- in der Benutzerverwaltung explizit erlaubt wurde.

ALTER TABLE users
    ADD COLUMN can_sign_electronically TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
