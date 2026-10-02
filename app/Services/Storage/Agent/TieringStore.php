<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use RuntimeException;

/**
 * Gemeinsames Verzeichnis von storage-sync und Nextcloud (Volume
 * storage_tiering, in beiden Containern unter /var/lib/lanpa-tiering):
 *
 *   config.json            Einstellungen fuer Nextcloud (Wartezeit, aktiv)
 *   agent.alive            Lebenszeichen der Rueckholung
 *   stubs/<pfad>.json      Kennzeichen ausgelagerter Dateien (Cold-Tier)
 *   recall/queue/<id>.json Rueckhol-Auftraege aus Nextcloud
 *   recall/status/<id>.json Fortschritt der Rueckholung (Fortschrittsbalken)
 *   access/access.log      Zugriffe ("<unix-zeit> <pfad>") fuer "haeufig genutzt"
 *
 * Eine ausgelagerte Datei bleibt im Hot-Tier als "sparse" Platzhalter gleicher
 * Groesse, Aenderungszeit und Rechte stehen (belegt keinen Speicher); Nextcloud
 * bemerkt die Auslagerung daher nicht. Pfade beziehen sich auf das
 * Nextcloud-Datenverzeichnis. Das Format ist mit
 * OCA\IntranetIntegration\Storage\TieringClient abgestimmt.
 */
final class TieringStore
{
    public const MARKER_SUFFIX = '.json';

    /**
     * @param array{0:int,1:int}|null $owner Besitzer (uid, gid) der Dateien, die Nextcloud schreiben darf
     */
    public function __construct(
        private readonly string $base,
        private readonly string $dataDir,
        private readonly ?array $owner = null
    ) {
    }

    public function base(): string
    {
        return $this->base;
    }

    public function dataDir(): string
    {
        return $this->dataDir;
    }

    public static function recallId(string $rel): string
    {
        return sha1($rel);
    }

