<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Service;

use OCA\IntranetIntegration\AppInfo\Application;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Speicherplatz-Kontingente (Quota) aus dem Intranet.
 *
 * Das Intranet uebergibt signiert den Standard (files/default_quota) und alle
 * davon abweichenden Benutzer (Kennung => MB; individuelle Kontingente und
 * AD-Gruppenregeln). Konten, die Nextcloud noch nicht kennt (z. B. AD-Konten
 * vor der ersten Anmeldung), erhalten ihr Kontingent bei der Anmeldung
 * (QuotaLoginListener). Faellt ein Benutzer aus der Liste, wird sein
 * Kontingent auf "default" zurueckgesetzt – aber nur, wenn es noch dem vom
 * Intranet gesetzten Wert entspricht (manuelle Aenderungen bleiben erhalten).
 */
class QuotaService {
    public const MAX_USERS = 50000;
    public const MAX_MB = 10485760;
    public const UID_PATTERN = '/^[a-zA-Z0-9._-]{1,64}(@[a-z0-9_]{1,32})?$/';

    private const KEY_USERS = 'quota_users';
    private const KEY_FINGERPRINT = 'quota_fingerprint';
    private const KEY_PENDING = 'quota_pending';
    private const KEY_ERROR = 'quota_error';
    private const KEY_APPLIED_AT = 'quota_applied_at';

    public function __construct(
        private IConfig $config,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{fingerprint: string, default_quota: string, users: int, pending: int, applied_at: string, error: string}
     */
    public function status(): array {
        return [
            'fingerprint' => $this->appValue(self::KEY_FINGERPRINT),
            'default_quota' => (string) $this->config->getAppValue('files', 'default_quota', 'none'),
            'users' => count($this->storedUsers()),
            'pending' => (int) $this->appValue(self::KEY_PENDING, '0'),
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
        $default = $payload['default_mb'] ?? null;
        $users = $payload['users'] ?? null;
        $fingerprint = $payload['fingerprint'] ?? null;
        if ((int) ($payload['version'] ?? 0) !== 1 || !is_int($default) || $default < 1 || $default > self::MAX_MB
            || !is_array($users) || count($users) > self::MAX_USERS
            || !is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            return $this->fail('Ungueltige Kontingente.');
        }

        $wanted = [];
        foreach ($users as $uid => $mb) {
            $uid = (string) $uid;
            if (preg_match(self::UID_PATTERN, $uid) !== 1 || !is_int($mb) || $mb < 1 || $mb > self::MAX_MB) {
                return $this->fail('Ungueltiges Kontingent fuer ' . substr($uid, 0, 80) . '.');
            }
            $wanted[$uid] = $mb;
        }

        try {
            $this->config->setAppValue('files', 'default_quota', self::quotaString($default));

            $changed = 0;
            $pending = 0;
            foreach ($wanted as $uid => $mb) {
                $user = $this->findUser($uid);
                if ($user === null) {
                    $pending++;
                    continue;
                }
                if ($this->setQuota($user, self::quotaString($mb))) {
                    $changed++;
                }
            }

            $reset = 0;
            $wantedLower = array_change_key_case($wanted, CASE_LOWER);
            foreach ($this->storedUsers() as $uid => $mb) {
                if (isset($wantedLower[strtolower((string) $uid)])) {
                    continue;
                }
                $user = $this->findUser((string) $uid);
                if ($user !== null && $user->getQuota() === self::quotaString((int) $mb)) {
                    $user->setQuota('default');
                    $reset++;
                }
            }

            $this->config->setAppValue(Application::APP_ID, self::KEY_USERS, (string) json_encode($wanted, JSON_FORCE_OBJECT));
            $this->config->setAppValue(Application::APP_ID, self::KEY_PENDING, (string) $pending);
            $this->config->setAppValue(Application::APP_ID, self::KEY_FINGERPRINT, $fingerprint);
            $this->config->setAppValue(Application::APP_ID, self::KEY_APPLIED_AT, gmdate('c'));
            $this->config->setAppValue(Application::APP_ID, self::KEY_ERROR, '');
        } catch (\Throwable $e) {
            $this->logger->error('Kontingente aus dem Intranet konnten nicht uebernommen werden', ['app' => Application::APP_ID, 'exception' => $e]);
            return $this->fail('Kontingente konnten nicht uebernommen werden: ' . get_class($e));
        }

        $this->logger->info('Kontingente aus dem Intranet uebernommen', [
            'app' => Application::APP_ID,
            'default' => self::quotaString($default),
            'users' => count($wanted),
            'changed' => $changed,
            'reset' => $reset,
            'pending' => $pending,
        ]);

        $message = 'Kontingente uebernommen: Standard ' . self::quotaString($default) . ', '
            . count($wanted) . ' abweichende Benutzer (' . $changed . ' geaendert, ' . $reset . ' zurueckgesetzt'
            . ($pending > 0 ? ', ' . $pending . ' erhalten ihr Kontingent bei der ersten Anmeldung' : '') . ').';

        return ['ok' => true, 'message' => $message];
    }

    /**
     * Setzt das Kontingent eines (neu) angemeldeten Benutzers.
     */
    public function applyForUser(IUser $user): void {
        $users = $this->storedUsers();
        if ($users === []) {
            return;
        }

        $uid = strtolower($user->getUID());
        foreach ($users as $candidate => $mb) {
            if (strtolower((string) $candidate) === $uid) {
                $this->setQuota($user, self::quotaString((int) $mb));
                return;
            }
        }
    }

    public static function quotaString(int $mb): string {
        return $mb . ' MB';
    }

    private function setQuota(IUser $user, string $quota): bool {
        if ($user->getQuota() === $quota) {
            return false;
        }
        $user->setQuota($quota);
        return true;
    }

    private function findUser(string $uid): ?IUser {
        // Nur bereits bekannte Konten (keine Verzeichnissuche je Benutzer);
        // unbekannte erhalten ihr Kontingent bei der ersten Anmeldung.
        return $this->userManager->get($uid);
    }

    /**
     * @return array<string,int>
     */
    private function storedUsers(): array {
        $users = json_decode($this->appValue(self::KEY_USERS, '{}'), true);
        return is_array($users) ? $users : [];
    }

    private function appValue(string $key, string $default = ''): string {
        return (string) $this->config->getAppValue(Application::APP_ID, $key, $default);
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
