<?php

declare(strict_types=1);

namespace App\Repositories;

/** Frei definierbare Tags für Tickets und Wissensartikel. */
final class TicketTagRepository extends BaseRepository
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->fetchAll(
            'SELECT tg.*, (SELECT COUNT(*) FROM ticket_tag_relations r WHERE r.tag_id = tg.id) AS ticket_count,
                    (SELECT COUNT(*) FROM knowledge_article_tags k WHERE k.tag_id = tg.id) AS article_count
             FROM ticket_tags tg ORDER BY tg.name'
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_tags WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_tags WHERE name = :n', ['n' => $name]);
    }

    /** @return array<int,array<string,mixed>> */
    public function suggest(string $term, int $limit = 10): array
    {
        return $this->fetchAll("SELECT id, name, color FROM ticket_tags WHERE name LIKE :q ORDER BY name LIMIT {$limit}", ['q' => $this->like($term)]);
    }

    /** Legt das Tag an, falls es nicht existiert, und liefert die ID. */
    public function ensure(string $name, string $color = 'neutral'): int
    {
        $existing = $this->findByName($name);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->insertRow('ticket_tags', ['name' => $name, 'color' => $color]);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('ticket_tags', $id, $data);
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM ticket_tags WHERE id = :id', ['id' => $id]);
    }

    // ------------------------------------------------------------------ Ticketzuordnung

    /** @return array<int,array<string,mixed>> */
    public function forTicket(int $ticketId): array
    {
        return $this->fetchAll('SELECT tg.* FROM ticket_tag_relations r JOIN ticket_tags tg ON tg.id = r.tag_id WHERE r.ticket_id = :t ORDER BY tg.name', ['t' => $ticketId]);
    }

    /** Tagnamen mehrerer Tickets in einem Zugriff (vermeidet N+1). @return array<int,array<int,string>> ticket_id → Namen */
    public function namesForTickets(array $ticketIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $ticketIds)));
        if ($ids === []) {
            return [];
        }
        $rows = $this->fetchAll('SELECT r.ticket_id, tg.name FROM ticket_tag_relations r JOIN ticket_tags tg ON tg.id = r.tag_id WHERE r.ticket_id IN (' . implode(',', $ids) . ') ORDER BY tg.name');
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['ticket_id']][] = (string) $row['name'];
        }

        return $out;
    }

    public function attach(int $ticketId, int $tagId): void
    {
        $this->execute('INSERT IGNORE INTO ticket_tag_relations (ticket_id, tag_id) VALUES (:t, :g)', ['t' => $ticketId, 'g' => $tagId]);
    }

    public function detach(int $ticketId, int $tagId): int
    {
        return $this->execute('DELETE FROM ticket_tag_relations WHERE ticket_id = :t AND tag_id = :g', ['t' => $ticketId, 'g' => $tagId]);
    }

    public function detachAll(int $ticketId): void
    {
        $this->execute('DELETE FROM ticket_tag_relations WHERE ticket_id = :t', ['t' => $ticketId]);
    }

    public function copyToTicket(int $fromTicketId, int $toTicketId): void
    {
        $this->execute('INSERT IGNORE INTO ticket_tag_relations (ticket_id, tag_id) SELECT :to, tag_id FROM ticket_tag_relations WHERE ticket_id = :from', ['to' => $toTicketId, 'from' => $fromTicketId]);
    }

    // ------------------------------------------------------------------ Wissensartikel

    /** @return array<int,array<string,mixed>> */
    public function forArticle(int $articleId): array
    {
        return $this->fetchAll('SELECT tg.* FROM knowledge_article_tags k JOIN ticket_tags tg ON tg.id = k.tag_id WHERE k.article_id = :a ORDER BY tg.name', ['a' => $articleId]);
    }

    public function setArticleTags(int $articleId, array $tagIds): void
    {
        $this->execute('DELETE FROM knowledge_article_tags WHERE article_id = :a', ['a' => $articleId]);
        foreach (array_unique(array_map('intval', $tagIds)) as $tagId) {
            $this->execute('INSERT IGNORE INTO knowledge_article_tags (article_id, tag_id) VALUES (:a, :g)', ['a' => $articleId, 'g' => $tagId]);
        }
    }
}
