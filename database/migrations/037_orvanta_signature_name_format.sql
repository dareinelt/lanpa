-- Orvanta: Darstellung des Namens je Signaturvorlage.
--
-- "first_last" = "Vorname Nachname", "last_first" = "Nachname, Vorname".
-- Vor- und Nachname stammen aus der Telefonliste (AD-Attribute givenName/sn);
-- fehlt einer davon, wird der AD-Anzeigename unveraendert verwendet.
ALTER TABLE orvanta_signatures
    ADD COLUMN name_format ENUM('first_last', 'last_first') NOT NULL DEFAULT 'first_last' AFTER greeting;
