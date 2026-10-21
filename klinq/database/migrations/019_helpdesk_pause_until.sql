-- 019_helpdesk_pause_until.sql – Pausierte Tickets mit automatischem Pausenende.
-- „Pausieren“ ersetzt die bisherigen Wartestati: Der Agent wählt einen Pausengrund (= Status) und
-- muss zusätzlich ein Ende mit Uhrzeit angeben. Der Scheduler (bin/helpdesk.php process) setzt
-- fällige Pausierungen automatisch auf „In Bearbeitung“ zurück; die SLA-Uhr läuft dabei weiter.
-- Bestehende Tickets in den alten Wartestati erhalten ein Pausenende drei Tage nach der Migration,
-- damit die Regel „jede Pausierung hat ein Ende“ auch für den Altbestand gilt.

ALTER TABLE tickets
    ADD COLUMN paused_until TIMESTAMP NULL AFTER sla_paused_at,
    ADD KEY idx_tickets_paused_until (paused_until);

UPDATE ticket_statuses SET code = 'waiting_feedback', name = 'Warten auf Rückmeldung', sort_order = 40 WHERE code = 'waiting_user';
UPDATE ticket_statuses SET code = 'waiting_third_party', name = 'Warten auf Dritte', sort_order = 50 WHERE code = 'waiting_vendor';
UPDATE ticket_statuses SET sort_order = 80 WHERE code = 'resolved';
UPDATE ticket_statuses SET sort_order = 90 WHERE code = 'closed';
UPDATE ticket_statuses SET sort_order = 100 WHERE code = 'cancelled';

INSERT INTO ticket_statuses (code, name, category, color, pauses_sla, sort_order) VALUES
    ('waiting_delivery', 'Warten auf Lieferung', 'pending', 'neutral', 1, 60),
    ('waiting_internal', 'Warten auf interne Rücksprache', 'pending', 'neutral', 1, 70)
ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), color = VALUES(color), pauses_sla = VALUES(pauses_sla), sort_order = VALUES(sort_order);

UPDATE tickets SET paused_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 DAY)
WHERE paused_until IS NULL
  AND status_id IN (SELECT id FROM ticket_statuses WHERE code IN ('waiting_feedback', 'waiting_third_party', 'waiting_delivery', 'waiting_internal'));
