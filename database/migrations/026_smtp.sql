ALTER TABLE admin_users ADD COLUMN email VARCHAR(254) NOT NULL DEFAULT '';

CREATE TABLE mail_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NULL,
    recipient VARCHAR(254) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    attempts INT NOT NULL DEFAULT 0,
    available_at BIGINT NOT NULL,
    message VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    KEY mail_delivery (status, available_at),
    KEY mail_event (event_id),
    FOREIGN KEY (event_id) REFERENCES emergency_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
