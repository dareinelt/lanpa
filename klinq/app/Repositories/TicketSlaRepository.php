<?php

declare(strict_types=1);

namespace App\Repositories;

/** SLA-Regeln (Reaktions-/Lösungszeit je Priorität und/oder Kategorie). */
final class TicketSlaRepository extends BaseRepository
{
    private const SELECT = 'SELECT s.*, p.name AS priority_name, p.code AS priority_code, c.name AS category_name, g.name AS escalation_group_name,
            (SELECT COUNT(*) FROM tickets t WHERE t.sla_id = s.id) AS ticket_count
        FROM ticket_slas s
        LEFT JOIN ticket_priorities p ON p.id = s.priority_id
        LEFT JOIN ticket_categories c ON c.id = s.category_id
        LEFT JOIN ticket_groups g ON g.id = s.escalation_group_id';

    /** @return array<int,array<string,mixed>> */
    public function all(bool $activeOnly = false): array
    {
        return $this->fetchAll(self::SELECT . ($activeOnly ? ' WHERE s.is_active = 1' : '') . ' ORDER BY s.sort_order, s.name');
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE s.id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE s.name = :n', ['n' => $name]);
    }

    /**
     * Passende SLA: spezifischste Regel gewinnt (Priorität+Kategorie > Kategorie > Priorität > Standard).
     * Kategorie wird sowohl als Haupt- als auch Unterkategorie geprüft.
     * @return array<string,mixed>|null
     */
    public function match(?int $priorityId, ?int $categoryId, ?int $subcategoryId): ?array
    {
        return $this->fetchOne(
            self::SELECT . ' WHERE s.is_active = 1
                AND (s.priority_id IS NULL OR s.priority_id = :p)
                AND (s.category_id IS NULL OR s.category_id = :c OR s.category_id = :sc)
             ORDER BY (s.priority_id IS NOT NULL) + (s.category_id IS NOT NULL) * 2 DESC, (s.category_id = :sc) DESC, s.is_default DESC, s.sort_order
             LIMIT 1',
            ['p' => $priorityId ?? 0, 'c' => $categoryId ?? 0, 'sc' => $subcategoryId ?? 0]
        );
    }

    /** @return array<string,mixed>|null */
    public function default(): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE s.is_active = 1 ORDER BY s.is_default DESC, s.sort_order LIMIT 1');
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('ticket_slas', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('ticket_slas', $id, $data);
    }

    public function clearDefault(): void
    {
        $this->execute('UPDATE ticket_slas SET is_default = 0');
    }
}
