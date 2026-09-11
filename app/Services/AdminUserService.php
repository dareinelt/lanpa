<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\AdminUserRepository;

/**
 * Verwaltet Administrationskonten (Benutzerverwaltung).
 *
 * Rollen:
 *  - "admin": voller Zugriff auf den gesamten Adminbereich, inkl. Benutzerverwaltung.
 *  - "redaktion": darf ausschliesslich die wichtigen Links bearbeiten.
 */
final class AdminUserService
{
    public const MIN_PASSWORD_LENGTH = 12;

    public function __construct(private readonly AdminUserRepository $repository)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function allItems(): array
    {
        return $this->repository->all();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array{username:string,role:string,password:?string,active:bool}
     */
    private function validate(array $input, ?int $id): array
    {
        $errors = [];

        $username = trim((string) ($input['username'] ?? ''));
        if (preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username) !== 1) {
            $errors['username'] = 'Benutzername: 3-64 Zeichen, erlaubt sind A-Z a-z 0-9 . _ -.';
        } elseif ($this->repository->usernameExists($username, $id)) {
            $errors['username'] = 'Dieser Benutzername ist bereits vergeben.';
        }

        $role = (string) ($input['role'] ?? '');
        if (!in_array($role, AdminUserRepository::ROLES, true)) {
            $errors['role'] = 'Bitte eine gültige Rolle auswählen.';
        }

        $password = (string) ($input['password'] ?? '');
        if ($id === null && $password === '') {
            $errors['password'] = 'Bitte ein Passwort vergeben.';
        }
        if ($password !== '' && mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors['password'] = sprintf('Das Passwort muss mindestens %d Zeichen lang sein.', self::MIN_PASSWORD_LENGTH);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'username' => $username,
            'role' => $role,
            'password' => $password === '' ? null : $password,
            'active' => !empty($input['active']),
        ];
    }

    /**
     * @param array<string,mixed> $input
     */
    public function create(array $input): int
    {
        $data = $this->validate($input, null);

        /** @var string $password */
        $password = $data['password'];
        $id = $this->repository->create($data['username'], password_hash($password, PASSWORD_DEFAULT), $data['role']);

        if (!$data['active']) {
            $this->repository->setActive($id, false);
        }

        return $id;
    }

    /**
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $input): void
    {
        $existing = $this->repository->find($id);
        if ($existing === null) {
            throw new ValidationException(['id' => 'Benutzer nicht gefunden.']);
        }

        $data = $this->validate($input, $id);

        if ((string) $existing['role'] === AdminUserRepository::ROLE_ADMIN
            && ($data['role'] !== AdminUserRepository::ROLE_ADMIN || !$data['active'])
            && $this->isLastActiveAdmin($id)
        ) {
            throw new ValidationException(['role' => 'Der letzte aktive Administrator kann nicht geändert werden.']);
        }

        $this->repository->updateUsernameAndRole($id, $data['username'], $data['role']);
        $this->repository->setActive($id, $data['active']);

        if ($data['password'] !== null) {
            $this->repository->updatePasswordHash($id, password_hash($data['password'], PASSWORD_DEFAULT));
        }
    }

    public function toggle(int $id): void
    {
        $user = $this->repository->find($id);
        if ($user === null) {
            throw new ValidationException(['id' => 'Benutzer nicht gefunden.']);
        }

        $active = (bool) $user['active'];
        if ($active && (string) $user['role'] === AdminUserRepository::ROLE_ADMIN && $this->isLastActiveAdmin($id)) {
            throw new ValidationException(['active' => 'Der letzte aktive Administrator kann nicht deaktiviert werden.']);
        }

        $this->repository->setActive($id, !$active);
    }

    public function delete(int $id): void
    {
        $user = $this->repository->find($id);
        if ($user === null) {
            throw new ValidationException(['id' => 'Benutzer nicht gefunden.']);
        }

        if ((string) $user['role'] === AdminUserRepository::ROLE_ADMIN && $this->isLastActiveAdmin($id)) {
            throw new ValidationException(['id' => 'Der letzte aktive Administrator kann nicht gelöscht werden.']);
        }

        $this->repository->delete($id);
    }

    private function isLastActiveAdmin(int $id): bool
    {
        $user = $this->repository->find($id);
        if ($user === null || (string) $user['role'] !== AdminUserRepository::ROLE_ADMIN || !(bool) $user['active']) {
            return false;
        }

        return $this->repository->countActiveByRole(AdminUserRepository::ROLE_ADMIN) <= 1;
    }
}
