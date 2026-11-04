-- 037_helpdesk_ticket_forward_reason.sql – optionaler Grund bei der Weiterleitung eines Tickets
-- Beim Weiterleiten kann ein kurzer Grund hinterlegt werden. Er erscheint beim neuen Bearbeiter
-- (Banner, Dashboard-Karte, Benachrichtigung) und im Ticketverlauf. Die Spalte wird zusammen mit
-- den übrigen Weiterleitungsmarkern gesetzt und beim Aufheben der Weiterleitung wieder geleert.
-- Zeitstempel in UTC, alle Tabellen InnoDB / utf8mb4.

SET NAMES utf8mb4;

ALTER TABLE tickets
    ADD COLUMN forward_reason VARCHAR(255) NULL DEFAULT NULL AFTER forwarded_at;
