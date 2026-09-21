<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\PhonebookRepository;
use App\Support\Dates;

final class PhonebookService
{
    public const DEFAULT_LIMIT = 25;

    public function __construct(private readonly PhonebookRepository $repository)
    {
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int,has_more:bool}
     */
    public function search(string $term, int $limit = self::DEFAULT_LIMIT, int $offset = 0, bool $includeWithoutPhone = false): array
    {
        $limit = max(1, min(PhonebookRepository::MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $result = $this->repository->search($term, $limit, $offset, $includeWithoutPhone);

        $items = array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'display_name' => (string) ($row['display_name'] ?? ''),
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'mobile' => (string) ($row['mobile'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'modified' => Dates::formatDate(is_string($row['ad_modified'] ?? null) ? $row['ad_modified'] : null),
            ],
            $result['items']
        );

        return [
            'items' => $items,
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $result['total'],
        ];
    }

    public function countActive(): int
    {
        return $this->repository->countActive();
    }

    public function countVisible(bool $includeWithoutPhone = false): int
    {
        return $this->repository->countVisible($includeWithoutPhone);
    }

    public function lastSyncedAt(): ?string
    {
        return $this->repository->lastSyncedAt();
    }

    /**
     * Alle Eintraege fuer den Adminbereich (aktiv/inaktiv, ein-/ausgeblendet).
     *
     * @return list<array<string,mixed>>
     */
    public function allEntries(string $term = ''): array
    {
        $rows = $this->repository->allForAdmin($term);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'display_name' => (string) ($row['display_name'] ?? ''),
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'mobile' => (string) ($row['mobile'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'active' => (int) ($row['active'] ?? 0) === 1,
                'visible' => (int) ($row['visible'] ?? 1) === 1,
                'synced_at' => is_string($row['synced_at'] ?? null) ? $row['synced_at'] : null,
            ],
            $rows
        );
    }

    public function setVisible(int $id, bool $visible): void
    {
        if (!$this->repository->setVisible($id, $visible)) {
            throw new ValidationException(['id' => 'Eintrag nicht gefunden.']);
        }
    }
}
