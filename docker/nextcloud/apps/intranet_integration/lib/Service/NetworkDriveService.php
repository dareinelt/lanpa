<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Service;

use OCA\IntranetIntegration\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

/**
 * Netzlaufwerke der Windows-Clients als externe SMB-Speicher.
 *
 * Das Intranet uebergibt signiert die von den Clients gemeldeten
 * Netzlaufwerke je Benutzer (ohne die im Intranet ausgeschlossenen
 * Laufwerksbuchstaben). Eingebunden werden sie nur fuer Benutzer, die in
 * "Dateien" > Einstellungen "Netzlaufwerke anzeigen" aktiviert haben.
 *
 * Je Laufwerk (Buchstabe, Server, Freigabe, Pfad, Domaene) entsteht ein
 * externer Speicher (files_external, Backend smb) mit genau den Benutzern,
 * die dieses Laufwerk gemeldet und die Anzeige aktiviert haben. Anmeldung:
 * "Globale Anmeldedaten, vom Benutzer eingegeben" (password::global::user) –
 * das Windows-Kennwort wird je Benutzer einmal hinterlegt und gilt fuer alle
 * seine Laufwerke. Externe Speicher zaehlen nicht zum Kontingent.
 */
class NetworkDriveService {
    public const MAX_USERS = 50000;
    public const MAX_DRIVES = 26;
    public const UID_PATTERN = '/^[a-zA-Z0-9._-]{1,64}(@[a-z0-9_]{1,32})?$/';
    public const PREF_KEY = 'network_drives';
    public const CREDENTIALS_IDENTIFIER = 'password::global';
    public const AUTH_MECHANISM = 'password::global::user';

