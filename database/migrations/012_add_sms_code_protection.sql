-- SMS-Code-Schutz: geschuetzter Zugriffsmodus je Navigationselement und
-- Verwaltung der erlaubten Aktivierungs-Rufnummern.
ALTER TABLE navigation_items
    ADD COLUMN protected_access TINYINT(1) NOT NULL DEFAULT 0 AFTER alarm_group_id;

CREATE TABLE IF NOT EXISTS activation_numbers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone VARCHAR(64) NOT NULL,
    phone_digits VARCHAR(32) NOT NULL,
    alarm_group_id INT UNSIGNED NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_activation_numbers_phone (phone_digits),
    KEY idx_activation_numbers_active_sort (active, sort_order),
    CONSTRAINT fk_activation_numbers_alarm_group FOREIGN KEY (alarm_group_id)
        REFERENCES alarm_groups (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
