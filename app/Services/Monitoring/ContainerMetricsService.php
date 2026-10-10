<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Exceptions\ValidationException;
use App\Repositories\ContainerMetricsRepository;
use App\Support\Dates;

/**
 * Kennzahlen der uebrigen Container (CPU und Arbeitsspeicher) fuer die Kacheln
 * auf dem Admin-Dashboard.
 *
 * Anders als beim auth-Container (App\Services\Auth\AuthMetricsService) kann
 * sich diese Messung nicht im Container selbst durchfuehren: app, db,
 * mail-proxy, nextcloud und eurooffice bringen kein Messskript mit. Der
 * Sammel-Container (docker/monitor/metrics.py) liest die Werte deshalb ueber
 * den read-only gemounteten Docker-Socket aus der Docker-Engine - dieselben
 * Zahlen wie "docker stats" - und meldet je Container eine Probe an
 * POST /internal/container-metrics. Die Anwendung prueft die Werte und
 * speichert sie in der Tabelle container_metrics; aus den Proben entstehen je
 * Container der aktuelle Wert, die Spitze und das Mittel der letzten 12
 * Stunden sowie der Verlauf fuer das Overlay der Kachel.
 *
 * Die CPU-Auslastung ist ein Wert des Messfensters zwischen zwei Proben
 * (MONITOR_INTERVAL, Standard 60 s): die Differenz der verbrauchten CPU-Zeit
 * im Verhaeltnis zur Differenz der Systemzeit. Bezugsgroesse ist die
 * zugewiesene CPU-Obergrenze des Containers; ohne Obergrenze gelten die Kerne
 * des Hosts (cpu_limited = 0) - wie bei "docker stats". Bei gesetzter
 * Obergrenze ist der Wert damit unabhaengig von der Groesse des Hosts und von
 * anderen Containern. Der Arbeitsspeicher wird ebenso auf die Obergrenze des
 * Containers bezogen (memory.max; ohne Obergrenze der Arbeitsspeicher des
 * Hosts); gemeldet wird zusaetzlich der absolute Verbrauch in Byte.
 *
 * Die Aufbewahrung ist auf RETENTION_HOURS begrenzt; geraeumt wird beim
 * Schreiben (kein eigener Worker noetig, da der Sammel-Container jede Minute
 * meldet). Aus derselben Aufbewahrung entsteht der Verlauf fuer das Overlay der
 * Kachel: gleichmaessige Zeitabschnitte von HISTORY_BUCKET_MINUTES Minuten mit
 * dem Mittel der Proben je Abschnitt.
 *
 * Container, die nicht laufen (nextcloud und eurooffice gehoeren zum Profil
 * "office" von docker-compose.yml), melden nichts: ihre Kachel zeigt dann nur
 * den Hinweis, dass noch keine Messwerte eingegangen sind.
 */
final class ContainerMetricsService
{
    /**
     * Beobachtete Container (Kennung aus docker-compose.yml => Bezeichnung auf
     * dem Dashboard). Nur diese Kennungen nimmt der Endpunkt an; dieselben
     * Kennungen muss der Sammel-Container melden (MONITOR_SERVICES).
     */
    public const SERVICES = [
        'app' => 'Anwendung',
        'db' => 'Datenbank',
        'mail-proxy' => 'SMTP-/IMAP-Proxy',
        'nextcloud' => 'Nextcloud',
        'eurooffice' => 'Euro-Office',
    ];

    /** Zeitfenster der CPU-Kennzahlen (Stunden). */
    public const CPU_WINDOW_HOURS = 12;

    /** Zeitfenster der Arbeitsspeicher-Kennzahlen (Stunden). */
    public const RAM_WINDOW_HOURS = 12;

    /** Aufbewahrung der Proben (Stunden). */
    public const RETENTION_HOURS = 48;

    /** Schwelle fuer die gelbe Anzeige (Prozent). */
    public const WARN_PERCENT = 75.0;

    /** Schwelle fuer die rote Anzeige (Prozent). */
    public const CRIT_PERCENT = 90.0;

    /** Hoechste Bezugsgroesse der CPU-Messung (Kerne). */
    public const MAX_CPU_CORES = 1024.0;

    /** Hoechster gemeldeter Arbeitsspeicher (Byte). */
    public const MAX_MEMORY_BYTES = 1125899906842624;

    /**
     * Ab wann eine Kachel als veraltet gilt (Sekunden); die Proben kommen
     * jede Minute.
     */
    public const STALE_SECONDS = 300;

    /** Laenge eines Zeitabschnitts im Verlauf (Minuten). */
    public const HISTORY_BUCKET_MINUTES = 5;

