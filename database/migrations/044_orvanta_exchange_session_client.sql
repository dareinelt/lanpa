-- Orvanta: Clientangaben in der Sitzungstabelle der Exchange-DAG.
-- Die Liste der verbundenen Sitzungen (Office -> Orvanta - DAG-Hosts) zeigt
-- neben Benutzer und Host auch die IP-Adresse und, soweit aufloesbar, den
-- Hostnamen des Clients. Beide Werte werden beim Beginn einer Sitzung
-- ermittelt und bleiben bei einer Umleitung (Failover) unveraendert; leer
-- bedeutet "nicht bekannt".
ALTER TABLE orvanta_exchange_sessions
    ADD COLUMN client_ip VARCHAR(45) NOT NULL DEFAULT '' AFTER user_uid,
    ADD COLUMN client_host VARCHAR(190) NOT NULL DEFAULT '' AFTER client_ip;
