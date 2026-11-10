-- 038_helpdesk_calendar.sql – Kalender des Help Desk
-- Sammelt alle für den Help Desk relevanten Termine. Termine werden im Kalender manuell angelegt
-- oder aus einem Ticket erzeugt (Datumsvorschlag aus dem Tickettext über den KI-Endpunkt).
-- `ticket_id` verknüpft den Termin mit dem Ursprungsticket; wird das Ticket gelöscht, bleibt der
-- Termin erhalten (ticket_id → NULL). Ganztägige Termine speichern den lokalen Tagesbeginn
-- (APP_TIMEZONE) als starts_at und den Beginn des Folgetags als ends_at.
-- Zeitstempel in UTC, alle Tabellen InnoDB / utf8mb4.

SET NAMES utf8mb4;

CREATE TABLE helpdesk_calendar_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    location VARCHAR(200) NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    ticket_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_by_name VARCHAR(200) NOT NULL DEFAULT 'system',
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_helpdesk_calendar_range (starts_at, ends_at),
    KEY idx_helpdesk_calendar_ticket (ticket_id),
    CONSTRAINT fk_helpdesk_calendar_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
    CONSTRAINT fk_helpdesk_calendar_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_helpdesk_calendar_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
