-- Orvanta: E-Mail-Signaturvorlagen.
--
-- Die Position (AD-Attribut "title") wird kuenftig mit synchronisiert, damit
-- Signaturen Name, Position und Abteilung aus dem Active Directory beziehen.
ALTER TABLE phonebook
    ADD COLUMN title VARCHAR(120) NULL AFTER last_name;

-- Vorlagen werden im Adminbereich gepflegt; die Zuordnung zu Mitarbeitern
-- erfolgt ueber AD-Gruppen (Namen wie bei den Office-App-Freigaben). Adresse
-- und optionaler Rufnummern-Praefix stammen aus der Vorlage, die uebrigen
-- Angaben aus dem Active Directory. Farben und Logo kommen zur Laufzeit aus
-- den Designeinstellungen.
CREATE TABLE orvanta_signatures (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    greeting VARCHAR(120) NOT NULL DEFAULT 'Mit freundlichen Grüßen',
    street VARCHAR(190) NOT NULL DEFAULT '',
    postal_city VARCHAR(190) NOT NULL DEFAULT '',
    phone_mode ENUM('prefix', 'full') NOT NULL DEFAULT 'prefix',
    phone_prefix VARCHAR(64) NOT NULL DEFAULT '',
    ad_groups TEXT NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_orvanta_signatures_active (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
