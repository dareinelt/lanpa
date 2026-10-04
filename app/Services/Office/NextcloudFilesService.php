<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Contracts\OfficeProbeInterface;
use App\Support\Validator;

/**
 * Legt Dateien direkt in den Nextcloud-Dateien eines Benutzers ab (z. B.
 * Notfallplan-Exporte). Uebertragen wird an intranet_integration/api/files,
 * signiert mit dem gemeinsamen Euro-Office-Secret; das Token ist an Benutzer,
 * Zielordner, Dateiname und Inhalt gebunden. Nextcloud legt nur in bereits
 * vorhandenen, aktiven Konten ab (keine Kontoanlage).
 */
final class NextcloudFilesService
{
    /** Obergrenze je Datei (entspricht MAX_BODY in FilesController der Nextcloud-App). */
    public const MAX_BYTES = 16777216;
    public const UID_PATTERN = '/^[a-zA-Z0-9._-]{1,64}(@[a-z0-9_]{1,32})?$/';

    public function __construct(
        private readonly OfficeConfigService $office,
        private readonly OfficeProbeInterface $probe
    ) {
    }

    /** Grund, warum keine Ablage moeglich ist, oder null. */
    public function unavailableReason(): ?string
    {
        if (!$this->office->isEnabled()) {
            return 'Office (Nextcloud) ist in dieser Installation nicht aktiviert.';
        }
        if ($this->office->jwtSecret() === '') {
            return 'Für Nextcloud ist kein JWT-Secret konfiguriert (OFFICE_JWT_SECRET_FILE).';
        }

        return null;
    }

    /** Gueltiger Ordner- bzw. Dateiname (ein Pfadsegment) fuer Nextcloud und Windows-Clients. */
    public static function isSafeSegment(string $segment): bool
    {
        return $segment !== '' && mb_check_encoding($segment, 'UTF-8') && mb_strlen($segment) <= 120
            && trim($segment) === $segment && !str_starts_with($segment, '.') && !str_ends_with($segment, '.')
            && preg_match('/[\x00-\x1F\x7F\/\\\\<>:"|?*]/u', $segment) !== 1;
    }

    /**
     * Bereinigt einen frei gewaehlten Namen zu einem sicheren Pfadsegment.
     */
    public static function segment(string $value, string $fallback): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\<>:"|?*]+/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value), " .\t");
        $value = mb_substr($value, 0, 80);
        $value = rtrim($value, ' .');

