<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\Ldap\LdapAuthenticator;

/**
 * Authentifizierung lokaler Benutzer (Argon2id) mit Sperre nach Fehlversuchen;
 * optional LDAP/AD-Anmeldung über LdapAuthenticator.
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly ?LdapAuthenticator $ldapAuthenticator = null
    ) {}

    /** @return array<string,mixed>|null Benutzerdatensatz bei Erfolg */
    public function attempt(string $username, string $password): ?array
    {
        $username = trim($username);
        if ($username === '' || $password === '' || mb_strlen($username) > 120) {
            return null;
        }

        $user = $this->users->findByUsername($username);

        if ($user !== null && (int) $user['is_active'] !== 1) {
            $this->logger->warning('Login für deaktivierten Benutzer abgelehnt', ['username' => $username]);
            return null;
        }

        if ($user !== null && (int) ($user['is_locked'] ?? 0) === 1) {
            $this->logger->warning('Login für gesperrten Benutzer abgelehnt', ['username' => $username]);
            return null;
        }

        if ($user !== null && $user['auth_source'] === 'local') {
            if ($this->hasher->verify($password, (string) ($user['password_hash'] ?? ''))) {
                $this->users->recordLoginSuccess((int) $user['id']);
                if ($this->hasher->needsRehash((string) $user['password_hash'])) {
                    $this->users->update((int) $user['id'], ['password_hash' => $this->hasher->hash($password)]);
                }

                return $user;
            }
            $this->users->recordLoginFailure(
                (int) $user['id'],
                (int) $this->config->get('app.security.login_max_attempts', 10),
                (int) $this->config->get('app.security.login_lockout_minutes', 15)
            );
            $this->logger->info('Fehlgeschlagener Login', ['username' => $username]);

            return null;
        }

        if ($this->ldapAuthenticator !== null && $this->ldapAuthenticator->isEnabled()) {
            $ldapUser = $this->ldapAuthenticator->authenticate($username, $password);
            if ($ldapUser === null) {
                if ($user !== null) {
                    $this->users->recordLoginFailure((int) $user['id'], 10, 15);
                }

                return null;
            }

            if ($user === null) {
                $roleId = $this->users->roleId((string) $this->config->get('ldap.auth.default_role', 'readonly'));
                if ($roleId === null) {
                    return null;
                }
                $id = $this->users->create([
                    'username' => $username,
                    'display_name' => $ldapUser['display_name'] ?? $username,
                    'email' => $ldapUser['email'] ?? null,
                    'password_hash' => null,
                    'role_id' => $roleId,
                    'auth_source' => 'ldap',
                    'employee_id' => $ldapUser['employee_id'] ?? null,
                ]);
                $user = $this->users->find($id);
            }
            if ($user !== null) {
                $this->users->recordLoginSuccess((int) $user['id']);
            }

            return $user;
        }

        // Timing-Angleichung für unbekannte Benutzer
        $this->hasher->verify($password, '$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXlzYWx0ZHVtbXk$Y2hlY2s');

        return null;
    }
}
