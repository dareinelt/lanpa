<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use App\Services\Storage\StorageSettings;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Wiederherstellung aus einem Speicherziel des Cold-Tiers (SMB-/S3-Tier) in den
 * Hot-Tier (lokales Storage). Die Nextcloud-Datenbank stellt
 * scripts/storage-restore.sh aus der mitgesicherten Datei wieder her.
 */
final class Restore
{
    /** @var \Closure(string):void */
    private \Closure $log;

    /**
     * @param array<string,string> $sources
     * @param callable(string):void $log
     */
    public function __construct(
        private readonly Catalog $catalog,
        private readonly TieringStore $store,
        private readonly FileCopier $copier,
        private readonly array $sources,
        private readonly StorageSettings $settings,
        callable $log
    ) {
        $this->log = \Closure::fromCallable($log);
    }

    public function targetInstance(string $root): ?string
    {
        $data = json_decode((string) @file_get_contents($root . '/' . PathRules::TARGET_MARKER), true);
        $instance = is_array($data) ? (string) ($data['instance'] ?? '') : '';

        return $instance === '' ? null : $instance;
    }

    /**
     * @param array{id:int,label:string,root:string} $target
     *
     * @return array{copied:int,stubbed:int,skipped:int,failed:int}
     */
    public function run(array $target, bool $full): array
    {
        $stats = ['copied' => 0, 'stubbed' => 0, 'skipped' => 0, 'failed' => 0];
        $sparse = $this->store->sparseSupported();
        $cutoff = time() - $this->settings->localDays() * 86400;
        $this->store->prepare();

        foreach ($this->sources as $source => $localRoot) {
            if ($source === PathRules::SOURCE_NEXTCLOUD_DB) {
                $remoteDump = $target['root'] . '/' . $source . '/nextcloud.dump';
                if (is_file($remoteDump)) {
                    FileCopier::ensureDir($localRoot);
                    $this->copier->copy($remoteDump, $localRoot . '/nextcloud.dump', (int) filemtime($remoteDump), $target['id'], Catalog::LOCAL);
                    ($this->log)('Datenbanksicherung bereitgestellt: ' . $localRoot . '/nextcloud.dump');
                }
                continue;
            }
            $remoteRoot = $target['root'] . '/' . $source;
            if (!is_dir($remoteRoot)) {
                continue;
            }
            FileCopier::ensureDir($localRoot);
            ($this->log)('Stelle ' . $source . ' wieder her …');
            $iterator = new RecursiveIteratorIterator(
                new ExcludeFilter(new RecursiveDirectoryIterator($remoteRoot, FilesystemIterator::SKIP_DOTS), $source, $remoteRoot),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iterator as $path => $info) {
                /** @var \SplFileInfo $info */
                if (!$info->isFile()) {
                    continue;
                }
                $rel = PathRules::relative($remoteRoot, (string) $path);
                if ($rel === null || PathRules::isExcluded($source, $rel)) {
                    continue;
                }
                $size = (int) $info->getSize();
                $mtime = (int) $info->getMTime();
                $local = $localRoot . '/' . $rel;
                $existing = @stat($local);
                if ($existing !== false && (int) $existing['size'] === $size && abs((int) $existing['mtime'] - $mtime) <= 1) {
                    $stats['skipped']++;
                    continue;
                }
                try {
                    if (!$full && $sparse && $source === PathRules::SOURCE_NEXTCLOUD_DATA && PathRules::isTiered($source, $rel)
                        && $size >= PathRules::MIN_TIER_SIZE && $mtime < $cutoff) {
                        $this->stub($rel, $local, $size, $mtime, $target['id']);
                        $stats['stubbed']++;
                    } else {
                        $this->copier->copy((string) $path, $local, $mtime, $target['id'], Catalog::LOCAL);
                        $this->store->removeMarker($rel);
                        $stats['copied']++;
                    }
                } catch (RuntimeException $exception) {
                    $stats['failed']++;
                    ($this->log)('Fehler: ' . $source . '/' . $rel . ' – ' . $exception->getMessage());
                }
                if (($stats['copied'] + $stats['stubbed']) % 1000 === 0) {
                    ($this->log)(sprintf('… %d kopiert, %d Platzhalter', $stats['copied'], $stats['stubbed']));
                }
            }
            // Besitzer wie das Wurzelverzeichnis der Quelle (www-data bzw. Euro-Office).
            Shell::run(['chown', '-R', '--reference=' . $localRoot, $localRoot], 0);
        }

        // Katalog neu aufbauen: Der naechste vollstaendige Abgleich uebernimmt
        // gleiche Dateien auf allen Zielen ohne erneute Uebertragung.
        $this->catalog->reset();

        return $stats;
    }

    private function stub(string $rel, string $local, int $size, int $mtime, int $targetId): void
    {
        $this->store->writeMarker($rel, [
            'path' => $rel,
            'size' => $size,
            'mtime' => $mtime,
            'sha256' => null,
            'version' => 1,
            'targets' => [$targetId],
            'evicted_at' => time(),
            'reason' => 'age',
        ]);
        FileCopier::ensureDir(dirname($local));
        $temp = $local . PathRules::TEMP_SUFFIX;
        $handle = @fopen($temp, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Platzhalter kann nicht angelegt werden.');
        }
        ftruncate($handle, $size);
        fclose($handle);
        @touch($temp, $mtime);
        if (!@rename($temp, $local)) {
            @unlink($temp);
            throw new RuntimeException('Platzhalter kann nicht eingesetzt werden.');
        }
    }
}