        return self::isSafeSegment($value) ? $value : $fallback;
    }

    /**
     * Pfad zur Dateien-App (unterhalb des Office-Pfads) fuer einen Ordner,
     * als Ziel fuer /office-starten?ziel=… (automatische Anmeldung).
     */
    public function folderTarget(string $folder): string
    {
        return $this->office->publicPath() . 'index.php/apps/files/?' . http_build_query(['dir' => '/' . $folder]);
    }

    /**
     * @return array{ok:bool,message:string,path:string}
     */
    public function upload(string $uid, string $folder, string $name, string $contents): array
    {
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            return ['ok' => false, 'message' => $reason, 'path' => ''];
        }
        if (preg_match(self::UID_PATTERN, $uid) !== 1) {
            return ['ok' => false, 'message' => 'Für Ihre Anmeldung ist kein Nextcloud-Konto bekannt.', 'path' => ''];
        }
        $segments = explode('/', $folder);
        if (count($segments) > 4 || in_array(false, array_map(self::isSafeSegment(...), $segments), true) || !self::isSafeSegment($name)) {
            return ['ok' => false, 'message' => 'Ungültiger Ordner- oder Dateiname.', 'path' => ''];
        }
        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            return ['ok' => false, 'message' => 'Die Datei ist leer oder zu groß für die Übertragung an Nextcloud.', 'path' => ''];
        }

        $infra = $this->office->infrastructure();
        $url = rtrim((string) ($infra['nextcloud_internal_url'] ?? ''), '/') . '/index.php/apps/intranet_integration/api/files';
        $response = $this->probe->request('POST', $url, [
            'Content-Type' => 'application/octet-stream',
            'Authorization' => 'Bearer ' . OfficeJwt::filesToken($this->office->jwtSecret(), $uid, $folder, $name, $contents),
        ], $contents, max(60, (int) ($infra['timeout'] ?? 4) * 5));

        if ($response['error'] !== null || $response['status'] === 0) {
            return ['ok' => false, 'message' => 'Nextcloud nicht erreichbar: ' . ($response['error'] ?? 'keine Antwort'), 'path' => ''];
        }
        if ($response['status'] === 401) {
            return ['ok' => false, 'message' => 'Nextcloud hat die Übergabe abgelehnt (JWT-Secret abweichend).', 'path' => ''];
        }
        $data = json_decode($response['body'], true);
        if ($response['status'] === 404 && !is_array($data)) {
            return ['ok' => false, 'message' => 'Nextcloud-App intranet_integration ist nicht aktiv oder veraltet (docker compose restart nextcloud).', 'path' => ''];
        }
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'Unerwartete Antwort von Nextcloud (HTTP ' . $response['status'] . ').', 'path' => ''];
        }
        $ok = !empty($data['ok']) && $response['status'] === 200;

        return [
            'ok' => $ok,
            'message' => Validator::cleanText((string) ($data['message'] ?? ($ok ? 'In Nextcloud gespeichert.' : 'Ablage in Nextcloud fehlgeschlagen.')), 300),
            'path' => $ok ? '/' . $folder . '/' . $name : '',
        ];
    }

    /**
     * Ruft eine zuvor abgelegte Datei ab (Orvanta-Zwischenspeicher).
     *
     * @return array{ok:bool,message:string,content:string,content_type:string}
     */
    public function fetch(string $uid, string $folder, string $name): array
    {
        $response = $this->fileAction('GET', 'fetch', $uid, $folder, $name);
        if (isset($response['message'])) {
            return ['ok' => false, 'message' => $response['message'], 'content' => '', 'content_type' => ''];
        }

        return ['ok' => true, 'message' => '', 'content' => $response['body'], 'content_type' => $response['content_type']];
    }

    /**
     * Loescht eine zuvor abgelegte Datei (Quota-Verdraengung des Zwischenspeichers).
     *
     * @return array{ok:bool,message:string}
     */
    public function delete(string $uid, string $folder, string $name): array
    {
        $response = $this->fileAction('DELETE', 'delete', $uid, $folder, $name);
        if (isset($response['message'])) {
            return ['ok' => false, 'message' => $response['message']];
        }

        return ['ok' => true, 'message' => 'Gelöscht.'];
    }

    /**
     * @return array{message:string}|array{body:string,content_type:string}
     */
    private function fileAction(string $method, string $action, string $uid, string $folder, string $name): array
    {
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            return ['message' => $reason];
        }
        if (preg_match(self::UID_PATTERN, $uid) !== 1) {
            return ['message' => 'Für Ihre Anmeldung ist kein Nextcloud-Konto bekannt.'];
        }
        $segments = explode('/', $folder);
        if (count($segments) > 4 || in_array(false, array_map(self::isSafeSegment(...), $segments), true) || !self::isSafeSegment($name)) {
            return ['message' => 'Ungültiger Ordner- oder Dateiname.'];
        }
        $infra = $this->office->infrastructure();
        $url = rtrim((string) ($infra['nextcloud_internal_url'] ?? ''), '/') . '/index.php/apps/intranet_integration/api/files';
        $response = $this->probe->request($method, $url, [
            'Authorization' => 'Bearer ' . OfficeJwt::fileActionToken($this->office->jwtSecret(), $uid, $folder, $name, $action),
        ], null, max(30, (int) ($infra['timeout'] ?? 4) * 5));
        if ($response['error'] !== null || $response['status'] === 0) {
            return ['message' => 'Nextcloud nicht erreichbar: ' . ($response['error'] ?? 'keine Antwort')];
        }
        if ($response['status'] !== 200) {
            $data = json_decode($response['body'], true);
            $message = is_array($data) ? (string) ($data['message'] ?? ($data['error'] ?? '')) : '';

            return ['message' => Validator::cleanText($message !== '' ? $message : 'Nextcloud-Antwort HTTP ' . $response['status'], 300)];
        }
        if ($action === 'delete') {
            return ['body' => '', 'content_type' => ''];
        }
        $headers = $response['headers'] ?? [];
        $type = '';
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'content-type') {
                $type = trim(explode(';', (string) $value)[0]);
            }
        }

        return ['body' => $response['body'], 'content_type' => $type];
    }
}
