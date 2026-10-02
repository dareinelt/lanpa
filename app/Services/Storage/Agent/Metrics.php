<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

/**
 * Datenrate (Byte/s) und IOPS aus kumulierten Zaehlern:
 *
 * - Hot-Tier: Blockgeraet des Nextcloud-Datenverzeichnisses
 *   (/sys/dev/block/MAJ:MIN/stat, enthaelt alle Zugriffe auf den Datentraeger).
 * - Cold-Tier: CIFS-Statistik des Kernels je Freigabe (/proc/fs/cifs/Stats).
 * - Ersatzweise die Zaehler von storage-sync selbst (nur eigene Uebertragungen).
 */
final class Metrics
{
    /** @var array<string,array{t:float,c:array{0:int,1:int,2:int,3:int}}> */
    private array $previous = [];

    /**
     * @param array{0:int,1:int,2:int,3:int} $counters [Bytes gelesen, Bytes geschrieben, Lesevorgaenge, Schreibvorgaenge]
     *
     * @return array{read_bps:int,write_bps:int,read_iops:float,write_iops:float}
     */
    public function rates(string $key, array $counters, ?float $now = null): array
    {
        $now ??= microtime(true);
        $previous = $this->previous[$key] ?? null;
        $this->previous[$key] = ['t' => $now, 'c' => $counters];
        $zero = ['read_bps' => 0, 'write_bps' => 0, 'read_iops' => 0.0, 'write_iops' => 0.0];
        if ($previous === null) {
            return $zero;
        }
        $elapsed = $now - $previous['t'];
        if ($elapsed <= 0.0) {
            return $zero;
        }
        $delta = [];
        foreach ($counters as $i => $value) {
            // Zaehler zurueckgesetzt (Neueinbindung, Neustart): diesen Messpunkt verwerfen.
            if ($value < $previous['c'][$i]) {
                return $zero;
            }
            $delta[$i] = $value - $previous['c'][$i];
        }

        return [
            'read_bps' => (int) round($delta[0] / $elapsed),
            'write_bps' => (int) round($delta[1] / $elapsed),
            'read_iops' => round($delta[2] / $elapsed, 2),
            'write_iops' => round($delta[3] / $elapsed, 2),
        ];
    }

    /**
     * Zaehler des Blockgeraets, auf dem ein Pfad liegt.
     *
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    public static function blockCounters(string $path, string $sys = '/sys/dev/block'): ?array
    {
        $stat = @stat($path);
        if ($stat === false) {
            return null;
        }
        $dev = (int) $stat['dev'];
        $major = (($dev >> 8) & 0xfff) | (($dev >> 32) & ~0xfff);
        $minor = ($dev & 0xff) | (($dev >> 12) & ~0xff);
        $raw = @file_get_contents($sys . '/' . $major . ':' . $minor . '/stat');
        if ($raw === false) {
            return null;
        }

        return self::parseBlockStat($raw);
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    public static function parseBlockStat(string $raw): ?array
    {
        $fields = preg_split('/\s+/', trim($raw));
        if ($fields === false || count($fields) < 7) {
            return null;
        }

        // Sektoren zu 512 Byte (unabhaengig von der tatsaechlichen Sektorgroesse).
        return [(int) $fields[2] * 512, (int) $fields[6] * 512, (int) $fields[0], (int) $fields[4]];
    }

    /**
     * CIFS-Zaehler je Freigabe ("host\share", Kleinschreibung).
     *
     * @return array<string,array{0:int,1:int,2:int,3:int}>
     */
    public static function parseCifsStats(string $raw): array
    {
        $result = [];
        $current = null;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^\s*\d+\)\s+\\\\\\\\([^\\\\\s]+)\\\\([^\\\\\s]+)/', $line, $match) === 1) {
                $current = strtolower($match[1] . '\\' . $match[2]);
                $result[$current] ??= [0, 0, 0, 0];
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/Bytes read:\s*(\d+)\s+Bytes written:\s*(\d+)/i', $line, $match) === 1) {
                $result[$current][0] += (int) $match[1];
                $result[$current][1] += (int) $match[2];
            }
            if (preg_match('/^\s*Reads:\s*(\d+)/', $line, $match) === 1) {
                $result[$current][2] += (int) $match[1];
            }
            if (preg_match('/^\s*Writes:\s*(\d+)/', $line, $match) === 1) {
                $result[$current][3] += (int) $match[1];
            }
        }

        return $result;
    }

    /**
     * @return array<string,array{0:int,1:int,2:int,3:int}>
     */
    public static function cifsCounters(string $file = '/proc/fs/cifs/Stats'): array
    {
        $raw = @file_get_contents($file);

        return $raw === false ? [] : self::parseCifsStats($raw);
    }
}
