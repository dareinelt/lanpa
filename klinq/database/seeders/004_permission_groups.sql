-- Berechtigungsgruppen (idempotent). Zusätzlich zur Rolle zuweisbare Rechte;
-- „Statistik“ gewährt Zugriff auf die Help-Desk-Ticket-Berichte, „ticketaufruf“ erlaubt das
-- Auswerten der Ticketaufrufe (siehe config/permissions.php).
INSERT INTO permission_groups (name, label) VALUES
    ('statistik', 'Statistik'),
    ('ticketaufruf', 'Darf Ticketaufruf auswerten')
ON DUPLICATE KEY UPDATE label = VALUES(label);
