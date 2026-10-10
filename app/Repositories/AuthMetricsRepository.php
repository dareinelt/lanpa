<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Proben der Kennzahlen des auth-Containers (Karte auf dem Admin-Dashboard).
 *
 * Gespeichert werden nur Zaehler: CPU-Auslastung, Bezugsgroesse, Zahl der
 * offenen TCP-Verbindungen und deren Verteilung auf die Quellnetze.
 */
final class AuthMetricsRepository extends Repository
{
    /**
     * @param array<string,int> $sources Quellnetz => offene Verbindungen
     */
    public function record(
        string $recordedAt,
        float $cpuPercent,
        float $cpuLimit,
        int $tcpOpen,
        array $sources
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO auth_metrics (recorded_at, cpu_percent, cpu_limit, tcp_open, sources)'
            . ' VALUES (:recorded_at, :cpu_percent, :cpu_limit, :tcp_open, :sources)'
        );
        $statement->execute([
            'recorded_at' => $recordedAt,
            'cpu_percent' => number_format($cpuPercent, 2, '.', ''),
            'cpu_limit' => number_format($cpuLimit, 2, '.', ''),
            'tcp_open' => $tcpOpen,
            'sources' => $sources === []
                ? null
                : (string) json_encode($sources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Proben ab einem Zeitpunkt, aelteste zuerst.
     *
     * @return list<array{recorded_at:string,cpu_percent:float,cpu_limit:float,tcp_open:int,sources:?string}>
     */
    public function window(string $from): array
    {
        $statement = $this->pdo->prepare(
            'SELECT recorded_at, cpu_percent, cpu_limit, tcp_open, sources FROM auth_metrics'
            . ' WHERE recorded_at >= :from ORDER BY recorded_at ASC, id ASC'
        );
        $statement->execute(['from' => $from]);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'recorded_at' => (string) $row['recorded_at'],
                'cpu_percent' => (float) $row['cpu_percent'],
                'cpu_limit' => (float) $row['cpu_limit'],
                'tcp_open' => (int) $row['tcp_open'],
                'sources' => $row['sources'] === null ? null : (string) $row['sources'],
            ];
        }

        return $rows;
    }

    public function prune(string $before): int
    {
        $statement = $this->pdo->prepare('DELETE FROM auth_metrics WHERE recorded_at < :before');
        $statement->execute(['before' => $before]);

        return $statement->rowCount();
    }
}
