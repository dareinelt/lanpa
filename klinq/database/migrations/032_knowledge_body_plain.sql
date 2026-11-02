-- 032_knowledge_body_plain.sql – Suchfassung der Wissensdatenbank-Artikel ohne Bilddaten
-- Seit Bilder per STRG+V als Base64 im Artikeltext stehen, trafen kurze Suchbegriffe zufällig
-- die Bilddaten nahezu jedes bebilderten Artikels. Die Suche läuft deshalb über `body_plain`:
-- den Artikeltext ohne eingebettete Bilder. Die Anwendung pflegt die Spalte bei jedem Speichern
-- (KnowledgeBaseRepository); hier werden bestehende Artikel einmalig nachgezogen.
-- Zeitstempel in UTC, alle Tabellen InnoDB / utf8mb4.

SET NAMES utf8mb4;

ALTER TABLE knowledge_articles
    ADD COLUMN body_plain MEDIUMTEXT NULL DEFAULT NULL AFTER body;

UPDATE knowledge_articles
SET body_plain = REGEXP_REPLACE(
    REGEXP_REPLACE(body, '!\\[[^\\]\\r\\n]*\\]\\(\\s*data:image/[^)]*\\)', ' '),
    'data:image/[A-Za-z0-9.+-]+(;[^;,()\\s]*)*;base64,[A-Za-z0-9+/=]+',
    ' '
);
