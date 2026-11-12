-- 042_helpdesk_template_name_scope.sql – Vorlagennamen nur im sichtbaren Umfang eindeutig
-- Bisher galt uq_ticket_templates_name über alle Vorlagen; damit verrieten persönliche Vorlagen
-- (is_shared = 0) über „Bezeichnung bereits vergeben“ ihren Namen an andere Benutzer.
-- Jetzt sind nur freigegebene Vorlagen datenbankseitig eindeutig (der virtuelle Schlüssel ist bei
-- persönlichen Vorlagen NULL); Dubletten innerhalb der Vorlagen eines Erstellers verhindert
-- HelpdeskAdminService::saveTemplate(). Der Seeder (ON DUPLICATE KEY) trifft weiterhin den neuen Schlüssel.

SET NAMES utf8mb4;

ALTER TABLE ticket_templates
    ADD COLUMN shared_name_key TINYINT(1) GENERATED ALWAYS AS (IF(is_shared = 1, 1, NULL)) VIRTUAL,
    ADD UNIQUE KEY uq_ticket_templates_shared_name (name, shared_name_key),
    DROP INDEX uq_ticket_templates_name;
