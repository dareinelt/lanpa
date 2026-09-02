<?php

declare(strict_types=1);

namespace App\Repositories;

final class SettingsRepository extends Repository
{
    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        $statement = $this->pdo->query('SELECT setting_key, setting_value FROM settings');
        if ($statement === false) {
            return [];
        }

        $result = [];
        /** @var array{setting_key:string,setting_value:?string} $row */
        foreach ($statement->fetchAll() as $row) {
            $result[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $result;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $statement = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? $default : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $statement->execute(['key' => $key, 'value' => $value]);
    }

    /**
     * @param array<string,string> $values
     */
    public function setMany(array $values): void
    {
        if ($values === []) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($values as $key => $value) {
                $this->set($key, $value);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }
    }
}
