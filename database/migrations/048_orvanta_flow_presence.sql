-- Orvanta-Nachrichtenfluss: Praesenz, Nutzerproben und Zustand der
-- Identitaetsquellen.
--
-- Das Admin-Dashboard "Office -> Orvanta - Nachrichtenfluss" zeigt unter
-- anderem, wie viele Nutzer Orvanta gerade verwenden (aktuell / Minimum /
-- Maximum der letzten 24 Stunden) und wie sich diese Zahl ueber 365, 180, 90,
-- 30 und 14 Tage entwickelt hat. Beides laesst sich nicht aus den vorhandenen
-- Tabellen ableiten: orvanta_exchange_sessions entsteht nur im Exchange-Betrieb
-- und wird nach 24 Stunden geraeumt, der Proxy-Betrieb erzeugt gar keine
-- Sitzungszeile.
--
-- Deshalb drei Ergaenzungen:
--
--   orvanta_activity        Letzte Aktivitaet je Orvanta-Benutzer, erfasst an
--                           der einzigen Eintrittstelle der Orvanta-Schnitt-
--                           stelle (/api/orvanta/...). Enthaelt bewusst nur
--                           Kennung, Backend und Zeitstempel - keine Inhalte,
--                           keine Betreffzeilen, keine Empfaenger. Zeilen
--                           aelter als 24 Stunden werden geraeumt.
--
--   orvanta_user_samples    Proben der aktiven Nutzer im 5-Minuten-Raster
--                           (hoechstens eine Probe je Zeitraster, zusaetzlich
--                           beim Lauf des Archivierungs-Workers). Nur Zaehler,
--                           keine Kennungen. Aufbewahrung 400 Tage.
--
--   mail_proxy_source_state Zustand je Identitaetsquelle: Der Proxy ist
--                           zustandslos, sein /health gilt fuer den ganzen
--                           Dienst. Ob eine einzelne Quelle nicht erreichbar
--                           ist (Netzwerk, TLS, Anmeldung), zeigt erst die
--                           Auswertung der tatsaechlichen Vorgaenge und der
--                           Pruefung im Adminbereich.
CREATE TABLE IF NOT EXISTS orvanta_activity (
    user_uid VARCHAR(190) NOT NULL,
    backend VARCHAR(16) NOT NULL DEFAULT 'exchange',
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    requests INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_uid),
    KEY idx_orvanta_activity_seen (last_seen_at),
    KEY idx_orvanta_activity_backend (backend, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orvanta_user_samples (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sampled_at DATETIME NOT NULL,
    active_users SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    exchange_users SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    proxy_users SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ai_users SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_orvanta_user_samples_at (sampled_at),
    KEY idx_orvanta_user_samples_day (sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_proxy_source_state (
    identity_source_id INT UNSIGNED NOT NULL,
    last_success_at DATETIME NULL,
    last_error_at DATETIME NULL,
    last_error VARCHAR(500) NOT NULL DEFAULT '',
    failures INT UNSIGNED NOT NULL DEFAULT 0,
    checked_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (identity_source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
