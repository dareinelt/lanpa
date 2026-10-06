<?php

declare(strict_types=1);

namespace App\Security;

use App\Contracts\AdminUserStoreInterface;
use Closure;

/**
 * Session-basierte Authentifizierung fuer den Administrationsbereich.
 *
 * Neben lokalen Konten (admin_users) gibt es Administratoren aus AD-Gruppen:
 * Sie melden sich per Windows-Anmeldung (SSO) an (loginDirectory()). Ihre
 * Berechtigung wird bei jeder Anfrage erneut ueber $directoryRole geprueft
 * (Windows-Anmeldung noch gueltig, weiterhin Mitglied einer berechtigten
 * Gruppe); andernfalls endet die Sitzung im Adminbereich.
 */
final class Auth
{
    private const USER_KEY = '_admin_user_id';
    private const NAME_KEY = '_admin_username';
    private const ROLE_KEY = '_admin_role';
    private const DIRECTORY_KEY = '_admin_directory';
    private const LAST_ACTIVITY = '_admin_last_activity';
    private const ATTEMPTS_KEY = '_login_attempts';
    private const LOCKED_UNTIL = '_login_locked_until';

    public const MAX_ATTEMPTS = 5;
    public const LOCK_SECONDS = 300;
    public const ROLE_ADMIN = 'admin';
    public const ROLE_REDAKTION = 'redaktion';
    public const ROLE_KAEP = 'kaep';

    /** Berechtigung eines AD-Administrators nur einmal je Anfrage pruefen. */
    private bool $directoryVerified = false;
    private ?string $directoryRoleCache = null;

    /**
     * @param null|Closure(array{username:string,source_key:string}): ?string $directoryRole
     *        aktuelle Rolle eines AD-Administrators (null = kein Zugriff mehr)
     */
    public function __construct(
        private readonly AdminUserStoreInterface $users,
        private readonly int $idleTimeout = 3600,
        private readonly ?Closure $directoryRole = null
    ) {
    }

    public function attempt(string $username, string $password): bool
    {
        if ($this->isLockedOut()) {
            return false;
        }

        $user = $this->users->findActiveByUsername($username);

        // Timing-Angriffe erschweren: auch ohne Treffer wird ein Hash geprueft.
        $hash = is_array($user) ? (string) $user['password_hash'] : '$2y$12$usesomesillystringfortestingpurposesonlyxxxxxxxxxxxxxxxxxxxxx';
        $valid = password_verify($password, $hash) && is_array($user);

        if (!$valid) {
            $this->registerFailedAttempt();

            return false;
        }

        /** @var array<string,mixed> $user */
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->users->updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        Session::regenerate();
        Csrf::rotate();
        Session::forget(self::DIRECTORY_KEY);
        Session::put(self::USER_KEY, (int) $user['id']);
        Session::put(self::NAME_KEY, (string) $user['username']);
        Session::put(self::ROLE_KEY, isset($user['role']) ? (string) $user['role'] : self::ROLE_ADMIN);
        Session::put(self::LAST_ACTIVITY, time());
        Session::forget(self::ATTEMPTS_KEY);
        Session::forget(self::LOCKED_UNTIL);
        $this->users->touchLastLogin((int) $user['id']);

        return true;
    }

    /**
     * Anmeldung eines Administrators aus einer AD-Gruppe (per Windows-
     * Anmeldung erkannt). $role wurde zuvor aus seinen Gruppen ermittelt.
     *
     * @param array{username:string,source_key:string,display_name?:string,office_uid?:string} $identity
     */
    public function loginDirectory(array $identity, string $role): void
    {
        $username = trim((string) $identity['username']);
        if ($username === '' || !in_array($role, [self::ROLE_ADMIN, self::ROLE_REDAKTION, self::ROLE_KAEP], true)) {
            return;
        }
        $sourceKey = strtoupper(trim((string) $identity['source_key']));
        $label = trim((string) ($identity['office_uid'] ?? '')) ?: SsoAuth::officeUid($username, $sourceKey);

        Session::regenerate();
        Csrf::rotate();
        Session::forget(self::USER_KEY);
        Session::put(self::DIRECTORY_KEY, [
            'username' => $username,
            'source_key' => $sourceKey,
            'display_name' => trim((string) ($identity['display_name'] ?? '')),
        ]);
        Session::put(self::NAME_KEY, $label);
        Session::put(self::ROLE_KEY, $role);
        Session::put(self::LAST_ACTIVITY, time());
        Session::forget(self::ATTEMPTS_KEY);
        Session::forget(self::LOCKED_UNTIL);
        $this->directoryVerified = true;
        $this->directoryRoleCache = $role;
    }

