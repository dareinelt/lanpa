-- Ein-/Ausblenden einzelner Telefonbuch-Eintraege im Adminbereich.
-- 1 = eingeblendet (Standard, auch fuer neu synchronisierte Eintraege).
ALTER TABLE phonebook
    ADD COLUMN visible TINYINT(1) NOT NULL DEFAULT 1 AFTER active;
