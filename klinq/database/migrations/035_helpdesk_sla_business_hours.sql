-- 035_helpdesk_sla_business_hours.sql – Dienstzeiten je Wochentag für SLA-Regeln.
-- Bisher galten für alle gewählten Geschäftstage dieselben Zeiten (business_days +
-- business_start/business_end). Neu hält business_hours die Zeiten je Tag, z. B.
-- "1=08:00-17:00,2=08:00-16:00". NULL/leer = Altbestand, dann gelten weiterhin
-- business_days mit gemeinsamem business_start/business_end.

ALTER TABLE ticket_slas
    ADD COLUMN business_hours VARCHAR(255) NULL DEFAULT NULL AFTER business_end;
