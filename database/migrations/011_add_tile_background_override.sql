-- Opt-out-Flag: Abweichende Kachel-Hintergrundfarbe/-Deckkraft je Navigationselement
ALTER TABLE navigation_items
    ADD COLUMN override_background TINYINT(1) NOT NULL DEFAULT 0 AFTER background_opacity;
