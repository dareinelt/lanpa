<?php

declare(strict_types=1);

namespace App\Repositories;

final class AnnouncementRepository extends Repository
{
    private const COLUMNS = 'id, title, message, active, created_at, updated_at';

    /**
     * Die aktuell aktive Mitteilung (es kann immer nur eine geben).
     *
     * @return array<string,mixed>|null
     */
    public function findActive(): ?array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ' FROM announcements WHERE active = 1 ORDER BY id DESC LIMIT 1'
        );
        $row = $statement === false ? null : $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Alle Mitteilungen fuer den Adminbereich, neueste zuerst.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ' FROM announcements ORDER BY created_at DESC, id DESC'
        );

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll();

        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM announcements WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        if (!empty($data['active'])) {
            $this->deactivateAll();
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO announcements (title, message, active) VALUES (:title, :message, :active)'
        );
        $statement->execute($this->bindings($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): void
    {
        if (!empty($data['active'])) {
            $this->deactivateAll();
        }

        $statement = $this->pdo->prepare(
            'UPDATE announcements SET title = :title, message = :message, active = :active WHERE id = :id'
        );
        $statement->execute($this->bindings($data) + ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM announcements WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * Aktiviert eine Mitteilung (und deaktiviert alle anderen), oder
     * archiviert sie.
     */
    public function setActive(int $id, bool $active): void
    {
        if ($active) {
            $this->deactivateAll();
        }

        $statement = $this->pdo->prepare('UPDATE announcements SET active = :active WHERE id = :id');
        $statement->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    private function deactivateAll(): void
    {
        $this->pdo->exec('UPDATE announcements SET active = 0 WHERE active = 1');
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function bindings(array $data): array
    {
        return [
            'title' => (string) $data['title'],
            'message' => (string) $data['message'],
            'active' => !empty($data['active']) ? 1 : 0,
        ];
    }
}
