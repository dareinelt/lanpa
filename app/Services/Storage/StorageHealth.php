<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Bewertung von HA- und Synchronisationsstatus sowie Hochrechnung des lokalen
 * Speicherbedarfs. Reine Funktionen – genutzt vom Adminbereich (live, auch bei
 * ausgefallenem Agenten) und vom Container storage-sync (Wert fuer SNMP).
 */
final class StorageHealth
{
    /** Ohne Lebenszeichen des Monitors gilt storage-sync als ausgefallen. */
    public const HEARTBEAT_STALE_SECONDS = 90;

    /** Die Synchronisation meldet sich auch waehrend langer Kopien regelmaessig. */
    public const SYNC_STALE_SECONDS = 300;

    /** Mindestzeitraum der Messwerte fuer eine Hochrechnung. */
    public const FORECAST_MIN_SPAN = 3600;

    /** Zeitraum der Messwerte fuer die Hochrechnung. */
    public const FORECAST_WINDOW = 7 * 86400;

    /** SNMP-/Nagios-Exit-Codes. */
    public const EXIT = ['ok' => 0, 'degraded' => 1, 'critical' => 2, 'disabled' => 3,
        'in_sync' => 0, 'syncing' => 0, 'lagging' => 1, 'paused' => 1, 'error' => 2, 'blocked' => 2];

