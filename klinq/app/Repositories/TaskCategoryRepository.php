<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Katalog der Aufgaben-Kategorien (Pill vor dem Aufgabentitel). Der Katalog ist flach und unabhängig
 * von den hierarchischen Ticket-Kategorien; Aufgaben referenzieren höchstens eine Kategorie.
 */
final class TaskCategoryRepository extends BaseRepository
{
    /**
     * Alle Kategorien in Anzeigereihenfolge.
     * @return list<array<string,mixed>>
     */
    public function all(bool $activeOnly = true): array
    {
        return $this->fetchAll(
            'SELECT * FROM task_categories' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name'
        );
    }

    /**
     * Auswahl für Formulare: aktive Kategorien plus die bereits gesetzte, auch wenn sie inaktiv ist.
     * @return list<array<string,mixed>>
     */
    public function selectable(?int $includeId = null): array
    {
        if ($includeId === null || $includeId <= 0) {
            return $this->all();
        }

        return $this->fetchAll(
            'SELECT * FROM task_categories WHERE is_active = 1 OR id = :id ORDER BY sort_order, name',
            ['id' => $includeId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM task_categories WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        return $this->fetchOne('SELECT * FROM task_categories WHERE name = :n', ['n' => $name]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('task_categories', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): int
    {
        return $this->updateRow('task_categories', $id, $data);
    }

    /**
     * Verwendungszähler je Kategorie (Anzahl Aufgaben) für die Administration.
     * @return array<int,int>
     */
    public function usageCounts(): array
    {
        $counts = [];
        foreach ($this->fetchAll('SELECT category_id, COUNT(*) AS usage_count FROM tasks WHERE category_id IS NOT NULL GROUP BY category_id') as $row) {
            $counts[(int) $row['category_id']] = (int) $row['usage_count'];
        }

        return $counts;
    }
}
