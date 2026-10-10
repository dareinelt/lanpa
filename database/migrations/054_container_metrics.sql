-- Kennzahlen der uebrigen Container (CPU und Arbeitsspeicher) fuer die Kacheln
-- auf dem Admin-Dashboard.
--
-- Anders als beim auth-Container (siehe 050_auth_metrics.sql) kann sich diese
-- Messung nicht im Container selbst durchfuehren: app, db, mail-proxy,
-- nextcloud und eurooffice bringen kein Messskript mit. Der Sammel-Container
-- (docker/monitor/metrics.py) liest die Werte ueber den read-only gemounteten
-- Docker-Socket aus der Docker-Engine - dieselben Zahlen wie "docker stats" -
-- und meldet je Container und Messfenster eine Probe an
-- POST /internal/container-metrics.
--
--   service       Kennung des Containers aus docker-compose.yml (app, db,
--                 mail-proxy, nextcloud, eurooffice)
--   recorded_at   Zeitpunkt des Eingangs der Probe in der Anwendung
--   cpu_percent   CPU-Auslastung bezogen auf cpu_limit (0-100)
--   cpu_limit     Bezugsgroesse der CPU-Messung in Kernen: die zugewiesene
--                 Obergrenze des Containers, ohne Obergrenze die Kerne des
--                 Hosts
--   cpu_limited   1 = Bezugsgroesse ist die Obergrenze des Containers,
--                 0 = ohne Obergrenze gelten die Kerne des Hosts
--   ram_percent   Arbeitsspeicher-Auslastung bezogen auf ram_total (0-100)
--   ram_used      belegter Arbeitsspeicher in Byte
--   ram_total     Bezugsgroesse in Byte (memory.max; ohne Limit der
--                 Arbeitsspeicher des Hosts)
--
-- Die drei ram_*-Werte sind NULL, wenn die Probe keinen Arbeitsspeicher
-- gemeldet hat (nicht lesbare Probe bzw. fehlender Hostwert). Nur Kennzahlen,
-- keine Kennungen; Aufbewahrung 48 h (Aufraeumen beim Schreiben, siehe
-- App\Services\Monitoring\ContainerMetricsService).
CREATE TABLE IF NOT EXISTS container_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service VARCHAR(32) NOT NULL,
    recorded_at DATETIME NOT NULL,
    cpu_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
    cpu_limit DECIMAL(5,2) NOT NULL DEFAULT 1,
    cpu_limited TINYINT(1) NOT NULL DEFAULT 0,
    ram_percent DECIMAL(6,2) NULL,
    ram_used BIGINT UNSIGNED NULL,
    ram_total BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_container_metrics_service (service, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