    public function prepare(): void
    {
        foreach (['', '/stubs', '/recall', '/recall/queue', '/recall/status', '/access'] as $dir) {
            $path = $this->base . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0770, true);
            }
            @chmod($path, $dir === '/recall/status' || $dir === '' ? 0775 : 0770);
            $this->own($path);
        }
        $recall = $this->dataDir . '/' . PathRules::RECALL_DIR;
        if (!is_dir($recall)) {
            @mkdir($recall, 0750, true);
        }
    }

    /**
     * @param array<string,mixed> $config
     */
    public function writeConfig(array $config): void
    {
        $this->writeJson($this->base . '/config.json', $config, 0664);
    }

    public function heartbeat(): void
    {
        @touch($this->base . '/agent.alive');
        $this->own($this->base . '/agent.alive');
    }

    // --- Kennzeichen ausgelagerter Dateien ---------------------------------

    public function markerPath(string $rel): string
    {
        return $this->base . '/stubs/' . $rel . self::MARKER_SUFFIX;
    }

    public function hasMarker(string $rel): bool
    {
        return is_file($this->markerPath($rel));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function readMarker(string $rel): ?array
    {
        $raw = @file_get_contents($this->markerPath($rel));
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string,mixed> $marker
     */
    public function writeMarker(string $rel, array $marker): void
    {
        $path = $this->markerPath($rel);
        $this->ensureDir(dirname($path));
        $this->writeJson($path, $marker, 0660);
    }

    public function removeMarker(string $rel): void
    {
        $path = $this->markerPath($rel);
        @unlink($path);
        $this->pruneDirs(dirname($path), $this->base . '/stubs');
    }

    public function moveMarker(string $from, string $to): void
    {
        $source = $this->markerPath($from);
        if (!is_file($source)) {
            return;
        }
        $marker = $this->readMarker($from) ?? [];
        $marker['path'] = $to;
        $this->writeMarker($to, $marker);
        $this->removeMarker($from);
    }

    /**
     * Ist die Datei ein unveraenderter Platzhalter (sparse, Groesse wie Kennzeichen)?
     *
     * @param array<string,mixed>|null $marker
     */
    public function isStub(string $rel, ?array $marker = null): bool
    {
        $marker ??= $this->readMarker($rel);
        if ($marker === null) {
            return false;
        }
        clearstatcache(true, $this->dataDir . '/' . $rel);
        $stat = @stat($this->dataDir . '/' . $rel);

        return $stat !== false && (int) $stat['size'] === (int) ($marker['size'] ?? -1)
            && (int) $stat['mtime'] === (int) ($marker['mtime'] ?? -1) && self::allocated($stat) < 4096;
    }

    /**
     * Ersetzt eine Datei im Hot-Tier durch einen Platzhalter. Liefert den neuen
     * Inode oder null, wenn sich die Datei inzwischen geaendert hat.
     *
     * @param array<string,mixed> $marker
     */
    public function makeStub(string $rel, array $marker): ?int
    {
        $abs = $this->dataDir . '/' . $rel;
        clearstatcache(true, $abs);
        $before = @stat($abs);
        if ($before === false || (int) $before['size'] !== (int) $marker['size'] || (int) $before['mtime'] !== (int) $marker['mtime']) {
            return null;
        }

        $this->writeMarker($rel, $marker);
        $temp = dirname($abs) . '/.' . basename($abs) . '.' . bin2hex(random_bytes(4)) . PathRules::TEMP_SUFFIX;
        $handle = @fopen($temp, 'xb');
        if ($handle === false) {
            $this->removeMarker($rel);
            throw new RuntimeException('Platzhalter kann nicht angelegt werden: ' . $rel);
        }
        ftruncate($handle, (int) $marker['size']);
        fclose($handle);
        @chmod($temp, $before['mode'] & 07777);
        @chown($temp, (int) $before['uid']);
        @chgrp($temp, (int) $before['gid']);
        touch($temp, (int) $before['mtime']);

        clearstatcache(true, $abs);
        $now = @stat($abs);
        if ($now === false || $now['ino'] !== $before['ino'] || $now['mtime'] !== $before['mtime'] || $now['size'] !== $before['size']) {
            @unlink($temp);
            $this->removeMarker($rel);

            return null;
        }
        if (!@rename($temp, $abs)) {
            @unlink($temp);
            $this->removeMarker($rel);
            throw new RuntimeException('Platzhalter kann nicht eingesetzt werden: ' . $rel);
        }
        clearstatcache(true, $abs);
        $stat = stat($abs);

        return (int) $stat['ino'];
    }

    /**
     * Unterstuetzt das Dateisystem des Hot-Tiers Platzhalter ohne Speicherbelegung?
     */
    public function sparseSupported(): bool
    {
        $probe = $this->dataDir . '/' . PathRules::RECALL_DIR . '/.sparse-probe';
        @mkdir(dirname($probe), 0750, true);
        $handle = @fopen($probe, 'wb');
        if ($handle === false) {
            return false;
        }
        ftruncate($handle, 8 * 1048576);
        fclose($handle);
        clearstatcache(true, $probe);
        $stat = @stat($probe);
        @unlink($probe);

        return $stat !== false && self::allocated($stat) < 1048576;
    }

    /**
     * Tatsaechlich belegter Speicher (Bloecke zu 512 Byte).
     *
     * @param array<int|string,int> $stat
     */
    public static function allocated(array $stat): int
    {
        $blocks = (int) ($stat['blocks'] ?? -1);

        return $blocks < 0 ? (int) $stat['size'] : $blocks * 512;
    }

    // --- Rueckholung --------------------------------------------------------

    /**
     * @return list<string>
     */
    public function queuedIds(): array
    {
        $ids = [];
        foreach (glob($this->base . '/recall/queue/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if (preg_match('/^[a-f0-9]{40}$/', $id) === 1) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function request(string $id): ?array
    {
        $data = json_decode((string) @file_get_contents($this->base . '/recall/queue/' . $id . '.json'), true);

        return is_array($data) && isset($data['path']) && is_string($data['path']) ? $data : null;
    }

    /**
     * Rueckholung aus dem Agenten selbst (z. B. nach Erweiterung des Hot-Tiers).
     */
    public function enqueue(string $rel, string $uid = ''): string
    {
        $id = self::recallId($rel);
        $file = $this->base . '/recall/queue/' . $id . '.json';
        if (!is_file($file)) {
            $this->writeJson($file, ['path' => $rel, 'uid' => $uid, 'requested_at' => time()], 0660);
        }

        return $id;
    }

    public function dequeue(string $id): void
    {
        @unlink($this->base . '/recall/queue/' . $id . '.json');
    }

    /**
     * @param array<string,mixed> $status
     */
    public function writeStatus(string $id, array $status): void
    {
        $this->writeJson($this->base . '/recall/status/' . $id . '.json', $status + ['updated' => time()], 0664);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function status(string $id): ?array
    {
        $data = json_decode((string) @file_get_contents($this->base . '/recall/status/' . $id . '.json'), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Entfernt abgeschlossene Statusdateien nach einer Weile.
     */
    public function cleanupStatus(int $maxAge = 600): void
    {
        $limit = time() - $maxAge;
        foreach (glob($this->base . '/recall/status/*.json') ?: [] as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            $state = is_array($data) ? (string) ($data['state'] ?? '') : '';
            $updated = is_array($data) ? (int) ($data['updated'] ?? 0) : 0;
            if (($state === 'done' || $state === 'failed' || $state === '') && $updated < $limit) {
                @unlink($file);
            }
        }
    }

    // --- Zugriffe -----------------------------------------------------------

    /**
     * Uebernimmt das Zugriffsprotokoll und liefert [Pfad => letzte Zeit].
     *
     * @return array<string,int>
     */
    public function takeAccessLog(): array
    {
        $log = $this->base . '/access/access.log';
        if (!is_file($log) || filesize($log) === 0) {
            return [];
        }
        $taken = $log . '.' . getmypid() . '.work';
        if (!@rename($log, $taken)) {
            return [];
        }
        $result = [];
        $handle = @fopen($taken, 'rb');
        if ($handle !== false) {
            while (($line = fgets($handle)) !== false) {
                $parts = explode(' ', rtrim($line, "\r\n"), 2);
                if (count($parts) === 2 && ctype_digit($parts[0]) && $parts[1] !== '') {
                    $result[$parts[1]] = max($result[$parts[1]] ?? 0, (int) $parts[0]);
                }
            }
            fclose($handle);
        }
        @unlink($taken);

        return $result;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeJson(string $file, array $data, int $mode): void
    {
        $this->ensureDir(dirname($file));
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            throw new RuntimeException('Datei kann nicht geschrieben werden: ' . $file);
        }
        @chmod($temp, $mode);
        $this->own($temp);
        if (!@rename($temp, $file)) {
            @unlink($temp);
            throw new RuntimeException('Datei kann nicht ersetzt werden: ' . $file);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        $missing = [];
        for ($current = $dir; !is_dir($current) && $current !== dirname($current); $current = dirname($current)) {
            $missing[] = $current;
        }
        foreach (array_reverse($missing) as $path) {
            if (!@mkdir($path, 0770) && !is_dir($path)) {
                throw new RuntimeException('Verzeichnis kann nicht angelegt werden: ' . $path);
            }
            $this->own($path);
        }
    }

    private function pruneDirs(string $dir, string $stop): void
    {
        while ($dir !== $stop && str_starts_with($dir, $stop . '/') && @rmdir($dir)) {
            $dir = dirname($dir);
        }
    }

    private function own(string $path): void
    {
        if ($this->owner !== null && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($path, $this->owner[0]);
            @chgrp($path, $this->owner[1]);
        }
    }
}
