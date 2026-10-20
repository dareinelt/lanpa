-- Wiederverwendbare Bestellvorlagen, Bedarfsmeldungen und nachbestellbares Verbrauchsmaterial.

ALTER TABLE articles
    ADD COLUMN is_consumable TINYINT(1) NOT NULL DEFAULT 0 AFTER is_handover_relevant,
    ADD COLUMN minimum_stock INT UNSIGNED NULL AFTER is_consumable,
    ADD COLUMN stock_quantity INT UNSIGNED NOT NULL DEFAULT 0 AFTER minimum_stock,
    ADD INDEX idx_articles_replenishment (is_consumable, is_active, stock_quantity);

CREATE TABLE purchase_order_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    supplier_id INT UNSIGNED NULL,
    cost_center_id INT UNSIGNED NULL,
    note TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_order_templates_name (name),
    CONSTRAINT fk_order_template_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_template_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_template_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_order_template_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_template_id INT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    article_id INT UNSIGNED NULL,
    asset_type_id INT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(12,2) NULL,
    creates_assets TINYINT(1) NOT NULL DEFAULT 0,
    note VARCHAR(255) NULL,
    UNIQUE KEY uq_order_template_position (purchase_order_template_id, position),
    CONSTRAINT fk_order_template_item_template FOREIGN KEY (purchase_order_template_id) REFERENCES purchase_order_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_template_item_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_template_item_type FOREIGN KEY (asset_type_id) REFERENCES asset_types(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requested_by INT UNSIGNED NULL,
    requested_by_name VARCHAR(120) NOT NULL,
    cost_center_id INT UNSIGNED NULL,
    status ENUM('open','converted','cancelled') NOT NULL DEFAULT 'open',
    note TEXT NULL,
    purchase_order_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_purchase_requests_user_status (requested_by, status),
    KEY idx_purchase_requests_status (status),
    CONSTRAINT fk_purchase_request_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_purchase_request_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE SET NULL,
    CONSTRAINT fk_purchase_request_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_request_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_request_id INT UNSIGNED NOT NULL,
    article_id INT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    note VARCHAR(255) NULL,
    CONSTRAINT fk_purchase_request_item_request FOREIGN KEY (purchase_request_id) REFERENCES purchase_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_request_item_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
