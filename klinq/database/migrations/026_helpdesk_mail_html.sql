-- 026_helpdesk_mail_html.sql – HTML-Fassung eingehender E-Mails für die Ticketansicht
-- Per E-Mail eingegangene Nachrichten werden zusätzlich zu ihrem Klartext als bereinigtes HTML
-- gespeichert, damit Beschreibung und Kommentare im Ticket so erscheinen wie versendet
-- (Formatierung, Tabellen, Bilder). Der Klartext in `description`/`body` bleibt maßgeblich für
-- Suche, Benachrichtigungen, API und Export; NULL in den HTML-Spalten heißt: keine HTML-Fassung,
-- die Anzeige erfolgt wie bisher als Text.
-- Zeitstempel in UTC, alle Tabellen InnoDB / utf8mb4.

SET NAMES utf8mb4;

ALTER TABLE tickets
    ADD COLUMN description_html MEDIUMTEXT NULL DEFAULT NULL AFTER description;

ALTER TABLE ticket_comments
    ADD COLUMN body_html MEDIUMTEXT NULL DEFAULT NULL AFTER body;
