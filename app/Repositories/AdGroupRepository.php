<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\AdGroupStoreInterface;
use PDO;

final class AdGroupRepository extends Repository implements AdGroupStoreInterface
{
    public const MAX_SUGGESTIONS = 20;

    public function replaceAll(array $groups, string $syncedAt, int $sourceId = 0): int
    {
        $upsert = $this->pdo->prepare(
            'INSERT INTO ad_groups (identity_source_id, dn_hash, dn, name, description, member_count, synced_at, active)
             VALUES (:source, :dn_hash, :dn, :name, :description, :member_count, :synced_at, 1)
             ON DUPLICATE KEY UPDATE dn = VALUES(dn), name = VALUES(name), description = VALUES(description),
                member_count = VALUES(member_count), synced_at = VALUES(synced_at), active = 1'
        );
        $deleteMembers = $this->pdo->prepare('DELETE FROM ad_group_members WHERE group_id = :group_id');
        $userIds = $this->phonebookIdsByExternalId($sourceId);

        // Bekannte Gruppen der Quelle in einem Zug lesen statt je Gruppe
        // einzeln zu suchen (das waren bei 200 Gruppen 200 Rundlaeufe). Nur
        // wirklich neue Gruppen brauchen weiterhin eine Einzelabfrage.
        $knownIds = $this->knownGroupIds($sourceId);
        $findId = $this->pdo->prepare('SELECT id FROM ad_groups WHERE identity_source_id = :source AND dn_hash = :dn_hash');

        $seenIds = [];
        foreach ($groups as $group) {
            $hash = hash('sha256', strtolower($group['dn']));
            $members = [];
            foreach ($group['members'] as $externalId) {
                if (isset($userIds[$externalId])) {
                    $members[$userIds[$externalId]] = true;
                }
            }
            $memberIds = array_keys($members);
            sort($memberIds);

            $upsert->execute([
                'source' => $sourceId,
                'dn_hash' => $hash,
                'dn' => mb_substr($group['dn'], 0, 1024),
                'name' => $group['name'],
                'description' => $group['description'],
                'member_count' => count($memberIds),
                'synced_at' => $syncedAt,
            ]);
            $groupId = $knownIds[$hash] ?? null;
            if ($groupId === null) {
                $findId->execute(['source' => $sourceId, 'dn_hash' => $hash]);
                $groupId = (int) $findId->fetchColumn();
                if ($groupId === 0) {
                    continue;
                }
                $knownIds[$hash] = $groupId;
            }

            // Mitglieder nur anfassen, wenn sich die Menge tatsaechlich
            // aendert. Bei einem unveraenderten Bestand entfaellt damit
            // DELETE + Neuaufbau aller Mitgliedszeilen.
            if ($this->currentMemberIds($groupId) !== $memberIds) {
                $deleteMembers->execute(['group_id' => $groupId]);
                if ($memberIds !== []) {
                    $this->insertMembers($groupId, $memberIds);
                }
            }
            $seenIds[$groupId] = true;
        }

        // Nicht mehr gelieferte Gruppen dieser Quelle deaktivieren und
        // Mitglieder entfernen (Abgleich ueber die IDs dieses Laufs, nicht
        // ueber den Zeitstempel). Andere Quellen bleiben unberuehrt.
        if ($seenIds === []) {
            $this->pdo->prepare('UPDATE ad_groups SET active = 0, member_count = 0 WHERE active = 1 AND identity_source_id = ?')
                ->execute([$sourceId]);
        } else {
            $ids = array_keys($seenIds);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stale = $this->pdo->prepare("UPDATE ad_groups SET active = 0, member_count = 0 WHERE active = 1 AND identity_source_id = ? AND id NOT IN ({$placeholders})");
            $stale->execute(array_merge([$sourceId], $ids));
        }
        $this->pdo->exec('DELETE m FROM ad_group_members m JOIN ad_groups g ON g.id = m.group_id WHERE g.active = 0');

        return count($seenIds);
    }

    public function namesForUser(int $phonebookId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT LOWER(g.name) FROM ad_groups g
               JOIN ad_group_members m ON m.group_id = g.id
              WHERE g.active = 1 AND m.phonebook_id = :id'
        );
        $statement->execute(['id' => $phonebookId]);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    /**
     * Vorschlaege fuer die Rechtevergabe aus dem lokalen Datenbestand:
     * Treffer am Namensanfang zuerst, danach Treffer im Namen oder in der
     * Beschreibung.
     *
     * @return list<array{name:string,description:string,members:int}>
     */
    public function suggest(string $term, int $limit = self::MAX_SUGGESTIONS): array
    {
        $term = trim($term);
        $limit = max(1, min(self::MAX_SUGGESTIONS, $limit));
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $prefix = addcslashes($term, '%_\\') . '%';

        $statement = $this->pdo->prepare(
            'SELECT name, COALESCE(description, \'\') AS description, member_count
               FROM ad_groups
              WHERE active = 1 AND (:empty = 1 OR name LIKE :like OR description LIKE :like2)
              ORDER BY CASE WHEN name LIKE :prefix THEN 0 ELSE 1 END, name ASC
              LIMIT ' . $limit
        );
        $statement->execute([
            'empty' => $term === '' ? 1 : 0,
            'like' => $like,
            'like2' => $like,
            'prefix' => $prefix,
        ]);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
                'members' => (int) $row['member_count'],
            ];
        }

        return $items;
    }

    public function countActive(): int
    {
        return (int) ($this->pdo->query('SELECT COUNT(*) FROM ad_groups WHERE active = 1')?->fetchColumn() ?: 0);
    }

    /**
     * IDs der bereits bekannten Gruppen einer Quelle, Schluessel dn_hash.
     *
     * @return array<string,int>
     */
    private function knownGroupIds(int $sourceId): array
    {
        $statement = $this->pdo->prepare('SELECT id, dn_hash FROM ad_groups WHERE identity_source_id = :source');
        $statement->execute(['source' => $sourceId]);
        $ids = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ids[(string) $row['dn_hash']] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * Aktuell gespeicherte Mitglieder einer Gruppe, aufsteigend sortiert -
     * Vergleichsbasis fuer {@see replaceAll()}.
     *
     * @return list<int>
     */
    private function currentMemberIds(int $groupId): array
    {
        $statement = $this->pdo->prepare('SELECT phonebook_id FROM ad_group_members WHERE group_id = :group_id');
        $statement->execute(['group_id' => $groupId]);
        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
        sort($ids);

        return $ids;
    }

    /**
     * @return array<string,int>
     */
    private function phonebookIdsByExternalId(int $sourceId): array
    {
        $map = [];
        $statement = $this->pdo->prepare('SELECT id, external_id FROM phonebook WHERE active = 1 AND identity_source_id = :source');
        $statement->execute(['source' => $sourceId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(string) $row['external_id']] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * @param list<int> $userIds
     */
    private function insertMembers(int $groupId, array $userIds): void
    {
        foreach (array_chunk($userIds, 500) as $chunk) {
            $placeholders = [];
            $bindings = [];
            foreach ($chunk as $index => $userId) {
                $placeholders[] = '(:g' . $index . ', :u' . $index . ')';
                $bindings['g' . $index] = $groupId;
                $bindings['u' . $index] = $userId;
            }
            $this->pdo->prepare('INSERT IGNORE INTO ad_group_members (group_id, phonebook_id) VALUES ' . implode(', ', $placeholders))
                ->execute($bindings);
        }
    }
}
