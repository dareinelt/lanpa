<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use RuntimeException;

/**
 * Kopiert eine Datei blockweise ueber eine temporaere Datei (atomares
 * Ersetzen), berechnet dabei SHA-256, behaelt die Aenderungszeit bei und
 * zaehlt Bytes/Operationen fuer Datenrate und IOPS.
 */
final class FileCopier
{
    private const CHUNK = 1048576;

    /** @var array<int,array{0:int,1:int,2:int,3:int}> */
    private array $pending = [];

    private float $lastFlush = 0.0;

    public function __construct(private readonly ?Catalog $catalog = null)
    {
    }

    /**
     * @param int $readCounter Zaehler-ID der Quelle (0 = Hot-Tier, sonst Speicherziel)
     * @param int $writeCounter Zaehler-ID des Ziels
     * @param (callable(int):void)|null $progress
     * @param string|null $temp Temporaere Datei (Vorgabe: neben dem Ziel)
     *
     * @return array{bytes:int,sha256:string}
     */
    public function copy(
        string $from,
        string $to,
        int $mtime,
        int $readCounter,
        int $writeCounter,
        ?callable $progress = null,
        ?string $temp = null,
        ?string $expectedSha = null
    ): array {
        self::ensureDir(dirname($to));
        $temp ??= dirname($to) . '/.' . basename($to) . '.' . bin2hex(random_bytes(4)) . PathRules::TEMP_SUFFIX;
        $in = @fopen($from, 'rb');
        if ($in === false) {
            throw new RuntimeException('Quelle nicht lesbar: ' . $from);
        }
        $out = @fopen($temp, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Ziel nicht beschreibbar: ' . dirname($to));
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($in)) {
                $chunk = @fread($in, self::CHUNK);
                if ($chunk === false) {
                    throw new RuntimeException('Lesefehler: ' . $from);
                }
                if ($chunk === '') {
                    break;
                }
                $length = strlen($chunk);
                for ($written = 0; $written < $length;) {
                    $result = @fwrite($out, $written === 0 ? $chunk : substr($chunk, $written));
                    if ($result === false || $result === 0) {
                        throw new RuntimeException('Schreibfehler (Speicher voll oder Verbindung unterbrochen): ' . $to);
                    }
                    $written += $result;
                }
                hash_update($hash, $chunk);
                $bytes += $length;
                $this->count($readCounter, $length, 0, 1, 0);
                $this->count($writeCounter, 0, $length, 0, 1);
                if ($progress !== null) {
                    $progress($bytes);
                }
            }
            if (!@fflush($out) || (function_exists('fsync') && !@fsync($out))) {
                throw new RuntimeException('Daten konnten nicht vollstaendig geschrieben werden: ' . $to);
            }
        } catch (\Throwable $exception) {
            fclose($in);
            fclose($out);
            @unlink($temp);
            $this->flush(true);
            throw $exception instanceof RuntimeException ? $exception : new RuntimeException($exception->getMessage(), 0, $exception);
        }
        fclose($in);
        fclose($out);
        $this->flush(true);

        $sha = hash_final($hash);
        if ($expectedSha !== null && $expectedSha !== '' && !hash_equals($expectedSha, $sha)) {
            @unlink($temp);
            throw new RuntimeException('Prüfsumme stimmt nicht: ' . $from);
        }
        @touch($temp, $mtime);
        if (!@rename($temp, $to)) {
            @unlink($temp);
            throw new RuntimeException('Datei kann nicht ersetzt werden: ' . $to);
        }

        return ['bytes' => $bytes, 'sha256' => $sha];
    }

    /**
     * SHA-256 einer Datei (zaehlt als Lesezugriff).
     */
    public function hash(string $file, int $readCounter): string
    {
        $in = @fopen($file, 'rb');
        if ($in === false) {
            throw new RuntimeException('Datei nicht lesbar: ' . $file);
        }
        $hash = hash_init('sha256');
        while (!feof($in)) {
            $chunk = fread($in, self::CHUNK);
            if ($chunk === false || $chunk === '') {
                break;
            }
            hash_update($hash, $chunk);
            $this->count($readCounter, strlen($chunk), 0, 1, 0);
        }
        fclose($in);
        $this->flush(true);

        return hash_final($hash);
    }

    public function count(int $counter, int $readBytes, int $writeBytes, int $readOps, int $writeOps): void
    {
        $current = $this->pending[$counter] ?? [0, 0, 0, 0];
        $this->pending[$counter] = [$current[0] + $readBytes, $current[1] + $writeBytes, $current[2] + $readOps, $current[3] + $writeOps];
        $this->flush(false);
    }

    public function flush(bool $force): void
    {
        if ($this->catalog === null || $this->pending === [] || (!$force && microtime(true) - $this->lastFlush < 1.0)) {
            return;
        }
        $pending = $this->pending;
        $this->pending = [];
        $this->lastFlush = microtime(true);
        try {
            foreach ($pending as $counter => [$readBytes, $writeBytes, $readOps, $writeOps]) {
                $this->catalog->addCounters($counter, $readBytes, $writeBytes, $readOps, $writeOps);
            }
        } catch (\PDOException) {
            // Zaehler sind nur fuer die Anzeige; bei gesperrter Datenbank spaeter erneut.
            foreach ($pending as $counter => $values) {
                $this->pending[$counter] = $values;
            }
        }
    }

    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Verzeichnis kann nicht angelegt werden: ' . $dir);
        }
    }
}
