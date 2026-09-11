<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Zugriff auf Administrationskonten. Als Schnittstelle definiert, damit die
 * Authentifizierung ohne Datenbank getestet werden kann.
 */
interface AdminUserStoreInterface
{
    /**
     * @return array<string,mixed>|null enthaelt u. a. die Schluessel id, username, password_hash, role, active
     */
    public function findActiveByUsername(string $username): ?array;

    public function updatePasswordHash(int $id, string $hash): void;

    public function touchLastLogin(int $id): void;
}