    /**
     * @param ContainerMetricsRepository $repository Ablage der Proben
     * @param \Closure|null $clock Zeitquelle in Sekunden (Tests); ohne Angabe
     *                             gilt time()
     */
    public function __construct(
        private readonly ContainerMetricsRepository $repository,
        private readonly ?\Closure $clock = null
    ) {
    }

    /**
     * Nimmt eine Probe eines Containers entgegen.
     *
     * @param array<string,mixed> $payload
     * @throws ValidationException
     */
    public function record(array $payload): void
    {
        $sample = self::validate($payload);
        $now = $this->now();

        $this->repository->record(
            $sample['service'],
            date('Y-m-d H:i:s', $now),
            $sample['cpu_percent'],
            $sample['cpu_limit'],
            $sample['cpu_limited'],
            $sample['ram'] === null ? null : $sample['ram']['percent'],
            $sample['ram'] === null ? null : $sample['ram']['used'],
            $sample['ram'] === null ? null : $sample['ram']['total']
        );
        $this->repository->prune(date('Y-m-d H:i:s', $now - self::RETENTION_HOURS * 3600));
    }

    /**
     * Anzeige aller beobachteten Container: je Container die Kachel (Kennzahlen
     * der letzten 12 Stunden) und der Verlauf der Aufbewahrung (48 h) fuer das
     * Overlay.
     *
     * @return array{cards:list<array<string,mixed>>,history:array<string,array<string,mixed>>}
     */
    public function dashboard(): array
    {
        $now = $this->now();
        $rows = $this->repository->window(
            array_keys(self::SERVICES),
            date('Y-m-d H:i:s', $now - self::RETENTION_HOURS * 3600)
        );

        $cards = [];
        $history = [];
        foreach (self::SERVICES as $service => $title) {
            $samples = $rows[$service] ?? [];
            $cards[] = array_merge(
                ['service' => $service, 'title' => $title],
                self::buildCard($samples, $now)
            );
            $history[$service] = self::buildHistory($samples, $now);
        }

        return ['cards' => $cards, 'history' => $history];
    }

    /**
     * Prueft und normalisiert eine Probe eines Containers.
     *
     * Erwartet service, cpu_percent, cpu_limit und cpu_limited; ram_percent,
     * ram_used und ram_total sind optional (nicht lesbare Probe der
     * Docker-Engine); sie gelten nur gemeinsam.
     *
     * @param array<string,mixed> $payload
     * @return array{service:string,cpu_percent:float,cpu_limit:float,cpu_limited:bool,ram:?array{percent:float,used:int,total:int}}
     * @throws ValidationException
     */
    public static function validate(array $payload): array
    {
        $errors = [];

        $service = self::service($payload['service'] ?? null);
        if ($service === null) {
            $errors['service'] = 'Der Container muss einer der beobachteten Dienste sein ('
                . implode(', ', array_keys(self::SERVICES)) . ').';
        }

        $cpuPercent = self::percent($payload['cpu_percent'] ?? null);
        if ($cpuPercent === null) {
            $errors['cpu_percent'] = 'Die CPU-Auslastung muss eine Zahl zwischen 0 und 100 sein.';
        }

        $cpuLimit = self::cpuLimit($payload['cpu_limit'] ?? null);
        if ($cpuLimit === null) {
            $errors['cpu_limit'] = 'Die Bezugsgroesse der CPU muss eine Zahl groesser 0 sein.';
        }

        $cpuLimited = self::flag($payload['cpu_limited'] ?? null);
        if ($cpuLimited === null) {
            $errors['cpu_limited'] = 'Die Angabe zur CPU-Obergrenze muss 0 oder 1 sein.';
        }

        $ram = self::ram($payload);
        if ($ram === false) {
            $errors['ram_percent'] = 'Die Arbeitsspeicher-Auslastung muss eine Zahl zwischen 0 und 100 sein.';
            $errors['ram_used'] = 'Der belegte Arbeitsspeicher muss eine Groesse in Byte sein.';
            $errors['ram_total'] = 'Die Bezugsgroesse des Arbeitsspeichers muss eine Groesse in Byte sein.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'service' => (string) $service,
            'cpu_percent' => (float) $cpuPercent,
            'cpu_limit' => (float) $cpuLimit,
            'cpu_limited' => (bool) $cpuLimited,
            'ram' => $ram === false ? null : $ram,
        ];
    }

