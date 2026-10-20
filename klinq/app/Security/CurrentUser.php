<?php

declare(strict_types=1);

namespace App\Security;

use App\Exceptions\ForbiddenException;

/**
 * Angemeldeter Benutzer aus der Session inkl. Rechteprüfung.
 */
final class CurrentUser
{
    private const SESSION_KEY = 'auth_user';

    /** Vorübergehender Systemkontext (siehe runAs); hat Vorrang vor der Sitzung. @var array<string,mixed>|null */
    private ?array $override = null;

    public function __construct(private readonly Permissions $permissions) {}

    /** @param array<string,mixed> $user */
    public function login(array $user): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'display_name' => (string) ($user['display_name'] ?? $user['username']),
            'role' => (string) $user['role'],
            'groups' => self::normalizeGroups($user['groups'] ?? []),
            'can_sign_electronically' => (bool) ($user['can_sign_electronically'] ?? false),
            'logged_in_at' => time(),
        ];
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Übernimmt geänderte Stammdaten (Rolle, Anzeigename) aus der Datenbank in die Sitzung,
     * damit Rollenwechsel ohne Neuanmeldung wirken. @param array<string,mixed> $user
     */
    public function refresh(array $user): void
    {
        if (!$this->isAuthenticated()) {
            return;
        }
        $_SESSION[self::SESSION_KEY]['role'] = (string) $user['role'];
        $_SESSION[self::SESSION_KEY]['groups'] = self::normalizeGroups($user['groups'] ?? []);
        $_SESSION[self::SESSION_KEY]['display_name'] = (string) ($user['display_name'] ?? $user['username']);
        $_SESSION[self::SESSION_KEY]['can_sign_electronically'] = (bool) ($user['can_sign_electronically'] ?? false);
    }

    public function isAuthenticated(): bool
    {
        return is_array($this->override) || is_array($_SESSION[self::SESSION_KEY] ?? null);
    }

    /**
     * Führt eine Aktion im Kontext eines Systembenutzers aus, ohne die Sitzung zu verändern.
     * Wird für anonyme Vorgänge benötigt (z. B. Störungsformular), damit der Besucher
     * nicht dauerhaft die Rechte des Systemkontos erhält.
     *
     * @param array<string,mixed> $user Benutzerdatensatz aus der Datenbank
     */
    public function runAs(array $user, callable $action): mixed
    {
        $previous = $this->override;
        $this->override = [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'display_name' => (string) ($user['display_name'] ?? $user['username']),
            'role' => (string) $user['role'],
            'groups' => self::normalizeGroups($user['groups'] ?? []),
            'logged_in_at' => time(),
        ];
        try {
            return $action();
        } finally {
            $this->override = $previous;
        }
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        if (is_array($this->override)) {
            return $this->override;
        }
        $user = $_SESSION[self::SESSION_KEY] ?? null;

        return is_array($user) ? $user : null;
    }

    public function id(): ?int
    {
        return $this->user()['id'] ?? null;
    }

    public function username(): string
    {
        return (string) ($this->user()['username'] ?? 'system');
    }

    public function displayName(): string
    {
        return (string) ($this->user()['display_name'] ?? $this->username());
    }

    public function role(): string
    {
        return (string) ($this->user()['role'] ?? '');
    }

    /** Opt-in aus der Benutzerverwaltung: darf Entnahme/Rückgabe am Desktop elektronisch signieren (Passwort-Bestätigung). */
    public function canSignElectronically(): bool
    {
        return (bool) ($this->user()['can_sign_electronically'] ?? false);
    }

    /** Zugewiesene Berechtigungsgruppen (zusätzlich zur Rolle). @return array<int,string> */
    public function groups(): array
    {
        return (array) ($this->user()['groups'] ?? []);
    }

    public function can(string $permission): bool
    {
        return $this->isAuthenticated() && $this->permissions->hasEffective($this->role(), $this->groups(), $permission);
    }

    public function require(string $permission): void
    {
        if (!$this->can($permission)) {
            throw new ForbiddenException('Keine Berechtigung: ' . $permission);
        }
    }

    /** @return array<int,string> */
    public function permissions(): array
    {
        if (!$this->isAuthenticated()) {
            return [];
        }
        $permissions = $this->permissions->forRole($this->role());
        foreach ($this->groups() as $group) {
            $permissions = array_merge($permissions, $this->permissions->forGroup($group));
        }

        return array_values(array_unique($permissions));
    }

    /** @param mixed $groups @return array<int,string> */
    private static function normalizeGroups(mixed $groups): array
    {
        return array_values(array_unique(array_map('strval', (array) $groups)));
    }
}
