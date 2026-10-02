<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Contracts\OfficeProbeInterface;
use App\Exceptions\ValidationException;
use App\Repositories\NetworkDriveRepository;
use App\Services\SettingsService;
use App\Support\Validator;
use JsonException;

/**
 * Netzlaufwerke der Windows-Clients in Nextcloud.
 *
 * Ein Anmeldeskript (scripts/network-drives-report.ps1, per Gruppenrichtlinie)
 * meldet die gemappten Netzlaufwerke des Benutzers per Windows-Anmeldung an
 * /sso/laufwerke. Das Intranet speichert den Stand je Benutzer und uebertraegt
 * ihn signiert an intranet_integration/api/drives. Nextcloud bindet die
 * Laufwerke als externe Speicher (SMB, Windows-Kennwort einmalig je Benutzer)
 * ein – aber nur fuer Benutzer, die in "Dateien" die Einstellung
 * "Netzlaufwerke anzeigen" aktiviert haben (Opt-in). Externe Speicher zaehlen
 * nicht zum Speicherplatz-Kontingent (Quota).
 *
 * Laufwerke aus der Ausschlussliste (Standard B:, G:) werden nie
 * weitergereicht – auch nicht, wenn sie gemeldet wurden.
 *
 * @phpstan-type Drive array{letter:string,unc:string,host:string,share:string,root:string}
 */
final class NetworkDriveService
{
    public const ENABLED_SETTING = 'office_network_drives_enabled';
    public const EXCLUDED_SETTING = 'office_network_drives_excluded';
    public const LAST_PUSH_SETTING = 'office_network_drives_last_push';
    /** Gespeicherter Wert fuer "keine Laufwerke ausgeschlossen" (leere Werte gelten als nicht gesetzt). */
    public const NONE = 'none';
    public const DEFAULT_EXCLUDED = ['B', 'G'];
    public const MAX_DRIVES = 26;
    public const MAX_UNC = 500;

