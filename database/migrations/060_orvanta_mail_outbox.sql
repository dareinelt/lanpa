-- Warteschlange fuer Orvanta-Mails, die wegen eines kurzfristig nicht
-- erreichbaren Exchange-Servers nicht sofort gesendet werden konnten.
--
--   orvanta_mail_outbox   Vollstaendiger Versandauftrag (Empfaenger, Betreff,
--                         Text, Anhaenge, Absender, Postfach) eines Exchange-
--                         Postfachs. Der Auftrag wird erst angelegt, wenn
--                         feststeht, dass die Nachricht den Server nicht
--                         erreicht hat - ein spaeterer Versuch kann also keine
--                         doppelte Zustellung verursachen. Der Dienst
--                         scripts/mail_worker.php stellt die Auftraege zu,
--                         sobald Exchange wieder antwortet.
--
-- Anhaenge liegen base64-kodiert im JSON-Auftrag (payload), damit die Spalte
-- auch binaere Inhalte aufnimmt; LONGTEXT deckt die zulaessigen 15 MB ab.
CREATE TABLE IF NOT EXISTS orvanta_mail_outbox (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_uid VARCHAR(100) NOT NULL,
    backend VARCHAR(20) NOT NULL DEFAULT 'exchange',
    mailbox VARCHAR(190) NOT NULL DEFAULT '',
    from_email VARCHAR(190) NOT NULL DEFAULT '',
    kind VARCHAR(20) NOT NULL DEFAULT 'new',
    subject VARCHAR(255) NOT NULL DEFAULT '',
    recipients TEXT NULL,
    payload LONGTEXT NOT NULL,
    draft_id VARCHAR(512) NOT NULL DEFAULT '',
    change_key VARCHAR(512) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NOT NULL DEFAULT '',
    next_attempt_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_orvanta_mail_outbox_due (status, next_attempt_at),
    KEY idx_orvanta_mail_outbox_user (user_uid, status),
    KEY idx_orvanta_mail_outbox_sent (status, sent_at),
    KEY idx_orvanta_mail_outbox_failed (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
