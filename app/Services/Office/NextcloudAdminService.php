<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Contracts\OfficeProbeInterface;
use App\Services\AdminGroupService;
use App\Services\SettingsService;
use App\Support\Validator;
use JsonException;

/**
 * Nextcloud-Administratoren aus AD-Gruppen.
 *
 * Nextcloud erhaelt die Kennungen aller Mitglieder der unter Benutzer →
 * "Administratoren aus AD-Gruppen" eingetragenen Nextcloud-Gruppen signiert
 * ueber intranet_integration/api/admins und nimmt sie in die Gruppe "admin"
 * auf. Wer herausfaellt, wird nur dann wieder entfernt, wenn ihn das Intranet
 * aufgenommen hat (vorhandene Administratoren bleiben unangetastet). Die
 * Gesundheitspruefung vergleicht den Fingerabdruck und uebertraegt bei
 * Abweichung erneut (z. B. nach der AD-Synchronisation).
 */
final class NextcloudAdminService
{
    public const LAST_PUSH_SETTING = 'office_admins_last_push';

    public function __construct(
        private readonly AdminGroupService $groups,
        private readonly SettingsService $settings,
        private readonly OfficeConfigService $office,
        private readonly OfficeProbeInterface $probe
    ) {
    }

    /**
     * @return list<string>
     */
    public function uids(): array
    {
        return $this->groups->nextcloudUids();
    }

    public function fingerprint(): string
    {
        return self::fingerprintOf($this->uids());
    }

    /**
     * @param list<string> $uids
     */
    public static function fingerprintOf(array $uids): string
    {
        $uids = array_values(array_unique($uids));
        sort($uids, SORT_STRING);

        return hash('sha256', 'admins' . "\n" . json_encode($uids, JSON_UNESCAPED_SLASHES));
    }

    public function inSync(mixed $remoteFingerprint): bool
    {
        return is_string($remoteFingerprint) && $remoteFingerprint !== '' && hash_equals($this->fingerprint(), $remoteFingerprint);
    }

    /**
     * @return array{version:int,users:list<string>,fingerprint:string}
     */
    public function payload(): array
    {
        $uids = $this->uids();

        return [
            'version' => 1,
            'users' => $uids,
            'fingerprint' => self::fingerprintOf($uids),
        ];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function pushToNextcloud(): array
    {
        $result = $this->doPush();
        $this->rememberPush($result);

        return $result;
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
     * @return array{ok:bool,message:string,at:string,fingerprint:string,in_sync:bool}|null
     */
    public function lastPush(): ?array
    {
        $data = json_decode($this->settings->get(self::LAST_PUSH_SETTING), true);
        if (!is_array($data)) {
            return null;
        }
        $fingerprint = (string) ($data['fingerprint'] ?? '');

        return [
            'ok' => !empty($data['ok']),
            'message' => (string) ($data['message'] ?? ''),
            'at' => (string) ($data['at'] ?? ''),
            'fingerprint' => $fingerprint,
            'in_sync' => !empty($data['ok']) && $fingerprint !== '' && hash_equals($this->fingerprint(), $fingerprint),
        ];
    }

    public function officeEnabled(): bool
    {
        return $this->office->isEnabled();
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function doPush(): array
    {
        $secret = $this->office->jwtSecret();
        if ($secret === '') {
            return ['ok' => false, 'message' => 'Kein JWT-Secret konfiguriert (OFFICE_JWT_SECRET_FILE).'];
        }

        try {
            $body = json_encode($this->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return ['ok' => false, 'message' => 'Administratorenliste konnte nicht erzeugt werden.'];
        }

        $infra = $this->office->infrastructure();
        $url = rtrim((string) ($infra['nextcloud_internal_url'] ?? ''), '/') . '/index.php/apps/intranet_integration/api/admins';
        $response = $this->probe->request('POST', $url, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . OfficeJwt::adminsConfigToken($secret, $body),
        ], $body, max(30, (int) ($infra['timeout'] ?? 4) * 3));

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

    /**
     * @param array{ok:bool,message:string} $result
     */
    private function rememberPush(array $result): void
    {
        try {
            $this->settings->update([self::LAST_PUSH_SETTING => (string) json_encode([
                'ok' => $result['ok'],
                'message' => $result['message'],
                'at' => date('Y-m-d H:i:s'),
                'fingerprint' => $result['ok'] ? $this->fingerprint() : '',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable) {
            // Nur informativ; die Gesundheitspruefung gleicht ohnehin erneut ab.
        }
    }
}
