<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\ValidationException;
use App\Repositories\AuthMetricsRepository;
use App\Support\Dates;

/**
 * Kennzahlen des auth-Containers (Einstieg/Reverse-Proxy) fuer die Karte auf
 * dem Admin-Dashboard.
 *
 * Der auth-Container misst selbst (docker/auth/metrics.py) und meldet jede
 * Probe an POST /internal/auth-metrics; die Anwendung prueft die Werte und
 * speichert sie in der Tabelle auth_metrics. Aus den Proben entstehen der
 * aktuelle Wert, die Spitze und das Mittel der letzten 12 Stunden (CPU) bzw.
 * 24 Stunden (Verbindungen) sowie die Verteilung der offenen Verbindungen auf
 * die Quellnetze.
 *
 * Die CPU-Auslastung bezieht sich auf das CPU-Limit des Containers (cpu.max);
 * ohne Limit gilt ein Kern als Bezugsgroesse. Der Wert ist damit unabhaengig
 * von der Groesse des Hosts und von anderen Containern.
 *
 * Die Aufbewahrung ist auf RETENTION_HOURS begrenzt; geraeumt wird beim
 * Schreiben (kein eigener Worker noetig, da der auth-Container jede Minute
 * meldet). Aus derselben Aufbewahrung entsteht der Verlauf fuer das Overlay der
 * Karte: gleichmaessige Zeitabschnitte von HISTORY_BUCKET_MINUTES Minuten mit
 * dem Mittel der Proben je Abschnitt.
 */
final class AuthMetricsService
{
    /** Zeitfenster der CPU-Kennzahlen (Stunden). */
    public const CPU_WINDOW_HOURS = 12;

    /** Zeitfenster der Verbindungs-Kennzahlen (Stunden). */
    public const TCP_WINDOW_HOURS = 24;

    /** Aufbewahrung der Proben (Stunden). */
    public const RETENTION_HOURS = 48;

    /** Ab dieser Auslastung wird die Anzeige gelb (Prozent). */
    public const WARN_PERCENT = 75.0;

    /** Ab dieser Auslastung wird die Anzeige rot (Prozent). */
    public const CRIT_PERCENT = 90.0;

    /** Hoechstzahl der Quellnetze je Probe. */
    public const MAX_SOURCES = 16;

    /** Laengenbegrenzung eines Quellnetz-Namens (Zeichen). */
    public const MAX_SOURCE_LENGTH = 64;

    /** Hoechstwert der Verbindungen je Probe. */
    public const MAX_CONNECTIONS = 1000000;

    /** Ab dieser Zeit ohne Probe gelten die Werte als veraltet (Sekunden). */
    public const STALE_SECONDS = 300;

    /** Breite eines Zeitabschnitts des Verlaufs (Minuten). */
    public const HISTORY_BUCKET_MINUTES = 5;

    /** Hoechstzahl der Quellnetze im Verlauf. */
    public const HISTORY_SOURCES = 5;

    /**
     * @param \Closure():int|null $clock liefert den aktuellen Zeitstempel
     *                                    (Tests); ohne Angabe gilt time()
     */
    public function __construct(
        private readonly AuthMetricsRepository $repository,
        private readonly ?\Closure $clock = null
    ) {
    }

    /**
     * Nimmt eine Probe des auth-Containers entgegen.
     *
     * @param array<string,mixed> $payload
     * @throws ValidationException
     */
    public function record(array $payload): void
    {
        $sample = self::validate($payload);
        $now = $this->now();

        $this->repository->record(
            date('Y-m-d H:i:s', $now),
            $sample['cpu_percent'],
            $sample['cpu_limit'],
            $sample['tcp_open'],
            $sample['sources']
        );
        $this->repository->prune(date('Y-m-d H:i:s', $now - self::RETENTION_HOURS * 3600));
    }

    /**
     * Anzeige der Karte aus den gespeicherten Proben.
     *
     * @return array<string,mixed>
     */
    public function card(): array
    {
        $now = $this->now();

        return self::buildCard(
            $this->repository->window(date('Y-m-d H:i:s', $now - self::TCP_WINDOW_HOURS * 3600)),
            $now
        );
    }

