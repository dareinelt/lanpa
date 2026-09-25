<?php
/**
 * Von der Intranet-Integration verwaltete Nextcloud-Konfiguration.
 *
 * Wird schreibgeschuetzt nach config/intranet.config.php eingebunden und bei
 * jedem Aufruf neu gelesen. Nextcloud fuehrt zusaetzliche *.config.php-Dateien
 * mit config.php zusammen; die Werte hier haben Vorrang. Geheimnisse werden
 * aus Docker-Secrets gelesen und nie im Repository abgelegt.
 *
 * Der Euro-Office-Connector liest seine Einstellungen zuerst aus der
 * App-Konfiguration (Admin-Oberflaeche/occ) und erst dann aus dem Abschnitt
 * "eurooffice" dieser Datei. Die Einrichtung (hooks/intranet-setup.sh)
 * entfernt deshalb abweichende App-Werte, damit diese Datei massgeblich ist.
 */

$CONFIG = (static function (): array {
    $secret = static function (string $name): string {
        $file = getenv($name . '_FILE');
        if (is_string($file) && $file !== '' && is_readable($file)) {
            return trim((string) file_get_contents($file));
        }
        $value = getenv($name);
        return is_string($value) ? trim($value) : '';
    };
    $env = static function (string $name, string $default): string {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : $default;
    };

    return [
        // Nextcloud liegt unter /office (siehe apache-office.conf).
        'htaccess.RewriteBase' => '/office',

        'eurooffice' => [
            // Oeffentlich relativ (Same-Origin, CSP 'self'), intern per Containername.
            'DocumentServerUrl' => $env('EUROOFFICE_PUBLIC_PATH', '/eurooffice/'),
            'DocumentServerInternalUrl' => $env('EUROOFFICE_INTERNAL_URL', 'http://eurooffice/'),
            'StorageUrl' => $env('NEXTCLOUD_INTERNAL_URL', 'http://nextcloud/office/'),
            'jwt_secret' => $secret('EUROOFFICE_JWT_SECRET'),
            'jwt_header' => $env('EUROOFFICE_JWT_HEADER', 'AuthorizationJwt'),
        ],

        // Basis-URL der Intranet-Anwendung (gleicher Host), fuer die Fusszeile.
        'intranet_integration' => [
            'intranet_base' => '/',
        ],
    ];
})();
