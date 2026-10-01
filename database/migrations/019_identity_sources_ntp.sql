-- Zeitserver (NTP) je Domaene fuer die auth-Container: Kerberos verlangt eine
-- Uhrzeitabweichung von weniger als 5 Minuten zwischen Client, Domaenen-
-- controller und auth-Container. Leer = die Domaenencontroller selbst.
-- Hauptquelle: Einstellung sso_ntp_servers in der Tabelle settings.
ALTER TABLE identity_sources
    ADD COLUMN sso_ntp_servers TEXT NULL AFTER sso_dcs;
