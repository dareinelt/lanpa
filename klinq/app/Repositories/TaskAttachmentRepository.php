<?php

declare(strict_types=1);

namespace App\Repositories;

/** Aufgabenanhänge: Verbindung zwischen documents (Datei) und Aufgabe. */
final class TaskAttachmentRepository extends BaseRepository
{
    private const SELECT = 'SELECT d.*, ta.task_id
        FROM task_attachments ta
        JOIN documents d ON d.id = ta.document_id';

    /** @return array<int,array<string,mixed>> */
    public function forTask(int $taskId): array
    {
        return $this->fetchAll(self::SELECT . ' WHERE ta.task_id = :t ORDER BY d.created_at, d.id', ['t' => $taskId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $documentId): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE ta.document_id = :d', ['d' => $documentId]);
    }

    public function link(int $documentId, int $taskId): void
    {
        $this->execute('INSERT INTO task_attachments (document_id, task_id) VALUES (:d, :t)', ['d' => $documentId, 't' => $taskId]);
    }

    public function countForTask(int $taskId): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM task_attachments WHERE task_id = :t', ['t' => $taskId]);
    }
}
