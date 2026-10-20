-- Help-Desk-Stammdaten (idempotent).
INSERT INTO roles (name, label) VALUES
    ('helpdesk_admin', 'Help Desk Administrator'),
    ('helpdesk_lead', 'Help Desk Leitung'),
    ('helpdesk_agent', 'Help Desk Agent')
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT INTO ticket_types (code, name, description, icon, color, sort_order) VALUES
    ('incident', 'Incident', 'Störung – etwas funktioniert nicht (mehr)', 'alert', 'danger', 10),
    ('service_request', 'Service Request', 'Serviceanfrage – Bereitstellung, Änderung, Zugriff', 'clipboard', 'info', 20),
    ('problem', 'Problem', 'Ursachenanalyse wiederkehrender Störungen', 'search', 'warning', 30),
    ('change', 'Change', 'Geplante Änderung an IT-Systemen', 'refresh', 'neutral', 40),
    ('general', 'Allgemein', 'Allgemeine Anfrage / Frage', 'help', 'neutral', 50)
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), icon = VALUES(icon), color = VALUES(color), sort_order = VALUES(sort_order);

INSERT INTO ticket_statuses (code, name, category, color, pauses_sla, sort_order) VALUES
    ('new', 'Neu', 'new', 'info', 0, 10),
    ('open', 'Offen', 'open', 'info', 0, 20),
    ('in_progress', 'In Bearbeitung', 'open', 'warning', 0, 30),
    ('waiting_user', 'Wartet auf Benutzer', 'pending', 'neutral', 1, 40),
    ('waiting_vendor', 'Wartet auf Drittanbieter', 'pending', 'neutral', 1, 50),
    ('resolved', 'Gelöst', 'resolved', 'success', 0, 60),
    ('closed', 'Geschlossen', 'closed', 'neutral', 0, 70),
    ('cancelled', 'Storniert', 'cancelled', 'neutral', 0, 80)
ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), color = VALUES(color), pauses_sla = VALUES(pauses_sla), sort_order = VALUES(sort_order);

INSERT INTO ticket_priorities (code, name, level, color, is_default, sort_order) VALUES
    ('low', 'Niedrig', 1, 'neutral', 0, 10),
    ('normal', 'Normal', 2, 'info', 1, 20),
    ('high', 'Hoch', 3, 'warning', 0, 30),
    ('critical', 'Kritisch', 4, 'danger', 0, 40)
ON DUPLICATE KEY UPDATE name = VALUES(name), level = VALUES(level), color = VALUES(color), is_default = VALUES(is_default), sort_order = VALUES(sort_order);

INSERT INTO ticket_groups (name, description, sort_order) VALUES
    ('Service Desk', 'Erstannahme und Standardanfragen', 10),
    ('Client Support', 'Arbeitsplatz-Hardware, Notebooks, Peripherie', 20),
    ('Network', 'LAN, WLAN, VPN, Internet', 30),
    ('Applications', 'Software, Microsoft 365, Fachanwendungen', 40),
    ('IT-Leitung', 'Eskalationen und kritische Tickets', 50)
ON DUPLICATE KEY UPDATE description = VALUES(description), sort_order = VALUES(sort_order);

-- Hauptkategorien
INSERT IGNORE INTO ticket_categories (parent_id, name, description, default_group_id, sort_order)
SELECT NULL, c.name, c.description, g.id, c.sort_order FROM (
    SELECT 'Hardware' AS name, 'PCs, Notebooks, Monitore, Drucker, Mobilgeräte' AS description, 'Client Support' AS group_name, 10 AS sort_order UNION ALL
    SELECT 'Software', 'Betriebssystem, Office, Fachanwendungen', 'Applications', 20 UNION ALL
    SELECT 'Netzwerk', 'LAN, WLAN, VPN, Internet', 'Network', 30 UNION ALL
    SELECT 'Benutzer', 'Konten, Passwörter, Berechtigungen', 'Service Desk', 40 UNION ALL
    SELECT 'Sonstiges', 'Alles, was nirgends passt', 'Service Desk', 90
) c LEFT JOIN ticket_groups g ON g.name = c.group_name
WHERE NOT EXISTS (SELECT 1 FROM ticket_categories x WHERE x.parent_id IS NULL AND x.name = c.name);

