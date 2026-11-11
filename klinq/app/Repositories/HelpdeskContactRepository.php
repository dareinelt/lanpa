<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Gemeinsames Kontaktverzeichnis des Help Desk (`helpdesk_contacts`, Stichworte in `helpdesk_contact_tags`).
 * Sortierung immer alphabetisch nach Firma, dann Name und Vorname.
 */
final class HelpdeskContactRepository extends BaseRepository
{
    private const SELECT = 'SELECT c.* FROM helpdesk_contacts c';

    private const ORDER = ' ORDER BY c.company, c.last_name, c.first_name, c.id';

    /**
     * Alle Kontakte mit Stichworten (Grundlage der Ähnlichkeitssuche im Service).
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->withTags($this->fetchAll(self::SELECT . self::ORDER));
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->fetchOne(self::SELECT . ' WHERE c.id = :id', ['id' => $id]);

        return $row === null ? null : $this->withTags([$row])[0];
    }

    /** Alle vergebenen Stichworte mit Anzahl der Kontakte. @return list<array{tag:string,count:int}> */
    public function tags(): array
    {
        return array_map(
            static fn (array $r): array => ['tag' => (string) $r['tag'], 'count' => (int) $r['cnt']],
            $this->fetchAll('SELECT tag, COUNT(*) AS cnt FROM helpdesk_contact_tags GROUP BY tag ORDER BY tag')
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('helpdesk_contacts', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): int
    {
        return $this->updateRow('helpdesk_contacts', $id, $data);
    }

    /**
     * Ersetzt die Stichworte eines Kontakts. INSERT IGNORE, weil die Kollation Schreibvarianten
     * („VPN“/„vpn“, „Büro“/„Buro“) als gleich ansieht.
     * @param list<string> $tags
     */
    public function replaceTags(int $contactId, array $tags): void
    {
        $this->execute('DELETE FROM helpdesk_contact_tags WHERE contact_id = :c', ['c' => $contactId]);
        foreach ($tags as $tag) {
            $this->execute('INSERT IGNORE INTO helpdesk_contact_tags (contact_id, tag) VALUES (:c, :t)', ['c' => $contactId, 't' => $tag]);
        }
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM helpdesk_contacts WHERE id = :id', ['id' => $id]);
    }

    /**
     * Ergänzt `tags` (alphabetisch) in einem Zugriff für alle Zeilen. Bewusst ohne GROUP_CONCAT,
     * dessen Standardlänge (group_concat_max_len) lange Stichwortlisten still abschneiden würde.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function withTags(array $rows): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['id'], $rows)));
        if ($ids === []) {
            return $rows;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $byContact = [];
        foreach ($this->fetchAll("SELECT contact_id, tag FROM helpdesk_contact_tags WHERE contact_id IN ({$placeholders}) ORDER BY tag", $ids) as $t) {
            $byContact[(int) $t['contact_id']][] = (string) $t['tag'];
        }
        foreach ($rows as $i => $row) {
            $rows[$i]['tags'] = $byContact[(int) $row['id']] ?? [];
        }

        return $rows;
    }
}
