<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Contracts\OfficeProbeInterface;
use App\Services\SettingsService;
use App\Support\Validator;
use JsonException;

/**
 * App-Store von Nextcloud ein- bzw. ausblenden.
 *
 * Unter Admin → Office → Nextcloud-App-Store laesst sich der App-Store
 * deaktivieren. Der Stand wird signiert an die Nextcloud-App
 * intranet_integration (api/appstore) uebergeben, die die Systemeinstellung
 * "appstoreenabled" setzt: Nextcloud zeigt unter "Apps" dann nur noch die
 * installierten Apps, die Kategorien des App-Stores (Entdecken, App-Pakete,
 * ...) sowie Installation und Aktualisierung aus dem App-Store entfallen.
 * Die Gesundheitspruefung vergleicht den Stand und uebertraegt bei
 * Abweichung erneut (selbstheilend).
 */
final class NextcloudAppStoreService
{
    public const SETTING = 'office_appstore_enabled';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly OfficeConfigService $office,
        private readonly OfficeProbeInterface $probe
    ) {
    }

    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        // Wie in Nextcloud selbst: App-Store standardmaessig sichtbar.
        return [self::SETTING => '1'];
    }

    public function enabled(): bool
    {
        return $this->settings->get(self::SETTING, '1') !== '0';
    }

    /**
     * @return array<string,string>
     */
    public function formValues(): array
    {
        return [self::SETTING => $this->enabled() ? '1' : '0'];
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array{values:array<string,string>,errors:array<string,string>}
     */
    public static function validate(array $input): array
    {
        return ['values' => [self::SETTING => !empty($input[self::SETTING]) ? '1' : '0'], 'errors' => []];
    }

    /**
     * Ob der von Nextcloud gemeldete Stand der Einstellung entspricht.
     *
     * @param mixed $remote "appstore" aus der Diagnose der Nextcloud-App
     */
    public function inSync(mixed $remote): bool
    {
        return is_array($remote) && is_bool($remote['enabled'] ?? null) && $remote['enabled'] === $this->enabled();
    }

    /**
     * @return array{version:int,enabled:bool}
     */
    public function payload(): array
    {
        return ['version' => 1, 'enabled' => $this->enabled()];
    }

    /**
     * Uebertraegt nur bei aktivem Office (sonst null).
     *
     * @return array{ok:bool,message:string}|null
     */
    public function pushIfEnabled(): ?array
    {
        return $this->office->isEnabled() ? $this->pushToNextcloud() : null;
    }

    /**
     * Uebertraegt den Stand an Nextcloud (signiert, an den Inhalt gebunden).
     *
     * @return array{ok:bool,message:string}
     */
    public function pushToNextcloud(): array
    {
        $secret = $this->office->jwtSecret();
        if ($secret === '') {
            return ['ok' => false, 'message' => 'Kein JWT-Secret konfiguriert (OFFICE_JWT_SECRET_FILE).'];
        }

        try {
            $body = json_encode($this->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return ['ok' => false, 'message' => 'Einstellung konnte nicht erzeugt werden.'];
        }

        $infra = $this->office->infrastructure();
        $url = rtrim((string) ($infra['nextcloud_internal_url'] ?? ''), '/') . '/index.php/apps/intranet_integration/api/appstore';
        $response = $this->probe->request('POST', $url, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . OfficeJwt::appStoreConfigToken($secret, $body),
        ], $body, max(10, (int) ($infra['timeout'] ?? 4) * 3));

        if ($response['error'] !== null || $response['status'] === 0) {
            return ['ok' => false, 'message' => 'Nextcloud nicht erreichbar: ' . ($response['error'] ?? 'keine Antwort')];
        }
        if ($response['status'] === 401) {
            return ['ok' => false, 'message' => 'Nextcloud hat die Übergabe abgelehnt (JWT-Secret abweichend).'];
        }
        if ($response['status'] === 404) {
            return ['ok' => false, 'message' => 'Nextcloud-App intranet_integration ist nicht aktiv oder veraltet (docker compose restart nextcloud).'];
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'Unerwartete Antwort von Nextcloud (HTTP ' . $response['status'] . ').'];
        }

        return [
            'ok' => !empty($data['ok']),
            'message' => Validator::cleanText((string) ($data['message'] ?? (!empty($data['ok']) ? 'An Nextcloud übergeben.' : 'Übergabe fehlgeschlagen.')), 300),
        ];
    }
}
