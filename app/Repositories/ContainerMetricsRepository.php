<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Proben der Kennzahlen der uebrigen Container (Kacheln auf dem Admin-Dashboard).
 *
 * Gespeichert werden nur Kennzahlen je Messfenster: CPU-Auslastung mit ihrer
 * Bezugsgroesse sowie der belegte und der verfuegbare Arbeitsspeicher. Je
 * Container (Spalte service) entstehen eigene Proben.
 */
final class ContainerMetricsRepository extends Repository
{
    /**
     * @param float|null $ramPercent Arbeitsspeicher-Auslastung in Prozent
     * @param int|null $ramUsed belegter Arbeitsspeicher in Byte
     * @param int|null $ramTotal Bezugsgroesse des Arbeitsspeichers in Byte
     */
    public function record(
        string $service,
        string $recordedAt,
        float $cpuPercent,
        float $cpuLimit,
        bool $cpuLimited,
        ?float $ramPercent,
        ?int $ramUsed,
        ?int $ramTotal
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO container_metrics'
            . ' (service, recorded_at, cpu_percent, cpu_limit, cpu_limited, ram_percent, ram_used, ram_total)'
            . ' VALUES (:service, :recorded_at, :cpu_percent, :cpu_limit, :cpu_limited, :ram_percent,'
            . ' :ram_used, :ram_total)'
        );
        $statement->execute([
            'service' => $service,
            'recorded_at' => $recordedAt,
            'cpu_percent' => number_format($cpuPercent, 2, '.', ''),
            'cpu_limit' => number_format($cpuLimit, 2, '.', ''),
            'cpu_limited' => $cpuLimited ? 1 : 0,
            'ram_percent' => $ramPercent === null ? null : number_format($ramPercent, 2, '.', ''),
            'ram_used' => $ramUsed,
            'ram_total' => $ramTotal,
        ]);
    }

    /**
     * Proben der genannten Container ab einem Zeitpunkt, je Container aelteste
     * zuerst.
     *
     * @param list<string> $services Kennungen der Container
     * @return array<string,list<array{recorded_at:string,cpu_percent:float,cpu_limit:float,cpu_limited:bool,ram_percent:?float,ram_used:?int,ram_total:?int}>>
     */
    public function window(array $services, string $from): array
    {
        $rows = [];
        foreach ($services as $service) {
            $rows[$service] = [];
        }

        if ($services === []) {
            return $rows;
        }

        $names = [];
        $parameters = ['from' => $from];
        foreach (array_values($services) as $index => $service) {
            $names[] = ':service' . $index;
            $parameters['service' . $index] = $service;
        }

        $statement = $this->pdo->prepare(
            'SELECT service, recorded_at, cpu_percent, cpu_limit, cpu_limited, ram_percent, ram_used, ram_total'
            . ' FROM container_metrics WHERE service IN (' . implode(', ', $names) . ') AND recorded_at >= :from'
            . ' ORDER BY service ASC, recorded_at ASC, id ASC'
        );
        $statement->execute($parameters);

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $service = (string) $row['service'];
            if (!isset($rows[$service])) {
                continue;
            }

            $rows[$service][] = [
                'recorded_at' => (string) $row['recorded_at'],
                'cpu_percent' => (float) $row['cpu_percent'],
                'cpu_limit' => (float) $row['cpu_limit'],
                'cpu_limited' => (bool) $row['cpu_limited'],
                'ram_percent' => $row['ram_percent'] === null ? null : (float) $row['ram_percent'],
                'ram_used' => $row['ram_used'] === null ? null : (int) $row['ram_used'],
                'ram_total' => $row['ram_total'] === null ? null : (int) $row['ram_total'],
            ];
        }

        return $rows;
    }

    public function prune(string $before): int
    {
        $statement = $this->pdo->prepare('DELETE FROM container_metrics WHERE recorded_at < :before');
        $statement->execute(['before' => $before]);

        return $statement->rowCount();
    }
}
