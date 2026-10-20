-- 008_helpdesk.sql – IT Help Desk / Ticketsystem
-- Alle Tabellen InnoDB / utf8mb4. Zeitstempel in UTC.
-- Mitarbeiter (employees), Assets (assets), Standorte, Kostenstellen und Benutzer werden referenziert, nie kopiert.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Sequenz für Ticketnummern: PREFIX-JJJJ-NNNNNN (transaktionssicher per SELECT ... FOR UPDATE)
-- ---------------------------------------------------------------------------
CREATE TABLE ticket_sequences (
    prefix VARCHAR(10) NOT NULL,
    year_code CHAR(4) NOT NULL,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (prefix, year_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Stammdaten: Typen, Status, Prioritäten, Kategorien, Gruppen, SLA
-- ---------------------------------------------------------------------------
CREATE TABLE ticket_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    icon VARCHAR(40) NULL,
    color VARCHAR(20) NOT NULL DEFAULT 'neutral',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- category steuert die fachliche Bedeutung (offen/wartend/gelöst/geschlossen/storniert), der Workflow selbst liegt im Service.
CREATE TABLE ticket_statuses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    category ENUM('new','open','pending','resolved','closed','cancelled') NOT NULL DEFAULT 'open',
    color VARCHAR(20) NOT NULL DEFAULT 'neutral',
    pauses_sla TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_statuses_code (code),
    KEY idx_ticket_statuses_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- level: 1 = niedrigste, 4 = höchste Priorität (Basis für Matrix Auswirkung × Dringlichkeit)
CREATE TABLE ticket_priorities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    level TINYINT UNSIGNED NOT NULL DEFAULT 2,
    color VARCHAR(20) NOT NULL DEFAULT 'neutral',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_priorities_code (code),
    UNIQUE KEY uq_ticket_priorities_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    email VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_group_members (
    group_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    is_lead TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, user_id),
    KEY idx_ticket_group_members_user (user_id),
    CONSTRAINT fk_ticket_group_members_group FOREIGN KEY (group_id) REFERENCES ticket_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_group_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kategorien hierarchisch (parent_id NULL = Hauptkategorie)
CREATE TABLE ticket_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    default_group_id INT UNSIGNED NULL,
    default_priority_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ticket_categories_parent (parent_id, sort_order),
    KEY idx_ticket_categories_name (name),
    CONSTRAINT fk_ticket_categories_parent FOREIGN KEY (parent_id) REFERENCES ticket_categories(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ticket_categories_group FOREIGN KEY (default_group_id) REFERENCES ticket_groups(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_categories_priority FOREIGN KEY (default_priority_id) REFERENCES ticket_priorities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SLA-Regeln: Zuordnung über Priorität und/oder Kategorie; Zeiten in Minuten.
-- business_hours_only = 1 rechnet nur innerhalb business_days/business_start..business_end (lokale Zeitzone der App).
CREATE TABLE ticket_slas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    priority_id INT UNSIGNED NULL,
    category_id INT UNSIGNED NULL,
    response_minutes INT UNSIGNED NOT NULL,
    resolution_minutes INT UNSIGNED NOT NULL,
    business_hours_only TINYINT(1) NOT NULL DEFAULT 0,
    business_days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
    business_start TIME NOT NULL DEFAULT '08:00:00',
    business_end TIME NOT NULL DEFAULT '17:00:00',
    warning_percent TINYINT UNSIGNED NULL,
    escalation_percent TINYINT UNSIGNED NULL,
    escalation_group_id INT UNSIGNED NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_slas_name (name),
    KEY idx_ticket_slas_match (is_active, priority_id, category_id),
    CONSTRAINT fk_ticket_slas_priority FOREIGN KEY (priority_id) REFERENCES ticket_priorities(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_slas_category FOREIGN KEY (category_id) REFERENCES ticket_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_slas_escalation_group FOREIGN KEY (escalation_group_id) REFERENCES ticket_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL,
    color VARCHAR(20) NOT NULL DEFAULT 'neutral',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Tickets
-- ---------------------------------------------------------------------------
CREATE TABLE tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(20) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    description MEDIUMTEXT NOT NULL,
    ticket_type_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,
    subcategory_id INT UNSIGNED NULL,
    status_id INT UNSIGNED NOT NULL,
    priority_id INT UNSIGNED NOT NULL,
    impact TINYINT UNSIGNED NOT NULL DEFAULT 2,
    urgency TINYINT UNSIGNED NOT NULL DEFAULT 2,
    sla_id INT UNSIGNED NULL,
    requester_employee_id INT UNSIGNED NULL,
    requester_user_id INT UNSIGNED NULL,
    affected_employee_id INT UNSIGNED NULL,
    assignee_user_id INT UNSIGNED NULL,
    deputy_user_id INT UNSIGNED NULL,
    group_id INT UNSIGNED NULL,
    location_id INT UNSIGNED NULL,
    cost_center_id INT UNSIGNED NULL,
    external_reference VARCHAR(120) NULL,
    source ENUM('web','portal','api','email','phone','scheduler') NOT NULL DEFAULT 'web',
    response_due_at TIMESTAMP NULL,
    resolution_due_at TIMESTAMP NULL,
    first_response_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    closed_at TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    sla_paused_at TIMESTAMP NULL,
    sla_paused_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    sla_response_state ENUM('none','ok','warning','breached','met') NOT NULL DEFAULT 'none',
    sla_resolution_state ENUM('none','ok','warning','breached','met') NOT NULL DEFAULT 'none',
    escalation_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
    escalated_at TIMESTAMP NULL,
    last_public_comment_at TIMESTAMP NULL,
    last_agent_comment_at TIMESTAMP NULL,
    reopen_count INT UNSIGNED NOT NULL DEFAULT 0,
    resolution TEXT NULL,
    close_reason VARCHAR(255) NULL,
    merged_into_ticket_id INT UNSIGNED NULL,
    knowledge_article_id INT UNSIGNED NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_by_name VARCHAR(200) NOT NULL DEFAULT 'system',
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tickets_number (number),
    KEY idx_tickets_status (status_id, updated_at),
    KEY idx_tickets_priority (priority_id),
    KEY idx_tickets_type (ticket_type_id),
    KEY idx_tickets_category (category_id, subcategory_id),
    KEY idx_tickets_assignee (assignee_user_id, status_id),
    KEY idx_tickets_group (group_id, status_id),
    KEY idx_tickets_requester (requester_employee_id),
    KEY idx_tickets_requester_user (requester_user_id),
    KEY idx_tickets_affected (affected_employee_id),
    KEY idx_tickets_location (location_id),
    KEY idx_tickets_cost_center (cost_center_id),
    KEY idx_tickets_sla (sla_id),
    KEY idx_tickets_response_due (response_due_at),
    KEY idx_tickets_resolution_due (resolution_due_at),
    KEY idx_tickets_created (created_at),
    KEY idx_tickets_resolved (resolved_at),
    KEY idx_tickets_closed (closed_at),
    KEY idx_tickets_merged (merged_into_ticket_id),
    KEY idx_tickets_external (external_reference),
    FULLTEXT KEY ft_tickets_text (subject, description),
    CONSTRAINT fk_tickets_type FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id),
    CONSTRAINT fk_tickets_category FOREIGN KEY (category_id) REFERENCES ticket_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_subcategory FOREIGN KEY (subcategory_id) REFERENCES ticket_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_status FOREIGN KEY (status_id) REFERENCES ticket_statuses(id),
    CONSTRAINT fk_tickets_priority FOREIGN KEY (priority_id) REFERENCES ticket_priorities(id),
    CONSTRAINT fk_tickets_sla FOREIGN KEY (sla_id) REFERENCES ticket_slas(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_requester_employee FOREIGN KEY (requester_employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_requester_user FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_affected_employee FOREIGN KEY (affected_employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_assignee FOREIGN KEY (assignee_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_deputy FOREIGN KEY (deputy_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_group FOREIGN KEY (group_id) REFERENCES ticket_groups(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_merged_into FOREIGN KEY (merged_into_ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verknüpfung zu vorhandenen Assets (n:m), keine redundanten Assetdaten
CREATE TABLE ticket_assets (
    ticket_id INT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NOT NULL,
    note VARCHAR(255) NULL,
    added_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ticket_id, asset_id),
    KEY idx_ticket_assets_asset (asset_id),
    CONSTRAINT fk_ticket_assets_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_assets_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ticket_assets_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kommunikation: öffentliche Antworten, interne Notizen. Einträge sind unveränderlich (kein updated_at).
CREATE TABLE ticket_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    type ENUM('public','internal') NOT NULL DEFAULT 'public',
    body MEDIUMTEXT NOT NULL,
    author_user_id INT UNSIGNED NULL,
    author_name VARCHAR(200) NOT NULL DEFAULT 'system',
    is_requester TINYINT(1) NOT NULL DEFAULT 0,
    source ENUM('web','portal','api','email','system') NOT NULL DEFAULT 'web',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ticket_comments_ticket (ticket_id, created_at),
    KEY idx_ticket_comments_author (author_user_id),
    FULLTEXT KEY ft_ticket_comments_body (body),
    CONSTRAINT fk_ticket_comments_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_comments_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ticketverlauf (fachlich lesbare Historie, ergänzend zum Audit-Log)
CREATE TABLE ticket_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    user_id INT UNSIGNED NULL,
    user_name VARCHAR(200) NOT NULL DEFAULT 'system',
    field VARCHAR(60) NULL,
    old_value VARCHAR(500) NULL,
    new_value VARCHAR(500) NULL,
    payload JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ticket_events_ticket (ticket_id, created_at, id),
    KEY idx_ticket_events_type (type, created_at),
    CONSTRAINT fk_ticket_events_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_tag_relations (
    ticket_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ticket_id, tag_id),
    KEY idx_ticket_tag_relations_tag (tag_id),
    CONSTRAINT fk_ticket_tag_relations_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_tag_relations_tag FOREIGN KEY (tag_id) REFERENCES ticket_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gerichtete Beziehungen: ticket_id --type--> related_ticket_id
-- duplicate_of: Ticket ist Duplikat von; parent_of: Hauptticket → Unterticket; related: verwandt;
-- problem_of: Problem → Incident; change_for: Change → Ticket
CREATE TABLE ticket_relations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    related_ticket_id INT UNSIGNED NOT NULL,
    type ENUM('duplicate_of','parent_of','related','problem_of','change_for') NOT NULL DEFAULT 'related',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_relations (ticket_id, related_ticket_id, type),
    KEY idx_ticket_relations_related (related_ticket_id),
    CONSTRAINT fk_ticket_relations_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_relations_related FOREIGN KEY (related_ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_relations_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_watchers (
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ticket_id, user_id),
    KEY idx_ticket_watchers_user (user_id),
    CONSTRAINT fk_ticket_watchers_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_watchers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_worklogs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    user_name VARCHAR(200) NOT NULL DEFAULT 'system',
    started_at TIMESTAMP NULL,
    ended_at TIMESTAMP NULL,
    minutes INT UNSIGNED NOT NULL,
    activity VARCHAR(255) NOT NULL,
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ticket_worklogs_ticket (ticket_id, created_at),
    KEY idx_ticket_worklogs_user (user_id, started_at),
    CONSTRAINT fk_ticket_worklogs_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_worklogs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    subject VARCHAR(255) NULL,
    body TEXT NULL,
    ticket_type_id INT UNSIGNED NULL,
    category_id INT UNSIGNED NULL,
    subcategory_id INT UNSIGNED NULL,
    priority_id INT UNSIGNED NULL,
    group_id INT UNSIGNED NULL,
    assignee_user_id INT UNSIGNED NULL,
    sla_id INT UNSIGNED NULL,
    tags VARCHAR(500) NULL,
    is_portal_visible TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_templates_name (name),
    CONSTRAINT fk_ticket_templates_type FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_templates_category FOREIGN KEY (category_id) REFERENCES ticket_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_templates_subcategory FOREIGN KEY (subcategory_id) REFERENCES ticket_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_templates_priority FOREIGN KEY (priority_id) REFERENCES ticket_priorities(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_templates_group FOREIGN KEY (group_id) REFERENCES ticket_groups(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_templates_assignee FOREIGN KEY (assignee_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_templates_sla FOREIGN KEY (sla_id) REFERENCES ticket_slas(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_templates_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Regelengine: conditions/actions als JSON (Schema siehe docs/helpdesk.md), serverseitig ausgewertet
CREATE TABLE ticket_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    trigger_event ENUM('created','updated','status_changed','comment_added','sla_warning','sla_breached') NOT NULL DEFAULT 'created',
    conditions JSON NOT NULL,
    actions JSON NOT NULL,
    stop_processing TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_rules_name (name),
    KEY idx_ticket_rules_trigger (trigger_event, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Benachrichtigungsprotokoll; event_key + recipient sichern Idempotenz (z. B. SLA-Warnung nur einmal je Ticket)
CREATE TABLE ticket_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    event_key VARCHAR(120) NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    status ENUM('sent','failed','skipped') NOT NULL,
    error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_notifications_event (ticket_id, event_key, recipient),
    KEY idx_ticket_notifications_created (created_at),
    CONSTRAINT fk_ticket_notifications_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Wissensdatenbank
-- ---------------------------------------------------------------------------
CREATE TABLE knowledge_articles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    summary VARCHAR(500) NULL,
    body MEDIUMTEXT NOT NULL,
    category_id INT UNSIGNED NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    visibility ENUM('internal','public') NOT NULL DEFAULT 'internal',
    source_ticket_id INT UNSIGNED NULL,
    view_count INT UNSIGNED NOT NULL DEFAULT 0,
    published_at TIMESTAMP NULL,
    created_by INT UNSIGNED NULL,
    created_by_name VARCHAR(200) NOT NULL DEFAULT 'system',
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_knowledge_articles_slug (slug),
    KEY idx_knowledge_articles_status (status, visibility, updated_at),
    KEY idx_knowledge_articles_category (category_id),
    KEY idx_knowledge_articles_source (source_ticket_id),
    FULLTEXT KEY ft_knowledge_articles_text (title, summary, body),
    CONSTRAINT fk_knowledge_articles_category FOREIGN KEY (category_id) REFERENCES ticket_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_knowledge_articles_ticket FOREIGN KEY (source_ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
    CONSTRAINT fk_knowledge_articles_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_knowledge_articles_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tickets
    ADD CONSTRAINT fk_tickets_knowledge_article FOREIGN KEY (knowledge_article_id) REFERENCES knowledge_articles(id) ON DELETE SET NULL;

CREATE TABLE knowledge_article_tags (
    article_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (article_id, tag_id),
    KEY idx_knowledge_article_tags_tag (tag_id),
    CONSTRAINT fk_knowledge_article_tags_article FOREIGN KEY (article_id) REFERENCES knowledge_articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_knowledge_article_tags_tag FOREIGN KEY (tag_id) REFERENCES ticket_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verknüpfung Artikel <-> Ticket (n:m, z. B. „Artikel hat geholfen“ / Lösungsquelle)
CREATE TABLE knowledge_article_tickets (
    article_id INT UNSIGNED NOT NULL,
    ticket_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (article_id, ticket_id),
    KEY idx_knowledge_article_tickets_ticket (ticket_id),
    CONSTRAINT fk_knowledge_article_tickets_article FOREIGN KEY (article_id) REFERENCES knowledge_articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_knowledge_article_tickets_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_knowledge_article_tickets_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Anhänge nutzen die vorhandene Dokumentinfrastruktur (documents, storage/uploads)
-- ---------------------------------------------------------------------------
ALTER TABLE documents
    MODIFY COLUMN entity_type ENUM('asset','purchase_order','license','movement','supplier','import','handover','ticket','knowledge_article') NOT NULL,
    MODIFY COLUMN document_type ENUM('order','order_confirmation','delivery_note','invoice','license','photo','signature','handover_protocol','movement_receipt','attachment','other') NOT NULL DEFAULT 'other';

-- Zusatzdaten je Ticketanhang: Zuordnung zu Kommentar und Sichtbarkeit (interne Anhänge sieht der Melder nicht)
CREATE TABLE ticket_attachments (
    document_id INT UNSIGNED NOT NULL PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    comment_id INT UNSIGNED NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ticket_attachments_ticket (ticket_id, created_at),
    KEY idx_ticket_attachments_comment (comment_id),
    CONSTRAINT fk_ticket_attachments_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_attachments_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_attachments_comment FOREIGN KEY (comment_id) REFERENCES ticket_comments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
