<?php

declare(strict_types=1);

namespace App\Repositories;

/** Dateianhänge (Fotos, Lieferscheine, Rechnungen …) – Dateien liegen außerhalb von public/. */
final class DocumentRepository extends BaseRepository
{
    private const SELECT = 'SELECT d.*, u.display_name AS uploaded_by_display FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by';

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE d.id = :id', ['id' => $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function forEntity(string $entityType, int $entityId): array
    {
        return $this->fetchAll(self::SELECT . ' WHERE d.entity_type = :type AND d.entity_id = :id ORDER BY d.created_at ASC, d.id ASC', ['type' => $entityType, 'id' => $entityId]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('documents', $data);
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM documents WHERE id = ?', [$id]);
    }
}
