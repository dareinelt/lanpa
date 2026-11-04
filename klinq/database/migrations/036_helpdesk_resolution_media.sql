-- 036_helpdesk_resolution_media.sql – Lösungstext im Abschlussdialog mit eingefügten Bildern
-- Im Abschlussdialog eines Tickets lassen sich Screenshots wie in Beschreibung und Kommentaren
-- per STRG+V einfügen. Die Bilder stehen als Base64 im Text; TEXT (64 KB) ist dafür zu klein.
-- Die Lösung erhält deshalb dieselbe Obergrenze wie Beschreibung und Kommentare (MEDIUMTEXT).
-- Zeitstempel in UTC, alle Tabellen InnoDB / utf8mb4.

SET NAMES utf8mb4;

ALTER TABLE tickets
    MODIFY COLUMN resolution MEDIUMTEXT NULL;
