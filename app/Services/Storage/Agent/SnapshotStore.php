<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Ablage der Vorgaengerversionen auf dem Snapshot-Speicher (eigene
 * SMB-Freigabe, unabhaengig vom Cold-Tier):
 *
 *   <root>/.lanpa-snapshots.json                    Kennung der Installation
 *   <root>/versions/<quelle>/<xx>/<uid>/data        Inhalt der Version (unveraenderlich)
 *   <root>/versions/<quelle>/<xx>/<uid>/meta.json   Beschreibung (Pfad, Benutzer, Zeit, SHA-256 ...)
 *
 * Die Kennung <uid> ist aus Quelle, Pfad, Katalogversion, Groesse und mtime
 * abgeleitet (deterministisch, daher ohne Dubletten) und enthaelt keine
 * Benutzerpfade – es gibt keine Pfadbestandteile aus Benutzereingaben im
 * Dateisystem. meta.json wird zuletzt geschrieben: Nur Versionen mit
 * meta.json gelten als vollstaendig.
 */
final class SnapshotStore
{
    public const MARKER = '.lanpa-snapshots.json';
    public const DIR = 'versions';
    public const DATA = 'data';
    public const META = 'meta.json';

    public function __construct(
        private readonly string $root,
        private readonly FileCopier $copier
    ) {
    }

    public function root(): string
    {
        return $this->root;
    }

    public static function uid(string $source, string $path, int $version, int $size, int $mtime): string
    {
        return sha1($source . "\0" . $path . "\0" . $version . "\0" . $size . "\0" . $mtime);
    }

    public static function validUid(string $uid): bool
    {
        return preg_match('/^[a-f0-9]{40}$/', $uid) === 1;
    }

    /**
     * Freigabe eingebunden und als Snapshot-Speicher gekennzeichnet?
     */
    public function available(): bool
    {
        clearstatcache(true, $this->root . '/' . self::MARKER);

        return is_file($this->root . '/' . self::MARKER);
    }

    /**
     * Kennung anlegen bzw. pruefen. Liefert eine Fehlermeldung oder null.
     */
    public function prepare(string $instanceId): ?string
    {
        $file = $this->root . '/' . self::MARKER;
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            $instance = is_array($data) ? (string) ($data['instance'] ?? '') : '';
            if ($instance !== '' && $instance !== $instanceId) {
                return 'Die Freigabe enthält Dateiversionen einer anderen Installation (Kennung ' . substr($instance, 0, 8) . '…).';
            }
            if ($instance !== '') {
                return null;
            }
        }
        $data = ['instance' => $instanceId, 'created_at' => gmdate('c'), 'kind' => 'snapshots'];
        if (@file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            return 'Auf der Freigabe kann nicht geschrieben werden (Schreibrechte prüfen).';
        }

        return null;
    }

    public function dir(string $source, string $uid): string
    {
        return $this->root . '/' . self::DIR . '/' . $source . '/' . substr($uid, 0, 2) . '/' . $uid;
    }

    public function dataPath(string $source, string $uid): string
    {
        return $this->dir($source, $uid) . '/' . self::DATA;
    }

    public function metaPath(string $source, string $uid): string
    {
        return $this->dir($source, $uid) . '/' . self::META;
    }

    /**
     * Vollstaendig gesichert (Inhalt und Beschreibung vorhanden)?
     */
    public function exists(string $source, string $uid): bool
    {
        clearstatcache(true, $this->metaPath($source, $uid));

        return is_file($this->metaPath($source, $uid)) && is_file($this->dataPath($source, $uid));
    }

    /**
     * Sichert eine Version atomar: Inhalt in eine temporaere Datei (fsync,
     * SHA-256, rename), danach die Beschreibung. Bricht die Uebertragung ab,
     * bleibt hoechstens eine temporaere Datei zurueck (siehe cleanupTemp()).
     *
     * @param array<string,mixed> $meta
     *
     * @return array{bytes:int,sha256:string}
     *
     * @throws RuntimeException
     */
    public function write(string $from, string $source, string $uid, array $meta, int $readCounter, ?string $expectedSha = null): array
    {
        if (!$this->available()) {
            throw new RuntimeException('Snapshot-Speicher nicht erreichbar.');
        }
        $dir = $this->dir($source, $uid);
        FileCopier::ensureDir($dir);
        $data = $this->dataPath($source, $uid);
        $temp = $dir . '/.' . self::DATA . '.' . bin2hex(random_bytes(4)) . PathRules::TEMP_SUFFIX;
        $copy = $this->copier->copy($from, $data, (int) ($meta['mtime'] ?? 0), $readCounter, Catalog::SNAPSHOT, null, $temp, $expectedSha);
        $meta += ['id' => $uid, 'source' => $source, 'format' => 1];
        $meta['size'] = $copy['bytes'];
        $meta['sha256'] = $copy['sha256'];
        $meta['stored_at'] = gmdate('c');
        $this->writeJson($this->metaPath($source, $uid), $meta);

        return $copy;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function readMeta(string $source, string $uid): ?array
    {
        $raw = @file_get_contents($this->metaPath($source, $uid));
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Inhalt unversehrt (Groesse und SHA-256 stimmen)?
     */
    public function verify(string $source, string $uid, string $sha, int $size): bool
    {
        $file = $this->dataPath($source, $uid);
        clearstatcache(true, $file);
        if (!is_file($file) || (int) filesize($file) !== $size) {
            return false;
        }

        try {
            return hash_equals($sha, $this->copier->hash($file, Catalog::SNAPSHOT));
        } catch (RuntimeException) {
            return false;
        }
    }

    public function remove(string $source, string $uid): void
    {
        $dir = $this->dir($source, $uid);
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
        @rmdir(dirname($dir));
    }

    /**
     * Entfernt liegen gebliebene temporaere Dateien abgebrochener Sicherungen.
     */
    public function cleanupTemp(int $olderThanSeconds = 3600): int
    {
        $base = $this->root . '/' . self::DIR;
        if (!is_dir($base)) {
            return 0;
        }
        $limit = time() - $olderThanSeconds;
        $removed = 0;
        try {
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($items as $item) {
                if ($item->isFile() && str_ends_with($item->getFilename(), PathRules::TEMP_SUFFIX) && $item->getMTime() < $limit && @unlink((string) $item)) {
                    $removed++;
                }
            }
        } catch (\UnexpectedValueException) {
            // Verzeichnis waehrenddessen verschwunden (Freigabe getrennt).
        }

        return $removed;
    }

    /**
     * Alle vollstaendigen Versionen (Beschreibungen) – zum Neuaufbau des Katalogs.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $base = $this->root . '/' . self::DIR;
        if (!is_dir($base)) {
            return [];
        }
        $result = [];
        try {
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($items as $item) {
                if (!$item->isFile() || $item->getFilename() !== self::META) {
                    continue;
                }
                $data = json_decode((string) @file_get_contents((string) $item), true);
                if (is_array($data) && self::validUid((string) ($data['id'] ?? '')) && is_file(dirname((string) $item) . '/' . self::DATA)) {
                    $result[] = $data;
                }
            }
        } catch (\UnexpectedValueException) {
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeJson(string $file, array $data): void
    {
        $temp = $file . '.' . bin2hex(random_bytes(4)) . PathRules::TEMP_SUFFIX;
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($temp, $json) === false) {
            @unlink($temp);
            throw new RuntimeException('Beschreibung kann nicht geschrieben werden: ' . $file);
        }
        if (!@rename($temp, $file)) {
            @unlink($temp);
            throw new RuntimeException('Beschreibung kann nicht abgelegt werden: ' . $file);
        }
    }
}