-- Unterkategorien
INSERT IGNORE INTO ticket_categories (parent_id, name, sort_order)
SELECT p.id, s.name, s.sort_order FROM (
    SELECT 'Hardware' AS parent, 'PC / Notebook' AS name, 10 AS sort_order UNION ALL
    SELECT 'Hardware', 'Monitor', 20 UNION ALL
    SELECT 'Hardware', 'Drucker', 30 UNION ALL
    SELECT 'Hardware', 'Mobilgerät', 40 UNION ALL
    SELECT 'Software', 'Windows', 10 UNION ALL
    SELECT 'Software', 'Microsoft 365', 20 UNION ALL
    SELECT 'Software', 'Fachanwendung', 30 UNION ALL
    SELECT 'Software', 'Sonstige', 40 UNION ALL
    SELECT 'Netzwerk', 'LAN', 10 UNION ALL
    SELECT 'Netzwerk', 'WLAN', 20 UNION ALL
    SELECT 'Netzwerk', 'VPN', 30 UNION ALL
    SELECT 'Netzwerk', 'Internet', 40 UNION ALL
    SELECT 'Benutzer', 'Passwort', 10 UNION ALL
    SELECT 'Benutzer', 'Konto', 20 UNION ALL
    SELECT 'Benutzer', 'Berechtigung', 30 UNION ALL
    SELECT 'Benutzer', 'Neueinstellung', 40
) s JOIN ticket_categories p ON p.parent_id IS NULL AND p.name = s.parent
WHERE NOT EXISTS (SELECT 1 FROM ticket_categories x WHERE x.parent_id = p.id AND x.name = s.name);

-- SLA je Priorität (Werktage = 8 Arbeitsstunden bei business_hours_only)
INSERT INTO ticket_slas (name, description, priority_id, response_minutes, resolution_minutes, business_hours_only, warning_percent, escalation_percent, escalation_group_id, is_default, sort_order)
SELECT s.name, s.description, p.id, s.response_minutes, s.resolution_minutes, s.business_hours_only, 75, 90, g.id, s.is_default, s.sort_order FROM (
    SELECT 'Kritisch' AS name, 'Reaktion 15 Minuten, Lösung 4 Stunden – rund um die Uhr' AS description, 'critical' AS priority, 15 AS response_minutes, 240 AS resolution_minutes, 0 AS business_hours_only, 0 AS is_default, 10 AS sort_order UNION ALL
    SELECT 'Hoch', 'Reaktion 1 Stunde, Lösung 8 Stunden', 'high', 60, 480, 0, 0, 20 UNION ALL
    SELECT 'Standard', 'Reaktion 4 Stunden, Lösung 2 Werktage', 'normal', 240, 960, 1, 1, 30 UNION ALL
    SELECT 'Niedrig', 'Reaktion 1 Werktag, Lösung 5 Werktage', 'low', 480, 2400, 1, 0, 40
) s JOIN ticket_priorities p ON p.code = s.priority
LEFT JOIN ticket_groups g ON g.name = 'IT-Leitung'
ON DUPLICATE KEY UPDATE description = VALUES(description), sort_order = VALUES(sort_order);

