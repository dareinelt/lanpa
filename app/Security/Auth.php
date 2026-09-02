<?php

declare(strict_types=1);

namespace App\Security;

use App\Contracts\AdminUserStoreInterface;

/**
 * Session-basierte Authentifizierung fuer den Administrationsbereich.
 */
final class Auth
{
    private const USER_KEY = '_admin_user_id';
    private const NAME_KEY = '_admin_username';
    private const LAST_ACTIVITY = '_admin_last_activity';
    private const ATTEMPTS_KEY = '_login_attempts';
    private const LOCKED_UNTIL = '_login_locked_until';

    public const MAX_ATTEMPTS = 5;
    public const LOCK_SECONDS = 300;

    public function __construct(
        private readonly AdminUserStoreInterface $users,
        private readonly int $idleTimeout = 3600
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
        Session::put(self::USER_KEY, (int) $user['id']);
        Session::put(self::NAME_KEY, (string) $user['username']);
        Session::put(self::LAST_ACTIVITY, time());
        Session::forget(self::ATTEMPTS_KEY);
        Session::forget(self::LOCKED_UNTIL);
        $this->users->touchLastLogin((int) $user['id']);

        return true;
    }

    public function check(): bool
    {
        $id = Session::get(self::USER_KEY);
        if (!is_int($id)) {
            return false;
        }

        $lastActivity = Session::get(self::LAST_ACTIVITY);
        if (is_int($lastActivity) && (time() - $lastActivity) > $this->idleTimeout) {
            $this->logout();

            return false;
        }

        Session::put(self::LAST_ACTIVITY, time());

        return true;
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

    public function logout(): void
    {
        Session::forget(self::USER_KEY);
        Session::forget(self::NAME_KEY);
        Session::forget(self::LAST_ACTIVITY);
        Csrf::rotate();
        Session::regenerate();
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
