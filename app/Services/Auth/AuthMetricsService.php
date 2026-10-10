<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\ValidationException;
use App\Repositories\AuthMetricsRepository;
use App\Services\SettingsService;
use App\Support\Dates;
use App\Support\SourceNetworks;

/**
 * Kennzahlen des auth-Containers (Einstieg/Reverse-Proxy) fuer die Karte auf
 * dem Admin-Dashboard.
 *
 * Der auth-Container misst selbst (docker/auth/metrics.py) und meldet jede
 * Probe an POST /internal/auth-metrics; die Anwendung prueft die Werte und
 * speichert sie in der Tabelle auth_metrics. Aus den Proben entstehen der
 * aktuelle Wert, die Spitze und das Mittel der letzten 12 Stunden (CPU und
 * Arbeitsspeicher) bzw. 24 Stunden (Verbindungen) sowie die Verteilung der
 * Anfragen auf die Quellnetze.
 *
 * CPU-Auslastung und Verbindungen sind Werte des Messfensters zwischen zwei
 * Proben (AUTH_METRICS_INTERVAL, Standard 60 s): die CPU-Auslastung aus der
 * Differenz der verbrauchten CPU-Zeit und die Verbindungen aus dem
 * Zugriffsprotokoll des Proxys (Adresse und Quellport je Anfrage; mehrere
 * Anfragen einer Keep-Alive-Verbindung zaehlen nur einmal). Eine
 * Momentaufnahme waere hier unbrauchbar, weil der Reverse-Proxy eine
 * Verbindung schon wenige Sekunden nach der letzten Anfrage schliesst
 * (KeepAliveTimeout) und die Probe nur einmal je Minute erfolgt.
 *
 * Die CPU-Auslastung bezieht sich auf das CPU-Limit des Containers (cpu.max);
 * ohne Limit gilt ein Kern als Bezugsgroesse. Der Wert ist damit unabhaengig
 * von der Groesse des Hosts und von anderen Containern. Der Arbeitsspeicher
 * wird ebenso im Container gemessen und auf seine Bezugsgroesse bezogen
 * (memory.max; ohne Limit die Groesse des Arbeitsspeichers) - gemeldet wird
 * zusaetzlich der absolute Verbrauch in Byte.
 *
 * Die Aufbewahrung ist auf RETENTION_HOURS begrenzt; geraeumt wird beim
 * Schreiben (kein eigener Worker noetig, da der auth-Container jede Minute
 * meldet). Aus derselben Aufbewahrung entsteht der Verlauf fuer das Overlay der
 * Karte: gleichmaessige Zeitabschnitte von HISTORY_BUCKET_MINUTES Minuten mit
 * dem Mittel der Proben je Abschnitt.
 *
 * Der auth-Container meldet die Quellnetze verfeinert (IPv4 /24, IPv6 /64).
 * Unter Admin → System → Bekannte Quellnetze lassen sich groessere Netze
 * eintragen; deren Adressbereich wird aus dem Praefix errechnet
 * (App\Support\SourceNetworks) und die enthaltenen gemeldeten Netze werden
 * beim Lesen zu dem bekannten Netz zusammengefasst - auch rueckwirkend fuer
 * bereits gespeicherte Proben.
 */
final class AuthMetricsService
{
    /** Zeitfenster der CPU-Kennzahlen (Stunden). */
    public const CPU_WINDOW_HOURS = 12;

    /** Zeitfenster der Arbeitsspeicher-Kennzahlen (Stunden). */
    public const RAM_WINDOW_HOURS = 12;

    /** Zeitfenster der Verbindungs- und Anfrage-Kennzahlen (Stunden). */
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

    /** Hoechstwert der im Messfenster aufgebauten Verbindungen je Probe. */
    public const MAX_CONNECTIONS = 1000000;

    /** Hoechstwert einer gemeldeten Speichergroesse (Byte, 1 PiB). */
    public const MAX_MEMORY_BYTES = 1125899906842624;

    /** Ab dieser Zeit ohne Probe gelten die Werte als veraltet (Sekunden). */
    public const STALE_SECONDS = 300;

    /** Breite eines Zeitabschnitts des Verlaufs (Minuten). */
    public const HISTORY_BUCKET_MINUTES = 5;

    /** Hoechstzahl der Quellnetze im Verlauf. */
    public const HISTORY_SOURCES = 5;

