-- 016_helpdesk_second_level_reminders.sql – Tagesabschluss-Erinnerung für den 2nd-Level-Support.
-- Wer sich für einen Tag als 2nd Level zuständig meldet, erhält für alle offenen Tickets, die nicht
-- vom betreffenden Tag stammen und an diesem Tag noch nicht von ihm kommentiert wurden, eine
-- In-App-Benachrichtigung (Glocke in der Kopfzeile). Wird bis 23:59 Uhr (APP_TIMEZONE) nicht kommentiert,
-- erzeugt der Scheduler automatisch einen internen Kommentar „Zuständig am … war …, kein Kommentar erfolgt.“
-- reminder_finalized_at markiert den Abschluss je Tag/Level, damit der Job (bin/helpdesk.php process)
-- idempotent bleibt und ein Tag nur einmal verarbeitet wird.

ALTER TABLE helpdesk_support_shifts
    ADD COLUMN reminder_finalized_at TIMESTAMP NULL AFTER is_active,
    ADD KEY idx_helpdesk_support_shifts_reminder (level, shift_date, reminder_finalized_at);
