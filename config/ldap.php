<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * Basiswerte fuer die Active-Directory-Anbindung.
 *
 * Alle Werte koennen im Adminbereich ueberschrieben werden (Tabelle
 * `settings`). Das Bind-Passwort wird ausschliesslich im Adminbereich gepflegt
 * und verschluesselt gespeichert (kein Eintrag in der .env).
 */
return [
    // Beschriftung der Hauptquelle (z. B. "Zentrale"), sichtbar im Adminbereich
    // und – bei mehreren Identitaetsquellen – in der Telefonliste.
    'label' => Env::get('LDAP_LABEL', 'Zentrale'),
    // Ein oder mehrere Server (IP oder Hostname, getrennt durch Komma,
    // Semikolon oder Leerzeichen). Sie werden der Reihe nach versucht, falls
    // ein Server nicht erreichbar ist.
    'host' => Env::get('LDAP_HOST', ''),
    'port' => Env::int('LDAP_PORT', 636),
    'use_tls' => Env::bool('LDAP_USE_TLS', true),
    'verify_cert' => Env::bool('LDAP_VERIFY_CERT', true),
    'base_dn' => Env::get('LDAP_BASE_DN', ''),
    'bind_dn' => Env::get('LDAP_BIND_DN', ''),
    'filter' => Env::get('LDAP_FILTER', '(&(objectClass=user)(objectCategory=person))'),
    'timeout' => Env::int('LDAP_TIMEOUT', 10),
    'page_size' => Env::int('LDAP_PAGE_SIZE', 500),
    'sync_interval' => Env::int('LDAP_SYNC_INTERVAL', 3600),
    // Pfad(e) der fuer die Rechtevergabe relevanten Gruppen (mehrere per ";"
    // getrennt). Leer = keine Gruppen-Synchronisation.
    'group_base_dn' => Env::get('LDAP_GROUP_BASE_DN', ''),
    'group_filter' => Env::get('LDAP_GROUP_FILTER', '(objectClass=group)'),
    'group_name_attribute' => Env::get('LDAP_GROUP_NAME_ATTRIBUTE', 'cn'),
    'attributes' => [
        'display_name' => Env::get('LDAP_ATTR_DISPLAY_NAME', 'displayName'),
        'first_name' => Env::get('LDAP_ATTR_FIRST_NAME', 'givenName'),
        'last_name' => Env::get('LDAP_ATTR_LAST_NAME', 'sn'),
        'phone' => Env::get('LDAP_ATTR_PHONE', 'telephoneNumber'),
        'mobile' => Env::get('LDAP_ATTR_MOBILE', 'mobile'),
        'email' => Env::get('LDAP_ATTR_EMAIL', 'mail'),
        'department' => Env::get('LDAP_ATTR_DEPARTMENT', 'department'),
        'modified' => Env::get('LDAP_ATTR_MODIFIED', 'whenChanged'),
        'unique_id' => Env::get('LDAP_ATTR_UNIQUE_ID', 'objectGUID'),
        'samaccount_name' => Env::get('LDAP_ATTR_SAMACCOUNT_NAME', 'sAMAccountName'),
    ],
];
