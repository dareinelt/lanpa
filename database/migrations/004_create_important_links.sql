-- Wichtige Links: aufklappbarer Bereich unterhalb der Navigations-Kacheln.
-- Die Sortierung erfolgt ausschliesslich automatisch alphabetisch nach Titel;
-- eine manuelle Reihenfolge wird bewusst nicht im Adminbereich angeboten.
CREATE TABLE IF NOT EXISTS important_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(120) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    icon_file VARCHAR(64) NULL,
    icon_mime VARCHAR(64) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_important_links_active_title (active, title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
