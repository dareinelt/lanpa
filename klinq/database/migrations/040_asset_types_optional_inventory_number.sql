-- 040_asset_types_optional_inventory_number.sql – Assettypen ohne Inventarnummer.
-- requires_inventory_number = 0: Assets dieses Typs erhalten keine Inventarnummer
-- (assets.inventory_number bleibt NULL, keine Sequenz wird verbraucht); der Typ
-- benötigt dann auch kein Inventarnummern-Präfix. Bestehende Typen behalten 1.
-- Der eindeutige Index auf inventory_number erlaubt beliebig viele NULL-Werte.

ALTER TABLE asset_types
    ADD COLUMN requires_inventory_number TINYINT(1) NOT NULL DEFAULT 1 AFTER inventory_prefix,
    MODIFY COLUMN inventory_prefix VARCHAR(10) NULL;

ALTER TABLE assets
    MODIFY COLUMN inventory_number VARCHAR(20) NULL;