    /**
     * Verlauf der Aufbewahrung (RETENTION_HOURS) fuer das Overlay der Karte.
     *
     * @return array<string,mixed>
     */
    public function history(): array
    {
        $now = $this->now();

        return self::buildHistory(
            $this->repository->window(date('Y-m-d H:i:s', $now - self::RETENTION_HOURS * 3600)),
            $now
        );
    }

    /**
     * Prueft und normalisiert eine Probe des auth-Containers.
     *
     * Erwartet cpu_percent, cpu_limit, tcp_open und sources; sources ist ein
     * JSON-Objekt (Quellnetz => Verbindungen) oder ein Array.
     *
     * @param array<string,mixed> $payload
     * @return array{cpu_percent:float,cpu_limit:float,tcp_open:int,sources:array<string,int>}
     * @throws ValidationException
     */
    public static function validate(array $payload): array
    {
        $errors = [];

        $cpuPercent = self::percent($payload['cpu_percent'] ?? null);
        if ($cpuPercent === null) {
            $errors['cpu_percent'] = 'Die CPU-Auslastung muss eine Zahl zwischen 0 und 100 sein.';
        }

        $cpuLimit = self::cpuLimit($payload['cpu_limit'] ?? null);
        if ($cpuLimit === null) {
            $errors['cpu_limit'] = 'Das CPU-Limit muss eine Zahl groesser 0 sein.';
        }

        $tcpOpen = self::count($payload['tcp_open'] ?? null);
        if ($tcpOpen === null) {
            $errors['tcp_open'] = 'Die Zahl der Verbindungen muss eine ganze Zahl sein.';
        }

        $sources = self::sources($payload['sources'] ?? null);
        if ($sources === null) {
            $errors['sources'] = 'Die Quellnetze muessen als Objekt aus Netz und Anzahl uebergeben werden.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'cpu_percent' => (float) $cpuPercent,
            'cpu_limit' => (float) $cpuLimit,
            'tcp_open' => (int) $tcpOpen,
            'sources' => (array) $sources,
        ];
    }

    /**
     * Zustand der CPU-Anzeige: "ok", "warn" (ab WARN_PERCENT) oder "crit"
     * (ab CRIT_PERCENT).
     */
    public static function level(float $percent): string
    {
        if ($percent >= self::CRIT_PERCENT) {
            return 'crit';
        }

        return $percent >= self::WARN_PERCENT ? 'warn' : 'ok';
    }

    /**
     * Baut die Anzeige der Karte aus den Proben der letzten 24 Stunden.
     *
     * @param list<array{recorded_at:string,cpu_percent:float,cpu_limit:float,tcp_open:int,sources:?string}> $rows
     * @return array<string,mixed>
     */
    public static function buildCard(array $rows, int $now): array
    {
        if ($rows === []) {
            return [
                'available' => false,
                'samples' => 0,
                'stale' => true,
                'recorded_at' => null,
                'cpu' => null,
                'tcp' => null,
                'sources' => [],
                'source_total' => 0,
            ];
        }

        $latest = $rows[count($rows) - 1];
        $latestAt = strtotime($latest['recorded_at']);
        $cpuFrom = $now - self::CPU_WINDOW_HOURS * 3600;
        $tcpFrom = $now - self::TCP_WINDOW_HOURS * 3600;

        $cpuValues = [];
        $cpuPeak = null;
        $cpuPeakAt = null;
        $tcpValues = [];

        foreach ($rows as $row) {
            $at = strtotime($row['recorded_at']);
            if ($at === false) {
                continue;
            }

            if ($at >= $cpuFrom) {
                $cpuValues[] = $row['cpu_percent'];
                if ($cpuPeak === null || $row['cpu_percent'] > $cpuPeak) {
                    $cpuPeak = $row['cpu_percent'];
                    $cpuPeakAt = $at;
                }
            }

            if ($at >= $tcpFrom) {
                $tcpValues[] = $row['tcp_open'];
            }
        }

        $sources = self::sourcesOf($latest);
        $sourceTotal = 0;
        foreach ($sources as $source) {
            $sourceTotal += $source['count'];
        }

        $cpuCurrent = round($latest['cpu_percent'], 1);

        return [
            'available' => true,
            'samples' => count($rows),
            'stale' => $latestAt !== false && ($now - $latestAt) > self::STALE_SECONDS,
            'recorded_at' => Dates::formatDateTime($latest['recorded_at']),
            'cpu' => [
                'current' => $cpuCurrent,
                'peak' => round((float) $cpuPeak, 1),
                'peak_at' => $cpuPeakAt === null
                    ? null
                    : Dates::formatDateTime(date('Y-m-d H:i:s', $cpuPeakAt)),
                'avg' => round(self::average($cpuValues), 1),
                'limit' => round($latest['cpu_limit'], 2),
                'level' => self::level($cpuCurrent),
                'window' => self::CPU_WINDOW_HOURS,
            ],
            'tcp' => [
                'open' => $latest['tcp_open'],
                'peak' => $tcpValues === [] ? $latest['tcp_open'] : max($tcpValues),
                'avg' => round(self::average($tcpValues), 1),
                'window' => self::TCP_WINDOW_HOURS,
            ],
            'sources' => $sources,
            'source_total' => $sourceTotal,
        ];
    }

