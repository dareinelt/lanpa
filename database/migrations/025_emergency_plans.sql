ALTER TABLE admin_users MODIFY COLUMN role ENUM('admin', 'redaktion', 'kaep') NOT NULL DEFAULT 'admin';

CREATE TABLE emergency_plans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    published TINYINT NOT NULL DEFAULT 0,
    revision INT NOT NULL DEFAULT 1,
    definition MEDIUMTEXT NOT NULL,
    updated_by VARCHAR(190) NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE emergency_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id INT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    actor VARCHAR(190) NOT NULL,
    request_key CHAR(64) NOT NULL,
    snapshot MEDIUMTEXT NOT NULL,
    state MEDIUMTEXT NOT NULL,
    revision INT NOT NULL DEFAULT 1,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    started_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    UNIQUE KEY emergency_request (request_key),
    KEY emergency_actor (actor, started_at),
    KEY emergency_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE emergency_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    node_id VARCHAR(64) NOT NULL DEFAULT '',
    actor VARCHAR(190) NOT NULL,
    action VARCHAR(32) NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY emergency_log_event (event_id, id),
    FOREIGN KEY (event_id) REFERENCES emergency_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE emergency_sms (
    event_id INT UNSIGNED NOT NULL,
    node_id VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL,
    message TEXT NOT NULL,
    PRIMARY KEY (event_id, node_id),
    FOREIGN KEY (event_id) REFERENCES emergency_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE emergency_password_attempts (
    actor VARCHAR(190) NOT NULL PRIMARY KEY,
    window_start BIGINT NOT NULL,
    attempts INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
