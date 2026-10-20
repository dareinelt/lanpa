<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * KLINQ-Integration.
 *
 * KLINQ (Help Desk, Asset-Management, Statistiken) bleibt ein eigenstaendiger
 * Container-Verbund. Der auth-Container stellt ihn unter demselben Host
 * bereit (Standard /klinq) und uebernimmt Anmeldung und SSO; die Anwendung
 * uebergibt die Identitaet signiert an KLINQ.
 *
 * Infrastrukturwerte und Secrets stehen hier (nie in der Datenbank), Pfade und
 * Freigaben werden im Adminbereich gepflegt.
 */
return [
    'enabled' => Env::bool('KLINQ_ENABLED', false),

    // Oeffentlicher Pfad hinter dem auth-Container (ohne abschliessenden Slash).
    // Wird nur fuer den Unterpfad-Betrieb ausgewertet.
    'public_path' => (string) Env::get('KLINQ_PATH', '/klinq'),

    // Oeffentliche Adresse von KLINQ. KLINQ erzeugt absolute Pfade, deshalb
    // laeuft es unter einem eigenen Hostnamen hinter dem auth-Container.
    'public_url' => (string) Env::get('KLINQ_PUBLIC_URL', ''),

    // Interner Name des KLINQ-Containers im Docker-Netz.
    'upstream' => (string) Env::get('KLINQ_UPSTREAM', 'http://klinq-app:80'),
    'internal_url' => (string) Env::get('KLINQ_INTERNAL_URL', 'http://klinq-app/'),

    // Gemeinsames Secret fuer den signierten Einstieg (LANPA -> KLINQ) und fuer
    // die Kontaktdaten-Uebergabe (KLINQ -> LANPA). Docker-Secret moeglich
    // (KLINQ_SSO_SECRET_FILE).
    'sso_secret' => (string) Env::get('KLINQ_SSO_SECRET', ''),

    // Gueltigkeit des Einstiegs-Tokens in Sekunden (kurz halten).
    'entry_lifetime' => Env::int('KLINQ_ENTRY_LIFETIME', 300),

    // Erreichbarkeitspruefung.
    'health_url' => (string) Env::get('KLINQ_HEALTH_URL', 'http://klinq-app/health'),
    'health_timeout' => Env::int('KLINQ_HEALTH_TIMEOUT', 4),
    'health_cache_ttl' => Env::int('KLINQ_HEALTH_CACHE_TTL', 30),
    'health_cache_file' => BASE_PATH . '/storage/cache/klinq_health.json',

    // Optionaler API-Schluessel des KI-Endpunkts als Secret (Vorrang vor dem
    // im Adminbereich gespeicherten Schluessel).
    'ai_api_key' => (string) Env::get('KLINQ_AI_API_KEY', ''),
];
