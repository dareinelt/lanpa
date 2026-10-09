-- Orvanta: Abwesenheitsnotizen (Out-of-Office).
--
-- Vorlagen werden im Adminbereich gepflegt und ueber AD-Gruppen zugewiesen.
-- Jede Vorlage besteht aus einem festen, fuer den Benutzer schreibgeschuetzten
-- Text und einem dynamischen Beispieltext, den der Benutzer in Orvanta an
-- seine Vertretung anpasst. Beim Aktivieren wird der Text zusammen mit der
-- zugewiesenen Signatur auf den Exchange-Server uebertragen
-- (SetUserOofSettings); den Versand uebernimmt der Server, Orvanta muss
-- dafuer nicht geoeffnet bleiben.
CREATE TABLE orvanta_oof_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    fixed_text TEXT NOT NULL,
    example_text TEXT NOT NULL,
    ad_groups TEXT NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_orvanta_oof_templates_active (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Je Benutzer die zuletzt in Orvanta eingestellte Abwesenheitsnotiz
-- (Schluessel ist die Office-Kennung aus der Anmeldung). Massgeblich fuer den
-- Banner ist der Zustand auf dem Exchange-Server; diese Zeile liefert den
-- bearbeitbaren Text und die Einstellungen fuer den Dialog und traegt im
-- Demomodus ohne Exchange-Server den Zustand.
CREATE TABLE orvanta_oof_settings (
    user_uid VARCHAR(190) NOT NULL PRIMARY KEY,
    template_id INT UNSIGNED NULL,
    dynamic_text TEXT NOT NULL,
    external_audience ENUM('none', 'all') NOT NULL DEFAULT 'none',
    schedule_mode ENUM('range', 'until_off') NOT NULL DEFAULT 'until_off',
    start_date DATE NULL,
    end_date DATE NULL,
    active TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Erste Vorlage (Text der Aufgabenstellung; die Zuordnung zu AD-Gruppen
-- nimmt der Adminbereich vor).
INSERT INTO orvanta_oof_templates (name, fixed_text, example_text, ad_groups, sort_order, active) VALUES
('Allgemeine Abwesenheit',
 'Sehr geehrte Damen und Herren,\nich befinde mich derzeit nicht im Haus. Ihre Mails werden nicht weitergeleitet.',
 'Bei dringenden Themen oder Anfragen wenden Sie sich bitte an Herrn/Frau XY unter der example@khwf.de oder telefonisch unter der 05331/934-wxyz.',
 '[]', 1, 1);
