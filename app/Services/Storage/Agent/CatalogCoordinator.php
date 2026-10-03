<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

/**
 * Prozessuebergreifende Abstimmung fuer den Katalog von storage-sync.
 *
 * Die Wahrheit liegt immer in MySQL (Container storage-sync-catalog). Der
 * Koordinator (Redis, Container storage-sync-redis) ist nur Helfer:
 * - exclusive(): serialisiert Mehrzeilen-Transaktionen aller Prozesse, damit
 *   sie sich in InnoDB nicht gegenseitig verklemmen (Deadlocks).
 * - Zaehler: hochfrequente I/O-Zaehler (MB/s, IOPS) ohne Zeilensperren.
 *
 * Faellt der Helfer aus, arbeitet der Katalog ohne ihn weiter (MySQL erkennt
 * Deadlocks selbst, Einzelanweisungen werden wiederholt; die Zaehler beginnen
 * neu, was Metrics::rates() als Neustart verwirft).
 */
interface CatalogCoordinator
{
    /**
     * Fuehrt $callback unter einer prozessuebergreifenden Sperre aus.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function exclusive(string $name, callable $callback): mixed;

    public function addCounters(int $targetId, int $readBytes, int $writeBytes, int $readOps, int $writeOps): void;

    /**
     * @return array<int,array{read_bytes:int,write_bytes:int,read_ops:int,write_ops:int}>
     */
    public function counters(): array;

    /**
     * Entfernt die Zaehler nicht mehr eingerichteter Ziele (0 und negative
     * Kennungen - Hot-Tier, Snapshot-Speicher - bleiben).
     *
     * @param list<int> $keep
     */
    public function forgetCounters(array $keep): void;
}
