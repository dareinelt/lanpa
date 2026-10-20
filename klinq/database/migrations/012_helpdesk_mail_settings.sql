-- Help Desk: Herkunft eingegangener E-Mails am Ticket und am Kommentar festhalten.
-- Die Absenderadresse wird getrennt von requester_email gespeichert, damit sie auch dann
-- erhalten bleibt, wenn das Ticket später einem Mitarbeiter mit anderer Adresse zugeordnet wird.
ALTER TABLE tickets
    ADD COLUMN mail_from_address VARCHAR(255) NULL AFTER mail_message_id,
    ADD COLUMN mail_from_name VARCHAR(150) NULL AFTER mail_from_address;

ALTER TABLE ticket_comments
    ADD COLUMN mail_from_address VARCHAR(255) NULL AFTER mail_message_id;

-- Bestandsdaten: Absender aus dem Eingangsprotokoll nachtragen
UPDATE tickets t
    JOIN ticket_inbound_mails m ON m.ticket_id = t.id AND m.action = 'created'
    SET t.mail_from_address = m.from_address
    WHERE t.source = 'email' AND t.mail_from_address IS NULL AND m.from_address <> '';

UPDATE ticket_comments c
    JOIN ticket_inbound_mails m ON m.comment_id = c.id AND m.action = 'comment'
    SET c.mail_from_address = m.from_address
    WHERE c.source = 'email' AND c.mail_from_address IS NULL AND m.from_address <> '';