    /**
     * @param list<array{id:int,label:string,active:bool,state:string,in_sync:bool,lag_seconds:int,frozen?:bool}> $targets
     * @param array{sync_state?:string,sync_message?:string,pending_files?:int,lag_seconds?:int} $status
     *
     * @return array{
     *   ha:array{state:string,message:string,exit:int},
     *   sync:array{state:string,message:string,exit:int},
     *   active:int,online:int,remote_unavailable:bool,partial:bool,offline_labels:list<string>,frozen_labels:list<string>,agent_running:bool
     * }
     */
    public static function evaluate(
        bool $enabled,
        array $targets,
        ?int $heartbeatAge,
        ?int $syncHeartbeatAge,
        array $status,
        int $lagWarnSeconds
    ): array {
        $active = array_values(array_filter($targets, static fn (array $t): bool => $t['active']));
        $online = array_values(array_filter($active, static fn (array $t): bool => $t['state'] === 'online'));
        $offlineLabels = array_values(array_map(
            static fn (array $t): string => $t['label'],
            array_filter($active, static fn (array $t): bool => $t['state'] !== 'online')
        ));
        $agentRunning = $heartbeatAge !== null && $heartbeatAge <= self::HEARTBEAT_STALE_SECONDS;

        $result = [
            'ha' => self::state('disabled', 'Speicher-Tiering ist nicht aktiviert.'),
            'sync' => self::state('disabled', 'Synchronisation ist nicht aktiv.'),
            'active' => count($active),
            'online' => $agentRunning ? count($online) : 0,
            'remote_unavailable' => false,
            'partial' => false,
            'offline_labels' => $offlineLabels,
            'frozen_labels' => [],
            'agent_running' => $agentRunning,
        ];

        if (!$enabled) {
            return $result;
        }
        if ($active === []) {
            $result['ha'] = self::state('disabled', 'Im Cold-Tier (SMB-/S3-Tier) ist kein aktives Speicherziel eingerichtet.');

            return $result;
        }

        if (!$agentRunning) {
            $result['ha'] = self::state('critical', 'Der Dienst storage-sync meldet sich nicht – es findet keine Synchronisation statt.');
            $result['sync'] = self::state('error', 'Der Dienst storage-sync meldet sich nicht.');
            $result['remote_unavailable'] = true;
            $result['offline_labels'] = array_map(static fn (array $t): string => $t['label'], $active);

            return $result;
        }

        if ($online === []) {
            $result['ha'] = self::state('critical', 'Cold-Tier (SMB-/S3-Tier) nicht erreichbar: ' . implode(', ', $offlineLabels) . '.');
            $result['remote_unavailable'] = true;
        } elseif (count($online) < count($active)) {
            $result['ha'] = self::state('degraded', sprintf(
                '%d von %d Speicherzielen des Cold-Tiers nicht erreichbar: %s.',
                count($active) - count($online),
                count($active),
                implode(', ', $offlineLabels)
            ));
            $result['partial'] = true;
        } else {
            $lagging = array_values(array_filter(
                $online,
                static fn (array $t): bool => !($t['frozen'] ?? false) && !$t['in_sync'] && $t['lag_seconds'] > $lagWarnSeconds
            ));
            if ($lagging !== []) {
                $result['ha'] = self::state('degraded', 'Nicht aktuell: ' . implode(', ', array_map(
                    static fn (array $t): string => $t['label'] . ' (Rückstand ' . self::formatDuration($t['lag_seconds']) . ')',
                    $lagging
                )) . '.');
            } elseif (count($active) === 1) {
                $result['ha'] = self::state('degraded', 'Nur ein Speicherziel im Cold-Tier – keine Redundanz außerhalb der VM.');
            } elseif (array_filter($online, static fn (array $t): bool => !($t['frozen'] ?? false) && !$t['in_sync']) !== []) {
                $result['ha'] = self::state('degraded', 'Alle Speicherziele sind erreichbar, aber noch nicht vollständig synchron.');
            } else {
                $result['ha'] = self::state('ok', sprintf('Alle %d Speicherziele des Cold-Tiers erreichbar und synchron.', count($active)));
            }
        }

        // Schutzziel bei einem Sicherheitsvorfall: schreibgeschuetzt, ohne Synchronisation.
        $frozen = array_values(array_map(
            static fn (array $t): string => $t['label'],
            array_filter($active, static fn (array $t): bool => (bool) ($t['frozen'] ?? false))
        ));
        if ($frozen !== [] && $result['ha']['state'] !== 'critical') {
            $message = 'Speicherziel ' . implode(', ', $frozen) . ' wegen Sicherheitsvorfall schreibgeschützt (keine Synchronisation).';
            $result['ha'] = self::state('degraded', $result['ha']['state'] === 'degraded' ? $message . ' ' . $result['ha']['message'] : $message);
        }
        $result['frozen_labels'] = $frozen;

        $syncState = (string) ($status['sync_state'] ?? '');
        $pending = (int) ($status['pending_files'] ?? 0);
        $lag = (int) ($status['lag_seconds'] ?? 0);
        if ($syncHeartbeatAge === null || $syncHeartbeatAge > self::SYNC_STALE_SECONDS) {
            $result['sync'] = self::state('error', 'Die Synchronisation meldet sich nicht.');
        } elseif ($syncState === 'error') {
            $result['sync'] = self::state('error', (string) ($status['sync_message'] ?? '') !== '' ? (string) $status['sync_message'] : 'Fehler bei der Synchronisation.');
        } elseif ($syncState === 'blocked') {
            $result['sync'] = self::state('blocked', (string) ($status['sync_message'] ?? '') !== '' ? (string) $status['sync_message'] : 'Löschungen warten auf Bestätigung.');
        } elseif ($syncState === 'paused') {
            $result['sync'] = self::state('paused', (string) ($status['sync_message'] ?? '') !== '' ? (string) $status['sync_message'] : 'Synchronisation ist angehalten (Wiederherstellung).');
        } elseif ($pending > 0 && ($lag > $lagWarnSeconds || $online === [])) {
            $result['sync'] = self::state('lagging', sprintf('%d Datei(en) ausstehend, Rückstand %s.', $pending, self::formatDuration($lag)));
        } elseif ($pending > 0) {
            $result['sync'] = self::state('syncing', sprintf('%d Datei(en) werden übertragen.', $pending));
        } else {
            $result['sync'] = self::state('in_sync', 'Alle Daten sind synchron.');
        }

        return $result;
    }

