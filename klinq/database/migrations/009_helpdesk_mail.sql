-- Help Desk: E-Mail-Eingang (IMAP) mit Threading über Ticketnummer und Message-ID
ALTER TABLE tickets
    ADD COLUMN requester_email VARCHAR(255) NULL AFTER requester_user_id,
    ADD COLUMN mail_message_id VARCHAR(255) NULL AFTER external_reference,
    ADD KEY idx_tickets_mail_message (mail_message_id);

ALTER TABLE ticket_comments
    ADD COLUMN mail_message_id VARCHAR(255) NULL AFTER source,
    ADD KEY idx_ticket_comments_mail_message (mail_message_id);

ALTER TABLE ticket_notifications
    ADD COLUMN message_id VARCHAR(255) NULL AFTER error,
    ADD KEY idx_ticket_notifications_message (message_id);

-- Protokoll aller abgeholten Nachrichten: Dedupe über Message-ID und Nachvollziehbarkeit
CREATE TABLE IF NOT EXISTS ticket_inbound_mails (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(255) NOT NULL,
    mailbox_uid VARCHAR(64) NULL,
    from_address VARCHAR(255) NOT NULL DEFAULT '',
    subject VARCHAR(255) NOT NULL DEFAULT '',
    action ENUM('created','comment','ignored','failed') NOT NULL,
    ticket_id INT UNSIGNED NULL,
    comment_id INT UNSIGNED NULL,
    matched_by ENUM('reference','subject','none') NOT NULL DEFAULT 'none',
    detail VARCHAR(500) NULL,
    received_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_inbound_mails_message (message_id),
    KEY idx_ticket_inbound_mails_ticket (ticket_id),
    KEY idx_ticket_inbound_mails_created (created_at),
    CONSTRAINT fk_ticket_inbound_mails_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
