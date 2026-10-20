-- CSV-Import: Läufe und Zeilenprotokoll (Vorschau und Ergebnis)
-- Ersetzt den Platzhalter aus 001_initial.sql (noch ungenutzt).
DROP TABLE IF EXISTS import_rows;
DROP TABLE IF EXISTS import_runs;

CREATE TABLE import_runs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type VARCHAR(40) NOT NULL DEFAULT 'assets',
    status ENUM('preview','completed','cancelled','failed') NOT NULL DEFAULT 'preview',
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    delimiter CHAR(1) NOT NULL DEFAULT ';',
    encoding VARCHAR(20) NOT NULL DEFAULT 'UTF-8',
    options JSON NULL,
    columns_found JSON NULL,
    rows_total INT UNSIGNED NOT NULL DEFAULT 0,
    rows_valid INT UNSIGNED NOT NULL DEFAULT 0,
    rows_warning INT UNSIGNED NOT NULL DEFAULT 0,
    rows_error INT UNSIGNED NOT NULL DEFAULT 0,
    rows_duplicate INT UNSIGNED NOT NULL DEFAULT 0,
    rows_imported INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    committed_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idx_import_runs_status (status, created_at),
    CONSTRAINT fk_import_runs_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_rows (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_run_id INT UNSIGNED NOT NULL,
    line_no INT UNSIGNED NOT NULL,
    status ENUM('valid','warning','error','duplicate','imported','skipped') NOT NULL,
    inventory_number VARCHAR(20) NULL,
    asset_id INT UNSIGNED NULL,
    summary VARCHAR(255) NULL,
    messages JSON NULL,
    data JSON NULL,
    PRIMARY KEY (id),
    KEY idx_import_rows_run (import_run_id, status, line_no),
    CONSTRAINT fk_import_rows_run FOREIGN KEY (import_run_id) REFERENCES import_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_import_rows_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
