-- 044_helpdesk_calendar_checklist.sql – Checklisten an Terminen des Help-Desk-Kalenders
-- Der Ersteller eines Termins kann optional eine Checkliste anlegen („Checkliste anlegen“); der
-- bearbeitende Agent hakt jeden Punkt als „Bestätigt“ ab.
--   helpdesk_calendar_checklist_items          Punkte je Termin; bei Serienterminen am Serientermin
--                                              (gilt für alle Vorkommen der Serie)
--   helpdesk_calendar_checklist_confirmations  Bestätigung je Punkt und Vorkommen; `occurrence_key` ist
--                                              '' beim Einzeltermin, sonst der lokale Termintag (Y-m-d)
--                                              des Vorkommens der Serie
-- Das Löschen des Termins entfernt Punkte und Bestätigungen. Zeitstempel in UTC.

SET NAMES utf8mb4;

CREATE TABLE helpdesk_calendar_checklist_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    label VARCHAR(300) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_helpdesk_calendar_checklist_event (event_id, position),
    CONSTRAINT fk_helpdesk_calendar_checklist_event FOREIGN KEY (event_id) REFERENCES helpdesk_calendar_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE helpdesk_calendar_checklist_confirmations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id INT UNSIGNED NOT NULL,
    occurrence_key VARCHAR(10) NOT NULL DEFAULT '',
    confirmed_by INT UNSIGNED NULL,
    confirmed_by_name VARCHAR(200) NOT NULL DEFAULT 'system',
    confirmed_at DATETIME NOT NULL,
    UNIQUE KEY uq_helpdesk_calendar_checklist_confirmation (item_id, occurrence_key),
    CONSTRAINT fk_helpdesk_calendar_checklist_confirmation_item FOREIGN KEY (item_id) REFERENCES helpdesk_calendar_checklist_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_helpdesk_calendar_checklist_confirmation_user FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
