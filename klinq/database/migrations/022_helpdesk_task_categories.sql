-- 022_helpdesk_task_categories.sql – Kategorien für Aufgaben
-- Aufgaben erhalten einen eigenen, frei pflegbaren Katalog (Tabelle task_categories). Die Kategorie
-- ist optional, wird vom Agenten gesetzt (beim Umwandeln eines Tickets oder später im Aufgabendetail)
-- und erscheint als Pill vor dem Aufgabentitel. Der Katalog ist bewusst unabhängig von den
-- hierarchischen Ticket-Kategorien (ticket_categories), weil er nur der Sortierung der Aufgaben dient.
-- Wird eine Kategorie gelöscht, bleibt die Aufgabe erhalten (category_id → NULL).

SET NAMES utf8mb4;

CREATE TABLE task_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_task_categories_name (name),
    KEY idx_task_categories_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tasks
    ADD COLUMN category_id INT UNSIGNED NULL AFTER status,
    ADD KEY idx_tasks_category (category_id),
    ADD CONSTRAINT fk_tasks_category FOREIGN KEY (category_id) REFERENCES task_categories(id) ON DELETE SET NULL;
