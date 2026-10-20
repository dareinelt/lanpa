-- 001_initial.sql – Vollständiges initiales Schema von KLINQ
-- Alle Tabellen InnoDB / utf8mb4. Zeitstempel in UTC.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Benutzer, Rollen, Einstellungen, Audit
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    label VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(120) NOT NULL,
    display_name VARCHAR(200) NOT NULL DEFAULT '',
    email VARCHAR(255) NULL,
    password_hash VARCHAR(255) NULL,
    role_id INT UNSIGNED NOT NULL,
    auth_source ENUM('local','ldap') NOT NULL DEFAULT 'local',
    employee_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until TIMESTAMP NULL,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
    `key` VARCHAR(190) NOT NULL PRIMARY KEY,
    value MEDIUMTEXT NOT NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    username VARCHAR(120) NOT NULL DEFAULT 'system',
    action VARCHAR(100) NOT NULL,
    object_type VARCHAR(60) NOT NULL,
    object_id BIGINT UNSIGNED NULL,
    object_label VARCHAR(255) NULL,
    old_data JSON NULL,
    new_data JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_object (object_type, object_id),
    KEY idx_audit_created (created_at),
    KEY idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Standorte (hierarchisch), Kostenstellen, Mitarbeiter
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    type ENUM('site','building','floor','room','workplace','warehouse','other') NOT NULL DEFAULT 'other',
    code VARCHAR(50) NULL,
    full_path VARCHAR(1000) NOT NULL DEFAULT '',
    depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_locations_parent (parent_id),
    KEY idx_locations_name (name),
    KEY idx_locations_path (full_path(255)),
    CONSTRAINT fk_locations_parent FOREIGN KEY (parent_id) REFERENCES locations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cost_centers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number CHAR(5) NOT NULL,
    description VARCHAR(200) NOT NULL,
    location_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cost_centers_number (number),
    KEY idx_cost_centers_location (location_id),
    CONSTRAINT fk_cost_centers_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ad_object_guid CHAR(36) NULL,
    username VARCHAR(120) NULL,
    first_name VARCHAR(120) NOT NULL DEFAULT '',
    last_name VARCHAR(120) NOT NULL DEFAULT '',
    display_name VARCHAR(250) NOT NULL,
    email VARCHAR(255) NULL,
    personnel_number VARCHAR(50) NULL,
    department VARCHAR(150) NULL,
    position VARCHAR(150) NULL,
    phone VARCHAR(60) NULL,
    ad_location VARCHAR(150) NULL,
    ad_cost_center VARCHAR(50) NULL,
    location_id INT UNSIGNED NULL,
    cost_center_id INT UNSIGNED NULL,
    source ENUM('ad','manual') NOT NULL DEFAULT 'manual',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    deactivated_at TIMESTAMP NULL,
    last_synced_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employees_guid (ad_object_guid),
    KEY idx_employees_username (username),
    KEY idx_employees_name (last_name, first_name),
    KEY idx_employees_display (display_name),
    KEY idx_employees_personnel (personnel_number),
    KEY idx_employees_active (is_active),
    CONSTRAINT fk_employees_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
    CONSTRAINT fk_employees_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS ad_sync_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL,
    status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
    triggered_by VARCHAR(120) NOT NULL DEFAULT 'system',
    total_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    deactivated_count INT UNSIGNED NOT NULL DEFAULT 0,
    reactivated_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    message TEXT NULL,
    details JSON NULL,
    KEY idx_ad_sync_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Hersteller, Artikel, Lieferanten
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS manufacturers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    short_name VARCHAR(50) NULL,
    website VARCHAR(255) NULL,
    contact VARCHAR(255) NULL,
    note TEXT NULL,
    phonetic_key VARCHAR(100) NOT NULL DEFAULT '',
    normalized_name VARCHAR(150) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_manufacturers_name (name),
    KEY idx_manufacturers_phonetic (phonetic_key),
    KEY idx_manufacturers_normalized (normalized_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    inventory_prefix VARCHAR(10) NOT NULL,
    has_serial_number TINYINT(1) NOT NULL DEFAULT 1,
    has_mac_address TINYINT(1) NOT NULL DEFAULT 0,
    has_imei TINYINT(1) NOT NULL DEFAULT 0,
    supports_licenses TINYINT(1) NOT NULL DEFAULT 0,
    icon VARCHAR(50) NOT NULL DEFAULT 'box',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_types_code (code),
    UNIQUE KEY uq_asset_types_prefix (inventory_prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_type_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_categories (asset_type_id, name),
    CONSTRAINT fk_asset_categories_type FOREIGN KEY (asset_type_id) REFERENCES asset_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS articles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    manufacturer_id INT UNSIGNED NOT NULL,
    asset_type_id INT UNSIGNED NOT NULL,
    asset_category_id INT UNSIGNED NULL,
    name VARCHAR(200) NOT NULL,
    article_number VARCHAR(100) NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_articles_manufacturer_name (manufacturer_id, name),
    KEY idx_articles_type (asset_type_id),
    KEY idx_articles_name (name),
    CONSTRAINT fk_articles_manufacturer FOREIGN KEY (manufacturer_id) REFERENCES manufacturers(id),
    CONSTRAINT fk_articles_type FOREIGN KEY (asset_type_id) REFERENCES asset_types(id),
    CONSTRAINT fk_articles_category FOREIGN KEY (asset_category_id) REFERENCES asset_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    street VARCHAR(200) NULL,
    postal_code VARCHAR(20) NULL,
    city VARCHAR(120) NULL,
    country VARCHAR(80) NULL,
    contact_person VARCHAR(200) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(60) NULL,
    customer_number VARCHAR(80) NULL,
    website VARCHAR(255) NULL,
    note TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Bestellungen, Wareneingang
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(60) NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    order_date DATE NULL,
    ordered_by_user_id INT UNSIGNED NULL,
    ordered_by_name VARCHAR(200) NULL,
    status ENUM('draft','ordered','partially_delivered','delivered','cancelled','closed') NOT NULL DEFAULT 'draft',
    expected_delivery_date DATE NULL,
    cost_center_id INT UNSIGNED NULL,
    note TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_purchase_orders_number (order_number),
    KEY idx_purchase_orders_supplier (supplier_id),
    KEY idx_purchase_orders_status (status),
    KEY idx_purchase_orders_expected (expected_delivery_date),
    CONSTRAINT fk_purchase_orders_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_purchase_orders_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE SET NULL,
    CONSTRAINT fk_purchase_orders_user FOREIGN KEY (ordered_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 1,
    article_id INT UNSIGNED NULL,
    asset_type_id INT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    quantity_received INT UNSIGNED NOT NULL DEFAULT 0,
    unit_price DECIMAL(12,2) NULL,
    creates_assets TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_po_items_order (purchase_order_id),
    CONSTRAINT fk_po_items_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_po_items_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE SET NULL,
    CONSTRAINT fk_po_items_type FOREIGN KEY (asset_type_id) REFERENCES asset_types(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goods_receipts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT UNSIGNED NOT NULL,
    received_at DATE NOT NULL,
    delivery_note_number VARCHAR(100) NULL,
    received_by INT UNSIGNED NULL,
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_goods_receipts_order (purchase_order_id),
    CONSTRAINT fk_goods_receipts_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
    CONSTRAINT fk_goods_receipts_user FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Assets
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_statuses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(20) NOT NULL DEFAULT 'neutral',
    is_available TINYINT(1) NOT NULL DEFAULT 0,
    is_final TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_asset_statuses_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sequenz für Inventarnummern: PRÄFIX + YY + laufende Nummer (transaktionssicher)
CREATE TABLE IF NOT EXISTS inventory_sequences (
    prefix VARCHAR(10) NOT NULL,
    year_code CHAR(2) NOT NULL,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (prefix, year_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_number VARCHAR(20) NOT NULL,
    asset_type_id INT UNSIGNED NOT NULL,
    asset_category_id INT UNSIGNED NULL,
    manufacturer_id INT UNSIGNED NULL,
    article_id INT UNSIGNED NULL,
    serial_number VARCHAR(120) NULL,
    serial_number_normalized VARCHAR(120) NULL,
    mac_address VARCHAR(17) NULL,
    imei VARCHAR(20) NULL,
    purchase_date DATE NULL,
    supplier_id INT UNSIGNED NULL,
    purchase_order_id INT UNSIGNED NULL,
    purchase_order_item_id INT UNSIGNED NULL,
    purchase_price DECIMAL(12,2) NULL,
    warranty_until DATE NULL,
    location_id INT UNSIGNED NULL,
    cost_center_id INT UNSIGNED NULL,
    employee_id INT UNSIGNED NULL,
    expected_return_at DATE NULL,
    status_id INT UNSIGNED NOT NULL,
    parent_asset_id INT UNSIGNED NULL,
    is_legacy TINYINT(1) NOT NULL DEFAULT 0,
    name VARCHAR(200) NULL,
    note TEXT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assets_inventory (inventory_number),
    UNIQUE KEY uq_assets_type_serial (asset_type_id, serial_number_normalized),
    KEY idx_assets_status (status_id),
    KEY idx_assets_employee (employee_id),
    KEY idx_assets_location (location_id),
    KEY idx_assets_cost_center (cost_center_id),
    KEY idx_assets_article (article_id),
    KEY idx_assets_manufacturer (manufacturer_id),
    KEY idx_assets_parent (parent_asset_id),
    KEY idx_assets_mac (mac_address),
    KEY idx_assets_imei (imei),
    KEY idx_assets_warranty (warranty_until),
    KEY idx_assets_expected_return (expected_return_at),
    KEY idx_assets_order (purchase_order_id),
    CONSTRAINT fk_assets_type FOREIGN KEY (asset_type_id) REFERENCES asset_types(id),
    CONSTRAINT fk_assets_category FOREIGN KEY (asset_category_id) REFERENCES asset_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_manufacturer FOREIGN KEY (manufacturer_id) REFERENCES manufacturers(id),
    CONSTRAINT fk_assets_article FOREIGN KEY (article_id) REFERENCES articles(id),
    CONSTRAINT fk_assets_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_order_item FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_location FOREIGN KEY (location_id) REFERENCES locations(id),
    CONSTRAINT fk_assets_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id),
    CONSTRAINT fk_assets_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_assets_status FOREIGN KEY (status_id) REFERENCES asset_statuses(id),
    CONSTRAINT fk_assets_parent FOREIGN KEY (parent_asset_id) REFERENCES assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goods_receipt_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    goods_receipt_id INT UNSIGNED NOT NULL,
    purchase_order_item_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    asset_id INT UNSIGNED NULL,
    serial_number VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gr_items_receipt (goods_receipt_id),
    KEY idx_gr_items_po_item (purchase_order_item_id),
    CONSTRAINT fk_gr_items_receipt FOREIGN KEY (goods_receipt_id) REFERENCES goods_receipts(id) ON DELETE CASCADE,
    CONSTRAINT fk_gr_items_po_item FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id),
    CONSTRAINT fk_gr_items_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vollständige Historie je Asset (Anlage, Bewegungen, Feldänderungen)
CREATE TABLE IF NOT EXISTS asset_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    field VARCHAR(60) NULL,
    old_value VARCHAR(500) NULL,
    new_value VARCHAR(500) NULL,
    old_id INT UNSIGNED NULL,
    new_id INT UNSIGNED NULL,
    movement_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    actor_name VARCHAR(120) NOT NULL DEFAULT 'system',
    note VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_asset_history_asset (asset_id, created_at),
    KEY idx_asset_history_type (event_type),
    CONSTRAINT fk_asset_history_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Bewegungen: Entnahme / Retoure (auch unvollständig = offen)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('checkout','return') NOT NULL,
    status ENUM('open','completed','cancelled') NOT NULL DEFAULT 'completed',
    asset_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NULL,
    from_location_id INT UNSIGNED NULL,
    to_location_id INT UNSIGNED NULL,
    cost_center_id INT UNSIGNED NULL,
    movement_date DATE NOT NULL,
    movement_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    condition_code ENUM('ok','worn','damaged','defective') NULL,
    has_damage TINYINT(1) NOT NULL DEFAULT 0,
    damage_description TEXT NULL,
    accessories_checked TINYINT(1) NOT NULL DEFAULT 0,
    accessories_note VARCHAR(500) NULL,
    target_status_code VARCHAR(40) NULL,
    missing_fields JSON NULL,
    note TEXT NULL,
    client_transaction_id CHAR(36) NULL,
    source ENUM('web','mobile','offline_sync','import') NOT NULL DEFAULT 'web',
    created_by INT UNSIGNED NULL,
    created_by_name VARCHAR(120) NOT NULL DEFAULT 'system',
    completed_by INT UNSIGNED NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_movements_client_tx (client_transaction_id),
    KEY idx_movements_asset (asset_id),
    KEY idx_movements_status_type (status, type),
    KEY idx_movements_date (movement_date),
    KEY idx_movements_employee (employee_id),
    CONSTRAINT fk_movements_asset FOREIGN KEY (asset_id) REFERENCES assets(id),
    CONSTRAINT fk_movements_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_movements_from_location FOREIGN KEY (from_location_id) REFERENCES locations(id),
    CONSTRAINT fk_movements_to_location FOREIGN KEY (to_location_id) REFERENCES locations(id),
    CONSTRAINT fk_movements_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE asset_history ADD CONSTRAINT fk_asset_history_movement FOREIGN KEY (movement_id) REFERENCES movements(id) ON DELETE SET NULL;

-- Idempotenz für Offline-Synchronisation
CREATE TABLE IF NOT EXISTS sync_transactions (
    client_transaction_id CHAR(36) NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    payload JSON NOT NULL,
    status ENUM('applied','conflict','rejected') NOT NULL,
    result JSON NULL,
    movement_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sync_tx_user (user_id),
    CONSTRAINT fk_sync_tx_movement FOREIGN KEY (movement_id) REFERENCES movements(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Dokumente (Uploads außerhalb von public/)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('asset','purchase_order','license','movement','supplier','import') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    document_type ENUM('order','order_confirmation','delivery_note','invoice','license','photo','other') NOT NULL DEFAULT 'other',
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NULL,
    note VARCHAR(500) NULL,
    uploaded_by INT UNSIGNED NULL,
    uploaded_by_name VARCHAR(120) NOT NULL DEFAULT 'system',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_documents_stored (stored_name),
    KEY idx_documents_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Lizenzen
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS licenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    manufacturer_id INT UNSIGNED NULL,
    product VARCHAR(200) NOT NULL,
    license_type VARCHAR(100) NULL,
    license_key TEXT NULL,
    license_number VARCHAR(120) NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    purchase_date DATE NULL,
    expires_at DATE NULL,
    supplier_id INT UNSIGNED NULL,
    purchase_order_id INT UNSIGNED NULL,
    cost DECIMAL(12,2) NULL,
    cost_center_id INT UNSIGNED NULL,
    note TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_licenses_expires (expires_at),
    KEY idx_licenses_product (product),
    CONSTRAINT fk_licenses_manufacturer FOREIGN KEY (manufacturer_id) REFERENCES manufacturers(id) ON DELETE SET NULL,
    CONSTRAINT fk_licenses_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    CONSTRAINT fk_licenses_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_licenses_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS license_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id INT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by VARCHAR(120) NOT NULL DEFAULT 'system',
    released_at TIMESTAMP NULL,
    released_by VARCHAR(120) NULL,
    note VARCHAR(255) NULL,
    KEY idx_license_assignments_license (license_id),
    KEY idx_license_assignments_asset (asset_id),
    CONSTRAINT fk_license_assignments_license FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    CONSTRAINT fk_license_assignments_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Import (Migration Altdaten)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_type VARCHAR(40) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    status ENUM('preview','imported','failed') NOT NULL DEFAULT 'preview',
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    valid_rows INT UNSIGNED NOT NULL DEFAULT 0,
    imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
    error_rows INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_rows INT UNSIGNED NOT NULL DEFAULT 0,
    report JSON NULL,
    created_by INT UNSIGNED NULL,
    created_by_name VARCHAR(120) NOT NULL DEFAULT 'system',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
