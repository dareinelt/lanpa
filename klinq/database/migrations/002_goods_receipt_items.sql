-- Wareneingang: Notiz je Lieferposition, Lagerort der Lieferung, Verknüpfung Asset → Wareneingang
ALTER TABLE goods_receipt_items
    ADD COLUMN note VARCHAR(255) NULL AFTER serial_number;

ALTER TABLE goods_receipts
    ADD COLUMN location_id INT UNSIGNED NULL AFTER delivery_note_number,
    ADD CONSTRAINT fk_goods_receipts_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL;

ALTER TABLE assets
    ADD COLUMN goods_receipt_id INT UNSIGNED NULL AFTER purchase_order_item_id,
    ADD KEY idx_assets_receipt (goods_receipt_id),
    ADD CONSTRAINT fk_assets_receipt FOREIGN KEY (goods_receipt_id) REFERENCES goods_receipts(id) ON DELETE SET NULL;
