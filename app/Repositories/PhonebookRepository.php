<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\PhonebookStoreInterface;
use App\Support\Validator;

final class PhonebookRepository extends Repository implements PhonebookStoreInterface
{
    public const MAX_LIMIT = 100;

    /**
     * Sucht im lokalen Datenbestand (nicht im AD).
     *
     * Es werden bewusst Praefix-Suchen (`term%`) verwendet, damit die Indizes
     * greifen; nur die Telefonnummer wird als Teilstring gesucht.
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function search(string $term, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        [$where, $params] = $this->buildSearchCondition($term);

        $countStatement = $this->pdo->prepare('SELECT COUNT(*) FROM phonebook WHERE ' . $where);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $sql = 'SELECT id, display_name, first_name, last_name, phone, mobile, email, department, ad_modified, synced_at
                  FROM phonebook
                 WHERE ' . $where . '
                 ORDER BY last_name ASC, first_name ASC, display_name ASC
                 LIMIT ' . $limit . ' OFFSET ' . $offset;

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string,mixed>> $items */
        $items = $statement->fetchAll();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    public function buildSearchCondition(string $term): array
    {
        $conditions = ['active = 1'];
        $params = [];

        $tokens = preg_split('/\s+/u', trim($term)) ?: [];
        $tokens = array_values(array_filter(array_slice($tokens, 0, 5), static fn (string $t): bool => $t !== ''));

        foreach ($tokens as $index => $token) {
            $token = mb_substr($token, 0, 64);
            $prefix = $this->escapeLike($token) . '%';
            $digits = Validator::normalizePhone($token);

            $parts = [];
            // Jeder Platzhalter darf nur einmal vorkommen (native Prepared Statements).
            foreach (['display_name', 'first_name', 'last_name', 'department'] as $column => $field) {
                $name = 't' . $index . '_' . $column;
                $parts[] = $field . ' LIKE :' . $name;
                $params[$name] = $prefix;
            }

            if ($digits !== '') {
                $parts[] = 'phone_digits LIKE :d' . $index;
                $params['d' . $index] = '%' . $this->escapeLike($digits) . '%';
            }

            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }

        return [implode(' AND ', $conditions), $params];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    public function countActive(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM phonebook WHERE active = 1')?->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    public function lastSyncedAt(): ?string
    {
        $value = $this->pdo->query('SELECT MAX(synced_at) FROM phonebook WHERE active = 1')?->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * @param array<string,string|null> $user
     */
    public function upsert(array $user, string $syncedAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO phonebook
                (external_id, display_name, first_name, last_name, phone, phone_digits, mobile, email, department, ad_modified, synced_at, active)
             VALUES
                (:external_id, :display_name, :first_name, :last_name, :phone, :phone_digits, :mobile, :email, :department, :ad_modified, :synced_at, 1)
             ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                phone = VALUES(phone),
                phone_digits = VALUES(phone_digits),
                mobile = VALUES(mobile),
                email = VALUES(email),
                department = VALUES(department),
                ad_modified = VALUES(ad_modified),
                synced_at = VALUES(synced_at),
                active = 1'
        );

        $statement->execute([
            'external_id' => (string) $user['external_id'],
            'display_name' => (string) ($user['display_name'] ?? ''),
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'phone' => $user['phone'] ?? null,
            'phone_digits' => Validator::normalizePhone($user['phone'] ?? null),
            'mobile' => $user['mobile'] ?? null,
            'email' => $user['email'] ?? null,
            'department' => $user['department'] ?? null,
            'ad_modified' => $user['ad_modified'] ?? null,
            'synced_at' => $syncedAt,
        ]);
    }

    public function deactivateStale(string $syncedAt): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE phonebook SET active = 0 WHERE active = 1 AND (synced_at IS NULL OR synced_at < :synced_at)'
        );
        $statement->execute(['synced_at' => $syncedAt]);

        return $statement->rowCount();
    }
}
