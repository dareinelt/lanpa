-- 005_articles_handover.sql – Artikelstamm mit phonetischer Dublettenprüfung und
-- Kennzeichen „Relevant für Übergabeprotokoll“; versionierte Übergabeprotokolle je Mitarbeiter.

-- ---------------------------------------------------------------------------
-- Artikel: phonetischer Schlüssel + Normalname (Backfill erfolgt in bin/migrate.php)
-- ---------------------------------------------------------------------------
ALTER TABLE articles
    ADD COLUMN normalized_name VARCHAR(200) NOT NULL DEFAULT '' AFTER description,
    ADD COLUMN phonetic_key VARCHAR(120) NOT NULL DEFAULT '' AFTER normalized_name,
    ADD COLUMN is_handover_relevant TINYINT(1) NOT NULL DEFAULT 0 AFTER phonetic_key,
    ADD INDEX idx_articles_normalized (manufacturer_id, normalized_name),
    ADD INDEX idx_articles_phonetic (phonetic_key),
    ADD INDEX idx_articles_number (article_number),
    ADD INDEX idx_articles_handover (is_handover_relevant);

-- ---------------------------------------------------------------------------
-- Dokumente: Unterschrift (PNG) und Protokoll (PDF) hängen am Übergabeprotokoll
-- ---------------------------------------------------------------------------
ALTER TABLE documents
    MODIFY entity_type ENUM('asset','purchase_order','license','movement','supplier','import','handover') NOT NULL,
    MODIFY document_type ENUM('order','order_confirmation','delivery_note','invoice','license','photo','signature','handover_protocol','other') NOT NULL DEFAULT 'other';

-- ---------------------------------------------------------------------------
-- Vorlage (Baukasten): geordnete Blöcke als JSON, vom Admin pflegbar
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS handover_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    blocks JSON NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_handover_templates_default (is_default),
    CONSTRAINT fk_handover_templates_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Übergabeprotokolle: je Mitarbeiter fortlaufend versioniert. Das zuletzt
-- unterschriebene Protokoll gilt; ältere werden beim Unterschreiben der
-- Folgeversion als „superseded“ markiert und bleiben samt PDF archiviert.
-- Mitarbeiter, Assets, Vorlage und HTML werden als Snapshot eingefroren.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS handover_protocols (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    protocol_number VARCHAR(30) NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('draft','signed','superseded','cancelled') NOT NULL DEFAULT 'draft',
    template_id INT UNSIGNED NULL,
    template_snapshot JSON NOT NULL,
    employee_snapshot JSON NOT NULL,
    items JSON NOT NULL,
    item_count INT UNSIGNED NOT NULL DEFAULT 0,
    asset_fingerprint CHAR(64) NOT NULL DEFAULT '',
    note TEXT NULL,
    rendered_html MEDIUMTEXT NULL,
    signature_document_id INT UNSIGNED NULL,
    pdf_document_id INT UNSIGNED NULL,
    signed_at TIMESTAMP NULL,
    signed_device VARCHAR(255) NULL,
    signed_ip VARCHAR(45) NULL,
    issuer_user_id INT UNSIGNED NULL,
    issuer_name VARCHAR(120) NOT NULL DEFAULT 'system',
    cancelled_at TIMESTAMP NULL,
    cancel_reason VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_by_name VARCHAR(120) NOT NULL DEFAULT 'system',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_handover_number (protocol_number),
    UNIQUE KEY uq_handover_employee_version (employee_id, version),
    KEY idx_handover_employee_status (employee_id, status),
    KEY idx_handover_status (status, signed_at),
    CONSTRAINT fk_handover_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_handover_template FOREIGN KEY (template_id) REFERENCES handover_templates(id) ON DELETE SET NULL,
    CONSTRAINT fk_handover_signature_doc FOREIGN KEY (signature_document_id) REFERENCES documents(id) ON DELETE SET NULL,
    CONSTRAINT fk_handover_pdf_doc FOREIGN KEY (pdf_document_id) REFERENCES documents(id) ON DELETE SET NULL,
    CONSTRAINT fk_handover_issuer FOREIGN KEY (issuer_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_handover_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