    public function check(): bool
    {
        $id = Session::get(self::USER_KEY);
        $directory = Session::get(self::DIRECTORY_KEY);
        if (!is_int($id) && !is_array($directory)) {
            return false;
        }

        $lastActivity = Session::get(self::LAST_ACTIVITY);
        if (is_int($lastActivity) && (time() - $lastActivity) > $this->idleTimeout) {
            $this->expire();

            return false;
        }

        if (is_int($id)) {
            $current = $this->users->findActiveByUsername((string) $this->username());
            if ($current === null || (int) $current['id'] !== $id) {
                $this->expire();

                return false;
            }
            Session::put(self::ROLE_KEY, (string) ($current['role'] ?? self::ROLE_ADMIN));
        }

        if (!is_int($id) && is_array($directory)) {
            if (!$this->directoryVerified) {
                $this->directoryRoleCache = $this->currentDirectoryRole($directory);
                $this->directoryVerified = true;
            }
            $role = $this->directoryRoleCache;
            if ($role === null) {
                $this->expire();

                return false;
            }
            Session::put(self::ROLE_KEY, $role);
        }

        Session::put(self::LAST_ACTIVITY, time());

        return true;
    }

    /**
     * Angemeldet ueber eine AD-Gruppe (kein lokales Konto)?
     */
    public function isDirectoryUser(): bool
    {
        return !is_int(Session::get(self::USER_KEY)) && is_array(Session::get(self::DIRECTORY_KEY));
    }

    public function displayName(): ?string
    {
        $directory = Session::get(self::DIRECTORY_KEY);
        if ($this->isDirectoryUser() && is_array($directory) && trim((string) ($directory['display_name'] ?? '')) !== '') {
            return (string) $directory['display_name'];
        }

        return $this->username();
    }

    public function id(): ?int
    {
        $id = Session::get(self::USER_KEY);

        return is_int($id) ? $id : null;
    }

    public function username(): ?string
    {
        $name = Session::get(self::NAME_KEY);

        return is_string($name) ? $name : null;
    }

    public function role(): ?string
    {
        $role = Session::get(self::ROLE_KEY);

        return is_string($role) ? $role : null;
    }

    public function isAdmin(): bool
    {
        return $this->role() === self::ROLE_ADMIN;
    }

    public function logout(): void
    {
        $this->expire();
        Csrf::rotate();
        Session::regenerate();
    }

    /**
     * Beendet nur die Admin-Anmeldung (Leerlauf, Konto/Gruppe ungueltig).
     * CSRF-Token und Sitzungs-ID bleiben erhalten: Die Sitzung traegt auch
     * Windows-Anmeldung und offene Seiten (z. B. Orvanta), deren Formulare
     * sonst mit „Sitzung abgelaufen“ scheitern wuerden. Eine Rechte-
     * Erweiterung findet hier nicht statt, daher ist keine Rotation noetig.
     */
    private function expire(): void
    {
        Session::forget(self::USER_KEY);
        Session::forget(self::NAME_KEY);
        Session::forget(self::ROLE_KEY);
        Session::forget(self::DIRECTORY_KEY);
        Session::forget(self::LAST_ACTIVITY);
        $this->directoryVerified = false;
        $this->directoryRoleCache = null;
    }

    /**
     * @param array<mixed> $directory
     */
    private function currentDirectoryRole(array $directory): ?string
    {
        $username = (string) ($directory['username'] ?? '');
        if ($this->directoryRole === null || $username === '') {
            return null;
        }

        try {
            $role = ($this->directoryRole)(['username' => $username, 'source_key' => (string) ($directory['source_key'] ?? '')]);
        } catch (\Throwable) {
            return null;
        }

        return in_array($role, [self::ROLE_ADMIN, self::ROLE_REDAKTION, self::ROLE_KAEP], true) ? $role : null;
    }

    public function isLockedOut(): bool
    {
        $until = Session::get(self::LOCKED_UNTIL);

        return is_int($until) && $until > time();
    }

    public function lockedForSeconds(): int
    {
        $until = Session::get(self::LOCKED_UNTIL);

        return is_int($until) ? max(0, $until - time()) : 0;
    }

    private function registerFailedAttempt(): void
    {
        $attempts = Session::get(self::ATTEMPTS_KEY);
        $attempts = is_int($attempts) ? $attempts + 1 : 1;
        Session::put(self::ATTEMPTS_KEY, $attempts);

        if ($attempts >= self::MAX_ATTEMPTS) {
            Session::put(self::LOCKED_UNTIL, time() + self::LOCK_SECONDS);
            Session::put(self::ATTEMPTS_KEY, 0);
        }
    }
}