    /**
     * Zustand der Anzeige: "ok", "warn" (ab WARN_PERCENT) oder "crit"
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
     * Zustand der Kachel: die schlechtere der beiden Kennzahlen faerbt den
     * oberen Rand (CPU-Auslastung und Arbeitsspeicher-Auslastung).
     *
     * @param array<string,mixed> $card Kachel aus buildCard()
     */
    public static function state(array $card): string
    {
        $level = 'ok';
        foreach (['cpu', 'ram'] as $key) {
            $value = $card[$key] ?? null;
            if (!is_array($value)) {
                continue;
            }

            $current = (string) ($value['level'] ?? 'ok');
            if ($current === 'crit') {
                return 'crit';
            }

            if ($current === 'warn') {
                $level = 'warn';
            }
        }

        return $level;
    }

    /**
     * Bezugsgroesse der CPU-Auslastung: eine gesetzte Obergrenze des
     * Containers oder - ohne Grenze - die Kerne des Hosts (wie bei
     * "docker stats" die Bezugsgroesse der Prozentangabe).
     *
     * @param array<string,mixed> $cpu CPU-Anzeige aus buildCard()
     */
    public static function cpuReference(array $cpu): string
    {
        $cores = number_format((float) ($cpu['limit'] ?? 0), 2, ',', '.') . ' Kerne';

        return !empty($cpu['limited'])
            ? 'Zugewiesen: ' . $cores
            : 'Bezugsgröße: ' . $cores . ' (Host)';
    }

    /**
     * Baut die Anzeige einer Kachel aus den Proben eines Containers.
     *
     * @param list<array{recorded_at:string,cpu_percent:float,cpu_limit:float,cpu_limited:bool,ram_percent:?float,ram_used:?int,ram_total:?int}> $rows
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
                'ram' => null,
            ];
        }

        $latest = $rows[count($rows) - 1];
        $latestAt = strtotime($latest['recorded_at']);
        $cpuFrom = $now - self::CPU_WINDOW_HOURS * 3600;
        $ramFrom = $now - self::RAM_WINDOW_HOURS * 3600;

        $cpuValues = [];
        $cpuPeak = null;
        $cpuPeakAt = null;
        $ramValues = [];
        $ramPeak = null;
        $ramPeakAt = null;

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

            if ($at >= $ramFrom && $row['ram_percent'] !== null) {
                $ramValues[] = $row['ram_percent'];
                if ($ramPeak === null || $row['ram_percent'] > $ramPeak) {
                    $ramPeak = $row['ram_percent'];
                    $ramPeakAt = $at;
                }
            }
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
                'limited' => $latest['cpu_limited'],
                'level' => self::level($cpuCurrent),
                'window' => self::CPU_WINDOW_HOURS,
            ],
            'ram' => self::ramCard($rows, $ramValues, $ramPeak, $ramPeakAt),
        ];
    }

    /**
     * Arbeitsspeicher-Anzeige: letzter gemessener Wert (auch wenn die juengste
     * Probe keinen Speicher meldet) mit Spitze und Mittel des Zeitfensters.
     * null, wenn keine Probe Speicherwerte enthaelt.
     *
     * @param list<array{recorded_at:string,cpu_percent:float,cpu_limit:float,cpu_limited:bool,ram_percent:?float,ram_used:?int,ram_total:?int}> $rows
     * @param list<float> $values
     * @return array<string,mixed>|null
     */
    private static function ramCard(array $rows, array $values, ?float $peak, ?int $peakAt): ?array
    {
        $latest = null;
        for ($index = count($rows) - 1; $index >= 0; $index--) {
            if ($rows[$index]['ram_percent'] !== null) {
                $latest = $rows[$index];
                break;
            }
        }

        if ($latest === null) {
            return null;
        }

        $current = round((float) $latest['ram_percent'], 1);

        return [
            'current' => $current,
            'used' => $latest['ram_used'],
            'total' => $latest['ram_total'],
            'peak' => $peak === null ? $current : round($peak, 1),
            'peak_at' => $peakAt === null
                ? null
                : Dates::formatDateTime(date('Y-m-d H:i:s', $peakAt)),
            'avg' => $values === [] ? $current : round(self::average($values), 1),
            'level' => self::level($current),
            'window' => self::RAM_WINDOW_HOURS,
            'recorded_at' => Dates::formatDateTime($latest['recorded_at']),
        ];
    }

