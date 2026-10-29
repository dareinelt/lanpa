-- 023_helpdesk_task_comments.sql – Kommentare an Aufgaben
-- Aufgaben erhalten einen eigenen Kommentarverlauf (task_comments). Die Kommentare erscheinen im
-- Aufgabendetail chronologisch in der Reihenfolge des Eintragens. Das bisherige Einzelfeld „Notiz“
-- unter „Status ändern“ entfällt; vorhandene Notizen werden als Kommentar übernommen, damit nichts
-- verloren geht. Zeitstempel in UTC, alle Tabellen InnoDB / utf8mb4.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Kommentare einer Aufgabe. Einträge sind unveränderlich (kein UPDATE/DELETE in der Anwendung).
-- ---------------------------------------------------------------------------
CREATE TABLE task_comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    body MEDIUMTEXT NOT NULL,
    author_user_id INT UNSIGNED NULL,
    author_name VARCHAR(200) NOT NULL DEFAULT 'system',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_task_comments_task (task_id, created_at, id),
    KEY idx_task_comments_author (author_user_id),
    CONSTRAINT fk_task_comments_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_comments_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Bestehende Notizen als Kommentar übernehmen (Autor und Zeitpunkt der letzten Änderung).
-- ---------------------------------------------------------------------------
INSERT INTO task_comments (task_id, body, author_user_id, author_name, created_at)
SELECT a.id, a.note, a.updated_by, COALESCE(u.display_name, a.created_by_name), a.updated_at
FROM tasks a
LEFT JOIN users u ON u.id = a.updated_by
WHERE a.note IS NOT NULL AND TRIM(a.note) <> '';

ALTER TABLE tasks DROP COLUMN note;
