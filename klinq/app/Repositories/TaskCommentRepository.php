<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Kommentarverlauf einer Aufgabe. Einträge sind inhaltlich unveränderlich; lediglich die Anheftung
 * (`is_pinned`) lässt sich setzen und lösen. Angeheftete Einträge stehen oben, untereinander und zu den
 * übrigen weiterhin chronologisch in der Reihenfolge des Eintragens.
 */
final class TaskCommentRepository extends BaseRepository
{
    /** @return array<int,array<string,mixed>> */
    public function forTask(int $taskId): array
    {
        return $this->fetchAll(
            'SELECT * FROM task_comments
             WHERE task_id = :t
             ORDER BY is_pinned DESC, created_at ASC, id ASC',
            ['t' => $taskId]
        );
    }

    /** Heftet einen Kommentar an bzw. löst die Anheftung. */
    public function setPinned(int $id, bool $pinned): int
    {
        return $this->execute(
            'UPDATE task_comments SET is_pinned = :p, pinned_at = :at WHERE id = :id',
            ['p' => $pinned ? 1 : 0, 'at' => $pinned ? gmdate('Y-m-d H:i:s') : null, 'id' => $id]
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
