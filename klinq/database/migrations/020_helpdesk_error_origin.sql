-- 020_helpdesk_error_origin.sql – Fehlerursprung beim Schließen eines Tickets.
-- Der Schließen-Dialog bietet zusätzlich zum Schließungsgrund einen Fehlerursprung an. Die Auswahl
-- stammt aus dem Katalog in den Help-Desk-Einstellungen (Reiter „Fehlerursprung“, settings-Präfix
-- helpdesk.error_origin.); der gewählte Wert wird am Ticket gespeichert und steht im Schließungstext
-- als Platzhalter {fehlerursprung} zur Verfügung.

ALTER TABLE tickets
    ADD COLUMN error_origin VARCHAR(120) NULL AFTER close_reason;
