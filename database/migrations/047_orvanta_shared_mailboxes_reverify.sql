-- Orvanta: zusaetzliche Postfaecher neu pruefen.
--
-- Bisher hat Orvanta eine Zuordnung nur mit dem Dienstkonto geprueft. Mit
-- ApplicationImpersonation erreicht das Dienstkonto jedes Postfach, die
-- Pruefung bestaetigte also nicht den Vollzugriff des Benutzers. Orvanta
-- prueft jetzt als der Benutzer; alle vorhandenen Zuordnungen werden bei der
-- naechsten Anmeldung des Benutzers neu geprueft (checked_at = NULL gilt als
-- abgelaufen). Bis dahin bleibt der bisherige Zustand bestehen.

UPDATE orvanta_shared_mailboxes SET checked_at = NULL;
