-- 006_email_notifications.sql – Opt-in E-Mail-Versand von Entnahme-/Retourennachweisen und
-- Übergabeprotokollen an Mitarbeiter (Checkbox im Workflow). Reiner Protokollierungszweck:
-- notify_email = im Workflow angefordert, email_sent_at/email_sent_to = tatsächlicher Versand.

ALTER TABLE movements
    ADD COLUMN notify_email TINYINT(1) NOT NULL DEFAULT 0 AFTER source,
    ADD COLUMN email_sent_at TIMESTAMP NULL AFTER completed_at,
    ADD COLUMN email_sent_to VARCHAR(190) NULL AFTER email_sent_at;

ALTER TABLE handover_protocols
    ADD COLUMN notify_email TINYINT(1) NOT NULL DEFAULT 0 AFTER note,
    ADD COLUMN email_sent_at TIMESTAMP NULL AFTER signed_ip,
    ADD COLUMN email_sent_to VARCHAR(190) NULL AFTER email_sent_at;

-- Neuer Dokumenttyp für den archivierten Entnahme-/Retourennachweis (PDF, wie beim Versand erzeugt)
ALTER TABLE documents
    MODIFY document_type ENUM('order','order_confirmation','delivery_note','invoice','license','photo','signature','handover_protocol','movement_receipt','other') NOT NULL DEFAULT 'other';
