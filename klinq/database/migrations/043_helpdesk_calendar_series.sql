-- 043_helpdesk_calendar_series.sql – Serientermine im Help-Desk-Kalender
-- Ein Serientermin steht genau einmal in der Tabelle; die Wiederholung wird beim Lesen aufgefächert
-- (`HelpdeskCalendarService::eventsIn()`), damit Änderungen an der Serie nur eine Zeile betreffen.
--   recurrence            '' = Einzeltermin, sonst daily|weekly|monthly|yearly
--   recurrence_interval   Abstand in Tagen/Wochen/Monaten/Jahren (1 = jede Woche usw.)
--   recurrence_until      letzter Tag der Serie (lokal, APP_TIMEZONE); NULL = unbefristet
-- Abweichende Einzeltermine einer Serie (verschoben, geändert oder gelöscht) verweisen über
-- `series_parent_id` auf den Serientermin; `series_date` ist der ursprüngliche Termintag, an dem
-- statt des Serientermins diese Zeile gilt. `series_canceled = 1` blendet den Termin an diesem Tag aus.
-- Alle Zeitstempel weiterhin in UTC; das Löschen des Serientermins entfernt seine Einzeltermine.

SET NAMES utf8mb4;

ALTER TABLE helpdesk_calendar_events
    ADD COLUMN recurrence VARCHAR(10) NOT NULL DEFAULT '' AFTER all_day,
    ADD COLUMN recurrence_interval TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER recurrence,
    ADD COLUMN recurrence_until DATE NULL AFTER recurrence_interval,
    ADD COLUMN series_parent_id INT UNSIGNED NULL AFTER recurrence_until,
    ADD COLUMN series_date DATE NULL AFTER series_parent_id,
    ADD COLUMN series_canceled TINYINT(1) NOT NULL DEFAULT 0 AFTER series_date,
    ADD KEY idx_helpdesk_calendar_recurrence (recurrence, starts_at),
    ADD KEY idx_helpdesk_calendar_series (series_parent_id, series_date),
    ADD CONSTRAINT fk_helpdesk_calendar_series FOREIGN KEY (series_parent_id) REFERENCES helpdesk_calendar_events(id) ON DELETE CASCADE;
