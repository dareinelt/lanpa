<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\AdminUserStoreInterface;

final class AdminUserRepository extends Repository implements AdminUserStoreInterface
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_REDAKTION = 'redaktion';

    /** @var list<string> */
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_REDAKTION];

    /**
     * @return array<string,mixed>|null
     */
    public function findActiveByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, password_hash, role, active FROM admin_users WHERE username = :username AND active = 1'
        );
        $statement->execute(['username' => $username]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, role, active, last_login_at, created_at FROM admin_users WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, username, role, active, last_login_at, created_at FROM admin_users ORDER BY username ASC'
        );

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement !== false ? $statement->fetchAll() : [];

        return $rows;
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $statement = $this->pdo->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id');
        $statement->execute(['hash' => $hash, 'id' => $id]);
    }

    public function touchLastLogin(int $id): void
    {
        $statement = $this->pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function create(string $username, string $passwordHash, string $role = self::ROLE_ADMIN): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_users (username, password_hash, role, active) VALUES (:username, :hash, :role, 1)'
        );
        $statement->execute(['username' => $username, 'hash' => $passwordHash, 'role' => $role]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateUsernameAndRole(int $id, string $username, string $role): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE admin_users SET username = :username, role = :role WHERE id = :id'
        );
        $statement->execute(['username' => $username, 'role' => $role, 'id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $statement = $this->pdo->prepare('UPDATE admin_users SET active = :active WHERE id = :id');
        $statement->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM admin_users WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function count(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM admin_users')?->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    public function countActiveByRole(string $role): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE role = :role AND active = 1');
        $statement->execute(['role' => $role]);
        $value = $statement->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    public function usernameExists(string $username, ?int $exceptId = null): bool
    {
        if ($exceptId !== null) {
            $statement = $this->pdo->prepare('SELECT 1 FROM admin_users WHERE username = :username AND id <> :id');
            $statement->execute(['username' => $username, 'id' => $exceptId]);
        } else {
            $statement = $this->pdo->prepare('SELECT 1 FROM admin_users WHERE username = :username');
            $statement->execute(['username' => $username]);
        }

        return $statement->fetchColumn() !== false;
    }
}
