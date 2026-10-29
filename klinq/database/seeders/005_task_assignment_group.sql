-- Berechtigungsgruppe „Aufgabenverteilung“ (idempotent).
-- Mitglieder dürfen Aufgaben aus „Alle Aufgaben“ einem bestimmten Agenten zuweisen.
-- Die AD-Gruppe wird im Intranet (LANPA) unter KLINQ → Zugriff gepflegt und beim Login
-- als Berechtigungsgruppe übergeben (siehe config/permissions.php).
INSERT INTO permission_groups (name, label) VALUES
    ('aufgabenverteilung', 'Darf Aufgaben zuweisen')
ON DUPLICATE KEY UPDATE label = VALUES(label);