    /**
     * Verlauf der Aufbewahrung eines Containers als gleichmaessige
     * Zeitabschnitte (Grundlage der Grafiken im Overlay der Kachel).
     *
     * Der Verlauf beginnt an einer Abschnittsgrenze und endet mit dem
     * laufenden Abschnitt; fehlende Proben bleiben leer (Luecke), sie zaehlen
     * nicht als Nullwert. Jeder Abschnitt traegt das Mittel seiner Proben.
     *
     * @param list<array{recorded_at:string,cpu_percent:float,cpu_limit:float,cpu_limited:bool,ram_percent:?float,ram_used:?int,ram_total:?int}> $rows
     * @return array<string,mixed>
     */
    public static function buildHistory(array $rows, int $now): array
    {
        $bucket = self::HISTORY_BUCKET_MINUTES * 60;
        $buckets = intdiv(self::RETENTION_HOURS * 3600, $bucket);
        $end = intdiv($now, $bucket) * $bucket + $bucket;
        $start = $end - $buckets * $bucket;

        $cpuBuckets = array_fill(0, $buckets, []);
        $ramBuckets = array_fill(0, $buckets, []);
        $cpuValues = [];
        $ramValues = [];
        $cpuPeak = null;
        $cpuPeakAt = null;
        $ramPeak = null;
        $ramPeakAt = null;
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
            $cpuValues[] = $row['cpu_percent'];

            if ($cpuPeak === null || $row['cpu_percent'] > $cpuPeak) {
                $cpuPeak = $row['cpu_percent'];
                $cpuPeakAt = $at;
            }

            if ($row['ram_percent'] !== null) {
                $ramBuckets[$index][] = $row['ram_percent'];
                $ramValues[] = $row['ram_percent'];

                if ($ramPeak === null || $row['ram_percent'] > $ramPeak) {
                    $ramPeak = $row['ram_percent'];
                    $ramPeakAt = $at;
                }
            }
        }

        $latest = $rows === [] ? null : $rows[count($rows) - 1];

        return [
            'available' => $samples > 0,
            'bucket_minutes' => self::HISTORY_BUCKET_MINUTES,
            'buckets' => $buckets,
            'start' => date('Y-m-d H:i:s', $start),
            'end' => date('Y-m-d H:i:s', $end),
            'samples' => $samples,
            'cpu' => [
                'values' => self::bucketsAverage($cpuBuckets),
                'peak' => $cpuPeak === null ? null : round($cpuPeak, 1),
                'peak_at' => $cpuPeakAt === null
                    ? null
                    : Dates::formatDateTime(date('Y-m-d H:i:s', $cpuPeakAt)),
                'avg' => $cpuValues === [] ? null : round(self::average($cpuValues), 1),
                'limit' => $latest === null ? 1.0 : round($latest['cpu_limit'], 2),
                'limited' => $latest !== null && $latest['cpu_limited'],
            ],
            'ram' => [
                'values' => self::bucketsAverage($ramBuckets),
                'peak' => $ramPeak === null ? null : round($ramPeak, 1),
                'peak_at' => $ramPeakAt === null
                    ? null
                    : Dates::formatDateTime(date('Y-m-d H:i:s', $ramPeakAt)),
                'avg' => $ramValues === [] ? null : round(self::average($ramValues), 1),
            ],
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
     * Kennung des Containers; nur die beobachteten Dienste sind erlaubt.
     */
    private static function service(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $service = trim($value);

        return array_key_exists($service, self::SERVICES) ? $service : null;
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
        if ($number === null || $number <= 0.0 || $number > self::MAX_CPU_CORES) {
            return null;
        }

        return round($number, 2);
    }

    /**
     * Wahrheitswert der Probe ("1"/"0", 1/0, true/false); null bei ungueltiger
     * Angabe.
     */
    private static function flag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return match ($value) {
                0 => false,
                1 => true,
                default => null,
            };
        }

        if (is_string($value)) {
            return match (trim($value)) {
                '0', 'false' => false,
                '1', 'true' => true,
                default => null,
            };
        }

        return null;
    }

    /**
     * Arbeitsspeicher der Probe: null, wenn keine der drei Angaben enthalten
     * ist; false, wenn sie unvollstaendig oder ungueltig sind.
     *
     * @param array<string,mixed> $payload
     * @return array{percent:float,used:int,total:int}|null|false
     */
    private static function ram(array $payload): array|null|false
    {
        $percent = $payload['ram_percent'] ?? null;
        $used = $payload['ram_used'] ?? null;
        $total = $payload['ram_total'] ?? null;

        if ($percent === null && $used === null && $total === null) {
            return null;
        }

        $percent = self::percent($percent);
        $used = self::bytes($used);
        $total = self::bytes($total);

        if ($percent === null || $used === null || $total === null || $total <= 0 || $used > $total) {
            return false;
        }

        return ['percent' => $percent, 'used' => $used, 'total' => $total];
    }

    private static function bytes(mixed $value): ?int
    {
        if (is_int($value)) {
            $bytes = $value;
        } elseif (is_float($value) && $value === floor($value)) {
            $bytes = (int) $value;
        } elseif (is_string($value) && ctype_digit(trim($value))) {
            $bytes = (int) trim($value);
        } else {
            return null;
        }

        return $bytes >= 0 && $bytes <= self::MAX_MEMORY_BYTES ? $bytes : null;
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

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }
}
