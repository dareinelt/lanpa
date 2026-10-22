-- Icon-Katalog fuer Navigationskacheln: eigene SVG-Icons werden im
-- Adminbereich unter einem Alias gepflegt und im Icon-Dropdown der
-- Kachel-Einstellung zusaetzlich zu den eingebauten Icons angeboten.
CREATE TABLE IF NOT EXISTS navigation_icons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    alias VARCHAR(32) NOT NULL,
    file_name VARCHAR(120) NOT NULL,
    mime_type VARCHAR(64) NOT NULL DEFAULT 'image/svg+xml',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_navigation_icons_alias (alias)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
