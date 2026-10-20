-- Audit-Log-Ansicht: Filter nach Aktion und Benutzer
ALTER TABLE audit_logs
    ADD INDEX idx_audit_action (action, created_at),
    ADD INDEX idx_audit_username (username, created_at);
