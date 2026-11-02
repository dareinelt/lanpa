-- 031_helpdesk_task_html.sql – HTML-Fassung beim Umwandeln eines Tickets in eine Aufgabe
-- Per E-Mail eingegangene Tickets und Kommentare tragen seit 026 eine bereinigte HTML-Fassung.
-- Damit Formatierung, Tabellen und Bilder beim Umwandeln nicht verloren gehen, erhalten die
-- Beschreibung der Aufgabe und die übernommenen Journal-Einträge ebenfalls eine HTML-Spalte.
-- NULL heißt: keine HTML-Fassung, die Anzeige erfolgt aus dem Klartext.
-- Zeitstempel in UTC, alle Tabellen InnoDB / utf8mb4.

SET NAMES utf8mb4;

ALTER TABLE tasks
    ADD COLUMN description_html MEDIUMTEXT NULL DEFAULT NULL AFTER description;

ALTER TABLE task_journal
    ADD COLUMN body_html MEDIUMTEXT NULL DEFAULT NULL AFTER body;
