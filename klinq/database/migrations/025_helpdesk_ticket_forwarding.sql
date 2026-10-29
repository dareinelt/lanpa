-- 025_helpdesk_ticket_forwarding.sql – Weiterleitung eines Tickets an einen anderen Bearbeiter
-- Ein weitergeleitetes Ticket wechselt sofort zum neuen Bearbeiter, bleibt aber bis zur
-- Übernahme durch ihn markiert: Hinweis auf dem Help-Desk-Dashboard und farbliche
-- Hervorhebung in der Ticketliste. `forward_acknowledged_at` hält die Übernahme fest,
-- eine erneute Weiterleitung überschreibt die Markierung.
-- Zeitstempel in UTC, alle Tabellen InnoDB / utf8mb4.

SET NAMES utf8mb4;

ALTER TABLE tickets
    ADD COLUMN forwarded_to_user_id INT UNSIGNED NULL DEFAULT NULL AFTER assignee_user_id,
    ADD COLUMN forwarded_by_user_id INT UNSIGNED NULL DEFAULT NULL AFTER forwarded_to_user_id,
    ADD COLUMN forwarded_at TIMESTAMP NULL DEFAULT NULL AFTER forwarded_by_user_id,
    ADD COLUMN forward_acknowledged_at TIMESTAMP NULL DEFAULT NULL AFTER forwarded_at,
    ADD KEY idx_tickets_forwarded (forwarded_to_user_id, forward_acknowledged_at),
    ADD CONSTRAINT fk_tickets_forwarded_to FOREIGN KEY (forwarded_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_tickets_forwarded_by FOREIGN KEY (forwarded_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
