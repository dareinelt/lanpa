<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Service;

use OC\User\Manager as OCUserManager;
use OCA\IntranetIntegration\AppInfo\Application;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Findet das Nextcloud-Konto zur Benutzerkennung des Intranets
 * (SamAccountName, bei weiteren Identitaetsquellen "samaccountname@kennung"),
 * lokal oder ueber user_ldap. Gemeinsam genutzt von SSO und Dateiablage.
 */
class UserResolver {
    public function __construct(
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }

    public function find(string $uid, string $email = ''): ?IUser {
        $user = $this->userManager->get($uid);
        if ($user !== null) {
            return $user;
        }

        // user_ldap kennt ein AD-Konto erst nach dem ersten Kontakt (Zuordnung
        // DN -> Benutzername). Wie beim Anmeldeformular wird der Anmeldename
        // deshalb ueber den Login-Filter (sAMAccountName/UPN/mail) aufgeloest;
        // dabei entsteht die Zuordnung, auch wenn das Konto nie aufgelistet wurde.
        $user = $this->resolveLoginName($uid);
        if ($user !== null) {
            return $user;
        }

        // Suche ueber die konfigurierten Suchattribute; interne Namen koennen
        // sich in der Grossschreibung unterscheiden.
        foreach ($this->userManager->search($uid, 10) as $candidate) {
            if (strcasecmp($candidate->getUID(), $uid) === 0) {
                return $candidate;
            }
        }

        // Kein Abgleich ueber die E-Mail-Adresse fuer Konten weiterer
        // Identitaetsquellen ("@kennung"): deren Verzeichnis koennte sonst per
        // gleicher Adresse ein Konto der Hauptquelle uebernehmen.
        if (!str_contains($uid, '@') && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $matches = $this->userManager->getByEmail($email);
            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        return null;
    }

    /**
     * Anmeldename -> interner Benutzername ueber die Benutzer-Backends
     * (user_ldap: Login-Filter mit sAMAccountName, userPrincipalName, mail).
     */
    private function resolveLoginName(string $uid): ?IUser {
        if (!$this->userManager instanceof OCUserManager) {
            return null;
        }

        foreach ($this->userManager->getBackends() as $backend) {
            if (!method_exists($backend, 'loginName2UserName')) {
                continue;
            }
            try {
                $internal = $backend->loginName2UserName($uid);
            } catch (\Throwable $e) {
                $this->logger->info('Intranet-SSO: Aufloesung des Anmeldenamens fehlgeschlagen.', [
                    'app' => Application::APP_ID,
                    'exception' => $e,
                ]);
                continue;
            }
            if (is_string($internal) && $internal !== '') {
                $user = $this->userManager->get($internal);
                if ($user !== null) {
                    return $user;
                }
            }
        }

        return null;
    }
}
