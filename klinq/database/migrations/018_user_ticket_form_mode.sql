-- 018_user_ticket_form_mode.sql – Bevorzugter Eingabemodus für neue Help-Desk-Tickets je Benutzer.
-- Der Benutzer wählt beim Anlegen zwischen klassischem Formular ('klassisch') und Assistent ('assistent').
-- Der zuletzt verwendete Modus wird serverseitig gespeichert und beim nächsten Aufruf vorausgewählt.
-- NULL = noch keine Wahl getroffen, dann bleibt das klassische Formular der Standard.

ALTER TABLE users
    ADD COLUMN ticket_form_mode VARCHAR(20) NULL DEFAULT NULL AFTER last_module;
