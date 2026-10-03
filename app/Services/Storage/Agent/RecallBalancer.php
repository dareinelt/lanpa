<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

/**
 * Lastverteilung der Rueckholung: Sind mehrere Cold-Tiers aktiv, erreichbar
 * und haben die Datei, werden sie gleichberechtigt (active-active) genutzt.
 *
 * Bewertung je Tier (kleiner = besser), jeder Anteil auf 0..1 normiert
 * (bezogen auf den hoechsten Wert der Kandidaten, mindestens die *_FLOOR-Werte,
 * damit Leerlaufrauschen keine Rolle spielt):
 *
 *   Datenrate (lesen + schreiben) + IOPS (lesen + schreiben) + Latenz
 *   + INFLIGHT_WEIGHT je laufender Rueckholung von diesem Tier
 *
 * Fair use: Unter den Tiers, deren Bewertung weniger als SKIP_TOLERANCE ueber
 * der besten liegt, kommt das am laengsten nicht gewaehlte zum Zug (Reihum;
 * eine deutlich staerker ausgelastete oder langsamere Alternative bleibt
 * aussen vor).
 *
 * Laufende Rueckholungen und die Reihenfolge der letzten Wahlen teilen sich
 * alle recall-one-Prozesse ueber eine Zustandsdatei (mit flock geschuetzt).
 */
final class RecallBalancer
{
    public const SKIP_TOLERANCE = 0.5;
    public const INFLIGHT_WEIGHT = 0.5;
    public const BPS_FLOOR = 50 * 1048576;
    public const IOPS_FLOOR = 200.0;
    public const LATENCY_FLOOR_MS = 20.0;
    public const UNKNOWN_LATENCY_FACTOR = 1.5;

    /** Eintraege laufender Rueckholungen gelten danach als verwaist (s). */
    private const STALE_SECONDS = 21600;

    public function __construct(private readonly ?string $file = null, private readonly ?\Closure $clock = null)
    {
    }

    /**
     * Sortiert die Kandidaten und vermerkt den ersten als laufende Rueckholung.
     *
     * @param list<array{id:int,primary?:bool,bps?:int,iops?:float,latency_ms?:float|null}> $candidates
     *
     * @return array{order:list<array<string,mixed>>,token:string} order: Kandidaten mit "score", beste zuerst
     */
    public function acquire(array $candidates): array
    {
        $token = bin2hex(random_bytes(8));
        $order = [];
        $this->update(function (array $state) use ($candidates, $token, &$order): array {
            $order = self::rank($candidates, self::inflight($state), self::used($state));
            if ($order !== []) {
                $state = self::assign($state, $token, (int) $order[0]['id'], $this->now());
            }

            return $state;
        });

        return ['order' => $order, 'token' => $token];
    }

    /**
     * Rueckholung weicht auf ein anderes Tier aus (Datei dort fehlerhaft o. ae.).
     */
    public function switchTo(string $token, int $id): void
    {
        $this->update(fn (array $state): array => self::assign($state, $token, $id, $this->now()));
    }

    public function release(string $token): void
    {
        $this->update(static function (array $state) use ($token): array {
            unset($state['running'][$token]);

            return $state;
        });
    }

