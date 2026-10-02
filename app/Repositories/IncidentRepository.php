<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Sicherheitsvorfaelle des Speicher-Tierings (storage_incidents): erkanntes
 * auffaelliges Ueberschreiben, Einschraenkung des Benutzers und
 * schreibgeschuetztes Speicherziel bis zur Erledigung.
 */
final class IncidentRepository extends Repository
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    private const FIELDS = [
        'uid', 'attribution', 'rules', 'summary', 'source', 'files_changed', 'files_suspicious', 'files_extension', 'bytes',
        'first_seen', 'last_seen', 'details', 'user_restricted', 'frozen_target_id', 'frozen_target_label',
    ];

    /**
     * @return list<array<string,mixed>>
     */
    public function open(): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM storage_incidents WHERE status = :status ORDER BY id ASC');
        $statement->execute(['status' => self::STATUS_OPEN]);

        return $statement->fetchAll();
    }

    public function openCount(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM storage_incidents WHERE status = :status');
        $statement->execute(['status' => self::STATUS_OPEN]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Offene zuerst, danach die zuletzt erledigten.
     *
     * @return list<array<string,mixed>>
     */
    public function all(int $limit = 200): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM storage_incidents ORDER BY CASE WHEN status = :status THEN 0 ELSE 1 END, id DESC LIMIT ' . max(1, min(1000, $limit))
        );
        $statement->execute(['status' => self::STATUS_OPEN]);

        return $statement->fetchAll();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM storage_incidents WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $values
     */
    public function create(array $values): int
    {
        $values = array_intersect_key($values, array_flip(self::FIELDS));
        $now = self::now();
        $values += ['status' => self::STATUS_OPEN, 'created_at' => $now, 'updated_at' => $now];
        $values['status'] = self::STATUS_OPEN;
        $columns = array_keys($values);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO storage_incidents (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        ));
        $statement->execute($values);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Aktualisiert einen offenen Vorfall (erledigte bleiben unveraendert).
     *
     * @param array<string,mixed> $values
     */
    public function update(int $id, array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::FIELDS));
        if ($values === []) {
            return;
        }
        $values['updated_at'] = self::now();
        $statement = $this->pdo->prepare(sprintf(
            'UPDATE storage_incidents SET %s WHERE id = :id AND status = :status',
            implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($values)))
        ));
        $statement->execute($values + ['id' => $id, 'status' => self::STATUS_OPEN]);
    }

    /**
     * Schutzziel allen offenen Vorfaellen zuordnen (es gibt hoechstens eines).
     */
    public function assignFrozenTarget(int $targetId, string $label): void
    {
        $label = mb_substr($label, 0, 100);
        $statement = $this->pdo->prepare(
            'UPDATE storage_incidents SET frozen_target_id = :target, frozen_target_label = :label, updated_at = :now
             WHERE status = :status AND (frozen_target_id IS NULL OR frozen_target_id <> :other OR frozen_target_label <> :other_label)'
        );
        $statement->execute([
            'target' => $targetId, 'label' => $label, 'now' => self::now(), 'status' => self::STATUS_OPEN,
            'other' => $targetId, 'other_label' => $label,
        ]);
    }

    /**
     * Zuletzt erledigte Vorfaelle je Benutzer seit $since.
     *
     * @return array<string,string> Benutzer => resolved_at
     */
    public function resolvedSince(string $since): array
    {
        $statement = $this->pdo->prepare(
            'SELECT uid, MAX(resolved_at) AS resolved_at FROM storage_incidents
             WHERE status = :status AND resolved_at >= :since GROUP BY uid'
        );
        $statement->execute(['status' => self::STATUS_RESOLVED, 'since' => $since]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(string) $row['uid']] = (string) $row['resolved_at'];
        }

        return $result;
    }

    /**
     * @return bool false = nicht gefunden oder bereits erledigt
     */
    public function resolve(int $id, string $resolvedBy): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE storage_incidents SET status = :resolved, resolved_at = :resolved_at, resolved_by = :by, updated_at = :updated_at
             WHERE id = :id AND status = :open'
        );
        $statement->execute([
            'resolved' => self::STATUS_RESOLVED,
            'resolved_at' => self::now(),
            'updated_at' => self::now(),
            'by' => mb_substr($resolvedBy, 0, 100),
            'id' => $id,
            'open' => self::STATUS_OPEN,
        ]);

        return $statement->rowCount() === 1;
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
