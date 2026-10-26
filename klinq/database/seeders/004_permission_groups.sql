-- Berechtigungsgruppen (idempotent). Zusätzlich zur Rolle zuweisbare Rechte;
-- „Statistik“ gewährt Zugriff auf die Help-Desk-Ticket-Berichte, „ticketaufruf“ erlaubt das
-- Auswerten der Ticketaufrufe, „ticket_loeschen“ und „ticket_stornieren“ geben das Löschen
-- bzw. Stornieren von Tickets frei (siehe config/permissions.php).
INSERT INTO permission_groups (name, label) VALUES
    ('statistik', 'Statistik'),
    ('ticketaufruf', 'Darf Ticketaufruf auswerten'),
    ('ticket_loeschen', 'Darf Tickets löschen'),
    ('ticket_stornieren', 'Darf Tickets stornieren')
ON DUPLICATE KEY UPDATE label = VALUES(label);