    /**
     * Verlauf der Aufbewahrung als gleichmaessige Zeitabschnitte.
     *
     * Der Verlauf beginnt an einer Abschnittsgrenze und endet mit dem
     * laufenden Abschnitt; fehlende Proben bleiben leer (Luecke), sie zaehlen
     * nicht als Nullwert. Jeder Abschnitt traegt das Mittel seiner Proben.
     *
     * @param list<array{recorded_at:string,cpu_percent:float,cpu_limit:float,tcp_open:int,sources:?string}> $rows
     * @return array<string,mixed>
     */
    public static function buildHistory(array $rows, int $now): array
    {
        $bucket = self::HISTORY_BUCKET_MINUTES * 60;
        $buckets = intdiv(self::RETENTION_HOURS * 3600, $bucket);
        $end = intdiv($now, $bucket) * $bucket + $bucket;
        $start = $end - $buckets * $bucket;

        $cpuBuckets = array_fill(0, $buckets, []);
        $tcpBuckets = array_fill(0, $buckets, []);
        $sourceBuckets = [];
        $cpuValues = [];
        $tcpValues = [];
        $cpuPeak = null;
        $cpuPeakAt = null;
        $samples = 0;

        foreach ($rows as $row) {
            $at = strtotime($row['recorded_at']);
            if ($at === false) {
                continue;
            }

            $index = intdiv($at - $start, $bucket);
            if ($index < 0 || $index >= $buckets) {
                continue;
            }

            $samples++;
            $cpuBuckets[$index][] = $row['cpu_percent'];
            $tcpBuckets[$index][] = $row['tcp_open'];
            $cpuValues[] = $row['cpu_percent'];
            $tcpValues[] = $row['tcp_open'];

            if ($cpuPeak === null || $row['cpu_percent'] > $cpuPeak) {
                $cpuPeak = $row['cpu_percent'];
                $cpuPeakAt = $at;
            }

            foreach (self::sourcesOf($row) as $source) {
                $sourceBuckets[$source['network']][$index][] = $source['count'];
            }
        }

        $sources = [];
        foreach ($sourceBuckets as $network => $byIndex) {
            $values = [];
            $peak = 0;
            for ($index = 0; $index < $buckets; $index++) {
                $bucketValues = $byIndex[$index] ?? [];
                if ($bucketValues === []) {
                    $values[] = null;
                    continue;
                }

                $values[] = round(array_sum($bucketValues) / count($bucketValues), 1);
                $peak = max($peak, max($bucketValues));
            }

            $sources[] = ['network' => (string) $network, 'values' => $values, 'peak' => $peak];
        }
        usort(
            $sources,
            static fn (array $left, array $right): int => $right['peak'] <=> $left['peak']
                ?: strcmp($left['network'], $right['network'])
        );

        return [
            'bucket_minutes' => self::HISTORY_BUCKET_MINUTES,
            'buckets' => $buckets,
            'start' => date('Y-m-d H:i:s', $start),
            'end' => date('Y-m-d H:i:s', $end),
            'samples' => $samples,
            'cpu' => [
                'values' => self::bucketsAverage($cpuBuckets),
                'peak' => round((float) $cpuPeak, 1),
                'peak_at' => $cpuPeakAt === null
                    ? null
                    : Dates::formatDateTime(date('Y-m-d H:i:s', $cpuPeakAt)),
                'avg' => round(self::average($cpuValues), 1),
                'limit' => $rows === [] ? 1.0 : round($rows[count($rows) - 1]['cpu_limit'], 2),
            ],
            'tcp' => [
                'values' => self::bucketsAverage($tcpBuckets),
                'peak' => $tcpValues === [] ? 0 : max($tcpValues),
                'avg' => round(self::average($tcpValues), 1),
            ],
            'sources' => array_slice($sources, 0, self::HISTORY_SOURCES),
        ];
    }

