<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\PhonebookRepository;
use App\Support\Dates;

final class PhonebookService
{
    public const DEFAULT_LIMIT = 25;

    /** @var array<int,string>|null */
    private ?array $sourceLabels = null;

    public function __construct(
        private readonly PhonebookRepository $repository,
        private readonly ?IdentitySourceService $sources = null,
    ) {
    }

    /**
     * Beschriftung der Identitaetsquelle eines Eintrags; leer, solange nur
     * die Hauptquelle existiert (dann ist keine Unterscheidung noetig).
     *
     * @param array<string,mixed> $row
     */
    private function sourceLabel(array $row): string
    {
        if ($this->sourceLabels === null) {
            $this->sourceLabels = $this->sources !== null && $this->sources->hasAdditionalSources()
                ? $this->sources->labels()
                : [];
        }

        if ($this->sourceLabels === []) {
            return '';
        }

        return $this->sourceLabels[(int) ($row['identity_source_id'] ?? 0)] ?? '';
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int,has_more:bool}
     */
    public function search(string $term, int $limit = self::DEFAULT_LIMIT, int $offset = 0, bool $includeWithoutEmail = false): array
    {
        $limit = max(1, min(PhonebookRepository::MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $result = $this->repository->search($term, $limit, $offset, $includeWithoutEmail);

        $items = array_map(
            fn (array $row): array => [
                'id' => (int) $row['id'],
                'display_name' => (string) ($row['display_name'] ?? ''),
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'mobile' => (string) ($row['mobile'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'source' => $this->sourceLabel($row),
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

    public function countVisible(bool $includeWithoutEmail = false): int
    {
        return $this->repository->countVisible($includeWithoutEmail);
    }

    public function lastSyncedAt(): ?string
    {
        return $this->repository->lastSyncedAt();
    }

    /**
     * Alle Eintraege fuer den Adminbereich (aktiv/inaktiv, ein-/ausgeblendet).
     *
     * @param array{has_email?:bool,is_active?:bool,has_phone?:bool,is_visible?:bool} $filters
     *
     * @return list<array<string,mixed>>
     */
    public function allEntries(string $term = '', array $filters = []): array
    {
        $rows = $this->repository->allForAdmin($term, $filters);

        return array_map(
            fn (array $row): array => [
                'id' => (int) $row['id'],
                'display_name' => (string) ($row['display_name'] ?? ''),
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'mobile' => (string) ($row['mobile'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'source' => $this->sourceLabel($row),
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
