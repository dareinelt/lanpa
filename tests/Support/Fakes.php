<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\AdminUserStoreInterface;
use App\Contracts\LdapClientInterface;
use App\Contracts\PhonebookStoreInterface;
use App\Contracts\SyncLogStoreInterface;
use RuntimeException;

/**
 * Testdoppel fuer AD-Zugriffe, Telefonbuchspeicher, Synchronisationsprotokoll
 * und Administrationskonten – dadurch laufen die Tests ohne Datenbank und AD.
 */
final class FakeLdapClient implements LdapClientInterface
{
    /**
     * @param list<array<string,string|null>> $users
     */
    public function __construct(
        private readonly array $users = [],
        private readonly bool $shouldFail = false
    ) {
    }

    public function fetchUsers(): array
    {
        if ($this->shouldFail) {
            throw new RuntimeException('AD nicht erreichbar');
        }

        return $this->users;
    }

    public function testConnection(): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException('AD nicht erreichbar');
        }
    }
}

final class FakePhonebookStore implements PhonebookStoreInterface
{
    /** @var list<array<string,mixed>> */
    public array $upserted = [];

    public int $deactivateCalls = 0;

    public int $commits = 0;

    public int $rollbacks = 0;

    public bool $failOnUpsert = false;

    public function beginTransaction(): void
    {
    }

    public function commit(): void
    {
        $this->commits++;
    }

    public function rollBack(): void
    {
        $this->rollbacks++;
    }

    public function upsert(array $user, string $syncedAt): void
    {
        if ($this->failOnUpsert) {
            throw new RuntimeException('Schreibfehler');
        }

        $this->upserted[] = $user;
    }

    public function deactivateStale(string $syncedAt): int
    {
        $this->deactivateCalls++;

        return 2;
    }

    public function countActive(): int
    {
        return count($this->upserted);
    }
}

final class FakeSyncLog implements SyncLogStoreInterface
{
    /** @var list<array{status:string,processed:int,deactivated:int,message:?string}> */
    public array $entries = [];

    public function start(): int
    {
        return 1;
    }

    public function finish(int $id, string $status, int $processed, int $deactivated, ?string $message = null): void
    {
        $this->entries[] = [
            'status' => $status,
            'processed' => $processed,
            'deactivated' => $deactivated,
            'message' => $message,
        ];
    }
}

final class FakeAdminUserStore implements AdminUserStoreInterface
{
    public int $rehashes = 0;

    public int $logins = 0;

    /**
     * @param array<string,mixed>|null $user
     */
    public function __construct(private ?array $user = null)
    {
    }

    public function findActiveByUsername(string $username): ?array
    {
        if ($this->user === null || $this->user['username'] !== $username) {
            return null;
        }

        return $this->user;
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->rehashes++;
        if ($this->user !== null) {
            $this->user['password_hash'] = $hash;
        }
    }

    public function touchLastLogin(int $id): void
    {
        $this->logins++;
    }
}
