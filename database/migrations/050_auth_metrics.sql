-- Kennzahlen des auth-Containers (Einstieg/Reverse-Proxy) fuer die Karte auf
-- dem Admin-Dashboard.
--
-- Der auth-Container misst die Werte selbst (docker/auth/metrics.py) und
-- meldet jede Probe an POST /internal/auth-metrics. Aus den Proben entstehen
-- der aktuelle Wert sowie Spitze und Mittel der letzten 12 bzw. 24 Stunden.
--
--   recorded_at   Zeitpunkt des Eingangs der Probe in der Anwendung
--   cpu_percent   CPU-Auslastung im Container, bezogen auf cpu_limit (0-100)
--   cpu_limit     Bezugsgroesse der CPU-Messung in Kernen (cpu.max; ohne
--                 Limit 1 = ein Kern)
--   tcp_open      offene TCP-Verbindungen (Zustand ESTABLISHED)
--   sources       Quellnetze dieser Verbindungen als JSON-Objekt
--                 ("192.168.200.0/24": 5), null ohne Verbindungen
--
-- Nur Zaehler, keine Kennungen; Aufbewahrung 48 h (Aufraeumen beim Schreiben).
CREATE TABLE IF NOT EXISTS auth_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recorded_at DATETIME NOT NULL,
    cpu_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
    cpu_limit DECIMAL(5,2) NOT NULL DEFAULT 1,
    tcp_open INT UNSIGNED NOT NULL DEFAULT 0,
    sources TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_auth_metrics_recorded (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
