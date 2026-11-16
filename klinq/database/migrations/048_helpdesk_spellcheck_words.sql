-- 048_helpdesk_spellcheck_words.sql – persönliches Wörterbuch der Rechtschreibprüfung
-- Wörter, die ein Benutzer im Kontextmenü eines Help-Desk-Textfelds über
-- „Zum Wörterbuch hinzufügen“ bestätigt hat, gelten für ihn auf allen Geräten als korrekt.
-- Die Schreibweise wird genau unterschieden (utf8mb4_bin), damit „Klinq“ und „klinq“
-- getrennte Einträge sind. Zeitstempel in UTC, InnoDB / utf8mb4.

SET NAMES utf8mb4;

CREATE TABLE helpdesk_spellcheck_words (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    word VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_helpdesk_spellcheck_words_user_word (user_id, word),
    CONSTRAINT fk_helpdesk_spellcheck_words_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
