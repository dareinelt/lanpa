-- Help Desk: Anzeigename des Melders am Ticket
-- Bisher wurden nur Konto, Rechner, IP, Telefon und Abteilung der automatisch
-- erkannten Meldung gespeichert. Ohne Mitarbeiter- oder Benutzerdatensatz in KLINQ
-- (etwa bei Meldungen ueber /stoerung, deren Person nur im Intranet-Telefonbuch
-- steht) zeigte die Ticketliste deshalb nur die E-Mail-Adresse. Der Name wird
-- jetzt bei der Anlage mitgeschrieben und dient Liste, Suche und CSV-Export als
-- Rueckfallebene, wenn keine Verknuepfung zu Mitarbeiter/Benutzer besteht.
ALTER TABLE tickets
    ADD COLUMN reporter_name VARCHAR(200) NULL AFTER requester_email;

-- Bestehende Meldungen aus dem Stoerungsformular: Dort steht der Meldername in
-- created_by_name, weil das Formular unter dem Systemkonto anlegt.
UPDATE tickets
   SET reporter_name = created_by_name
 WHERE source = 'form'
   AND reporter_name IS NULL
   AND created_by_name NOT IN ('', 'system', 'Störungsmeldung');