    /**
     * Hochrechnung: Wie lange reicht der Hot-Tier (lokales Storage), wenn neue
     * Daten (wie bisher) nur noch lokal abgelegt werden koennen? Grundlage ist das
     * Wachstum des gesamten Datenbestands (lokal + ausgelagert) – unabhaengig
     * davon, wie viel durch Auslagerung lokal frei gehalten wurde.
     *
     * @param list<array{0:int,1:int}> $samples [Zeitpunkt, Datenbestand in Byte]
     *
     * @return array{rate_per_day:?float,days_free:?float,days_limit:?float,until_free:?int,basis_seconds:int,growing:bool}
     */
    public static function forecast(array $samples, int $freeBytes, ?int $limitRemainingBytes, int $now): array
    {
        $result = ['rate_per_day' => null, 'days_free' => null, 'days_limit' => null, 'until_free' => null,
            'basis_seconds' => 0, 'growing' => false];

        $samples = array_values(array_filter(
            $samples,
            static fn (array $s): bool => $s[0] >= $now - self::FORECAST_WINDOW && $s[0] <= $now
        ));
        if (count($samples) < 2) {
            return $result;
        }
        $first = min(array_column($samples, 0));
        $span = max(array_column($samples, 0)) - $first;
        $result['basis_seconds'] = $span;
        if ($span < self::FORECAST_MIN_SPAN) {
            return $result;
        }

        // Lineare Regression (kleinste Quadrate), Zeit relativ zum ersten Wert.
        $n = count($samples);
        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach ($samples as [$t, $bytes]) {
            $x = (float) ($t - $first);
            $y = (float) $bytes;
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }
        $denominator = $n * $sumXX - $sumX * $sumX;
        if ($denominator <= 0.0) {
            return $result;
        }
        $slope = ($n * $sumXY - $sumX * $sumY) / $denominator;
        $perDay = $slope * 86400;
        $result['rate_per_day'] = $perDay;

        // Unter 1 MB/Tag gilt der Bestand als nicht wachsend.
        if ($perDay < StorageSettings::MIB) {
            return $result;
        }
        $result['growing'] = true;
        $result['days_free'] = max(0.0, $freeBytes / $perDay);
        $result['until_free'] = $now + (int) round($result['days_free'] * 86400);
        if ($limitRemainingBytes !== null) {
            $result['days_limit'] = max(0.0, $limitRemainingBytes / $perDay);
        }

        return $result;
    }

    /**
     * Fuellstand in Prozent und Bewertung (ok/degraded/critical).
     *
     * @return array{percent:?float,state:string}
     */
    public static function fill(int $total, int $free, int $warn, int $crit): array
    {
        if ($total <= 0) {
            return ['percent' => null, 'state' => 'disabled'];
        }
        $percent = round(100 * ($total - max(0, $free)) / $total, 1);

        return ['percent' => $percent, 'state' => $percent >= $crit ? 'critical' : ($percent >= $warn ? 'degraded' : 'ok')];
    }

    public static function formatBytes(int|float $bytes, int $decimals = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) max(0, $bytes);
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return number_format($value, $i === 0 ? 0 : $decimals, ',', '.') . ' ' . $units[$i];
    }

    public static function formatRate(int|float $bytesPerSecond): string
    {
        return number_format(max(0, $bytesPerSecond) / StorageSettings::MIB, 2, ',', '.') . ' MB/s';
    }

    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 120) {
            return $seconds . ' s';
        }
        if ($seconds < 7200) {
            return (int) round($seconds / 60) . ' min';
        }
        if ($seconds < 172800) {
            return number_format($seconds / 3600, 1, ',', '.') . ' h';
        }

        return number_format($seconds / 86400, 1, ',', '.') . ' Tage';
    }

    public static function formatDays(float $days): string
    {
        if ($days < 1) {
            $hours = max(0, (int) floor($days * 24));

            return $hours <= 1 ? 'weniger als 1 Stunde' : 'ca. ' . $hours . ' Stunden';
        }
        if ($days >= 3650) {
            return 'mehr als 10 Jahre';
        }

        return 'ca. ' . number_format($days, $days < 10 ? 1 : 0, ',', '.') . ' Tage';
    }

    /**
     * @return array{state:string,message:string,exit:int}
     */
    private static function state(string $state, string $message): array
    {
        return ['state' => $state, 'message' => $message, 'exit' => self::EXIT[$state] ?? 3];
    }
}
