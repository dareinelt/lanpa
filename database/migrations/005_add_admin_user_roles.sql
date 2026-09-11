-- Benutzerverwaltung: Rollen fuer Administrationskonten.
-- "admin" darf alles im Adminbereich; "redaktion" darf ausschliesslich die
-- wichtigen Links bearbeiten und sieht keinen anderen Bereich.
ALTER TABLE admin_users
    ADD COLUMN role ENUM('admin', 'redaktion') NOT NULL DEFAULT 'admin' AFTER username;
