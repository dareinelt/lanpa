-- 033_permission_groups_defaults.sql – Berechtigungsgruppen unabhängig von den Seedern anlegen
-- Bisher legten nur die Seeder (004/005) die Gruppen an. Liefen sie nicht (z. B. `--no-seed`),
-- fehlte etwa „aufgabenverteilung“: Die Intranet-Anmeldung verwarf die übergebene Gruppe
-- stillschweigend, und Mitglieder der AD-Gruppe durften trotzdem keine Aufgaben zuweisen.
-- Idempotent; vorhandene Bezeichnungen bleiben unverändert.

SET NAMES utf8mb4;

INSERT INTO permission_groups (name, label) VALUES
    ('statistik', 'Statistik'),
    ('ticketaufruf', 'Darf Ticketaufruf auswerten'),
    ('ticket_loeschen', 'Darf Tickets löschen'),
    ('ticket_stornieren', 'Darf Tickets stornieren'),
    ('aufgabenverteilung', 'Darf Aufgaben zuweisen')
ON DUPLICATE KEY UPDATE name = name;
