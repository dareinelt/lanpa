-- Notfallnummern, die auf der Telefonliste vor einer Suche als Kacheln angezeigt werden.
CREATE TABLE IF NOT EXISTS emergency_numbers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    label VARCHAR(120) NOT NULL,
    phone VARCHAR(64) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_emergency_numbers_active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
