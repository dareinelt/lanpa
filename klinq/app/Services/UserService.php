<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Security\PasswordHasher;
use App\Security\Permissions;
use App\Support\Validator;

/**
 * Verwaltung lokaler Benutzerkonten (Anlage, Rolle, Aktiv/Inaktiv, Passwörter).
 * Passwörter werden ausschließlich als Argon2id-Hash gespeichert und nie protokolliert.
 */
final class UserService
{
    public const PASSWORD_MIN_LENGTH = 10;
    public const USERNAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9._\-@]{2,63}$/';

    /** Felder, die im Audit-Log erscheinen dürfen */
    private const AUDIT_FIELDS = ['username', 'display_name', 'email', 'role', 'is_active', 'auth_source', 'groups', 'can_sign_electronically'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly Permissions $permissions,
        private readonly CurrentUser $currentUser,
        private readonly AuditLogService $audit
    ) {}

    /** @param array<string,mixed> $input */
    public function create(array $input): int
    {
        $data = $this->validateProfile($input, null);
        $password = $this->validatePassword((string) ($input['password'] ?? ''), (string) ($input['password_confirmation'] ?? ''), true);

        $id = $this->users->create([
            'username' => $data['username'],
            'display_name' => $data['display_name'],
            'email' => $data['email'],
            'password_hash' => $this->hasher->hash($password),
            'role_id' => $this->users->roleId($data['role']),
            'auth_source' => 'local',
            'is_active' => $data['is_active'],
            'can_sign_electronically' => $data['can_sign_electronically'],
        ]);
        $this->users->syncGroups($id, $data['group_ids']);
        $this->audit->log('create', 'user', $id, $data['username'], null, $this->auditData($data + ['auth_source' => 'local']));

        return $id;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $existing, array $input): void
    {
        $data = $this->validateProfile($input, $id, $existing);
        $this->guardSelfAndLastAdmin($id, $existing, $data['role'], (bool) $data['is_active']);

        $this->users->update($id, [
            'display_name' => $data['display_name'],
            'email' => $data['email'],
            'role_id' => $this->users->roleId($data['role']),
            'is_active' => $data['is_active'],
            'can_sign_electronically' => $data['can_sign_electronically'],
        ]);
        $this->users->syncGroups($id, $data['group_ids']);
        $this->audit->log('update', 'user', $id, (string) $existing['username'], $this->auditData($existing), $this->auditData($data + ['username' => $existing['username'], 'auth_source' => $existing['auth_source']]));
    }

    /** @param array<string,mixed> $existing */
    public function setActive(int $id, array $existing, bool $active): void
    {
        $this->guardSelfAndLastAdmin($id, $existing, (string) $existing['role'], $active);
        $this->users->update($id, ['is_active' => $active ? 1 : 0, 'failed_logins' => 0, 'locked_until' => null]);
        $this->audit->log($active ? 'activate' : 'deactivate', 'user', $id, (string) $existing['username'], ['is_active' => $existing['is_active']], ['is_active' => $active ? 1 : 0]);
    }

    /** Passwort durch Administrator neu setzen (nur lokale Konten). @param array<string,mixed> $existing */
    public function resetPassword(int $id, array $existing, string $password, string $confirmation): void
    {
        if ($existing['auth_source'] !== 'local') {
            throw ValidationException::single('password', 'Für AD-Konten wird das Passwort im Active Directory verwaltet.');
        }
        $password = $this->validatePassword($password, $confirmation, true);
        $this->users->update($id, ['password_hash' => $this->hasher->hash($password), 'failed_logins' => 0, 'locked_until' => null]);
        $this->audit->log('reset_password', 'user', $id, (string) $existing['username']);
    }

    /** Eigenes Passwort ändern – erfordert das aktuelle Passwort. @param array<string,mixed> $user */
    public function changeOwnPassword(array $user, string $current, string $password, string $confirmation): void
    {
        if ($user['auth_source'] !== 'local') {
            throw ValidationException::single('current_password', 'Für AD-Konten wird das Passwort im Active Directory verwaltet.');
        }
        if ($current === '' || !$this->hasher->verify($current, (string) ($user['password_hash'] ?? ''))) {
            throw ValidationException::single('current_password', 'Das aktuelle Passwort ist nicht korrekt.');
        }
        $password = $this->validatePassword($password, $confirmation, true);
        if ($password === $current) {
            throw ValidationException::single('password', 'Das neue Passwort muss sich vom aktuellen unterscheiden.');
        }
        $this->users->update((int) $user['id'], ['password_hash' => $this->hasher->hash($password)]);
        $this->audit->log('change_password', 'user', (int) $user['id'], (string) $user['username']);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function validateProfile(array $input, ?int $excludeId, ?array $existing = null): array
    {
        $roles = array_keys($this->permissions->roleLabels());
        $v = (new Validator($input))
            ->string('display_name', 'Anzeigename', true, 150, 2)
            ->email('email', 'E-Mail')
            ->in('role', 'Rolle', $roles, true)
            ->bool('is_active')
            ->bool('can_sign_electronically');
        if ($existing === null) {
            $v->pattern('username', 'Benutzername', self::USERNAME_PATTERN, 'Benutzername: 3–64 Zeichen, Buchstaben, Ziffern sowie . _ - @ (muss mit Buchstabe oder Ziffer beginnen).', true);
        }
        $data = $v->validated();

        if ($existing === null && $this->users->findByUsername((string) $data['username']) !== null) {
            throw ValidationException::single('username', 'Dieser Benutzername ist bereits vergeben.');
        }
        $data['is_active'] = $data['is_active'] ? 1 : 0;
        $data['can_sign_electronically'] = $data['can_sign_electronically'] ? 1 : 0;

        // Berechtigungsgruppen (z. B. „Statistik“): einem Benutzer können mehrere zugeordnet werden.
        // Unbekannte/fremde IDs werden stillschweigend verworfen.
        $validGroupIds = array_column($this->users->permissionGroups(), 'id');
        $requested = array_map('intval', (array) ($input['groups'] ?? []));
        $data['group_ids'] = array_values(array_intersect($requested, array_map('intval', $validGroupIds)));
        $groupNames = array_column($this->users->permissionGroups(), 'name', 'id');
        $data['groups'] = array_values(array_map(static fn (int $id): string => (string) $groupNames[$id], $data['group_ids']));

        return $data;
    }

    private function validatePassword(string $password, string $confirmation, bool $required): string
    {
        $errors = [];
        if ($password === '') {
            if ($required) {
                $errors['password'] = 'Passwort ist erforderlich.';
            }
        } elseif (mb_strlen($password) < self::PASSWORD_MIN_LENGTH) {
            $errors['password'] = 'Das Passwort muss mindestens ' . self::PASSWORD_MIN_LENGTH . ' Zeichen lang sein.';
        } elseif (mb_strlen($password) > 200) {
            $errors['password'] = 'Das Passwort darf höchstens 200 Zeichen lang sein.';
        } elseif (!preg_match('/[a-zA-Z]/u', $password) || !preg_match('/[^a-zA-Z]/u', $password)) {
            $errors['password'] = 'Das Passwort muss Buchstaben und mindestens eine Ziffer oder ein Sonderzeichen enthalten.';
        }
        if (!isset($errors['password']) && $password !== $confirmation) {
            $errors['password_confirmation'] = 'Die Passwort-Wiederholung stimmt nicht überein.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        return $password;
    }

    /** Schutz vor Aussperren: eigenes Konto nicht deaktivieren/herabstufen, letzten aktiven Admin erhalten. @param array<string,mixed> $existing */
    private function guardSelfAndLastAdmin(int $id, array $existing, string $newRole, bool $active): void
    {
        $isSelf = $this->currentUser->id() === $id;
        if ($isSelf && !$active) {
            throw ValidationException::single('is_active', 'Das eigene Konto kann nicht deaktiviert werden.');
        }
        if ($isSelf && $existing['role'] === 'admin' && $newRole !== 'admin') {
            throw ValidationException::single('role', 'Die eigene Administratorrolle kann nicht entzogen werden.');
        }
        $wasActiveAdmin = $existing['role'] === 'admin' && (int) $existing['is_active'] === 1;
        $staysActiveAdmin = $newRole === 'admin' && $active;
        if ($wasActiveAdmin && !$staysActiveAdmin && $this->users->countActiveWithRole('admin', $id) === 0) {
            throw ValidationException::single('role', 'Mindestens ein aktiver Administrator muss erhalten bleiben.');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function auditData(array $row): array
    {
        $out = [];
        foreach (self::AUDIT_FIELDS as $field) {
            if (array_key_exists($field, $row)) {
                $out[$field] = $row[$field];
            }
        }

        return $out;
    }
}
