<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Service;

use OCA\IntranetIntegration\AppInfo\Application;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud-Administratoren aus AD-Gruppen (im Intranet festgelegt).
 *
 * Das Intranet uebergibt signiert die Kennungen aller Benutzer, die
 * Administratoren sein sollen; sie werden Mitglied der Gruppe "admin".
 * Entfernt werden nur Konten, die diese App selbst aufgenommen hat
 * ("managed") – bestehende Administratoren (lokaler admin, LDAP-Gruppe per
 * NEXTCLOUD_LDAP_ADMIN_GROUP, manuell ernannte) bleiben unangetastet.
 * Konten, die Nextcloud noch nicht kennt, erhalten die Rechte bei der
 * ersten Anmeldung (AdminsLoginListener).
 */
class AdminsService {
    public const MAX_USERS = 5000;
    public const ADMIN_GROUP = 'admin';

    private const KEY_USERS = 'admins_users';
    private const KEY_MANAGED = 'admins_managed';
    private const KEY_FINGERPRINT = 'admins_fingerprint';
    private const KEY_PENDING = 'admins_pending';
    private const KEY_ERROR = 'admins_error';
    private const KEY_APPLIED_AT = 'admins_applied_at';

    public function __construct(
        private IConfig $config,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{fingerprint: string, users: int, managed: int, pending: int, applied_at: string, error: string}
     */
    public function status(): array {
        return [
            'fingerprint' => $this->appValue(self::KEY_FINGERPRINT),
            'users' => count($this->storedList(self::KEY_USERS)),
            'managed' => count($this->storedList(self::KEY_MANAGED)),
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
        $users = $payload['users'] ?? null;
        $fingerprint = $payload['fingerprint'] ?? null;
        if ((int) ($payload['version'] ?? 0) !== 1 || !is_array($users) || !array_is_list($users)
            || count($users) > self::MAX_USERS
            || !is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            return $this->fail('Ungueltige Administratorenliste.');
        }

        $wanted = [];
        foreach ($users as $uid) {
            if (!is_string($uid) || preg_match(QuotaService::UID_PATTERN, $uid) !== 1) {
                return $this->fail('Ungueltige Kennung ' . substr(is_string($uid) ? $uid : '?', 0, 80) . '.');
            }
            $wanted[strtolower($uid)] = $uid;
        }

        try {
            $group = $this->adminGroup();
            $managed = [];
            foreach ($this->storedList(self::KEY_MANAGED) as $uid) {
                $managed[strtolower($uid)] = $uid;
            }

            $added = 0;
            $pending = 0;
            foreach ($wanted as $lower => $uid) {
                $user = $this->userManager->get($uid);
                if ($user === null) {
                    $pending++;
                    continue;
                }
                if (!$group->inGroup($user)) {
                    $group->addUser($user);
                    $managed[strtolower($user->getUID())] = $user->getUID();
                    $added++;
                }
            }

            $removed = 0;
            foreach ($managed as $lower => $uid) {
                if (isset($wanted[$lower])) {
                    continue;
                }
                unset($managed[$lower]);
                $user = $this->userManager->get($uid);
                if ($user !== null && $group->inGroup($user)) {
                    $group->removeUser($user);
                    $removed++;
                }
            }

            $this->storeList(self::KEY_USERS, array_values($wanted));
            $this->storeList(self::KEY_MANAGED, array_values($managed));
            $this->config->setAppValue(Application::APP_ID, self::KEY_PENDING, (string) $pending);
            $this->config->setAppValue(Application::APP_ID, self::KEY_FINGERPRINT, $fingerprint);
            $this->config->setAppValue(Application::APP_ID, self::KEY_APPLIED_AT, gmdate('c'));
            $this->config->setAppValue(Application::APP_ID, self::KEY_ERROR, '');
        } catch (\Throwable $e) {
            $this->logger->error('Administratoren aus dem Intranet konnten nicht uebernommen werden', ['app' => Application::APP_ID, 'exception' => $e]);
            return $this->fail('Administratoren konnten nicht uebernommen werden: ' . get_class($e));
        }

        $this->logger->info('Administratoren aus dem Intranet uebernommen', [
            'app' => Application::APP_ID,
            'users' => count($wanted),
            'added' => $added,
            'removed' => $removed,
            'pending' => $pending,
        ]);

        $message = 'Administratoren uebernommen: ' . count($wanted) . ' Benutzer (' . $added . ' aufgenommen, '
            . $removed . ' entfernt'
            . ($pending > 0 ? ', ' . $pending . ' erhalten die Rechte bei der ersten Anmeldung' : '') . ').';

        return ['ok' => true, 'message' => $message];
    }

    /**
     * Nimmt einen (neu) angemeldeten Benutzer in die Gruppe admin auf, wenn
     * ihn das Intranet als Administrator fuehrt.
     */
    public function applyForUser(IUser $user): void {
        $wanted = $this->storedList(self::KEY_USERS);
        if ($wanted === []) {
            return;
        }

        $uid = strtolower($user->getUID());
        if (!in_array($uid, array_map('strtolower', $wanted), true)) {
            return;
        }

        $group = $this->adminGroup();
        if ($group->inGroup($user)) {
            return;
        }
        $group->addUser($user);

        $managed = $this->storedList(self::KEY_MANAGED);
        $managed[] = $user->getUID();
        $this->storeList(self::KEY_MANAGED, array_values(array_unique($managed)));
        $pending = max(0, (int) $this->appValue(self::KEY_PENDING, '0') - 1);
        $this->config->setAppValue(Application::APP_ID, self::KEY_PENDING, (string) $pending);

        $this->logger->info('Administratorrechte aus dem Intranet bei der Anmeldung vergeben', [
            'app' => Application::APP_ID,
            'user' => $user->getUID(),
        ]);
    }

    private function adminGroup(): IGroup {
        $group = $this->groupManager->get(self::ADMIN_GROUP) ?? $this->groupManager->createGroup(self::ADMIN_GROUP);
        if ($group === null) {
            throw new \RuntimeException('Gruppe admin nicht verfuegbar.');
        }
        return $group;
    }

    /**
     * @return list<string>
     */
    private function storedList(string $key): array {
        $list = json_decode($this->appValue($key, '[]'), true);
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /**
     * @param list<string> $list
     */
    private function storeList(string $key, array $list): void {
        $this->config->setAppValue(Application::APP_ID, $key, (string) json_encode($list));
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
