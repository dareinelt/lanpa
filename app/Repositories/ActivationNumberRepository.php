<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Validator;

final class ActivationNumberRepository extends Repository
{
    private const COLUMNS = 'a.id, a.phone, a.phone_digits, a.alarm_group_id, a.sort_order, a.active, a.created_at, a.updated_at, g.group_number AS group_number, g.description AS group_description';

    private const FROM = ' FROM activation_numbers a LEFT JOIN alarm_groups g ON g.id = a.alarm_group_id';

    /**
     * @return list<array<string,mixed>>
     */
    public function allActive(): array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . self::FROM . ' WHERE a.active = 1 ORDER BY a.sort_order ASC, a.id ASC'
        );

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll();

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . self::FROM . ' ORDER BY a.sort_order ASC, a.id ASC'
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
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . self::FROM . ' WHERE a.id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Aktive, normalisierte Rufnummer (fuer den Code-Versand).
     *
     * @return array<string,mixed>|null
     */
    public function findByPhoneDigits(string $digits): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . self::FROM . ' WHERE a.phone_digits = :digits AND a.active = 1 LIMIT 1'
        );
        $statement->execute(['digits' => $digits]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Normalisierte Rufnummer unabhaengig vom Aktiv-Status (fuer Eindeutigkeit).
     *
     * @return array<string,mixed>|null
     */
    public function findByPhoneDigitsAny(string $digits): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . self::FROM . ' WHERE a.phone_digits = :digits LIMIT 1'
        );
        $statement->execute(['digits' => $digits]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO activation_numbers (phone, phone_digits, alarm_group_id, sort_order, active)
             VALUES (:phone, :phone_digits, :alarm_group_id, :sort_order, :active)'
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
            'UPDATE activation_numbers
                SET phone = :phone,
                    phone_digits = :phone_digits,
                    alarm_group_id = :alarm_group_id,
                    sort_order = :sort_order,
                    active = :active
              WHERE id = :id'
        );
        $statement->execute($this->bindings($data) + ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM activation_numbers WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function deleteAll(): void
    {
        $this->pdo->exec('DELETE FROM activation_numbers');
    }

    public function setActive(int $id, bool $active): void
    {
        $statement = $this->pdo->prepare('UPDATE activation_numbers SET active = :active WHERE id = :id');
        $statement->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    public function nextSortOrder(): int
    {
        $value = $this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM activation_numbers')?->fetchColumn();

        return is_numeric($value) ? (int) $value : 1;
    }

    /**
     * Verschiebt eine Aktivierungs-Rufnummer um eine Position nach oben oder unten.
     */
    public function move(int $id, string $direction): bool
    {
        $items = $this->all();
        $index = null;
        foreach ($items as $position => $item) {
            if ((int) $item['id'] === $id) {
                $index = $position;
                break;
            }
        }

        if ($index === null) {
            return false;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= count($items)) {
            return false;
        }

        [$items[$index], $items[$target]] = [$items[$target], $items[$index]];

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('UPDATE activation_numbers SET sort_order = :sort_order WHERE id = :id');
            foreach ($items as $position => $item) {
                $statement->execute(['sort_order' => $position + 1, 'id' => (int) $item['id']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }

        return true;
    }

    public function count(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM activation_numbers')?->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function bindings(array $data): array
    {
        $phone = (string) ($data['phone'] ?? '');
        $alarmGroupId = $data['alarm_group_id'] ?? null;

        return [
            'phone' => $phone,
            'phone_digits' => Validator::normalizePhone($phone),
            'alarm_group_id' => ($alarmGroupId === null || $alarmGroupId === '' || $alarmGroupId === 0) ? null : (int) $alarmGroupId,
            'sort_order' => (int) ($data['sort_order'] ?? 1),
            'active' => !empty($data['active']) ? 1 : 0,
        ];
    }
}
