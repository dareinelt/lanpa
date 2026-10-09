-- Orvanta: Herkunft zusaetzlicher Postfaecher (Auto-Mapping aus dem AD).
--
-- Welche Postfaecher ein Benutzer per Vollzugriff nutzen darf, pflegt der
-- Exchange-Administrator im ECP. Exchange traegt den Benutzer dabei in
-- msExchDelegateListLink des Postfachs ein (Auto-Mapping); Outlook bindet
-- diese Postfaecher ueber den Rueckverweis msExchDelegateListBL automatisch
-- ein. Orvanta liest denselben Rueckverweis aus der Identitaetsquelle des
-- Benutzers und gleicht die Zuordnungen bei der Anmeldung ab:
--   source = 'exchange'  aus dem AD uebernommen, wird automatisch angelegt,
--                        aktualisiert und entfernt
--   source = 'admin'     im Adminbereich gepflegt (Ergaenzung, z. B. ohne
--                        Auto-Mapping erteilter Vollzugriff)
ALTER TABLE orvanta_shared_mailboxes
    ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER display_name;
