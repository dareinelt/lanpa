<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Kommentarverlauf einer Aufgabe. Einträge sind unveränderlich und werden chronologisch in der
 * Reihenfolge des Eintragens geliefert.
 */
final class TaskCommentRepository extends BaseRepository
{
    /** @return array<int,array<string,mixed>> */
    public function forTask(int $taskId): array
    {
        return $this->fetchAll(
            'SELECT * FROM task_comments
             WHERE task_id = :t
             ORDER BY created_at ASC, id ASC',
            ['t' => $taskId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM task_comments WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('task_comments', $data);
    }
}