    /** Gleiches Muster wie die Nextcloud-App (SamAccountName[@kennung]). */
    public const UID_PATTERN = '/^[a-zA-Z0-9._-]{1,64}(@[a-z0-9_]{1,32})?$/';
    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$/';
    private const HOST_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,251}[A-Za-z0-9])?$/';
    /** Freigabe-/Ordnernamen: keine unter Windows verbotenen Zeichen. */
    private const SEGMENT_PATTERN = '/^[^\\\\\/:*?"<>|\x00-\x1F\x7F]{1,255}$/u';

    public function __construct(
        private readonly NetworkDriveRepository $repository,
        private readonly SettingsService $settings,
        private readonly OfficeConfigService $office,
        private readonly OfficeProbeInterface $probe
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->get(self::ENABLED_SETTING) !== '0';
    }

    /**
     * Nie weitergereichte Laufwerksbuchstaben (sortiert, ohne Doppelpunkt).
     *
     * @return list<string>
     */
    public function excludedLetters(): array
    {
        $raw = $this->settings->get(self::EXCLUDED_SETTING);
        if ($raw === '') {
            return self::DEFAULT_EXCLUDED;
        }
        if ($raw === self::NONE) {
            return [];
        }

        return self::parseLetters($raw) ?? self::DEFAULT_EXCLUDED;
    }

    /**
     * "B:/, G:/", "B: G:", "b;g", "H:\" -> ["B", "G", "H"]; null bei
     * ungueltigen Angaben.
     *
     * @return list<string>|null
     */
    public static function parseLetters(string $value): ?array
    {
        $letters = [];
        foreach (preg_split('/[\s,;]+/', trim($value)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^([A-Za-z])(?::[\\\\\/]?)?$/', $token, $m) !== 1) {
                return null;
            }
            $letters[strtoupper($m[1])] = true;
        }
        $letters = array_keys($letters);
        sort($letters, SORT_STRING);

        return $letters;
    }

    /**
     * @param list<string> $letters
     */
    public static function formatLetters(array $letters): string
    {
        return implode(', ', array_map(static fn (string $letter): string => $letter . ':/', $letters));
    }

    public function saveSettings(bool $enabled, string $excludedInput): void
    {
        $letters = self::parseLetters($excludedInput);
        if ($letters === null) {
            throw new ValidationException(['excluded' => 'Bitte Laufwerksbuchstaben angeben, getrennt durch Komma oder Leerzeichen, z. B. „B:/, G:/“.']);
        }

        $this->settings->update([
            self::ENABLED_SETTING => $enabled ? '1' : '0',
            self::EXCLUDED_SETTING => $letters === [] ? self::NONE : implode(',', $letters),
        ]);
    }

    /**
     * Zerlegt einen UNC-Pfad (\\server\freigabe[\ordner…]).
     *
     * @return array{unc:string,host:string,share:string,root:string}|null
     */
    public static function parseUnc(string $value): ?array
    {
        $value = trim(str_replace('/', '\\', $value));
        if (strlen($value) > self::MAX_UNC || !str_starts_with($value, '\\\\')) {
            return null;
        }

        $parts = explode('\\', rtrim(substr($value, 2), '\\'));
        if (count($parts) < 2) {
            return null;
        }
        $host = (string) array_shift($parts);
        if (preg_match(self::HOST_PATTERN, $host) !== 1 || str_contains($host, '..')) {
            return null;
        }
        foreach ($parts as $segment) {
            if (preg_match(self::SEGMENT_PATTERN, $segment) !== 1 || trim($segment, ' .') === '') {
                return null;
            }
        }
        $share = (string) array_shift($parts);
        if (mb_strlen($share) > 80) {
            return null;
        }

        return [
            'unc' => '\\\\' . $host . '\\' . $share . ($parts !== [] ? '\\' . implode('\\', $parts) : ''),
            'host' => $host,
            'share' => $share,
            'root' => implode('/', $parts),
        ];
    }

    /**
     * Uebernimmt die Meldung eines Clients ("H=\\server\freigabe" je Zeile)
     * fuer den per Windows-Anmeldung erkannten Benutzer. Die Meldung ersetzt
     * den bisherigen Stand des Benutzers.
     *
     * @param array{office_uid:string,display_name:string} $user
     *
     * @return array{accepted:list<array{letter:string,unc:string,excluded:bool}>,ignored:list<array{entry:string,reason:string}>,changed:bool,push:?array{ok:bool,message:string}}
     */
    public function report(array $user, string $rawDrives, string $domain, string $computer): array
    {
        $uid = (string) $user['office_uid'];
        if (preg_match(self::UID_PATTERN, $uid) !== 1) {
            throw new ValidationException(['user' => 'Ungültige Benutzerkennung.']);
        }

        $excluded = array_flip($this->excludedLetters());
        $drives = [];
        $accepted = [];
        $ignored = [];
        foreach (preg_split('/\r\n|\r|\n/', $rawDrives) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $entry = mb_substr($line, 0, 120);
            if (preg_match('/^([A-Za-z]):?\s*=\s*(.+)$/u', $line, $m) !== 1) {
                $ignored[] = ['entry' => $entry, 'reason' => 'Format „H=\\\\server\\freigabe“ erwartet'];
                continue;
            }
            $letter = strtoupper($m[1]);
            $unc = self::parseUnc($m[2]);
            if ($unc === null) {
                $ignored[] = ['entry' => $entry, 'reason' => 'kein gültiger UNC-Pfad'];
                continue;
            }
            if (isset($drives[$letter])) {
                $ignored[] = ['entry' => $entry, 'reason' => 'Laufwerk doppelt gemeldet'];
                continue;
            }
            if (count($drives) >= self::MAX_DRIVES) {
                $ignored[] = ['entry' => $entry, 'reason' => 'zu viele Laufwerke'];
                continue;
            }
            $drives[$letter] = ['letter' => $letter, 'unc' => $unc['unc']];
            $accepted[] = ['letter' => $letter, 'unc' => $unc['unc'], 'excluded' => isset($excluded[$letter])];
        }
        ksort($drives, SORT_STRING);

        $domain = preg_match(self::NAME_PATTERN, trim($domain)) === 1 ? strtoupper(trim($domain)) : '';
        $computer = preg_match(self::NAME_PATTERN, trim($computer)) === 1 ? strtoupper(trim($computer)) : '';
        $displayName = Validator::cleanText((string) $user['display_name'], 255);

        $before = $this->fingerprint();
        $this->repository->replaceForUser($uid, $displayName, array_values($drives), $domain, $computer);
        $changed = !hash_equals($before, $this->fingerprint());

        // Unveraenderte Meldungen (der Normalfall bei jeder Anmeldung) loesen
        // keine Uebertragung aus; die Gesundheitspruefung gleicht ohnehin ab.
        $push = $changed ? $this->pushIfEnabled() : null;

        return ['accepted' => $accepted, 'ignored' => $ignored, 'changed' => $changed, 'push' => $push];
    }

    /**
     * Alle gemeldeten Laufwerke fuer die Uebersicht (mit Ausschluss-Kennzeichen).
     *
     * @return list<array{user_uid:string,display_name:string,drive_letter:string,unc_path:string,domain:string,computer_name:string,reported_at:string,excluded:bool}>
     */
    public function rows(): array
    {
        $excluded = array_flip($this->excludedLetters());

        return array_map(
            static fn (array $row): array => $row + ['excluded' => isset($excluded[$row['drive_letter']])],
            $this->repository->all()
        );
    }

    /**
     * @return array{users:int,drives:int,passed:int}
     */
    public function summary(): array
    {
        $rows = $this->rows();
        $users = [];
        $passed = 0;
        foreach ($rows as $row) {
            $users[strtolower($row['user_uid'])] = true;
            if (!$row['excluded']) {
                $passed++;
            }
        }

        return ['users' => count($users), 'drives' => count($rows), 'passed' => $passed];
    }

    public function deleteUser(string $uid): int
    {
        return $this->repository->deleteForUser($uid);
    }

    /**
     * Weiterzureichende Laufwerke je Benutzer (ohne ausgeschlossene, nur bei
     * aktivierter Funktion).
     *
     * @return array<string,list<array{letter:string,host:string,share:string,root:string,domain:string}>>
     */
    public function nextcloudDrives(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $excluded = array_flip($this->excludedLetters());
        $users = [];
        foreach ($this->repository->all() as $row) {
            $unc = self::parseUnc($row['unc_path']);
            if ($unc === null || isset($excluded[$row['drive_letter']]) || preg_match(self::UID_PATTERN, $row['user_uid']) !== 1
                || preg_match('/^[A-Z]$/', $row['drive_letter']) !== 1) {
                continue;
            }
            $users[$row['user_uid']][] = [
                'letter' => $row['drive_letter'],
                'host' => $unc['host'],
                'share' => $unc['share'],
                'root' => $unc['root'],
                'domain' => $row['domain'],
            ];
        }
        ksort($users, SORT_STRING);
        foreach ($users as &$drives) {
            usort($drives, static fn (array $a, array $b): int => strcmp($a['letter'], $b['letter']));
        }
        unset($drives);

        return $users;
    }

    public function fingerprint(): string
    {
        return self::fingerprintOf($this->isEnabled(), $this->excludedLetters(), $this->nextcloudDrives());
    }

    /**
     * @param list<string> $excluded
     * @param array<string,list<array<string,string>>> $users
     */
    public static function fingerprintOf(bool $enabled, array $excluded, array $users): string
    {
        ksort($users, SORT_STRING);

        return hash('sha256', ($enabled ? '1' : '0') . "\n" . implode(',', $excluded) . "\n" . json_encode($users, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function inSync(mixed $remoteFingerprint): bool
    {
        return is_string($remoteFingerprint) && $remoteFingerprint !== '' && hash_equals($this->fingerprint(), $remoteFingerprint);
    }

    /**
     * @return array{version:int,enabled:bool,excluded:list<string>,users:array<string,list<array{letter:string,host:string,share:string,root:string,domain:string}>>,fingerprint:string}
     */
    public function payload(): array
    {
        $enabled = $this->isEnabled();
        $excluded = $this->excludedLetters();
        $users = $this->nextcloudDrives();

        return [
            'version' => 1,
            'enabled' => $enabled,
            'excluded' => $excluded,
            'users' => $users,
            'fingerprint' => self::fingerprintOf($enabled, $excluded, $users),
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
            $payload = $this->payload();
            $payload['users'] = (object) $payload['users'];
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return ['ok' => false, 'message' => 'Netzlaufwerke konnten nicht erzeugt werden.'];
        }

        $infra = $this->office->infrastructure();
        $url = rtrim((string) ($infra['nextcloud_internal_url'] ?? ''), '/') . '/index.php/apps/intranet_integration/api/drives';
        $response = $this->probe->request('POST', $url, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . OfficeJwt::drivesConfigToken($secret, $body),
        ], $body, max(60, (int) ($infra['timeout'] ?? 4) * 3));

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
