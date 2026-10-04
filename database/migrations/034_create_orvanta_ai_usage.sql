-- Orvanta: Nutzung der KI-Textunterstuetzung (nur Zaehler, keine Inhalte).
-- Das Modell stammt aus den globalen KI-Einstellungen des Adminbereichs
-- (settings.office_ai_*); hier werden ausschliesslich Zeitpunkt, Einsatzort
-- und Token-Zahlen je Aufruf festgehalten. Die Auswertung im Adminbereich
-- vergibt Pseudonyme erst beim Rendern - user_uid bleibt intern.
CREATE TABLE orvanta_ai_usage (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_uid VARCHAR(100) NOT NULL,
    kind ENUM('mail_compose', 'mail_reply', 'mail_forward', 'event', 'reminder') NOT NULL,
    model VARCHAR(100) NOT NULL DEFAULT '',
    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_orvanta_ai_usage_created (created_at),
    KEY idx_orvanta_ai_usage_user (user_uid, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
