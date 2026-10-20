<?php

declare(strict_types=1);

namespace App\Repositories;

final class SystemSettingRepository extends BaseRepository
{
    /** @return array<string,string> */
    public function all(): array
    {
        $rows = $this->fetchAll('SELECT `key`, value FROM system_settings');
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['key']] = (string) $row['value'];
        }

        return $result;
    }

    public function get(string $key): ?string
    {
        $value = $this->fetchValue('SELECT value FROM system_settings WHERE `key` = ?', [$key]);

        return $value === null ? null : (string) $value;
    }

    public function set(string $key, string $value, ?int $userId = null): void
    {
        $this->execute(
            'INSERT INTO system_settings (`key`, value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)',
            [$key, $value, $userId]
        );
    }
}
