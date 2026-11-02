-- 030_helpdesk_task_attachments.sql – Anhänge an Aufgaben
-- Beim Umwandeln eines Tickets in eine Aufgabe dürfen keine Anhänge verloren gehen. Die Dateien
-- liegen weiterhin in `documents` (Kopie je Aufgabe, damit beide Seiten eigenständig bleiben), die
-- Verknüpfung zur Aufgabe steht in `task_attachments`. Die Übernahme der Ticket-Kommentare läuft
-- über das bestehende Journal (`task_journal`), das dafür keine Schemaänderung braucht.
-- Zeitstempel in UTC, alle Tabellen InnoDB / utf8mb4.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Aufgaben dürfen eigene Dokumente tragen (Anhänge, aus Tickets übernommene Dateien).
-- ---------------------------------------------------------------------------
ALTER TABLE documents
    MODIFY COLUMN entity_type ENUM('asset','purchase_order','license','movement','supplier','import','handover','ticket','knowledge_article','task') NOT NULL;

-- ---------------------------------------------------------------------------
-- Anhänge einer Aufgabe. Aufgaben sind Agenten vorbehalten, deshalb gibt es – anders als bei
-- Tickets – keine Sichtbarkeitsstufe „intern“. Einträge entstehen beim Hochladen und bei der
-- Übernahme aus einem Ticket.
-- ---------------------------------------------------------------------------
CREATE TABLE task_attachments (
    document_id INT UNSIGNED NOT NULL PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_task_attachments_task (task_id, created_at),
    CONSTRAINT fk_task_attachments_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_attachments_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
