-- Protokoll wichtiger administrativer Aenderungen (optional nutzbar).
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id INT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    subject VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_log_created (created_at),
    CONSTRAINT fk_audit_log_admin FOREIGN KEY (admin_user_id)
        REFERENCES admin_users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
