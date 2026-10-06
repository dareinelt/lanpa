-- SMTP-/IMAP-Proxy fuer Orvanta: Mailserver je Identitaetsquelle,
-- Postfaecher (Zugangsdaten verschluesselt per SecretBox) und die Zuordnung
-- AD-Benutzer (phonebook.id) -> Postfach. Benutzer mit Zuordnung nutzen in
-- Orvanta das Postfach ueber den mail-proxy-Container statt Exchange/EWS.
--
-- identity_source_id: 0 = Hauptquelle (Einstellungen, keine Zeile in
-- identity_sources), sonst identity_sources.id - daher wie bei phonebook
-- ohne Fremdschluessel; die Gueltigkeit prueft MailProxyResolver.
CREATE TABLE IF NOT EXISTS mail_proxy_servers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    identity_source_id INT UNSIGNED NOT NULL DEFAULT 0,
    name VARCHAR(100) NOT NULL,
    smtp_host VARCHAR(253) NOT NULL,
    smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
    smtp_security ENUM('starttls', 'tls', 'none') NOT NULL DEFAULT 'starttls',
    smtp_auth TINYINT(1) NOT NULL DEFAULT 1,
    imap_host VARCHAR(253) NOT NULL,
    imap_port SMALLINT UNSIGNED NOT NULL DEFAULT 993,
    imap_security ENUM('tls', 'starttls') NOT NULL DEFAULT 'tls',
    verify_tls TINYINT(1) NOT NULL DEFAULT 1,
    timeout_seconds TINYINT UNSIGNED NOT NULL DEFAULT 20,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_mail_proxy_servers_source (identity_source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Postfaecher eines Mailservers. password_encrypted enthaelt ausschliesslich
-- SecretBox-Chiffrat ("enc:v1:..."), niemals Klartext.
CREATE TABLE IF NOT EXISTS mail_proxy_mailboxes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    server_id INT UNSIGNED NOT NULL,
    username VARCHAR(190) NOT NULL,
    email_address VARCHAR(254) NOT NULL,
    display_name VARCHAR(190) NOT NULL DEFAULT '',
    password_encrypted TEXT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_mail_proxy_mailboxes_email (server_id, email_address),
    KEY idx_mail_proxy_mailboxes_server (server_id, active),
    CONSTRAINT fk_mail_proxy_mailboxes_server FOREIGN KEY (server_id)
        REFERENCES mail_proxy_servers (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zuordnung AD-Benutzer -> Postfach. Stabiler Schluessel ist phonebook.id
-- (Upsert per objectGUID), nie der Anzeigename. Je Benutzer und je Postfach
-- hoechstens eine Zuordnung.
CREATE TABLE IF NOT EXISTS mail_proxy_mappings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    identity_source_id INT UNSIGNED NOT NULL DEFAULT 0,
    phonebook_id INT UNSIGNED NOT NULL,
    mailbox_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_mail_proxy_mappings_user (phonebook_id),
    UNIQUE KEY uniq_mail_proxy_mappings_mailbox (mailbox_id),
    KEY idx_mail_proxy_mappings_source (identity_source_id),
    CONSTRAINT fk_mail_proxy_mappings_user FOREIGN KEY (phonebook_id)
        REFERENCES phonebook (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_mail_proxy_mappings_mailbox FOREIGN KEY (mailbox_id)
        REFERENCES mail_proxy_mailboxes (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zustand (genau eine Zeile): generation wird bei jeder Aenderung der
-- Proxy-Konfiguration erhoeht und invalidiert damit alle Cache-Eintraege;
-- dazu Ergebnis der letzten Proxy-Verbindung fuer die Diagnose (ohne Geheimnisse).
CREATE TABLE IF NOT EXISTS mail_proxy_state (
    id TINYINT UNSIGNED NOT NULL,
    generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
    last_success_at DATETIME NULL,
    last_error_at DATETIME NULL,
    last_error VARCHAR(500) NOT NULL DEFAULT '',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO mail_proxy_state (id, generation) VALUES (1, 1);
