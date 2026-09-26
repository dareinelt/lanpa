<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * Euro-Office-Integration (Nextcloud + Euro-Office DocumentServer).
 *
 * Die Office-Dienste laufen als eigene Container (Compose-Profil "office") und
 * werden vom auth-Container unter demselben Host bereitgestellt:
 *   /office/      -> Nextcloud
 *   /eurooffice/  -> Euro-Office DocumentServer
 *
 * Darstellung (Fusszeile, Direktzugriff) wird im Adminbereich gepflegt; hier
 * stehen nur Infrastrukturwerte und Secrets (nie in der Datenbank).
 */
return [
    'enabled' => Env::bool('OFFICE_ENABLED', false),

    // Gemeinsames JWT-Secret von Nextcloud-Connector und DocumentServer
    // (Docker-Secret, OFFICE_JWT_SECRET_FILE).
    'jwt_secret' => (string) Env::get('OFFICE_JWT_SECRET', ''),
    'jwt_header' => Env::get('OFFICE_JWT_HEADER', 'AuthorizationJwt'),

    // Oeffentliche (same-origin) Pfade.
    'public_path' => Env::get('OFFICE_PUBLIC_PATH', '/office/'),
    'eurooffice_public_path' => Env::get('EUROOFFICE_PUBLIC_PATH', '/eurooffice/'),

    // Interne Adressen im Docker-Netz (nur fuer Health-/Diagnoseabfragen).
    'nextcloud_internal_url' => Env::get('OFFICE_NEXTCLOUD_INTERNAL_URL', 'http://nextcloud/office/'),
    'eurooffice_internal_url' => Env::get('OFFICE_EUROOFFICE_INTERNAL_URL', 'http://eurooffice/'),
    'redis_host' => Env::get('OFFICE_REDIS_HOST', 'nextcloud-redis'),
    'redis_port' => Env::int('OFFICE_REDIS_PORT', 6379),
    'postgres_host' => Env::get('OFFICE_POSTGRES_HOST', 'nextcloud-db'),
    'postgres_port' => Env::int('OFFICE_POSTGRES_PORT', 5432),

    'timeout' => Env::int('OFFICE_HEALTH_TIMEOUT', 4),
    'health_cache_ttl' => Env::int('OFFICE_HEALTH_CACHE_TTL', 30),
    'health_cache_file' => BASE_PATH . '/storage/cache/office_health.json',

    // Austauschverzeichnis mit dem Sicherungs-Agenten (office-backup).
    'backup_control_dir' => BASE_PATH . '/storage/office-backup',

    // Gueltigkeit des Intranet-Einstiegs (Direktzugriffsmodus "redirect").
    'entry_lifetime' => Env::int('OFFICE_ENTRY_LIFETIME', 43200),

    // Lokaler KI-Endpunkt (Einstellungen im Adminbereich unter Office -> KI).
    // Der API-Schluessel kann alternativ als Secret gesetzt werden
    // (OFFICE_AI_API_KEY bzw. OFFICE_AI_API_KEY_FILE) und hat dann Vorrang.
    'ai_api_key' => (string) Env::get('OFFICE_AI_API_KEY', ''),
    // Austauschverzeichnis mit dem DocumentServer (Volume office_ai,
    // dort als Laufzeitkonfiguration runtime.json eingebunden).
    'ai_config_dir' => Env::get('OFFICE_AI_CONFIG_DIR', BASE_PATH . '/storage/office-ai'),
];
