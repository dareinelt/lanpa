<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Repositories\SystemSettingRepository;

final class SettingsService
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly SystemSettingRepository $repository,
        private readonly Config $config
    ) {}

    public function get(string $key, ?string $default = null): ?string
    {
        $all = $this->all();
        $value = $all[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = $this->repository->all();
        }

        return $this->cache;
    }

    /** Cache verwerfen (z. B. nach externen Änderungen an der Tabelle). */
    public function refresh(): void
    {
        $this->cache = null;
    }

    public function set(string $key, string $value, ?int $userId = null): void
    {
        $this->repository->set($key, $value, $userId);
        $this->cache = null;
    }

    /** @param array<string,string> $values */
    public function setMany(array $values, ?int $userId = null): void
    {
        foreach ($values as $key => $value) {
            $this->repository->set($key, $value, $userId);
        }
        $this->cache = null;
    }

    /** @return array<string,string> */
    public function withPrefix(string $prefix): array
    {
        $result = [];
        foreach ($this->all() as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $result[substr($key, strlen($prefix))] = $value;
            }
        }

        return $result;
    }

    public function companyName(): string
    {
        return $this->get('label.company_name') ?? (string) $this->config->get('app.company_name', 'Firma');
    }
}
