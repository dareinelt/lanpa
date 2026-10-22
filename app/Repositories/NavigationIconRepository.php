<?php

declare(strict_types=1);

namespace App\Repositories;

final class NavigationIconRepository extends Repository
{
    private const COLUMNS = 'id, alias, file_name, mime_type, created_at, updated_at';

    /**
     * Alle Katalog-Icons, alphabetisch nach Alias.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ' FROM navigation_icons ORDER BY alias ASC, id ASC'
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
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM navigation_icons WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByAlias(string $alias): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM navigation_icons WHERE alias = :alias');
        $statement->execute(['alias' => $alias]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function create(string $alias, string $fileName, string $mimeType): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO navigation_icons (alias, file_name, mime_type)
             VALUES (:alias, :file_name, :mime_type)'
        );
        $statement->execute(['alias' => $alias, 'file_name' => $fileName, 'mime_type' => $mimeType]);

        return (int) $this->pdo->lastInsertId();
    }

    public function rename(int $id, string $alias): void
    {
        $statement = $this->pdo->prepare('UPDATE navigation_icons SET alias = :alias WHERE id = :id');
        $statement->execute(['id' => $id, 'alias' => $alias]);
    }

    public function updateFile(int $id, string $fileName, string $mimeType): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE navigation_icons SET file_name = :file_name, mime_type = :mime_type WHERE id = :id'
        );
        $statement->execute(['id' => $id, 'file_name' => $fileName, 'mime_type' => $mimeType]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM navigation_icons WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * Anzahl der Navigationselemente, die den Alias noch verwenden.
     */
    public function usageCount(string $alias): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM navigation_items WHERE icon = :alias');
        $statement->execute(['alias' => $alias]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Ersetzt einen alten Alias in allen Navigationselementen.
     */
    public function replaceAlias(string $oldAlias, string $newAlias): void
    {
        $statement = $this->pdo->prepare('UPDATE navigation_items SET icon = :new WHERE icon = :old');
        $statement->execute(['new' => $newAlias, 'old' => $oldAlias]);
    }
}
