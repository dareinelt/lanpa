<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\AdminUserStoreInterface;

final class AdminUserRepository extends Repository implements AdminUserStoreInterface
{
    /**
     * @return array<string,mixed>|null
     */
    public function findActiveByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, password_hash, active FROM admin_users WHERE username = :username AND active = 1'
        );
        $statement->execute(['username' => $username]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
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

    public function create(string $username, string $passwordHash): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_users (username, password_hash, active) VALUES (:username, :hash, 1)'
        );
        $statement->execute(['username' => $username, 'hash' => $passwordHash]);

        return (int) $this->pdo->lastInsertId();
    }

    public function count(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM admin_users')?->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    public function usernameExists(string $username): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM admin_users WHERE username = :username');
        $statement->execute(['username' => $username]);

        return $statement->fetchColumn() !== false;
    }
}
