-- Mehrere Identitaetsquellen (Active Directorys von Zweigstellen,
-- Tochtergesellschaften, ...).
--
-- Die Hauptquelle bleibt wie bisher in der Tabelle `settings` (ldap_*) bzw. in
-- den LDAP_*-Umgebungsvariablen konfiguriert und traegt die ID 0. Weitere
-- Quellen stehen in `identity_sources`. Jede Quelle kann mehrere Server
-- (IP-Adressen oder Hostnamen, einer je Zeile) haben, die der Reihe nach
-- versucht werden, falls ein Server nicht erreichbar ist.
--
-- Zugangsdaten (Bind-Passwort, Konto fuer den Domaenenbeitritt der
-- Windows-Anmeldung) werden im Adminbereich gepflegt und ausschliesslich
-- verschluesselt gespeichert (App\Security\SecretBox, Schluessel in
-- storage/keys/, nie in der Datenbank).

CREATE TABLE IF NOT EXISTS identity_sources (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_key VARCHAR(32) NOT NULL,
    label VARCHAR(100) NOT NULL,
    hosts TEXT NOT NULL,
    port INT UNSIGNED NOT NULL DEFAULT 636,
    use_tls TINYINT(1) NOT NULL DEFAULT 1,
    verify_cert TINYINT(1) NOT NULL DEFAULT 1,
    timeout INT UNSIGNED NOT NULL DEFAULT 10,
    base_dn VARCHAR(255) NOT NULL DEFAULT '',
    bind_dn VARCHAR(255) NOT NULL DEFAULT '',
    bind_password TEXT NULL,
    user_filter VARCHAR(512) NOT NULL DEFAULT '(&(objectClass=user)(objectCategory=person))',
    group_base_dn TEXT NULL,
    group_filter VARCHAR(512) NOT NULL DEFAULT '(objectClass=group)',
    group_name_attribute VARCHAR(64) NOT NULL DEFAULT 'cn',
    attributes TEXT NULL,
    -- Windows-Anmeldung (NTLM) ueber eine eigene auth-Instanz je Domaene.
    sso_enabled TINYINT(1) NOT NULL DEFAULT 0,
    sso_domain VARCHAR(15) NOT NULL DEFAULT '',
    sso_dcs TEXT NULL,
    sso_join_user VARCHAR(255) NOT NULL DEFAULT '',
    sso_join_password TEXT NULL,
    sso_networks TEXT NULL,
    sso_hostnames TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_identity_sources_key (source_key),
    KEY idx_identity_sources_active (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Herkunft der Telefonbuch-Eintraege (0 = Hauptquelle). SamAccountNames sind
-- nur innerhalb einer Quelle eindeutig.
ALTER TABLE phonebook
    ADD COLUMN identity_source_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER external_id;

ALTER TABLE phonebook
    ADD KEY idx_phonebook_source (identity_source_id, active),
    ADD KEY idx_phonebook_source_samaccount (identity_source_id, samaccount_name);

-- Herkunft der AD-Gruppen; derselbe DN darf in unabhaengigen Verzeichnissen
-- vorkommen.
ALTER TABLE ad_groups
    ADD COLUMN identity_source_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER id;

ALTER TABLE ad_groups
    DROP INDEX uniq_ad_groups_dn,
    ADD UNIQUE KEY uniq_ad_groups_source_dn (identity_source_id, dn_hash);
