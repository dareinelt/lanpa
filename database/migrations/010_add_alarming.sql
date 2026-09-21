-- Alarmierung: SMS-Gateway-Gruppen, Alarmierungsverlauf und Navigationstyp "alarm"
CREATE TABLE IF NOT EXISTS alarm_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_number VARCHAR(64) NOT NULL,
    description VARCHAR(255) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_alarm_groups_active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alarm_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    navigation_id INT UNSIGNED NULL,
    title VARCHAR(120) NOT NULL,
    alarm_text VARCHAR(255) NOT NULL,
    group_number VARCHAR(64) NOT NULL,
    group_description VARCHAR(255) NOT NULL DEFAULT '',
    status ENUM('success', 'error') NOT NULL DEFAULT 'error',
    message VARCHAR(1000) NULL,
    triggered_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_alarm_log_time (triggered_at),
    KEY idx_alarm_log_navigation_time (navigation_id, triggered_at),
    CONSTRAINT fk_alarm_log_navigation FOREIGN KEY (navigation_id)
        REFERENCES navigation_items (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE navigation_items
    MODIFY COLUMN type ENUM('external', 'internal', 'subpage', 'page', 'alarm') NOT NULL DEFAULT 'external',
    ADD COLUMN alarm_text VARCHAR(255) NULL AFTER content,
    ADD COLUMN alarm_group_id INT UNSIGNED NULL AFTER alarm_text,
    ADD CONSTRAINT fk_navigation_alarm_group FOREIGN KEY (alarm_group_id)
        REFERENCES alarm_groups (id) ON DELETE SET NULL ON UPDATE CASCADE;
