-- 027_helpdesk_task_journal.sql – Journal an Aufgaben
-- Jede Aufgabe erhält ein eigenes, unveränderliches Journal. Einträge entstehen z. B. beim
-- Zuweisen mit optionalem Kommentar und erscheinen im Aufgabendetail als eigener Abschnitt.
-- Zeitstempel in UTC, alle Tabellen InnoDB / utf8mb4.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Journal einer Aufgabe. Einträge sind unveränderlich (kein UPDATE/DELETE in der Anwendung).
-- ---------------------------------------------------------------------------
CREATE TABLE task_journal (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'note',
    body MEDIUMTEXT NOT NULL,
    author_user_id INT UNSIGNED NULL,
    author_name VARCHAR(200) NOT NULL DEFAULT 'system',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_task_journal_task (task_id, created_at, id),
    KEY idx_task_journal_author (author_user_id),
    CONSTRAINT fk_task_journal_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_journal_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
