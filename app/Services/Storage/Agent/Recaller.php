<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use RuntimeException;

/**
 * Holt ausgelagerte Dateien aus dem Cold-Tier (SMB-Tier) zurueck in den
 * Hot-Tier (lokales Storage): bevorzugt vom primaeren Ziel, Pruefsumme wird
 * kontrolliert, der Platzhalter erst danach atomar ersetzt.
 */
final class Recaller
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly TieringStore $store,
        private readonly TargetMap $targets,
        private readonly FileCopier $copier,
        private readonly ?\Closure $clock = null
    ) {
    }

    /**
     * @param (callable(int,int):void)|null $progress (Bytes, Gesamt)
     *
     * @return bool true = zurueckgeholt, false = lag bereits im Hot-Tier
     *
     * @throws RuntimeException
     */
    public function recall(string $rel, ?callable $progress = null): bool
    {
        $marker = $this->store->readMarker($rel);
        if ($marker === null) {
            return false;
        }
        $abs = $this->store->dataDir() . '/' . $rel;
        clearstatcache(true, $abs);
        $before = @stat($abs);
        if ($before === false) {
            // Platzhalter wurde inzwischen geloescht.
            $this->store->removeMarker($rel);

            return false;
        }
        if (!$this->store->isStub($rel, $marker)) {
            // Datei wurde vollstaendig neu geschrieben: Kennzeichen ist ueberholt.
            $this->store->removeMarker($rel);
            $this->markLocal($rel, (int) $before['ino']);

            return false;
        }

        $size = (int) $marker['size'];
        $file = $this->catalog->find(PathRules::SOURCE_NEXTCLOUD_DATA, $rel);
        $preferred = array_map('intval', (array) ($marker['targets'] ?? []));
        if ($file !== null) {
            $preferred = array_values(array_unique(array_merge($this->catalog->targetsWithCurrent((int) $file['id']), $preferred)));
        }
        $online = $this->targets->online();
        if ($online === []) {
            throw new RuntimeException('Kein Speicherziel des Cold-Tiers erreichbar.');
        }
        $candidates = array_values(array_filter($online, static fn (array $t): bool => in_array($t['id'], $preferred, true)));
        if ($candidates === []) {
            // Notfall: Datei auf einem anderen Ziel suchen (z. B. nach Verlust des Katalogs).
            $candidates = $online;
        }

        $temp = $this->store->dataDir() . '/' . PathRules::RECALL_DIR . '/' . TieringStore::recallId($rel) . PathRules::TEMP_SUFFIX;
        FileCopier::ensureDir(dirname($temp));
        $errors = [];
        foreach ($candidates as $target) {
            $source = $target['root'] . '/' . PathRules::SOURCE_NEXTCLOUD_DATA . '/' . $rel;
            $remote = @stat($source);
            if ($remote === false || (int) $remote['size'] !== $size) {
                $errors[] = $target['label'] . ': Datei fehlt oder hat eine andere Größe';
                continue;
            }
            try {
                $result = $this->copyTo($source, $temp, $marker, $target['id'], $progress);
            } catch (RuntimeException $exception) {
                $errors[] = $target['label'] . ': ' . $exception->getMessage();
                continue;
            }

            return $this->replace($rel, $abs, $temp, $before, $marker, $result['sha256']);
        }

        throw new RuntimeException('Rückholung fehlgeschlagen – ' . implode('; ', $errors));
    }

    /**
     * @param array<string,mixed> $marker
     * @param (callable(int,int):void)|null $progress
     *
     * @return array{bytes:int,sha256:string}
     */
    private function copyTo(string $source, string $temp, array $marker, int $targetId, ?callable $progress): array
    {
        $size = (int) $marker['size'];
        $tick = $progress === null ? null : static function (int $bytes) use ($progress, $size): void {
            $progress($bytes, $size);
        };

        // Die Kopie landet im Rueckhol-Verzeichnis; den Platzhalter ersetzt erst replace().
        $result = $this->copier->copy($source, $temp, (int) $marker['mtime'], $targetId, Catalog::LOCAL, $tick, null, (string) ($marker['sha256'] ?? ''));
        if ($result['bytes'] !== $size) {
            @unlink($temp);
            throw new RuntimeException('Unvollständige Übertragung.');
        }

        return $result;
    }

    /**
     * @param array<int|string,int> $before
     * @param array<string,mixed> $marker
     */
    private function replace(string $rel, string $abs, string $temp, array $before, array $marker, string $sha): bool
    {
        clearstatcache(true, $abs);
        $now = @stat($abs);
        if ($now === false || $now['ino'] !== $before['ino'] || !$this->store->hasMarker($rel)) {
            // Waehrend der Rueckholung geloescht, verschoben oder ueberschrieben.
            @unlink($temp);

            return false;
        }
        @chmod($temp, $before['mode'] & 07777);
        @chown($temp, (int) $before['uid']);
        @chgrp($temp, (int) $before['gid']);
        @touch($temp, (int) $marker['mtime']);
        if (!@rename($temp, $abs)) {
            @unlink($temp);
            throw new RuntimeException('Datei kann im Hot-Tier nicht ersetzt werden.');
        }
        $this->store->removeMarker($rel);
        clearstatcache(true, $abs);
        $stat = stat($abs);
        $this->markLocal($rel, (int) $stat['ino'], $sha);

        return true;
    }

    private function markLocal(string $rel, int $inode, ?string $sha = null): void
    {
        $file = $this->catalog->find(PathRules::SOURCE_NEXTCLOUD_DATA, $rel);
        if ($file === null) {
            return;
        }
        $this->catalog->setState((int) $file['id'], Catalog::STATE_LOCAL, null, $inode);
        if ($sha !== null) {
            $this->catalog->setHash((int) $file['id'], (int) $file['version'], $sha);
        }
        $this->catalog->recordAccess((int) $file['id'], $this->now());
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
