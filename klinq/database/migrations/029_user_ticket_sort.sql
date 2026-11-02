-- 029_user_ticket_sort.sql – Zuletzt gewählte Sortierung der Ticketliste je Benutzer.
-- Spalte und Richtung werden serverseitig gespeichert und beim nächsten Öffnen der
-- Ticketliste wieder angewendet – unabhängig von PC oder Browser (wie das zuletzt
-- geöffnete Modul). NULL = noch keine Wahl getroffen, dann gilt die Standardsortierung
-- (Aktualisiert, absteigend).

ALTER TABLE users
    ADD COLUMN ticket_sort VARCHAR(32) NULL DEFAULT NULL AFTER ticket_form_mode,
    ADD COLUMN ticket_sort_dir VARCHAR(4) NULL DEFAULT NULL AFTER ticket_sort;
