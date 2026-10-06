-- Orvanta: persoenliches Woerterbuch der Rechtschreibpruefung.
--
-- Woerter, die ein Benutzer im Kontextmenue "Zum Woerterbuch hinzufuegen"
-- bestaetigt hat, gelten fuer ihn auf allen Geraeten als korrekt. Die
-- Schreibweise wird genau unterschieden (utf8mb4_bin), damit "Lanpa" und
-- "lanpa" getrennte Eintraege sind. user_uid ist die Office-Kennung
-- (klein geschrieben) wie bei den uebrigen Orvanta-Tabellen.
CREATE TABLE orvanta_spellcheck_words (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_uid VARCHAR(100) NOT NULL,
    word VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orvanta_spellcheck_words_user_word (user_uid, word)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