INSERT INTO ticket_templates (name, description, subject, body, ticket_type_id, category_id, subcategory_id, priority_id, group_id, tags, sort_order)
SELECT t.name, t.description, t.subject, t.body, ty.id, c.id, sc.id, p.id, g.id, t.tags, t.sort_order FROM (
    SELECT 'Neuer Mitarbeiter' AS name, 'Onboarding: Konto, Hardware, Berechtigungen' AS description, 'Neuer Mitarbeiter: [Name], Eintritt am [Datum]' AS subject,
           'Name:\nAbteilung:\nEintrittsdatum:\nArbeitsplatz / Standort:\nBenötigte Hardware:\nBenötigte Software / Berechtigungen:\nVorbild-Benutzer (optional):' AS body,
           'service_request' AS type_code, 'Benutzer' AS category, 'Neueinstellung' AS subcategory, 'normal' AS priority, 'Service Desk' AS group_name, 'Onboarding' AS tags, 10 AS sort_order UNION ALL
    SELECT 'Passwort zurücksetzen', 'Kennwort vergessen oder Konto gesperrt', 'Passwort zurücksetzen für [Benutzername]',
           'Benutzername:\nKonto gesperrt? (ja/nein):\nRückruf-Telefonnummer:', 'service_request', 'Benutzer', 'Passwort', 'normal', 'Service Desk', NULL, 20 UNION ALL
    SELECT 'Notebook defekt', 'Hardwaredefekt am Notebook / PC', 'Notebook defekt: [Inventarnummer]',
           'Beschreibung des Fehlers:\nSeit wann tritt der Fehler auf?\nFehlermeldung (falls vorhanden):\nBereits versucht:', 'incident', 'Hardware', 'PC / Notebook', 'high', 'Client Support', 'Hardwaredefekt', 30 UNION ALL
    SELECT 'VPN funktioniert nicht', 'Keine VPN-Verbindung möglich', 'VPN-Verbindung nicht möglich',
           'Standort (Homeoffice / unterwegs):\nFehlermeldung:\nInternet sonst verfügbar? (ja/nein):\nSeit wann?', 'incident', 'Netzwerk', 'VPN', 'normal', 'Network', 'VPN', 40 UNION ALL
    SELECT 'Microsoft-365-Zugriff', 'Zugriff auf Postfach, Teams, SharePoint', 'Microsoft 365: Zugriff auf [Dienst] benötigt',
           'Benötigter Dienst / Gruppe / Postfach:\nBegründung:\nGenehmigt durch:', 'service_request', 'Software', 'Microsoft 365', 'normal', 'Applications', 'Microsoft365', 50 UNION ALL
    SELECT 'Druckerproblem', 'Drucker druckt nicht oder fehlerhaft', 'Druckerproblem: [Drucker / Standort]',
           'Drucker / Standort:\nFehlermeldung am Gerät:\nBetroffene Benutzer (alle / einzelne):', 'incident', 'Hardware', 'Drucker', 'normal', 'Client Support', NULL, 60
) t
LEFT JOIN ticket_types ty ON ty.code = t.type_code
LEFT JOIN ticket_categories c ON c.parent_id IS NULL AND c.name = t.category
LEFT JOIN ticket_categories sc ON sc.parent_id = c.id AND sc.name = t.subcategory
LEFT JOIN ticket_priorities p ON p.code = t.priority
LEFT JOIN ticket_groups g ON g.name = t.group_name
ON DUPLICATE KEY UPDATE description = VALUES(description), sort_order = VALUES(sort_order);

INSERT INTO ticket_tags (name, color) VALUES
    ('VIP', 'danger'),
    ('Microsoft365', 'info'),
    ('VPN', 'info'),
    ('Onboarding', 'success'),
    ('Security', 'danger'),
    ('Hardwaredefekt', 'warning')
ON DUPLICATE KEY UPDATE color = VALUES(color);

-- Beispielregeln (aktiv): kritische Tickets an IT-Leitung, VPN an Network
INSERT INTO ticket_rules (name, description, trigger_event, conditions, actions, sort_order)
SELECT r.name, r.description, r.trigger_event, r.conditions, r.actions, r.sort_order FROM (
    SELECT 'Kritisch → IT-Leitung' AS name, 'Kritische Tickets automatisch an die IT-Leitung' AS description, 'created' AS trigger_event,
           JSON_OBJECT('all', JSON_ARRAY(JSON_OBJECT('field', 'priority_code', 'op', 'eq', 'value', 'critical'))) AS conditions,
           JSON_OBJECT('set_group', 'IT-Leitung', 'notify_group', true) AS actions, 10 AS sort_order UNION ALL
    SELECT 'VPN → Network', 'VPN-Tickets an die Netzwerkgruppe', 'created',
           JSON_OBJECT('all', JSON_ARRAY(JSON_OBJECT('field', 'subcategory_name', 'op', 'eq', 'value', 'VPN'))),
           JSON_OBJECT('set_group', 'Network', 'add_tags', JSON_ARRAY('VPN')), 20
) r
ON DUPLICATE KEY UPDATE description = VALUES(description), sort_order = VALUES(sort_order);
