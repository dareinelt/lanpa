<?php

declare(strict_types=1);

namespace App\Repositories;

final class ImportantLinkRepository extends Repository
{
    private const COLUMNS = 'id, title, url, icon_file, icon_mime, active, created_at, updated_at';

    /**
     * Aktive Links, immer alphabetisch nach Titel sortiert.
     *
     * @return list<array<string,mixed>>
     */
    public function allActive(): array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ' FROM important_links WHERE active = 1 ORDER BY title ASC, id ASC'
        );

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll();

        return $rows;
    }

    /**
     * Alle Links fuer den Adminbereich. Eine manuelle Sortierung wird bewusst
     * nicht angeboten, daher immer alphabetisch nach Titel.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ' FROM important_links ORDER BY title ASC, id ASC'
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
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM important_links WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO important_links (title, url, icon_file, icon_mime, active)
             VALUES (:title, :url, :icon_file, :icon_mime, :active)'
        );
        $statement->execute($this->bindings($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE important_links
                SET title = :title,
                    url = :url,
                    icon_file = :icon_file,
                    icon_mime = :icon_mime,
                    active = :active
              WHERE id = :id'
        );
        $statement->execute($this->bindings($data) + ['id' => $id]);
    }

    /**
     * Aktualisiert ausschliesslich das automatisch ermittelte Favicon.
     */
    public function updateIcon(int $id, ?string $iconFile, ?string $iconMime): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE important_links SET icon_file = :icon_file, icon_mime = :icon_mime WHERE id = :id'
        );
        $statement->execute(['icon_file' => $iconFile, 'icon_mime' => $iconMime, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM important_links WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $statement = $this->pdo->prepare('UPDATE important_links SET active = :active WHERE id = :id');
        $statement->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    public function count(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM important_links')?->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
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
            'url' => (string) $data['url'],
            'icon_file' => $data['icon_file'] === null || $data['icon_file'] === '' ? null : (string) $data['icon_file'],
            'icon_mime' => $data['icon_mime'] === null || $data['icon_mime'] === '' ? null : (string) $data['icon_mime'],
            'active' => !empty($data['active']) ? 1 : 0,
        ];
    }
}
