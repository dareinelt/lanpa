-- Kategorien für Aufgaben (idempotent). Weitere Kategorien im Intranet unter
-- KLINQ → Help-Desk-Administration → Aufgaben-Kategorien pflegen.

INSERT INTO task_categories (name, sort_order) VALUES
    ('Parametrierung', 10),
    ('Unifi', 20),
    ('Arbeitsplatz', 30)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);
