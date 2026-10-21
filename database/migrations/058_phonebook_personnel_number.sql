-- Personalnummer aus den Identitaetsquellen (AD-Attribut employeeNumber).
--
-- Die Personalnummer wird mit synchronisiert, damit KLINQ die Felder "Melder"
-- und "Betroffener Mitarbeiter" auch danach durchsuchen kann. Das Attribut ist
-- je Quelle im Attributmapping konfigurierbar (Standard: employeeNumber).
ALTER TABLE phonebook
    ADD COLUMN personnel_number VARCHAR(64) NULL AFTER title;

ALTER TABLE phonebook
    ADD KEY idx_phonebook_personnel (personnel_number);
