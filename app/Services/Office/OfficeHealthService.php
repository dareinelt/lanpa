<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Contracts\OfficeProbeInterface;
use Throwable;

/**
 * Gesundheitspruefung der Office-Dienste.
 *
 * Komponenten:
 *   nextcloud       status.php (installiert, Wartung, Upgrade)
 *   eurooffice      DocumentServer /healthcheck
 *   eurooffice_jwt  signierter Versionsbefehl (prueft das gemeinsame Secret)
 *   connector       Diagnose der Nextcloud-App intranet_integration
 *                   (Connector installiert/aktiv, URLs, JWT; optional
 *                   vollstaendige Pruefung inkl. DocumentServer -> Nextcloud)
 *   redis           TCP + PING
 *   postgres        TCP + SSLRequest
 *
 * Das Ergebnis wird kurz zwischengespeichert, damit oeffentliche Abfragen
 * (Kachelstatus) die Dienste nicht belasten.
 */
final class OfficeHealthService
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const ERROR = 'error';

    private const LABELS = [
        'nextcloud' => 'Nextcloud',
        'eurooffice' => 'Euro-Office DocumentServer',
        'eurooffice_jwt' => 'JWT (Intranet ↔ DocumentServer)',
        'connector' => 'Nextcloud-Connector (eurooffice)',
        'redis' => 'Redis (Nextcloud-Cache)',
        'postgres' => 'PostgreSQL (Nextcloud-Datenbank)',
    ];

    /** Komponenten, ohne die Office nicht nutzbar ist. */
    private const CRITICAL = ['nextcloud', 'eurooffice', 'connector'];

    public function __construct(
        private readonly OfficeConfigService $config,
        private readonly OfficeProbeInterface $probe,
        private readonly string $cacheFile,
        private readonly int $cacheTtl = 30
    ) {
    }

    /**
     * @return array{state:string,available:bool,checked_at:string,components:array<string,array{label:string,status:string,message:string}>,diagnostics:array<string,mixed>}
     */
    public function check(bool $deep = false): array
    {
        if (!$this->config->isEnabled()) {
            return $this->finish([], [], 'disabled');
        }

        $infra = $this->config->infrastructure();
        $timeout = max(1, (int) ($infra['timeout'] ?? 4));
        $components = [];
        $diagnostics = [];

        $components['nextcloud'] = $this->checkNextcloud((string) ($infra['nextcloud_internal_url'] ?? ''), $timeout, $diagnostics);
        $components['eurooffice'] = $this->checkEuroOffice((string) ($infra['eurooffice_internal_url'] ?? ''), $timeout);
        $components['eurooffice_jwt'] = $this->checkEuroOfficeJwt((string) ($infra['eurooffice_internal_url'] ?? ''), $timeout, $diagnostics);
        $components['connector'] = $this->checkConnector(
            (string) ($infra['nextcloud_internal_url'] ?? ''),
            $components['nextcloud']['status'] !== self::ERROR,
            $deep,
            $deep ? max($timeout, 20) : $timeout,
            $diagnostics
        );
        $components['redis'] = $this->checkRedis((string) ($infra['redis_host'] ?? ''), (int) ($infra['redis_port'] ?? 6379), $timeout);
        $components['postgres'] = $this->checkPostgres((string) ($infra['postgres_host'] ?? ''), (int) ($infra['postgres_port'] ?? 5432), $timeout);

        $result = $this->finish($components, $diagnostics);
        $this->store($result);

        return $result;
    }

    /**
     * Zwischengespeichertes Ergebnis (neu pruefen, wenn aelter als TTL).
     *
     * @return array<string,mixed>
     */
    public function cached(): array
    {
        if (!$this->config->isEnabled()) {
            return $this->finish([], [], 'disabled');
        }

        $data = $this->load();
        if ($data !== null) {
            $age = time() - (int) strtotime((string) ($data['checked_at'] ?? ''));
            if ($age >= 0 && $age < $this->cacheTtl) {
                return $data;
            }
        }

        return $this->check();
    }

    /**
     * Oeffentliche Zusammenfassung ohne technische Details.
     *
     * @return array{state:string,available:bool,label:string}
     */
    public function publicSummary(): array
    {
        $data = $this->cached();
        $state = (string) ($data['state'] ?? 'down');

        $labels = [
            'ok' => 'Verfügbar',
            'degraded' => 'Eingeschränkt',
            'down' => 'Nicht verfügbar',
            'disabled' => 'Nicht aktiviert',
        ];

        return [
            'state' => $state,
            'available' => (bool) ($data['available'] ?? false),
            'label' => $labels[$state] ?? 'Unbekannt',
        ];
    }

    /**
     * @param array<string,array{label:string,status:string,message:string}> $components
     * @param array<string,mixed>                                             $diagnostics
     *
     * @return array{state:string,available:bool,checked_at:string,components:array<string,array{label:string,status:string,message:string}>,diagnostics:array<string,mixed>}
     */
    private function finish(array $components, array $diagnostics, ?string $state = null): array
    {
        if ($state === null) {
            $state = 'ok';
            foreach ($components as $name => $component) {
                if ($component['status'] === self::ERROR && in_array($name, self::CRITICAL, true)) {
                    $state = 'down';
                    break;
                }
                if ($component['status'] !== self::OK) {
                    $state = 'degraded';
                }
            }
        }

        return [
            'state' => $state,
            'available' => $state === 'ok' || $state === 'degraded',
            'checked_at' => gmdate('c'),
            'components' => $components,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param array<string,mixed> $diagnostics
     *
     * @return array{label:string,status:string,message:string}
     */
    private function checkNextcloud(string $baseUrl, int $timeout, array &$diagnostics): array
    {
        $response = $this->probe->request('GET', $this->join($baseUrl, 'status.php'), [], null, $timeout);
        if ($response['error'] !== null || $response['status'] === 0) {
            return $this->component('nextcloud', self::ERROR, 'Nicht erreichbar: ' . ($response['error'] ?? 'keine Antwort'));
        }

        $status = json_decode($response['body'], true);
        if (!is_array($status)) {
            return $this->component('nextcloud', self::ERROR, 'Unerwartete Antwort (HTTP ' . $response['status'] . ').');
        }

        $diagnostics['nextcloud_version'] = (string) ($status['versionstring'] ?? '');

        if (empty($status['installed'])) {
            return $this->component('nextcloud', self::ERROR, 'Nextcloud ist noch nicht installiert (Ersteinrichtung läuft?).');
        }
        if (!empty($status['maintenance'])) {
            return $this->component('nextcloud', self::WARN, 'Wartungsmodus aktiv.');
        }
        if (!empty($status['needsDbUpgrade'])) {
            return $this->component('nextcloud', self::WARN, 'Datenbank-Upgrade erforderlich (occ upgrade).');
        }

        return $this->component('nextcloud', self::OK, 'Version ' . $diagnostics['nextcloud_version']);
    }

    /**
     * @return array{label:string,status:string,message:string}
     */
    private function checkEuroOffice(string $baseUrl, int $timeout): array
    {
        $response = $this->probe->request('GET', $this->join($baseUrl, 'healthcheck'), [], null, $timeout);
        if ($response['error'] !== null || $response['status'] === 0) {
            return $this->component('eurooffice', self::ERROR, 'Nicht erreichbar: ' . ($response['error'] ?? 'keine Antwort'));
        }

        if ($response['status'] === 200 && trim($response['body']) === 'true') {
            return $this->component('eurooffice', self::OK, 'Healthcheck erfolgreich.');
        }

        return $this->component('eurooffice', self::ERROR, 'Healthcheck fehlgeschlagen (HTTP ' . $response['status'] . ').');
    }

    /**
     * @param array<string,mixed> $diagnostics
     *
     * @return array{label:string,status:string,message:string}
     */
    private function checkEuroOfficeJwt(string $baseUrl, int $timeout, array &$diagnostics): array
    {
        if (!$this->config->hasJwtSecret()) {
            return $this->component('eurooffice_jwt', self::ERROR, 'Kein JWT-Secret konfiguriert (OFFICE_JWT_SECRET_FILE).');
        }

        $command = ['c' => 'version'];
        try {
            $token = OfficeJwt::encode($command, $this->config->jwtSecret());
            $body = json_encode($command + ['token' => $token], JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->component('eurooffice_jwt', self::ERROR, 'Token konnte nicht erzeugt werden.');
        }

        $response = $this->probe->request('POST', $this->join($baseUrl, 'command'), [
            'Content-Type' => 'application/json',
            $this->config->jwtHeader() => 'Bearer ' . OfficeJwt::encode(['payload' => $command], $this->config->jwtSecret()),
        ], $body, $timeout);

        if ($response['error'] !== null || $response['status'] === 0) {
            return $this->component('eurooffice_jwt', self::ERROR, 'Nicht erreichbar: ' . ($response['error'] ?? 'keine Antwort'));
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            return $this->component('eurooffice_jwt', self::ERROR, 'Unerwartete Antwort (HTTP ' . $response['status'] . ').');
        }

        $error = (int) ($data['error'] ?? -1);
        if ($error === 0) {
            $diagnostics['eurooffice_version'] = (string) ($data['version'] ?? '');

            return $this->component('eurooffice_jwt', self::OK, 'Signierter Befehl akzeptiert, Version ' . $diagnostics['eurooffice_version']);
        }
        if ($error === 6) {
            return $this->component('eurooffice_jwt', self::ERROR, 'JWT abgelehnt – Secret von Intranet und DocumentServer stimmt nicht überein.');
        }

        return $this->component('eurooffice_jwt', self::ERROR, 'DocumentServer meldet Fehlercode ' . $error . '.');
    }

    /**
     * @param array<string,mixed> $diagnostics
     *
     * @return array{label:string,status:string,message:string}
     */
    private function checkConnector(string $baseUrl, bool $nextcloudReachable, bool $deep, int $timeout, array &$diagnostics): array
    {
        if (!$nextcloudReachable) {
            return $this->component('connector', self::ERROR, 'Nicht prüfbar (Nextcloud nicht erreichbar).');
        }
        if (!$this->config->hasJwtSecret()) {
            return $this->component('connector', self::ERROR, 'Nicht prüfbar (kein JWT-Secret).');
        }

        $url = $this->join($baseUrl, 'index.php/apps/intranet_integration/api/diagnostics') . ($deep ? '?check=1' : '');
        $response = $this->probe->request('GET', $url, [
            'Authorization' => 'Bearer ' . OfficeJwt::diagnosticsToken($this->config->jwtSecret()),
        ], null, $timeout);

        if ($response['error'] !== null || $response['status'] === 0) {
            return $this->component('connector', self::ERROR, 'Diagnose nicht erreichbar: ' . ($response['error'] ?? 'keine Antwort'));
        }
        if ($response['status'] === 401) {
            return $this->component('connector', self::ERROR, 'Diagnose verweigert – JWT-Secret in Nextcloud abweichend.');
        }
        if ($response['status'] === 404) {
            return $this->component('connector', self::ERROR, 'Nextcloud-App intranet_integration ist nicht aktiv.');
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['ok'])) {
            return $this->component('connector', self::ERROR, 'Unerwartete Diagnoseantwort (HTTP ' . $response['status'] . ').');
        }

        $connector = is_array($data['connector'] ?? null) ? $data['connector'] : [];
        $diagnostics['connector'] = $connector;
        $diagnostics['apps'] = is_array($data['apps'] ?? null) ? $data['apps'] : [];

        if (empty($connector['installed'])) {
            return $this->component('connector', self::ERROR, 'Connector eurooffice ist nicht installiert.');
        }
        if (empty($connector['enabled'])) {
            return $this->component('connector', self::ERROR, 'Connector eurooffice ist deaktiviert.');
        }
        if (empty($connector['jwt_configured'])) {
            return $this->component('connector', self::ERROR, 'Connector ohne JWT-Secret.');
        }

        if ($deep) {
            $check = is_array($data['check'] ?? null) ? $data['check'] : [];
            $diagnostics['connector_check'] = $check;
            if (empty($check['ok'])) {
                return $this->component('connector', self::ERROR, 'Connector-Prüfung fehlgeschlagen: ' . (string) ($check['error'] ?? 'unbekannt'));
            }
        }

        $overrides = (array) ($connector['app_config_overrides'] ?? []);
        if ($overrides !== []) {
            return $this->component('connector', self::WARN, 'Version ' . (string) ($connector['version'] ?? '')
                . ' – App-Einstellungen überschreiben die zentrale Konfiguration: ' . implode(', ', array_map('strval', $overrides)));
        }

        return $this->component('connector', self::OK, 'Version ' . (string) ($connector['version'] ?? '')
            . ($deep ? ' – vollständige Prüfung erfolgreich.' : ' – aktiv.'));
    }

    /**
     * @return array{label:string,status:string,message:string}
     */
    private function checkRedis(string $host, int $port, int $timeout): array
    {
        if ($host === '') {
            return $this->component('redis', self::WARN, 'Nicht konfiguriert.');
        }

        $reply = $this->probe->tcp($host, $port, "PING\r\n", $timeout);
        if ($reply === null) {
            return $this->component('redis', self::ERROR, 'Nicht erreichbar.');
        }

        // Ohne Passwort antwortet Redis mit -NOAUTH: Dienst laeuft.
        if (str_starts_with($reply, '+PONG') || str_contains($reply, 'NOAUTH')) {
            return $this->component('redis', self::OK, 'Erreichbar.');
        }

        return $this->component('redis', self::WARN, 'Unerwartete Antwort.');
    }

    /**
     * @return array{label:string,status:string,message:string}
     */
    private function checkPostgres(string $host, int $port, int $timeout): array
    {
        if ($host === '') {
            return $this->component('postgres', self::WARN, 'Nicht konfiguriert.');
        }

        // SSLRequest: Laenge 8, Code 80877103. Antwort 'S' oder 'N'.
        $reply = $this->probe->tcp($host, $port, pack('NN', 8, 80877103), $timeout, 1);
        if ($reply === null) {
            return $this->component('postgres', self::ERROR, 'Nicht erreichbar.');
        }
        if ($reply === 'S' || $reply === 'N') {
            return $this->component('postgres', self::OK, 'Erreichbar.');
        }

        return $this->component('postgres', self::WARN, 'Unerwartete Antwort.');
    }

    /**
     * @return array{label:string,status:string,message:string}
     */
    private function component(string $name, string $status, string $message): array
    {
        return ['label' => self::LABELS[$name] ?? $name, 'status' => $status, 'message' => $message];
    }

    private function join(string $base, string $path): string
    {
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load(): ?array
    {
        if (!is_readable($this->cacheFile)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->cacheFile), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function store(array $result): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $tmp = $this->cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $this->cacheFile);
        }
    }
}
