-- 021_helpdesk_tasks.sql – Aufgaben im Help Desk
-- Aufgaben entstehen aus Tickets („In Aufgabe umwandeln“) und können anschließend von Agenten
-- angenommen oder von Mitgliedern der Berechtigungsgruppe „aufgabenverteilung“ zugewiesen werden.
-- Zeitstempel in UTC, alle Tabellen InnoDB / utf8mb4. Tickets und Benutzer werden referenziert.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Aufgaben. `source_ticket_id` ist eindeutig: je Ticket höchstens eine Aufgabe.
-- ---------------------------------------------------------------------------
CREATE TABLE tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(20) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    description MEDIUMTEXT NOT NULL,
    status ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
    priority_id INT UNSIGNED NULL,
    source_ticket_id INT UNSIGNED NULL,
    assignee_user_id INT UNSIGNED NULL,
    assigned_by_user_id INT UNSIGNED NULL,
    assigned_at TIMESTAMP NULL,
    claimed_at TIMESTAMP NULL,
    due_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    completed_by INT UNSIGNED NULL,
    note VARCHAR(500) NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_by_name VARCHAR(200) NOT NULL DEFAULT 'system',
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tasks_number (number),
    UNIQUE KEY uq_tasks_source_ticket (source_ticket_id),
    KEY idx_tasks_assignee (assignee_user_id, status),
    KEY idx_tasks_status (status, updated_at),
    KEY idx_tasks_priority (priority_id),
    CONSTRAINT fk_tasks_source_ticket FOREIGN KEY (source_ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_priority FOREIGN KEY (priority_id) REFERENCES ticket_priorities(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_assignee FOREIGN KEY (assignee_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_assigned_by FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Verlauf der Aufgabe (Annahme, Zuweisung, Statuswechsel)
-- ---------------------------------------------------------------------------
CREATE TABLE task_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    user_id INT UNSIGNED NULL,
    user_name VARCHAR(200) NOT NULL DEFAULT 'system',
    field VARCHAR(60) NULL,
    old_value VARCHAR(500) NULL,
    new_value VARCHAR(500) NULL,
    payload JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_task_events_task (task_id, created_at, id),
    KEY idx_task_events_type (type, created_at),
    CONSTRAINT fk_task_events_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
