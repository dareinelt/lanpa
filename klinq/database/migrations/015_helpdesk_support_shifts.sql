-- 015_helpdesk_support_shifts.sql – Tagesweise Zuständigkeit für 1st-/2nd-Level-Support.
-- Ein Agent meldet sich über das Dashboard für den 1st- oder 2nd-Level-Support des Tages zuständig
-- (Overlay „Zuständigkeit“). Je Datum/Level ist maximal ein Eintrag aktiv (is_active = 1); eine erneute
-- Übernahme beendet den vorherigen Eintrag (ended_at gesetzt), sodass die Historie für Auswertungen
-- erhalten bleibt. Die Zuordnung endet automatisch um 19:00 Uhr (APP_TIMEZONE) über den Scheduler
-- (bin/helpdesk.php process → HelpdeskSchedulerService).

CREATE TABLE helpdesk_support_shifts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shift_date DATE NOT NULL,
    level TINYINT UNSIGNED NOT NULL COMMENT '1 = 1st Level, 2 = 2nd Level',
    user_id INT UNSIGNED NOT NULL,
    claimed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ends_at TIMESTAMP NOT NULL,
    ended_at TIMESTAMP NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_helpdesk_support_shifts_active (shift_date, level, is_active),
    KEY idx_helpdesk_support_shifts_user (user_id),
    CONSTRAINT fk_helpdesk_support_shifts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
