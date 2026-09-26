<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\IdentitySourceStoreInterface;

/**
 * Weitere Identitaetsquellen (Tabelle `identity_sources`). Die Hauptquelle
 * (ID 0) wird ueber die Einstellungen gepflegt.
 */
final class IdentitySourceRepository extends Repository implements IdentitySourceStoreInterface
{
    /** Schreibbare Spalten (ohne id/Zeitstempel). */
    private const FIELDS = [
        'source_key', 'label', 'hosts', 'port', 'use_tls', 'verify_cert', 'timeout', 'base_dn', 'bind_dn',
        'bind_password', 'user_filter', 'group_base_dn', 'group_filter', 'group_name_attribute', 'attributes',
        'sso_enabled', 'sso_domain', 'sso_dcs', 'sso_join_user', 'sso_join_password', 'sso_networks',
        'sso_hostnames', 'sort_order', 'active',
    ];

    private const BOOLEAN_FIELDS = ['use_tls', 'verify_cert', 'sso_enabled', 'active'];

    private const INT_FIELDS = ['port', 'timeout', 'sort_order'];

    /**
     * Felder, die beim Speichern nur ueberschrieben werden, wenn sie in den
     * Daten enthalten sind (verschluesselte Passwoerter).
     */
    private const OPTIONAL_FIELDS = ['bind_password', 'sso_join_password'];

    private static function columns(): string
    {
        return 'id, ' . implode(', ', self::FIELDS) . ', created_at, updated_at';
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::columns() . ' FROM identity_sources'
            . ($activeOnly ? ' WHERE active = 1' : '')
            . ' ORDER BY sort_order ASC, label ASC, id ASC';
        $statement = $this->pdo->query($sql);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll();

        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::columns() . ' FROM identity_sources WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findActiveByKey(string $key): ?array
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT ' . self::columns() . ' FROM identity_sources WHERE active = 1 AND UPPER(source_key) = UPPER(:source_key) LIMIT 1'
        );
        $statement->execute(['source_key' => $key]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function keyExists(string $key, ?int $exceptId = null): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM identity_sources WHERE UPPER(source_key) = UPPER(:source_key) AND id <> :id'
        );
        $statement->execute(['source_key' => $key, 'id' => $exceptId ?? 0]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $bindings = $this->bindings($data, true);
        $fields = array_keys($bindings);
        $statement = $this->pdo->prepare(
            'INSERT INTO identity_sources (' . implode(', ', $fields) . ')
             VALUES (:' . implode(', :', $fields) . ')'
        );
        $statement->execute($bindings);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Aktualisiert eine Quelle. Passwortfelder, die in $data fehlen, bleiben
     * unveraendert.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $bindings = $this->bindings($data, false);
        $assignments = array_map(static fn (string $field): string => $field . ' = :' . $field, array_keys($bindings));
        $statement = $this->pdo->prepare(
            'UPDATE identity_sources SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );
        $statement->execute($bindings + ['id' => $id]);
    }

    /**
     * Ersetzt alle Quellen durch die einer Sicherung (IDs bleiben erhalten,
     * damit die Zuordnung der Telefonbuch-Eintraege stimmt).
     *
     * @param list<array<string,mixed>> $rows bereits gepruefte Datensaetze
     */
    public function replaceAllWithIds(array $rows): void
    {
        $this->pdo->exec('DELETE FROM identity_sources');
        foreach ($rows as $row) {
            $bindings = $this->bindings($row, true) + ['id' => (int) $row['id']];
            $fields = array_keys($bindings);
            $this->pdo->prepare(
                'INSERT INTO identity_sources (' . implode(', ', $fields) . ')
                 VALUES (:' . implode(', :', $fields) . ')'
            )->execute($bindings);
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM identity_sources WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * Blendet alle Telefonbuch-Eintraege und Gruppen einer Quelle aus (beim
     * Deaktivieren oder Loeschen der Quelle). Eine spaetere Synchronisation
     * der wieder aktivierten Quelle stellt sie her.
     */
    public function deactivateData(int $id): void
    {
        $this->pdo->prepare('UPDATE phonebook SET active = 0 WHERE identity_source_id = :id AND active = 1')
            ->execute(['id' => $id]);
        $this->pdo->prepare('UPDATE ad_groups SET active = 0, member_count = 0 WHERE identity_source_id = :id AND active = 1')
            ->execute(['id' => $id]);
        $this->pdo->prepare('DELETE m FROM ad_group_members m JOIN ad_groups g ON g.id = m.group_id WHERE g.identity_source_id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function bindings(array $data, bool $allOptional): array
    {
        $defaults = ['port' => 636, 'timeout' => 10, 'attributes' => '{}'];
        $bindings = [];
        foreach (self::FIELDS as $field) {
            if (in_array($field, self::OPTIONAL_FIELDS, true)) {
                if (!array_key_exists($field, $data) && !$allOptional) {
                    continue;
                }
                $value = $data[$field] ?? null;
                $bindings[$field] = $value === null || $value === '' ? null : (string) $value;
                continue;
            }

            $value = $data[$field] ?? ($defaults[$field] ?? '');
            if (in_array($field, self::BOOLEAN_FIELDS, true)) {
                $bindings[$field] = !empty($value) ? 1 : 0;
            } elseif (in_array($field, self::INT_FIELDS, true)) {
                $bindings[$field] = (int) $value;
            } else {
                $bindings[$field] = (string) $value;
            }
        }

        return $bindings;
    }
}
