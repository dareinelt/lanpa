-- 033_user_task_sort.sql – Zuletzt gewählte Sortierung der Aufgabenliste je Benutzer.
-- Spalte und Richtung werden serverseitig gespeichert und beim nächsten Öffnen der
-- Aufgabenliste („Alle Aufgaben“ und „Meine Aufgaben“) wieder angewendet – unabhängig
-- von PC oder Browser (wie die Sortierung der Ticketliste). NULL = noch keine Wahl
-- getroffen, dann gilt die Standardsortierung (Aktualisiert, absteigend).

ALTER TABLE users
    ADD COLUMN task_sort VARCHAR(32) NULL DEFAULT NULL AFTER ticket_sort_dir,
    ADD COLUMN task_sort_dir VARCHAR(4) NULL DEFAULT NULL AFTER task_sort;
