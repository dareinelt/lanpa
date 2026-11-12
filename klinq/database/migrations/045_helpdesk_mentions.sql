-- 045_helpdesk_mentions.sql – Erwähnungen von Agenten in internen Kommentaren
-- Agenten können in internen Notizen zu Tickets und in Aufgabenkommentaren andere Agenten per
-- „@Name“ markieren. Je markiertem Agenten und Kommentar entsteht hier ein Eintrag, der als
-- Benachrichtigung auf dem Help-Desk-Dashboard des Empfängers erscheint, bis er gelesen ist
-- (Öffnen des Tickets/der Aufgabe oder „Gelesen“ auf dem Dashboard). Genau eines der Felder
-- ticket_id/task_id ist gesetzt; comment_id verweist je nach Bezug auf ticket_comments bzw.
-- task_comments. Zeitstempel in UTC, InnoDB / utf8mb4.

SET NAMES utf8mb4;

CREATE TABLE helpdesk_mentions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ticket_id INT UNSIGNED NULL,
    task_id INT UNSIGNED NULL,
    comment_id BIGINT UNSIGNED NOT NULL,
    mentioned_by INT UNSIGNED NULL,
    mentioned_by_name VARCHAR(200) NOT NULL DEFAULT 'system',
    excerpt VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_helpdesk_mentions_user (user_id, read_at, created_at),
    KEY idx_helpdesk_mentions_ticket (ticket_id),
    KEY idx_helpdesk_mentions_task (task_id),
    CONSTRAINT fk_helpdesk_mentions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_helpdesk_mentions_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_helpdesk_mentions_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_helpdesk_mentions_by FOREIGN KEY (mentioned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
