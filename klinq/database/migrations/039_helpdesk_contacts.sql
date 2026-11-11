-- 039_helpdesk_contacts.sql – Gemeinsames Kontaktverzeichnis des Help Desk
-- Ein über alle Agenten geteiltes Adressbuch wichtiger Kontakte (Dienstleister, Hersteller-Hotlines,
-- Ansprechpartner externer Firmen). Pflicht sind Firma, Vorname und Name sowie mindestens eine
-- Rufnummer (Telefon oder Mobil) oder eine E-Mail-Adresse – diese Regel prüft der
-- HelpdeskContactService. Stichworte (Tags) liegen je Kontakt in helpdesk_contact_tags und werden
-- beim Löschen des Kontakts mit entfernt.
-- Zeitstempel in UTC, alle Tabellen InnoDB / utf8mb4.

SET NAMES utf8mb4;

CREATE TABLE helpdesk_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company VARCHAR(200) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(50) NULL,
    mobile VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_by_name VARCHAR(200) NOT NULL DEFAULT 'system',
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_helpdesk_contacts_sort (company, last_name, first_name),
    CONSTRAINT fk_helpdesk_contacts_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_helpdesk_contacts_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE helpdesk_contact_tags (
    contact_id INT UNSIGNED NOT NULL,
    tag VARCHAR(50) NOT NULL,
    PRIMARY KEY (contact_id, tag),
    KEY idx_helpdesk_contact_tags_tag (tag),
    CONSTRAINT fk_helpdesk_contact_tags_contact FOREIGN KEY (contact_id) REFERENCES helpdesk_contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
