-- 017_user_last_module.sql – Zuletzt geöffnetes Modul je Benutzer (Assetverwaltung oder Help Desk).
-- Beim nächsten Start wird dieses Modul wieder als Startbereich geöffnet. NULL = noch keine Wahl
-- getroffen, dann bleibt Assetverwaltung (/dashboard) der Standard.

ALTER TABLE users
    ADD COLUMN last_module VARCHAR(20) NULL DEFAULT NULL AFTER can_sign_electronically;
