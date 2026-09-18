-- Unterseiten und formatierte Textseiten für die Navigation
ALTER TABLE navigation_items
    MODIFY COLUMN type ENUM('external', 'internal', 'subpage', 'page') NOT NULL DEFAULT 'external',
    ADD COLUMN parent_id INT UNSIGNED NULL AFTER type,
    ADD COLUMN content MEDIUMTEXT NULL AFTER description,
    ADD KEY idx_navigation_parent_sort (parent_id, sort_order),
    ADD CONSTRAINT fk_navigation_parent FOREIGN KEY (parent_id)
        REFERENCES navigation_items (id) ON DELETE SET NULL ON UPDATE CASCADE;
