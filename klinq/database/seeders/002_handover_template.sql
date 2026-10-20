-- Standardvorlage für das Übergabeprotokoll (Baukasten-Blöcke). Wird nur angelegt,
-- wenn noch keine Vorlage existiert – Anpassungen des Admins bleiben erhalten.
INSERT INTO handover_templates (name, blocks, is_default)
SELECT 'Standard', JSON_ARRAY(
    JSON_OBJECT('type', 'heading', 'text', 'Übergabeprotokoll {protokollnummer}', 'level', 1),
    JSON_OBJECT('type', 'meta', 'fields', JSON_ARRAY('protocol_number', 'version', 'date', 'company', 'issuer')),
    JSON_OBJECT('type', 'employee', 'title', 'Mitarbeiter', 'fields', JSON_ARRAY('display_name', 'personnel_number', 'department', 'position', 'email', 'phone', 'location', 'cost_center')),
    JSON_OBJECT('type', 'assets', 'title', 'Überlassene Arbeitsmittel', 'columns', JSON_ARRAY('inventory_number', 'article', 'serial_number', 'asset_type', 'assigned_at'), 'empty_text', 'Derzeit sind keine protokollrelevanten Arbeitsmittel zugeordnet.'),
    JSON_OBJECT('type', 'text', 'style', 'normal', 'text', 'Die oben aufgeführten Arbeitsmittel wurden {mitarbeiter} von {firma} zur dienstlichen Nutzung überlassen. Sie bleiben Eigentum von {firma} und sind bei Beendigung des Arbeitsverhältnisses oder auf Aufforderung unverzüglich und vollständig zurückzugeben.'),
    JSON_OBJECT('type', 'text', 'style', 'normal', 'text', 'Die Arbeitsmittel sind pfleglich zu behandeln. Verlust, Diebstahl oder Beschädigung sind unverzüglich der IT zu melden. Eine private Nutzung ist nur im Rahmen der geltenden Nutzungsrichtlinie gestattet.'),
    JSON_OBJECT('type', 'confirmation', 'required', TRUE, 'text', 'Ich bestätige den Erhalt der aufgeführten Arbeitsmittel in ordnungsgemäßem Zustand und habe die Hinweise gelesen.'),
    JSON_OBJECT('type', 'signature', 'party', 'employee', 'label', 'Unterschrift Mitarbeiter'),
    JSON_OBJECT('type', 'text', 'style', 'small', 'text', 'Dieses Protokoll ersetzt alle vorherigen Versionen. Ausgestellt am {datum} um {uhrzeit} Uhr durch {aussteller}.')
), 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM handover_templates);
