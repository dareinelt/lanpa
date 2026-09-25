-- Office-Apps (Euro-Office-Webapps, Dateien, Outlook Web App) und deren
-- Freigabe ueber AD-Gruppen.
--
-- Die Apps selbst sind im Code festgelegt (OfficeAppCatalog, Schluessel wie
-- "document" oder "owa"). Hier stehen nur
--   1. App-Pakete: frei benannte Buendel mehrerer Apps,
--   2. Freigaben: AD-Gruppe -> einzelne App oder App-Paket.
-- Eine App ohne Freigabe (weder direkt noch ueber ein Paket) ist fuer
-- niemanden sichtbar; nicht angemeldete Nutzer erhalten nie Office-Apps.

CREATE TABLE IF NOT EXISTS office_app_packages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_office_app_packages_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office_app_package_apps (
    package_id INT UNSIGNED NOT NULL,
    app_key VARCHAR(32) NOT NULL,
    PRIMARY KEY (package_id, app_key),
    CONSTRAINT fk_office_package_apps_package FOREIGN KEY (package_id)
        REFERENCES office_app_packages (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office_app_permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_name VARCHAR(190) NOT NULL,
    app_key VARCHAR(32) NULL,
    package_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_office_perm_app (app_key, group_name),
    UNIQUE KEY uniq_office_perm_package (package_id, group_name),
    KEY idx_office_perm_group (group_name),
    CONSTRAINT fk_office_perm_package FOREIGN KEY (package_id)
        REFERENCES office_app_packages (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
