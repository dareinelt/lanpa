<?php

declare(strict_types=1);

namespace App\Repositories;

/** Wissensdatenbank-Artikel inkl. Suche und Ticketverknüpfungen. */
final class KnowledgeBaseRepository extends BaseRepository
{
    public const SORTABLE = ['title' => 'a.title', 'updated_at' => 'a.updated_at', 'view_count' => 'a.view_count', 'status' => 'a.status'];

    private const SELECT = 'SELECT a.*, c.name AS category_name, pc.name AS parent_category_name, u.display_name AS updated_by_name, t.number AS source_ticket_number,
            (SELECT GROUP_CONCAT(tg.name ORDER BY tg.name SEPARATOR \',\') FROM knowledge_article_tags kt JOIN ticket_tags tg ON tg.id = kt.tag_id WHERE kt.article_id = a.id) AS tag_names,
            (SELECT COUNT(*) FROM knowledge_article_tickets lt WHERE lt.article_id = a.id) AS linked_ticket_count
        FROM knowledge_articles a
        LEFT JOIN ticket_categories c ON c.id = a.category_id
        LEFT JOIN ticket_categories pc ON pc.id = c.parent_id
        LEFT JOIN users u ON u.id = a.updated_by
        LEFT JOIN tickets t ON t.id = a.source_ticket_id';

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE a.id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE a.slug = :s', ['s' => $slug]);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $params = ['s' => $slug];
        $sql = 'SELECT 1 FROM knowledge_articles WHERE slug = :s';
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return $this->fetchValue($sql, $params) !== null;
    }

    /**
     * @param array<string,mixed> $filters q, category_id, status, tag, published_only (bool), visibility
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, string $sort, string $dir, int $limit, int $offset): array
    {
        [$where, $params] = $this->whereFor($filters);
        $column = self::SORTABLE[$sort] ?? 'a.updated_at';
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        return $this->fetchAll(self::SELECT . " {$where} ORDER BY {$column} {$direction}, a.id DESC LIMIT {$limit} OFFSET {$offset}", $params);
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->whereFor($filters);

        return (int) $this->fetchValue('SELECT COUNT(*) FROM knowledge_articles a LEFT JOIN ticket_categories c ON c.id = a.category_id ' . $where, $params);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function whereFor(array $filters): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['published_only'])) {
            $where[] = "a.status = 'published'";
        }
        if (!empty($filters['status'])) {
            $where[] = 'a.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['visibility'])) {
            $where[] = 'a.visibility = :visibility';
            $params['visibility'] = (string) $filters['visibility'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = '(a.category_id = :cat OR c.parent_id = :cat)';
            $params['cat'] = (int) $filters['category_id'];
        }
        if (!empty($filters['tag'])) {
            $where[] = 'EXISTS (SELECT 1 FROM knowledge_article_tags kt JOIN ticket_tags tg ON tg.id = kt.tag_id WHERE kt.article_id = a.id AND tg.name = :tag)';
            $params['tag'] = (string) $filters['tag'];
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(a.title LIKE :q OR a.summary LIKE :q OR a.body LIKE :q OR EXISTS (SELECT 1 FROM knowledge_article_tags kt JOIN ticket_tags tg ON tg.id = kt.tag_id WHERE kt.article_id = a.id AND tg.name LIKE :q))';
            $params['q'] = $this->like($q);
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }

    /** Vorschläge zu einem Ticket (Betreff-Wörter, Kategorie). @return array<int,array<string,mixed>> */
    public function suggestForTicket(string $subject, ?int $categoryId, ?int $subcategoryId, bool $internalAllowed, int $limit = 5): array
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($subject)) ?: [], static fn (string $w): bool => mb_strlen($w) >= 4));
        $words = array_slice(array_unique($words), 0, 6);
        $where = ["a.status = 'published'"];
        $params = [];
        if (!$internalAllowed) {
            $where[] = "a.visibility = 'public'";
        }
        $or = [];
        foreach ($words as $i => $word) {
            $or[] = "a.title LIKE :w{$i}";
            $params["w{$i}"] = $this->like($word);
        }
        if ($categoryId !== null) {
            $or[] = 'a.category_id = :cat';
            $params['cat'] = $categoryId;
        }
        if ($subcategoryId !== null) {
            $or[] = 'a.category_id = :sub';
            $params['sub'] = $subcategoryId;
        }
        if ($or === []) {
            return [];
        }
        $where[] = '(' . implode(' OR ', $or) . ')';

        return $this->fetchAll(self::SELECT . ' WHERE ' . implode(' AND ', $where) . " ORDER BY a.view_count DESC, a.updated_at DESC LIMIT {$limit}", $params);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('knowledge_articles', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('knowledge_articles', $id, $data);
    }

    public function incrementViews(int $id): void
    {
        $this->execute('UPDATE knowledge_articles SET view_count = view_count + 1 WHERE id = :id', ['id' => $id]);
    }

    // ------------------------------------------------------------------ Verknüpfte Tickets

    /** @return array<int,array<string,mixed>> */
    public function linkedTickets(int $articleId): array
    {
        return $this->fetchAll('SELECT t.id, t.number, t.subject, st.name AS status_name, st.color AS status_color, lt.created_at FROM knowledge_article_tickets lt JOIN tickets t ON t.id = lt.ticket_id JOIN ticket_statuses st ON st.id = t.status_id WHERE lt.article_id = :a ORDER BY lt.created_at DESC', ['a' => $articleId]);
    }

    /** @return array<int,array<string,mixed>> */
    public function articlesForTicket(int $ticketId): array
    {
        return $this->fetchAll(self::SELECT . ' WHERE EXISTS (SELECT 1 FROM knowledge_article_tickets lt WHERE lt.article_id = a.id AND lt.ticket_id = :t) OR a.source_ticket_id = :t ORDER BY a.title', ['t' => $ticketId]);
    }

    public function linkTicket(int $articleId, int $ticketId, ?int $userId): void
    {
        $this->execute('INSERT IGNORE INTO knowledge_article_tickets (article_id, ticket_id, created_by) VALUES (:a, :t, :u)', ['a' => $articleId, 't' => $ticketId, 'u' => $userId]);
    }

    public function unlinkTicket(int $articleId, int $ticketId): int
    {
        return $this->execute('DELETE FROM knowledge_article_tickets WHERE article_id = :a AND ticket_id = :t', ['a' => $articleId, 't' => $ticketId]);
    }

    /** Kennzahlen für die Übersicht. @return array<string,int> */
    public function counts(): array
    {
        $row = $this->fetchOne("SELECT COUNT(*) AS total, SUM(status = 'published') AS published, SUM(status = 'draft') AS draft, SUM(status = 'archived') AS archived FROM knowledge_articles") ?? [];

        return array_map('intval', array_map(static fn ($v) => $v ?? 0, $row));
    }
}
