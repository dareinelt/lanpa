-- Arbeitsspeicher des auth-Containers fuer die Kachel "Reverse-Proxy" auf dem
-- Admin-Dashboard.
--
-- Gemessen wird im Container selbst (cgroup); die Kachel zeigt die Auslastung
-- gross in Prozent und darunter den absoluten Verbrauch (belegt/gesamt).
--
--   ram_percent   Auslastung des Containers in Prozent der Bezugsgroesse (0-100)
--   ram_used      belegter Arbeitsspeicher in Byte (memory.current)
--   ram_total     Bezugsgroesse in Byte (memory.max; ohne Limit die Groesse
--                 des Arbeitsspeichers aus /proc/meminfo)
--
-- Alle drei Werte sind NULL, wenn die Probe keinen Arbeitsspeicher gemeldet hat
-- (aeltere Fassung von docker/auth/metrics.py bzw. nicht lesbare cgroup).
-- Aufbewahrung und Aufraeumen wie bisher (siehe 050_auth_metrics.sql).
ALTER TABLE auth_metrics
    ADD COLUMN ram_percent DECIMAL(6,2) NULL AFTER cpu_limit,
    ADD COLUMN ram_used BIGINT UNSIGNED NULL AFTER ram_percent,
    ADD COLUMN ram_total BIGINT UNSIGNED NULL AFTER ram_used;
