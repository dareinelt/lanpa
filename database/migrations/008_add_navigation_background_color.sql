-- Hintergrundfarbe für Navigationskacheln (optional, Transparenz wird beibehalten)
ALTER TABLE navigation_items
    ADD COLUMN background_color VARCHAR(7) NULL AFTER icon,
    ADD COLUMN background_opacity TINYINT UNSIGNED NULL AFTER background_color;
