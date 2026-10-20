-- Stammdaten, die die Anwendung benötigt (idempotent).
INSERT INTO roles (name, label) VALUES
    ('admin', 'Administrator'),
    ('assetmanagement', 'Assetmanagement'),
    ('lager', 'Lager'),
    ('einkauf', 'Einkauf'),
    ('readonly', 'Nur lesen')
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT INTO asset_types (code, name, inventory_prefix, has_serial_number, has_mac_address, has_imei, supports_licenses, icon, sort_order) VALUES
    ('PC', 'PC / Endgerät', 'PC', 1, 1, 0, 1, 'laptop', 10),
    ('MD', 'Mobilgerät', 'MD', 1, 1, 1, 1, 'phone', 20),
    ('NET', 'Netzwerkgerät', 'NET', 1, 1, 0, 0, 'network', 30),
    ('ZUB', 'Zubehör / Peripherie', 'ZUB', 1, 0, 0, 0, 'box', 40)
ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order);

INSERT IGNORE INTO asset_categories (asset_type_id, name, sort_order)
SELECT t.id, c.name, c.sort_order FROM asset_types t
JOIN (
    SELECT 'PC' AS code, 'Desktop' AS name, 10 AS sort_order UNION ALL
    SELECT 'PC', 'Notebook', 20 UNION ALL
    SELECT 'PC', 'Workstation', 30 UNION ALL
    SELECT 'PC', 'Thin Client', 40 UNION ALL
    SELECT 'MD', 'Smartphone', 10 UNION ALL
    SELECT 'MD', 'Tablet', 20 UNION ALL
    SELECT 'NET', 'Switch', 10 UNION ALL
    SELECT 'NET', 'Router', 20 UNION ALL
    SELECT 'NET', 'Access Point', 30 UNION ALL
    SELECT 'NET', 'Firewall', 40 UNION ALL
    SELECT 'ZUB', 'Monitor', 10 UNION ALL
    SELECT 'ZUB', 'Dockingstation', 20 UNION ALL
    SELECT 'ZUB', 'Drucker', 30 UNION ALL
    SELECT 'ZUB', 'Tastatur', 40 UNION ALL
    SELECT 'ZUB', 'Maus', 50 UNION ALL
    SELECT 'ZUB', 'Headset', 60 UNION ALL
    SELECT 'ZUB', 'Netzteil', 70
) c ON c.code = t.code;

INSERT INTO asset_statuses (code, name, color, is_available, is_final, sort_order) VALUES
    ('in_stock', 'Lagerbestand', 'success', 1, 0, 10),
    ('issued', 'Ausgegeben', 'info', 0, 0, 20),
    ('return_expected', 'Rückgabe erwartet', 'warning', 0, 0, 30),
    ('defective', 'Defekt', 'danger', 0, 0, 40),
    ('repair', 'Reparatur', 'warning', 0, 0, 50),
    ('retired', 'Ausgemustert', 'neutral', 0, 1, 60),
    ('disposed', 'Entsorgt', 'neutral', 0, 1, 70)
ON DUPLICATE KEY UPDATE name = VALUES(name), color = VALUES(color), is_available = VALUES(is_available), is_final = VALUES(is_final), sort_order = VALUES(sort_order);

INSERT IGNORE INTO system_settings (`key`, value) VALUES
    ('label.company_name', ''),
    ('label.show_logo', '0'),
    ('label.logo_document_id', ''),
    ('label.font_size_company', '7'),
    ('label.font_size_inventory', '11'),
    ('label.qr_size_mm', '20'),
    ('label.qr_position', 'left'),
    ('label.extra_field', ''),
    ('label.extra_text', ''),
    ('label.width_mm', '45'),
    ('label.height_mm', '30'),
    ('label.padding_mm', '2');