    /**
     * Mittel je Zeitabschnitt; Abschnitte ohne Probe bleiben leer.
     *
     * @param list<list<float|int>> $buckets
     * @return list<float|null>
     */
    private static function bucketsAverage(array $buckets): array
    {
        $values = [];
        foreach ($buckets as $bucket) {
            $values[] = $bucket === [] ? null : round(array_sum($bucket) / count($bucket), 1);
        }

        return $values;
    }

    /**
     * @param list<float> $values
     */
    private static function average(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /**
     * Verteilung der offenen Verbindungen der letzten Probe, groesste Gruppe
     * zuerst.
     *
     * @param array{recorded_at:string,cpu_percent:float,cpu_limit:float,tcp_open:int,sources:?string} $latest
     * @return list<array{network:string,count:int,share:float}>
     */
    private static function sourcesOf(array $latest): array
    {
        $raw = $latest['sources'];
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $sources = [];
        foreach ($decoded as $network => $count) {
            if (!is_string($network) || $network === '') {
                continue;
            }
            $sources[] = ['network' => $network, 'count' => max(0, (int) $count)];
        }

        usort(
            $sources,
            static fn (array $left, array $right): int => $right['count'] <=> $left['count']
                ?: strcmp($left['network'], $right['network'])
        );

        $total = 0;
        foreach ($sources as $source) {
            $total += $source['count'];
        }

        foreach ($sources as $index => $source) {
            $sources[$index]['share'] = $total > 0 ? round($source['count'] * 100 / $total, 1) : 0.0;
        }

        return $sources;
    }

    private static function percent(mixed $value): ?float
    {
        $number = self::number($value);
        if ($number === null || $number < 0.0 || $number > 100.0) {
            return null;
        }

        return round($number, 2);
    }

    private static function cpuLimit(mixed $value): ?float
    {
        $number = self::number($value);
        if ($number === null || $number <= 0.0 || $number > 1024.0) {
            return null;
        }

        return round($number, 2);
    }

    private static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } elseif (is_string($value) && is_numeric(trim($value))) {
            $number = (float) trim($value);
        } else {
            return null;
        }

        return is_finite($number) ? $number : null;
    }

    private static function count(mixed $value): ?int
    {
        if (is_int($value)) {
            $count = $value;
        } elseif (is_string($value) && ctype_digit(trim($value))) {
            $count = (int) trim($value);
        } else {
            return null;
        }

        return $count >= 0 && $count <= self::MAX_CONNECTIONS ? $count : null;
    }

    /**
     * Quellnetze der Probe; erlaubt ist ein Feld mit JSON-Objekt oder bereits
     * ein Array ("192.168.200.0/24" => 5).
     *
     * @return array<string,int>|null
     */
    private static function sources(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (!is_array($value)) {
            return null;
        }

        $sources = [];
        foreach ($value as $network => $count) {
            if (!is_string($network)) {
                return null;
            }

            $label = trim($network);
            if ($label === '' || strlen($label) > self::MAX_SOURCE_LENGTH) {
                return null;
            }

            if (preg_match('/^[\x20-\x7E]+$/', $label) !== 1) {
                return null;
            }

            $connections = self::count($count);
            if ($connections === null) {
                return null;
            }

            $sources[$label] = $connections;
        }

        if (count($sources) > self::MAX_SOURCES) {
            return null;
        }

        arsort($sources);

        return $sources;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }
}
