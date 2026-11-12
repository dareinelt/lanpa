-- 041_helpdesk_template_scope.sql – Vorlagenumfang und Berechtigungsgruppe „vorlagen“
-- Help-Desk-Agenten mit der AD-Gruppe „Help-Desk-Vorlagen“ dürfen eigene Vorlagen anlegen:
-- entweder nur für sich selbst (is_shared = 0) oder für alle Agenten (is_shared = 1).
-- Bestehende Vorlagen bleiben wie bisher für alle Agenten verfügbar.
-- schema_migrations führt die Datei genau einmal aus; die Gruppenzeile ist zusätzlich idempotent.

SET NAMES utf8mb4;

ALTER TABLE ticket_templates
    ADD COLUMN is_shared TINYINT(1) NOT NULL DEFAULT 1 AFTER is_portal_visible;

INSERT INTO permission_groups (name, label) VALUES
    ('vorlagen', 'Darf Help-Desk-Vorlagen anlegen')
ON DUPLICATE KEY UPDATE name = name;
