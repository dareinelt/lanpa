-- 049_articles_consumable_without_asset_type.sql – Verbrauchsmaterial ohne Assettyp.
-- Verbrauchsmaterial wird nur als Menge geführt und gehört zu keinem Assettyp; die
-- Kategorie hängt am Typ und entfällt deshalb ebenfalls. Bestehende Verbrauchsartikel
-- ohne zugeordnete Assets werden auf NULL gesetzt (Spalte bleibt nullable, FK und Index
-- bestehen weiter; Altbestände mit historischen Asset-Zuordnungen bleiben unangetastet).

ALTER TABLE articles
    MODIFY COLUMN asset_type_id INT UNSIGNED NULL;

UPDATE articles
SET asset_type_id = NULL,
    asset_category_id = NULL
WHERE is_consumable = 1
  AND NOT EXISTS (SELECT 1 FROM assets s WHERE s.article_id = articles.id);
