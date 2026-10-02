ALTER TABLE emergency_plans
    ADD COLUMN review_state VARCHAR(16) NOT NULL DEFAULT 'draft',
    ADD COLUMN contributors MEDIUMTEXT NULL,
    ADD COLUMN submitted_by VARCHAR(190) NULL,
    ADD COLUMN submitted_at DATETIME NULL,
    ADD COLUMN published_definition MEDIUMTEXT NULL,
    ADD COLUMN published_revision INT NULL;

-- Vorhandene Veröffentlichungen haben noch keinen Vier-Augen-Nachweis.
UPDATE emergency_plans SET published = 0, contributors = JSON_ARRAY(updated_by);

CREATE TABLE emergency_plan_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id INT UNSIGNED NOT NULL,
    revision INT NOT NULL,
    actor VARCHAR(190) NOT NULL,
    action VARCHAR(32) NOT NULL,
    comment TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY emergency_review_plan (plan_id, id),
    FOREIGN KEY (plan_id) REFERENCES emergency_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