    /**
     * @param list<array{id:int,primary?:bool,bps?:int,iops?:float,latency_ms?:float|null}> $candidates
     * @param array<int,int> $inflight Laufende Rueckholungen je Tier
     * @param array<int,int> $used     Laufende Nummer der letzten Wahl je Tier (groesser = juenger)
     *
     * @return list<array<string,mixed>>
     */
    public static function rank(array $candidates, array $inflight = [], array $used = []): array
    {
        if ($candidates === []) {
            return [];
        }
        $known = array_values(array_filter(array_map(static fn (array $c): ?float => isset($c['latency_ms']) ? (float) $c['latency_ms'] : null, $candidates), static fn (?float $l): bool => $l !== null));
        $refBps = max((float) self::BPS_FLOOR, ...array_map(static fn (array $c): float => (float) ($c['bps'] ?? 0), $candidates));
        $refIops = max(self::IOPS_FLOOR, ...array_map(static fn (array $c): float => (float) ($c['iops'] ?? 0.0), $candidates));
        $refLatency = max(self::LATENCY_FLOOR_MS, ...($known !== [] ? $known : [0.0]));

        foreach ($candidates as $i => $candidate) {
            // Ohne Messung pessimistisch: schlechter als der langsamste gemessene Kandidat.
            $latency = isset($candidate['latency_ms']) ? (float) $candidate['latency_ms'] : $refLatency * self::UNKNOWN_LATENCY_FACTOR;
            $candidates[$i]['score'] = round(
                (float) ($candidate['bps'] ?? 0) / $refBps
                + (float) ($candidate['iops'] ?? 0.0) / $refIops
                + $latency / $refLatency
                + self::INFLIGHT_WEIGHT * ($inflight[(int) $candidate['id']] ?? 0),
                4
            );
        }
        usort($candidates, static fn (array $a, array $b): int => [$a['score'], empty($a['primary']), (int) $a['id']] <=> [$b['score'], empty($b['primary']), (int) $b['id']]);

        // Fair use: Unter den nahezu gleich bewerteten Tiers das am laengsten
        // nicht gewaehlte nach vorn (auch bei drei und mehr Tiers reihum).
        $pick = 0;
        foreach ($candidates as $i => $candidate) {
            if ($candidate['score'] - $candidates[0]['score'] >= self::SKIP_TOLERANCE) {
                break;
            }
            if (($used[(int) $candidate['id']] ?? 0) < ($used[(int) $candidates[$pick]['id']] ?? 0)) {
                $pick = $i;
            }
        }
        if ($pick > 0) {
            array_unshift($candidates, ...array_splice($candidates, $pick, 1));
        }

        return array_values($candidates);
    }

    /**
     * @param array<string,mixed> $state
     *
     * @return array<int,int>
     */
    private static function used(array $state): array
    {
        $result = [];
        foreach ((array) ($state['used'] ?? []) as $id => $seq) {
            $result[(int) $id] = (int) $seq;
        }
        if ($result === [] && isset($state['last'])) {
            // Stand vor Einfuehrung von "used".
            $result[(int) $state['last']] = 1;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $state
     *
     * @return array<int,int>
     */
    private static function inflight(array $state): array
    {
        $result = [];
        foreach ((array) ($state['running'] ?? []) as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            $result[$id] = ($result[$id] ?? 0) + 1;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $state
     *
     * @return array<string,mixed>
     */
    private static function assign(array $state, string $token, int $id, int $now): array
    {
        $state['running'][$token] = ['id' => $id, 'pid' => (int) getmypid(), 'started' => (int) ($state['running'][$token]['started'] ?? $now)];
        $state['used'] = self::used($state);
        $seq = max((int) ($state['seq'] ?? 0), ...($state['used'] !== [] ? $state['used'] : [0])) + 1;
        $state['seq'] = $seq;
        $state['used'][$id] = $seq;
        unset($state['last']);

        return $state;
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $change
     */
    private function update(callable $change): void
    {
        if ($this->file === null) {
            // Ohne Zustandsdatei (Tests, Hilfsbefehle): nur nach aktueller Last.
            $change([]);

            return;
        }
        try {
            FileCopier::ensureDir(dirname($this->file));
        } catch (\RuntimeException) {
            $change([]);

            return;
        }
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            $change([]);

            return;
        }
        try {
            flock($handle, LOCK_EX);
            $state = json_decode((string) stream_get_contents($handle), true);
            $state = $this->prune(is_array($state) ? $state : []);
            $state = $change($state);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($state, JSON_UNESCAPED_SLASHES));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Entfernt Eintraege beendeter oder verwaister Rueckholungen.
     *
     * @param array<string,mixed> $state
     *
     * @return array<string,mixed>
     */
    private function prune(array $state): array
    {
        $running = [];
        $proc = is_dir('/proc/self');
        foreach (is_array($state['running'] ?? null) ? $state['running'] : [] as $token => $entry) {
            if (!is_array($entry) || $this->now() - (int) ($entry['started'] ?? 0) > self::STALE_SECONDS) {
                continue;
            }
            if ($proc && (int) ($entry['pid'] ?? 0) > 0 && !file_exists('/proc/' . (int) $entry['pid'])) {
                continue;
            }
            $running[(string) $token] = $entry;
        }
        $state['running'] = $running;

        return $state;
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
