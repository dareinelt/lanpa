-- 024_helpdesk_comment_pins.sql – Anheften von Kommentaren
-- Einträge in der Kommunikation eines Tickets und Kommentare einer Aufgabe lassen sich anheften.
-- Angeheftete Einträge stehen oben in der Liste, untereinander weiterhin chronologisch; die
-- Anheftung lässt sich jederzeit wieder lösen. Zeitstempel in UTC, alle Tabellen InnoDB / utf8mb4.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Kommunikation eines Tickets: Pinnadel oben rechts am Eintrag.
-- ---------------------------------------------------------------------------
ALTER TABLE ticket_comments
    ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER source,
    ADD COLUMN pinned_at TIMESTAMP NULL DEFAULT NULL AFTER is_pinned;

-- ---------------------------------------------------------------------------
-- Kommentare einer Aufgabe: dieselbe Anheftung.
-- ---------------------------------------------------------------------------
ALTER TABLE task_comments
    ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER author_name,
    ADD COLUMN pinned_at TIMESTAMP NULL DEFAULT NULL AFTER is_pinned;