    private const HOST_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,251}[A-Za-z0-9])?$/';
    private const SEGMENT_PATTERN = '/^[^\\\\\/:*?"<>|\x00-\x1F\x7F]{1,255}$/u';
    private const DOMAIN_PATTERN = '/^[A-Za-z0-9._-]{0,255}$/';

    private const KEY_PAYLOAD = 'drives_payload';
    private const KEY_FINGERPRINT = 'drives_fingerprint';
    private const KEY_MOUNTS = 'drives_mounts';
    private const KEY_ERROR = 'drives_error';
    private const KEY_APPLIED_AT = 'drives_applied_at';

    private const GLOBAL_STORAGES = 'OCA\\Files_External\\Service\\GlobalStoragesService';
    private const BACKEND_SERVICE = 'OCA\\Files_External\\Service\\BackendService';

    public function __construct(
        private IConfig $config,
        private IAppManager $appManager,
        private IUserManager $userManager,
        private ICredentialsManager $credentials,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{fingerprint: string, enabled: bool, users: int, opted_in: int, mounts: int, files_external: bool, smb_available: bool, applied_at: string, error: string}
     */
    public function status(): array {
        $payload = $this->storedPayload();

        return [
            'fingerprint' => $this->appValue(self::KEY_FINGERPRINT),
            'enabled' => (bool) ($payload['enabled'] ?? false),
            'users' => count($payload['users'] ?? []),
            'opted_in' => count($this->optedInUsers()),
            'mounts' => count($this->managedMounts()),
            'files_external' => $this->filesExternalEnabled(),
            'smb_available' => $this->smbAvailable(),
            'applied_at' => $this->appValue(self::KEY_APPLIED_AT),
            'error' => $this->appValue(self::KEY_ERROR),
        ];
    }

    /**
     * @param array<string,mixed> $payload vom Intranet (signiert)
     *
     * @return array{ok: bool, message: string}
     */
    public function apply(array $payload): array {
        $normalized = self::normalizePayload($payload);
        if (is_string($normalized)) {
            return $this->fail($normalized);
        }

        try {
            $this->config->setAppValue(Application::APP_ID, self::KEY_PAYLOAD, (string) json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->config->setAppValue(Application::APP_ID, self::KEY_APPLIED_AT, gmdate('c'));
        } catch (\Throwable $e) {
            $this->logger->error('Netzlaufwerke aus dem Intranet konnten nicht gespeichert werden', ['app' => Application::APP_ID, 'exception' => $e]);
            return $this->fail('Netzlaufwerke konnten nicht gespeichert werden: ' . get_class($e));
        }

        $result = $this->reconcile();
        // Fingerabdruck erst nach erfolgreichem Einbinden: sonst wiederholt
        // das Intranet die Uebergabe bei der naechsten Diagnose.
        $this->config->setAppValue(Application::APP_ID, self::KEY_FINGERPRINT, $result['ok'] ? $normalized['fingerprint'] : '');
        if (!$result['ok']) {
            return $result;
        }

        $count = array_sum(array_map('count', $normalized['users']));
        return ['ok' => true, 'message' => 'Netzlaufwerke uebernommen: ' . $count . ' Laufwerk(e) von '
            . count($normalized['users']) . ' Benutzer(n). ' . $result['message']];
    }

    /**
     * Prueft und normalisiert die Uebergabe des Intranets.
     *
     * @param array<string,mixed> $payload
     *
     * @return array{enabled: bool, excluded: list<string>, users: array<string,list<array{letter:string,host:string,share:string,root:string,domain:string}>>, fingerprint: string}|string
     */
    public static function normalizePayload(array $payload): array|string {
        $users = $payload['users'] ?? null;
        $excluded = $payload['excluded'] ?? null;
        $fingerprint = $payload['fingerprint'] ?? null;
        if ((int) ($payload['version'] ?? 0) !== 1 || !is_bool($payload['enabled'] ?? null)
            || !is_array($users) || count($users) > self::MAX_USERS || !is_array($excluded)
            || !is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            return 'Ungueltige Netzlaufwerke.';
        }

        $letters = [];
        foreach ($excluded as $letter) {
            if (!is_string($letter) || preg_match('/^[A-Z]$/', $letter) !== 1) {
                return 'Ungueltige Ausschlussliste.';
            }
            $letters[$letter] = true;
        }

        $result = [];
        foreach ($users as $uid => $drives) {
            $uid = (string) $uid;
            if (preg_match(self::UID_PATTERN, $uid) !== 1 || !is_array($drives) || count($drives) > self::MAX_DRIVES) {
                return 'Ungueltige Netzlaufwerke fuer ' . substr($uid, 0, 80) . '.';
            }
            $list = [];
            foreach ($drives as $drive) {
                $drive = is_array($drive) ? self::normalizeDrive($drive) : null;
                if ($drive === null) {
                    return 'Ungueltiges Netzlaufwerk fuer ' . substr($uid, 0, 80) . '.';
                }
                // Ausgeschlossene Laufwerke werden nie eingebunden – auch
                // wenn sie (faelschlich) uebergeben werden.
                if (!isset($letters[$drive['letter']])) {
                    $list[$drive['letter']] = $drive;
                }
            }
            if ($list !== []) {
                ksort($list, SORT_STRING);
                $result[strtolower($uid)] = array_values($list);
            }
        }

        return [
            'enabled' => $payload['enabled'],
            'excluded' => array_keys($letters),
            'users' => $payload['enabled'] ? $result : [],
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * @param array<string,mixed> $drive
     *
     * @return array{letter:string,host:string,share:string,root:string,domain:string}|null
     */
    public static function normalizeDrive(array $drive): ?array {
        $letter = $drive['letter'] ?? null;
        $host = $drive['host'] ?? null;
        $share = $drive['share'] ?? null;
        $root = $drive['root'] ?? '';
        $domain = $drive['domain'] ?? '';
        if (!is_string($letter) || preg_match('/^[A-Z]$/', $letter) !== 1
            || !is_string($host) || preg_match(self::HOST_PATTERN, $host) !== 1 || str_contains($host, '..')
            || !is_string($share) || !self::validSegment($share) || mb_strlen($share) > 80
            || !is_string($root) || strlen($root) > 1024
            || !is_string($domain) || preg_match(self::DOMAIN_PATTERN, $domain) !== 1) {
            return null;
        }
        $root = trim($root, '/');
        if ($root !== '') {
            foreach (explode('/', $root) as $segment) {
                if (!self::validSegment($segment)) {
                    return null;
                }
            }
        }

        return ['letter' => $letter, 'host' => $host, 'share' => $share, 'root' => $root, 'domain' => $domain];
    }

    /**
     * Anzeige im Dateien-App fuer den angemeldeten Benutzer.
     *
     * @return array{enabled: bool, available: bool, opted_in: bool, drives: list<array{letter:string,path:string}>, excluded: list<string>, credentials: bool, login: string}
     */
    public function userState(IUser $user): array {
        $payload = $this->storedPayload();
        $drives = [];
        foreach ($this->drivesFor($payload, $user->getUID()) as $drive) {
            $drives[] = [
                'letter' => $drive['letter'],
                'path' => '\\\\' . $drive['host'] . '\\' . $drive['share'] . ($drive['root'] !== '' ? '\\' . str_replace('/', '\\', $drive['root']) : ''),
            ];
        }
        $stored = $this->storedCredentials($user->getUID());

        return [
            'enabled' => (bool) ($payload['enabled'] ?? false),
            'available' => $this->filesExternalEnabled() && $this->smbAvailable(),
            'opted_in' => $this->isOptedIn($user->getUID()),
            'drives' => $drives,
            'excluded' => array_values($payload['excluded'] ?? []),
            'credentials' => $stored !== null,
            'login' => (string) ($stored['user'] ?? self::defaultLogin($user->getUID())),
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function setOptIn(IUser $user, bool $enabled): array {
        if ($enabled) {
            $this->config->setUserValue($user->getUID(), Application::APP_ID, self::PREF_KEY, '1');
        } else {
            $this->config->deleteUserValue($user->getUID(), Application::APP_ID, self::PREF_KEY);
        }

        return $this->reconcile();
    }

    /**
     * Hinterlegt das Windows-Kennwort fuer alle Netzlaufwerke des Benutzers
     * (gleicher Speicher wie "Globale Anmeldedaten, vom Benutzer eingegeben").
     */
    public function storeCredentials(IUser $user, string $login, string $password): void {
        $login = trim($login) !== '' ? trim($login) : self::defaultLogin($user->getUID());
        $this->credentials->store($user->getUID(), self::CREDENTIALS_IDENTIFIER, ['user' => $login, 'password' => $password]);
    }

    public static function defaultLogin(string $uid): string {
        $pos = strpos($uid, '@');
        return $pos === false ? $uid : substr($uid, 0, $pos);
    }

    /**
     * Gleicht die externen Speicher mit dem gespeicherten Stand und den
     * Benutzer-Einstellungen ab.
     *
     * @return array{ok: bool, message: string}
     */
    public function reconcile(): array {
        $payload = $this->storedPayload();
        if (!$this->filesExternalEnabled()) {
            $wanted = array_sum(array_map('count', $payload['users'] ?? []));
            return $wanted > 0
                ? $this->fail('App "Externer Speicher" (files_external) ist nicht aktiv.')
                : $this->succeed('Keine Netzlaufwerke einzubinden.');
        }

        $lock = $this->lock();
        try {
            $desired = $this->desiredMounts($payload);
            /** @var object $service */
            $service = \OCP\Server::get(self::GLOBAL_STORAGES);
            $mounts = $this->managedMounts();
            $created = 0;
            $updated = 0;
            $removed = 0;

            foreach ($mounts as $key => $id) {
                if (isset($desired[$key])) {
                    continue;
                }
                try {
                    $service->removeStorage((int) $id);
                } catch (\Throwable $e) {
                    if (!$this->isNotFound($e)) {
                        throw $e;
                    }
                }
                unset($mounts[$key]);
                $removed++;
            }

            foreach ($desired as $key => $mount) {
                $storage = null;
                if (isset($mounts[$key])) {
                    try {
                        $storage = $service->getStorage((int) $mounts[$key]);
                    } catch (\Throwable $e) {
                        if (!$this->isNotFound($e)) {
                            throw $e;
                        }
                    }
                }

                if ($storage === null) {
                    $storage = $service->createStorage(
                        $mount['mount_point'],
                        'smb',
                        self::AUTH_MECHANISM,
                        $mount['backend'],
                        [
                            'enable_sharing' => false,
                            'encrypt' => false,
                            'previews' => true,
                            'filesystem_check_changes' => 1,
                            'readonly' => false,
                        ],
                        $mount['users'],
                        [],
                        null,
                    );
                    $storage = $service->addStorage($storage);
                    $mounts[$key] = (int) $storage->getId();
                    $created++;
                    continue;
                }

                $current = array_values((array) $storage->getApplicableUsers());
                sort($current, SORT_STRING);
                if ($current !== $mount['users'] || (array) $storage->getApplicableGroups() !== []) {
                    $storage->setApplicableUsers($mount['users']);
                    $storage->setApplicableGroups([]);
                    $service->updateStorage($storage);
                    $updated++;
                }
            }

            $this->config->setAppValue(Application::APP_ID, self::KEY_MOUNTS, (string) json_encode($mounts, JSON_FORCE_OBJECT));
        } catch (\Throwable $e) {
            $this->logger->error('Netzlaufwerke konnten nicht eingebunden werden', ['app' => Application::APP_ID, 'exception' => $e]);
            return $this->fail('Netzlaufwerke konnten nicht eingebunden werden: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
        } finally {
            $this->unlock($lock);
        }

        if ($created + $updated + $removed > 0) {
            $this->logger->info('Netzlaufwerke abgeglichen', [
                'app' => Application::APP_ID,
                'created' => $created,
                'updated' => $updated,
                'removed' => $removed,
            ]);
        }

        return $this->succeed(count($desired) . ' eingebundene(s) Laufwerk(e) (' . $created . ' neu, ' . $updated . ' geaendert, ' . $removed . ' entfernt).');
    }

    /**
     * Gewuenschte externe Speicher: je Laufwerk die Benutzer, die es gemeldet
     * und "Netzlaufwerke anzeigen" aktiviert haben.
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,array{mount_point: string, backend: array<string,mixed>, users: list<string>}>
     */
    public function desiredMounts(array $payload): array {
        $users = is_array($payload['users'] ?? null) && !empty($payload['enabled']) ? $payload['users'] : [];
        if ($users === []) {
            return [];
        }

        $desired = [];
        foreach ($this->optedInUsers() as $uid) {
            foreach ($this->drivesFor($payload, $uid) as $drive) {
                $key = sha1($drive['letter'] . '|' . strtolower($drive['host']) . '|' . strtolower($drive['share']) . '|' . strtolower($drive['root']) . '|' . strtolower($drive['domain']));
                $desired[$key] ??= [
                    'mount_point' => self::mountPoint($drive),
                    'backend' => [
                        'host' => $drive['host'],
                        'share' => $drive['share'],
                        'root' => $drive['root'] !== '' ? '/' . $drive['root'] : '',
                        'domain' => $drive['domain'],
                        'show_hidden' => false,
                        'case_sensitive' => false,
                        'check_acl' => false,
                    ],
                    'users' => [],
                ];
                $desired[$key]['users'][] = $uid;
            }
        }
        foreach ($desired as &$mount) {
            $mount['users'] = array_values(array_unique($mount['users']));
            sort($mount['users'], SORT_STRING);
        }
        unset($mount);
        ksort($desired, SORT_STRING);

        return $desired;
    }

    /**
     * @param array{letter:string,share:string,root:string} $drive
     */
    public static function mountPoint(array $drive): string {
        $label = rtrim($drive['share'], '$');
        if ($drive['root'] !== '') {
            $parts = explode('/', $drive['root']);
            $label = (string) end($parts);
        }
        $label = trim(str_replace(['/', '\\'], ' ', $label));

        return '/Laufwerk ' . $drive['letter'] . ($label !== '' ? ' (' . $label . ')' : '');
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return list<array{letter:string,host:string,share:string,root:string,domain:string}>
     */
    private function drivesFor(array $payload, string $uid): array {
        if (empty($payload['enabled']) || !is_array($payload['users'] ?? null)) {
            return [];
        }
        $drives = $payload['users'][strtolower($uid)] ?? [];
        return is_array($drives) ? array_values($drives) : [];
    }

    /**
     * @return list<string> tatsaechliche Nextcloud-Kennungen
     */
    private function optedInUsers(): array {
        try {
            $users = $this->config->getUsersForUserValue(Application::APP_ID, self::PREF_KEY, '1');
        } catch (\Throwable) {
            return [];
        }
        $users = array_values(array_filter(array_map('strval', $users), fn (string $uid): bool => $uid !== ''));
        sort($users, SORT_STRING);
        return $users;
    }

    private function isOptedIn(string $uid): bool {
        return $this->config->getUserValue($uid, Application::APP_ID, self::PREF_KEY, '') === '1';
    }

    /**
     * @return array{user?: string, password?: string}|null
     */
    private function storedCredentials(string $uid): ?array {
        try {
            $stored = $this->credentials->retrieve($uid, self::CREDENTIALS_IDENTIFIER);
        } catch (\Throwable) {
            return null;
        }
        return is_array($stored) && ($stored['password'] ?? '') !== '' ? $stored : null;
    }

    private function filesExternalEnabled(): bool {
        try {
            return $this->appManager->isEnabledForAnyone('files_external') && class_exists(self::GLOBAL_STORAGES);
        } catch (\Throwable) {
            return false;
        }
    }

    private function smbAvailable(): bool {
        if (!$this->filesExternalEnabled()) {
            return false;
        }
        try {
            /** @var object $backends */
            $backends = \OCP\Server::get(self::BACKEND_SERVICE);
            $backend = $backends->getBackend('smb');
            return $backend !== null && $backend->checkDependencies() === [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function isNotFound(\Throwable $e): bool {
        return $e instanceof \OCP\Files\NotFoundException
            || is_a($e, 'OCA\\Files_External\\NotFoundException');
    }

    /**
     * @return array<string,mixed>
     */
    private function storedPayload(): array {
        $payload = json_decode($this->appValue(self::KEY_PAYLOAD, '{}'), true);
        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string,int>
     */
    private function managedMounts(): array {
        $mounts = json_decode($this->appValue(self::KEY_MOUNTS, '{}'), true);
        return is_array($mounts) ? array_map('intval', $mounts) : [];
    }

    /**
     * @return resource|null
     */
    private function lock() {
        $handle = @fopen(rtrim(sys_get_temp_dir(), '/\\') . '/intranet_integration_drives.lock', 'c');
        if ($handle === false) {
            return null;
        }
        flock($handle, LOCK_EX);
        return $handle;
    }

    /**
     * @param resource|null $handle
     */
    private function unlock($handle): void {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function validSegment(string $segment): bool {
        return preg_match(self::SEGMENT_PATTERN, $segment) === 1 && trim($segment, ' .') !== '';
    }

    private function appValue(string $key, string $default = ''): string {
        return (string) $this->config->getAppValue(Application::APP_ID, $key, $default);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function succeed(string $message): array {
        try {
            $this->config->setAppValue(Application::APP_ID, self::KEY_ERROR, '');
        } catch (\Throwable) {
        }
        return ['ok' => true, 'message' => $message];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function fail(string $message): array {
        try {
            $this->config->setAppValue(Application::APP_ID, self::KEY_ERROR, $message);
        } catch (\Throwable) {
        }
        return ['ok' => false, 'message' => $message];
    }
}
