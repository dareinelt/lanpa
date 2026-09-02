<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\SyncLogStoreInterface;

final class SyncLogRepository extends Repository implements SyncLogStoreInterface
{
    public function start(): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sync_log (started_at, status) VALUES (NOW(), :status)'
        );
        $statement->execute(['status' => 'running']);

        return (int) $this->pdo->lastInsertId();
    }

    public function finish(int $id, string $status, int $processed, int $deactivated, ?string $message = null): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE sync_log
                SET finished_at = NOW(),
                    status = :status,
                    processed = :processed,
                    deactivated = :deactivated,
                    message = :message
              WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'processed' => $processed,
            'deactivated' => $deactivated,
            'message' => $message === null ? null : mb_substr($message, 0, 1000),
            'id' => $id,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function last(): ?array
    {
        $statement = $this->pdo->query(
            'SELECT id, started_at, finished_at, status, processed, deactivated, message
               FROM sync_log ORDER BY id DESC LIMIT 1'
        );
        $row = $statement === false ? false : $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function lastSuccessful(): ?array
    {
        $statement = $this->pdo->query(
            "SELECT id, started_at, finished_at, status, processed, deactivated, message
               FROM sync_log WHERE status = 'success' ORDER BY id DESC LIMIT 1"
        );
        $row = $statement === false ? false : $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->query(
            'SELECT id, started_at, finished_at, status, processed, deactivated, message
               FROM sync_log ORDER BY id DESC LIMIT ' . $limit
        );

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll();

        return $rows;
    }
}
