-- Mail-Proxy: feste Postfachgroesse je Proxy-Postfach (MB, 0 = ohne Grenze).
--
-- Fuer Proxy-Postfaecher werden weder Exchange noch AD nach Postfachgrenzen
-- gefragt; die Belegungsanzeige in Orvanta verwendet diesen Wert als Grenze
-- (die Belegung selbst liefert der IMAP-Server).
ALTER TABLE mail_proxy_mailboxes
    ADD COLUMN quota_mb INT UNSIGNED NOT NULL DEFAULT 0 AFTER display_name;
