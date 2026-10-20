<?php

declare(strict_types=1);

namespace App\Repositories;

final class AssetStatusRepository extends BaseRepository
{
    /** @var array<string,array<string,mixed>>|null */
    private ?array $cache = null;

    /** @return array<int,array<string,mixed>> */
    public function all(bool $onlyActive = false): array
    {
        $rows = array_values($this->byCode());

        return $onlyActive ? array_values(array_filter($rows, static fn (array $r): bool => (int) $r['is_active'] === 1)) : $rows;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        foreach ($this->byCode() as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->byCode()[$code] ?? null;
    }

    /** @return array<string,mixed> */
    public function requireByCode(string $code): array
    {
        return $this->findByCode($code) ?? throw new \RuntimeException("Assetstatus „{$code}“ ist nicht konfiguriert.");
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $this->cache = null;

        return $this->insertRow('asset_statuses', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->cache = null;
        $this->updateRow('asset_statuses', $id, $data);
    }

    /** @return array<string,array<string,mixed>> */
    private function byCode(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->fetchAll('SELECT * FROM asset_statuses ORDER BY sort_order, name') as $row) {
                $this->cache[(string) $row['code']] = $row;
            }
        }

        return $this->cache;
    }
}