    /**
     * @param \Closure():int|null $clock liefert den aktuellen Zeitstempel
     *                                    (Tests); ohne Angabe gilt time()
     * @param SettingsService|null $settings fuer die bekannten Quellnetze
     *                                      (Admin → System → Bekannte
     *                                      Quellnetze); ohne Angabe wird
     *                                      nichts zusammengefasst
     */
    public function __construct(
        private readonly AuthMetricsRepository $repository,
        private readonly ?\Closure $clock = null,
        private readonly ?SettingsService $settings = null
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
            $sample['ram'] === null ? null : $sample['ram']['percent'],
            $sample['ram'] === null ? null : $sample['ram']['used'],
            $sample['ram'] === null ? null : $sample['ram']['total'],
            $sample['connections'],
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
            $now,
            $this->knownNetworks()
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
            $now,
            $this->knownNetworks()
        );
    }

    /**
     * Bekannte Quellnetze aus den Einstellungen (Admin → System → Bekannte
     * Quellnetze). Leer, wenn keine eingetragen sind.
     *
     * @return list<string>
     */
    public function knownNetworks(): array
    {
        if ($this->settings === null) {
            return [];
        }

        return SourceNetworks::fromSetting($this->settings->get('auth_known_source_networks'));
    }

    /**
     * Wirkung der bekannten Quellnetze auf die juengste Probe: je gemeldetem
     * Quellnetz die Zahl der Anfragen und das bekannte Netz, in dem es liegt
     * (null = bleibt einzeln), dazu die zusammengefasste Anzeige wie auf der
     * Karte. Grundlage fuer die Vorschau im Adminbereich.
     *
     * @return array{recorded_at:?string,rows:list<array{network:string,count:int,target:?string}>,merged:list<array{network:string,count:int,share:float}>}
     */
    public function groupingPreview(): array
    {
        $known = $this->knownNetworks();
        $rows = $this->repository->window(
            date('Y-m-d H:i:s', $this->now() - self::RETENTION_HOURS * 3600)
        );
        if ($rows === []) {
            return ['recorded_at' => null, 'rows' => [], 'merged' => []];
        }

        $latest = $rows[count($rows) - 1];
        $preview = [];
        foreach (self::rawSources($latest) as $network => $count) {
            $preview[] = [
                'network' => $network,
                'count' => $count,
                'target' => SourceNetworks::group($network, $known),
            ];
        }

        usort(
            $preview,
            static fn (array $left, array $right): int => $right['count'] <=> $left['count']
                ?: strcmp($left['network'], $right['network'])
        );

        return [
            'recorded_at' => Dates::formatDateTime($latest['recorded_at']),
            'rows' => $preview,
            'merged' => self::sourcesOf($latest, $known),
        ];
    }

    /**
     * Prueft und normalisiert eine Probe des auth-Containers.
     *
     * Erwartet cpu_percent, cpu_limit, connections und sources; sources ist ein
     * JSON-Objekt (Quellnetz => Anfragen) oder ein Array. ram_percent,
     * ram_used und ram_total sind optional (aeltere Fassung des Messskripts);
     * sie gelten nur gemeinsam.
     *
     * @param array<string,mixed> $payload
     * @return array{cpu_percent:float,cpu_limit:float,ram:?array{percent:float,used:int,total:int},connections:int,sources:array<string,int>}
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

        $connections = self::count($payload['connections'] ?? null);
        if ($connections === null) {
            $errors['connections'] = 'Die Zahl der Verbindungen muss eine ganze Zahl sein.';
        }

        $sources = self::sources($payload['sources'] ?? null);
        if ($sources === null) {
            $errors['sources'] = 'Die Quellnetze muessen als Objekt aus Netz und Anzahl uebergeben werden.';
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
            'cpu_percent' => (float) $cpuPercent,
            'cpu_limit' => (float) $cpuLimit,
            'ram' => $ram === false ? null : $ram,
            'connections' => (int) $connections,
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
     * @param list<array{recorded_at:string,cpu_percent:float,cpu_limit:float,ram_percent:?float,ram_used:?int,ram_total:?int,connections:int,sources:?string}> $rows
     * @param list<string> $knownNetworks bekannte Quellnetze (siehe SourceNetworks)
     * @return array<string,mixed>
     */
    public static function buildCard(array $rows, int $now, array $knownNetworks = []): array
    {
        if ($rows === []) {
            return [
                'available' => false,
                'samples' => 0,
                'stale' => true,
                'recorded_at' => null,
                'cpu' => null,
                'ram' => null,
                'tcp' => null,
                'sources' => [],
                'source_total' => 0,
            ];
        }

        $latest = $rows[count($rows) - 1];
        $latestAt = strtotime($latest['recorded_at']);
        $cpuFrom = $now - self::CPU_WINDOW_HOURS * 3600;
        $ramFrom = $now - self::RAM_WINDOW_HOURS * 3600;
        $tcpFrom = $now - self::TCP_WINDOW_HOURS * 3600;

        $cpuValues = [];
        $cpuPeak = null;
        $cpuPeakAt = null;
        $ramValues = [];
        $ramPeak = null;
        $ramPeakAt = null;
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

            if ($at >= $ramFrom) {
                $ramPercent = $row['ram_percent'] ?? null;
                if ($ramPercent !== null) {
                    $ramValues[] = $ramPercent;
                    if ($ramPeak === null || $ramPercent > $ramPeak) {
                        $ramPeak = $ramPercent;
                        $ramPeakAt = $at;
                    }
                }
            }

            if ($at >= $tcpFrom) {
                $tcpValues[] = $row['connections'];
            }
        }

        $sources = self::sourcesOf($latest, $knownNetworks);
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
            'ram' => self::ramCard($rows, $ramValues, $ramPeak, $ramPeakAt),
            'tcp' => [
                'connections' => $latest['connections'],
                'peak' => $tcpValues === [] ? $latest['connections'] : max($tcpValues),
                'avg' => round(self::average($tcpValues), 1),
                'window' => self::TCP_WINDOW_HOURS,
            ],
            'sources' => $sources,
            'source_total' => $sourceTotal,
        ];
    }

    /**
     * Arbeitsspeicher-Anzeige: letzter gemessener Wert (auch wenn die juengste
     * Probe keinen Speicher meldet) mit Spitze und Mittel des Zeitfensters.
     * null, wenn keine Probe Speicherwerte enthaelt (aeltere Fassung von
     * docker/auth/metrics.py).
     *
     * @param list<array{recorded_at:string,cpu_percent:float,cpu_limit:float,ram_percent:?float,ram_used:?int,ram_total:?int,connections:int,sources:?string}> $rows
     * @param list<float> $values
     * @return array<string,mixed>|null
     */
    private static function ramCard(array $rows, array $values, ?float $peak, ?int $peakAt): ?array
    {
        $latest = null;
        for ($index = count($rows) - 1; $index >= 0; $index--) {
            if (($rows[$index]['ram_percent'] ?? null) !== null) {
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
            'used' => $latest['ram_used'] ?? null,
            'total' => $latest['ram_total'] ?? null,
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
     * Verlauf der Aufbewahrung als gleichmaessige Zeitabschnitte.
     *
     * Der Verlauf beginnt an einer Abschnittsgrenze und endet mit dem
     * laufenden Abschnitt; fehlende Proben bleiben leer (Luecke), sie zaehlen
     * nicht als Nullwert. Jeder Abschnitt traegt das Mittel seiner Proben.
     *
     * @param list<array{recorded_at:string,cpu_percent:float,cpu_limit:float,ram_percent:?float,ram_used:?int,ram_total:?int,connections:int,sources:?string}> $rows
     * @param list<string> $knownNetworks bekannte Quellnetze (siehe SourceNetworks)
     * @return array<string,mixed>
     */
    public static function buildHistory(array $rows, int $now, array $knownNetworks = []): array
    {
        $bucket = self::HISTORY_BUCKET_MINUTES * 60;
        $buckets = intdiv(self::RETENTION_HOURS * 3600, $bucket);
        $end = intdiv($now, $bucket) * $bucket + $bucket;
        $start = $end - $buckets * $bucket;

        $cpuBuckets = array_fill(0, $buckets, []);
        $ramBuckets = array_fill(0, $buckets, []);
        $tcpBuckets = array_fill(0, $buckets, []);
        $sourceBuckets = [];
        $cpuValues = [];
        $ramValues = [];
        $tcpValues = [];
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
            $tcpBuckets[$index][] = $row['connections'];
            $cpuValues[] = $row['cpu_percent'];
            $tcpValues[] = $row['connections'];

            if ($cpuPeak === null || $row['cpu_percent'] > $cpuPeak) {
                $cpuPeak = $row['cpu_percent'];
                $cpuPeakAt = $at;
            }

            $ramPercent = $row['ram_percent'] ?? null;
            if ($ramPercent !== null) {
                $ramBuckets[$index][] = $ramPercent;
                $ramValues[] = $ramPercent;

                if ($ramPeak === null || $ramPercent > $ramPeak) {
                    $ramPeak = $ramPercent;
                    $ramPeakAt = $at;
                }
            }

            foreach (self::sourcesOf($row, $knownNetworks) as $source) {
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
            'ram' => [
                'values' => self::bucketsAverage($ramBuckets),
                'peak' => round((float) $ramPeak, 1),
                'peak_at' => $ramPeakAt === null
                    ? null
                    : Dates::formatDateTime(date('Y-m-d H:i:s', $ramPeakAt)),
                'avg' => round(self::average($ramValues), 1),
                'window' => self::RAM_WINDOW_HOURS,
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
     * Verteilung der Anfragen der letzten Probe, groesste Gruppe zuerst.
     * Quellnetze, die in einem bekannten Quellnetz liegen, werden zu diesem
     * zusammengefasst (SourceNetworks::merge).
     *
     * @param array{recorded_at:string,cpu_percent:float,cpu_limit:float,connections:int,sources:?string} $latest
     * @param list<string> $knownNetworks
     * @return list<array{network:string,count:int,share:float}>
     */
    private static function sourcesOf(array $latest, array $knownNetworks = []): array
    {
        $decoded = self::rawSources($latest);
        if ($decoded === []) {
            return [];
        }

        $sources = [];
        foreach (SourceNetworks::merge($decoded, $knownNetworks) as $network => $count) {
            $sources[] = ['network' => (string) $network, 'count' => $count];
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

    /**
     * Quellnetze einer Probe ohne Zusammenfassung.
     *
     * @param array{sources:?string} $row
     * @return array<string,int>
     */
    private static function rawSources(array $row): array
    {
        $raw = $row['sources'] ?? null;
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
            $sources[$network] = max(0, (int) $count);
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

        if ($percent === null || $used === null || $total === null) {
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
