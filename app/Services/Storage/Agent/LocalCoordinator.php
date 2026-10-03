<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

/**
 * Koordinator innerhalb eines Prozesses (Tests, Einzelbefehle ohne Redis).
 */
final class LocalCoordinator implements CatalogCoordinator
{
    /** @var array<int,array{read_bytes:int,write_bytes:int,read_ops:int,write_ops:int}> */
    private array $counters = [];

    public function exclusive(string $name, callable $callback): mixed
    {
        return $callback();
    }

    public function addCounters(int $targetId, int $readBytes, int $writeBytes, int $readOps, int $writeOps): void
    {
        $current = $this->counters[$targetId] ?? ['read_bytes' => 0, 'write_bytes' => 0, 'read_ops' => 0, 'write_ops' => 0];
        $this->counters[$targetId] = [
            'read_bytes' => $current['read_bytes'] + $readBytes,
            'write_bytes' => $current['write_bytes'] + $writeBytes,
            'read_ops' => $current['read_ops'] + $readOps,
            'write_ops' => $current['write_ops'] + $writeOps,
        ];
    }

    public function counters(): array
    {
        return $this->counters;
    }

    public function forgetCounters(array $keep): void
    {
        foreach (array_keys($this->counters) as $id) {
            if ($id > 0 && !in_array($id, $keep, true)) {
                unset($this->counters[$id]);
            }
        }
    }
}
