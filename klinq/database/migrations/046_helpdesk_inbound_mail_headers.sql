-- 046_helpdesk_inbound_mail_headers.sql – Diagnose ignorierter E-Mails im Mail-Protokoll
-- Wird eine eingehende Nachricht als automatisch erkannt und ignoriert, speichert der E-Mail-Eingang
-- die für die Erkennung relevanten Kopfzeilen (Auto-Submitted, Precedence, List-Id, …) als JSON.
-- Das Protokoll unter „Help Desk → Administration → E-Mail-Eingang“ zeigt sie aufklappbar an.

SET NAMES utf8mb4;

ALTER TABLE ticket_inbound_mails
    ADD COLUMN mail_headers TEXT NULL AFTER detail;
