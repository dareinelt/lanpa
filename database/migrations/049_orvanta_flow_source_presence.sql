-- Orvanta-Nachrichtenfluss: Praesenz je Identitaetsquelle.
--
-- Die Topologie zeigt an den Identitaetsquellen, wie viele ihrer Benutzer
-- Orvanta gerade verwenden und wie viele es im Tagesmaximum waren. Damit die
-- Summe ueber alle Quellen exakt der Anzeige am Knoten "Orvanta-Nutzer"
-- entspricht, wird die Quelle direkt an der Aktivitaet erfasst und bei jeder
-- Probe mitgeschrieben:
--
--   orvanta_activity.source_id   Identitaetsquelle des Benutzers (0 = Haupt-
--                                quelle aus den Einstellungen). Die Summe der
--                                aktiven Nutzer je Quelle ist die Gesamtzahl.
--
--   orvanta_source_samples       Aktive Nutzer je Quelle zum Zeitpunkt einer
--                                Probe aus orvanta_user_samples (gleicher
--                                Zeitstempel). Das 24-h-Maximum je Quelle ist
--                                der Wert zum Zeitpunkt des Gesamtmaximums,
--                                damit die Teilwerte zusammen das Maximum
--                                ergeben. Nur Zaehler, keine Kennungen;
--                                Aufbewahrung wie orvanta_user_samples.

ALTER TABLE orvanta_activity
    ADD COLUMN source_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER backend;

ALTER TABLE orvanta_activity
    ADD KEY idx_orvanta_activity_source (source_id, last_seen_at);

CREATE TABLE IF NOT EXISTS orvanta_source_samples (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sampled_at DATETIME NOT NULL,
    source_id INT UNSIGNED NOT NULL DEFAULT 0,
    active_users SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_orvanta_source_samples_at (sampled_at, source_id),
    KEY idx_orvanta_source_samples_source (source_id, sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
