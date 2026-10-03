<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use RuntimeException;

/**
 * Lebenszyklus der Vorgaengerversionen (Snapshot-Speicher):
 *
 *  1. register()  Beim Erfassen einer Inhaltsaenderung oder Loeschung wird die
 *                 bisherige Version im Katalog vorgemerkt (Status pending).
 *                 Der alte Inhalt ist im Hot-Tier zu diesem Zeitpunkt bereits
 *                 ueberschrieben – er liegt nur noch im Cold-Tier.
 *  2. secure()    Bevor der Cold-Tier-Abgleich die alte Kopie ueberschreibt
 *                 oder loescht, wird sie in den Snapshot-Speicher gesichert
 *                 (SHA-256-gesichert, atomar). Ist der Snapshot-Speicher nicht
 *                 erreichbar, wird nur diese Datei auf diesem Ziel zurueckgehalten
 *                 und spaeter erneut versucht; der uebrige Abgleich laeuft weiter.
 *  3. restore()   Administrative Wiederherstellung in den Hot-Tier – ohne dass
 *                 daraus ein neuer Snapshot entsteht. Auch fuer geloeschte Dateien.
 *  4. prune()     Aufbewahrung (Tage, Anzahl je Datei).
 *
 * Laeuft ausschliesslich im Agenten (storage-sync); Nextcloud und Benutzer
 * werden zu keinem Zeitpunkt blockiert.
 */
final class SnapshotEngine
{
    /** Wartezeit bis zum naechsten Versuch nach einem Fehler. */
    public const RETRY_SECONDS = 60;

    /** So lange wird eine Datei zurueckgehalten, bevor der Abgleich ohne Snapshot fortfaehrt. */
    public const HOLD_SECONDS = 86400;

    /** @var \Closure(string,string,string,?int):void */
    private \Closure $event;

    /** @var \Closure():int */
    private \Closure $clock;

    /**
     * @param array<string,string> $sources Quelle => absolutes Verzeichnis (Hot-Tier)
     * @param (callable(string,string,string,?int):void)|null $event
     * @param (callable():int)|null $clock
     */
    public function __construct(
        private readonly Catalog $catalog,
        private readonly SnapshotStore $store,
        private readonly TieringStore $tiering,
        private readonly FileCopier $copier,
        private readonly array $sources,
        private bool $enabled = true,
        ?callable $event = null,
        ?callable $clock = null
    ) {
        $this->event = $event !== null ? \Closure::fromCallable($event) : static function (): void {
        };
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function store(): SnapshotStore
    {
        return $this->store;
    }

    // --- 1. Vormerken ---------------------------------------------------------

    /**
     * Merkt die bisherige Version einer Datei vor (vor Catalog::changed()/delete()).
     *
     * @param array<string,mixed> $row Katalogzeile der Datei vor der Aenderung
     */
    public function register(array $row, bool $deleted = false): bool
    {
        if (!$this->enabled) {
            return false;
        }
        $source = (string) $row['source'];
        $path = (string) $row['path'];
        $size = (int) $row['size'];
        if (!PathRules::isSnapshotted($source, $path) || $size <= 0) {
            return false;
        }
        $id = (int) $row['id'];
        $version = (int) $row['version'];
        $mtime = (int) $row['mtime'];
        $holders = $this->catalog->targetsWithCurrent($id);
        $uid = SnapshotStore::uid($source, $path, $version, $size, $mtime);

        return $this->catalog->addSnapshot([
            'uid' => $uid,
            'file_id' => $deleted ? null : $id,
            'source' => $source,
            'path' => $path,
            'user' => $this->catalog->lastWriter($path),
            'version' => $version,
            'size' => $size,
            'mtime' => $mtime,
            'sha256' => is_string($row['sha256'] ?? null) && $row['sha256'] !== '' ? $row['sha256'] : null,
            'status' => $holders === [] ? Catalog::SNAPSHOT_UNAVAILABLE : Catalog::SNAPSHOT_PENDING,
            'error' => $holders === [] ? 'Die bisherige Version lag auf keinem Speicherziel vor.' : '',
            'created_at' => ($this->clock)(),
        ]);
    }

    // --- 2. Sichern -----------------------------------------------------------

    /**
     * Sichert alle vorgemerkten Versionen einer Datei aus der Kopie auf dem Ziel,
     * bevor diese ueberschrieben oder geloescht wird. Ist der neue lokale
     * Inhalt mit der vorgemerkten Version identisch (z. B. nur der Zeitstempel
     * geaendert), wird die Vormerkung verworfen: keine Version ohne Aenderung.
     *
     * @param string|null $local Neue lokale Fassung (null bei Loeschung/Auslagerung)
     *
     * @return bool true: Abgleich darf fortfahren; false: Datei auf diesem Ziel zurueckhalten
     */
    public function secure(int $targetId, string $remote, string $source, string $path, ?int $fileId, ?string $local = null): bool
    {
        if (!$this->enabled) {
            return true;
        }
        $pending = $this->catalog->pendingSnapshots($source, $path);
        if ($pending === []) {
            return true;
        }
        $now = ($this->clock)();
        $proceed = true;
        $localSha = null;
        foreach ($pending as $snapshot) {
            $uid = (string) $snapshot['uid'];
            if ($fileId !== null && $this->catalog->targetVersion($targetId, $fileId) !== (int) $snapshot['version']) {
                continue; // Dieses Ziel hat eine andere Version.
            }
            clearstatcache(true, $remote);
            $stat = @stat($remote);
            if ($stat === false || (int) $stat['size'] !== (int) $snapshot['size']) {
                continue; // Kopie passt nicht zur vorgemerkten Version.
            }
            if ($local !== null && is_string($snapshot['sha256'] ?? null) && $snapshot['sha256'] !== '') {
                clearstatcache(true, $local);
                if ((int) @filesize($local) === (int) $snapshot['size']) {
                    try {
                        $localSha ??= $this->copier->hash($local, Catalog::LOCAL);
                    } catch (RuntimeException) {
                        $localSha = '';
                    }
                    if ($localSha === $snapshot['sha256']) {
                        $this->catalog->updateSnapshot($uid, ['status' => Catalog::SNAPSHOT_DELETED, 'error' => '']);
                        continue; // Inhalt unveraendert: keine neue Version.
                    }
                }
            }
            if ($this->store->exists($source, $uid)) {
                $this->complete($snapshot, (string) ($snapshot['sha256'] ?? ''), $now);
                continue;
            }
            if ((int) $snapshot['next_attempt'] > $now) {
                $proceed = $proceed && $this->expired($snapshot, $now);
                continue;
            }
            try {
                $copy = $this->store->write($remote, $source, $uid, [
                    'path' => $path,
                    'user' => (string) $snapshot['user'],
                    'version' => (int) $snapshot['version'],
                    'mtime' => (int) $snapshot['mtime'],
                    'created_at' => (int) $snapshot['created_at'],
                ], $targetId, is_string($snapshot['sha256'] ?? null) ? $snapshot['sha256'] : null);
                $this->complete($snapshot, $copy['sha256'], $now);
            } catch (RuntimeException $exception) {
                $attempts = (int) $snapshot['attempts'] + 1;
                $this->catalog->updateSnapshot($uid, [
                    'attempts' => $attempts,
                    'next_attempt' => $now + self::RETRY_SECONDS,
                    'error' => $exception->getMessage(),
                ]);
                if ($attempts === 1 || $attempts % 10 === 0) {
                    ($this->event)('warning', 'snapshot', 'Dateiversion konnte nicht gesichert werden: ' . $source . '/' . $path . ' – ' . $exception->getMessage(), null);
                }
                $proceed = $proceed && $this->expired($snapshot, $now);
            }
        }

        return $proceed;
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function complete(array $snapshot, string $sha, int $now): void
    {
        $this->catalog->updateSnapshot((string) $snapshot['uid'], [
            'status' => Catalog::SNAPSHOT_COMPLETE,
            'sha256' => $sha,
            'stored_at' => $now,
            'error' => '',
        ]);
        ($this->event)('info', 'snapshot', 'Dateiversion gesichert: ' . $snapshot['source'] . '/' . $snapshot['path'] . ' (Version ' . $snapshot['version'] . ')', null);
        $this->refreshIndex((string) $snapshot['source'], (string) $snapshot['path']);
    }

    /**
     * Zu lange zurueckgehalten: endgueltig fehlgeschlagen, Abgleich faehrt fort.
     *
     * @param array<string,mixed> $snapshot
     */
    private function expired(array $snapshot, int $now): bool
    {
        if ((int) $snapshot['created_at'] + self::HOLD_SECONDS > $now) {
            return false;
        }
        $this->catalog->updateSnapshot((string) $snapshot['uid'], [
            'status' => Catalog::SNAPSHOT_FAILED,
            'error' => 'Snapshot-Speicher war zu lange nicht erreichbar; die Version konnte nicht gesichert werden.',
        ]);
        ($this->event)('error', 'snapshot', 'Dateiversion verloren (Snapshot-Speicher zu lange nicht erreichbar): ' . $snapshot['source'] . '/' . $snapshot['path'], null);

        return true;
    }

    // --- 3. Wiederherstellen --------------------------------------------------

    /**
     * Stellt eine gesicherte Version in den Hot-Tier zurueck. Es entsteht
     * keine neue Vorgaengerversion; der Katalog erhaelt eine neue Version, damit
     * der Cold-Tier den wiederhergestellten Stand uebernimmt. Geloeschte Dateien
     * werden neu angelegt.
     *
     * @return array<string,mixed> Snapshotzeile nach der Wiederherstellung
     *
     * @throws RuntimeException mit verstaendlicher Meldung
     */
    public function restore(string $uid, string $by, ?string $expectedPath = null): array
    {
        if (!SnapshotStore::validUid($uid)) {
            throw new RuntimeException('Ungültige Kennung.');
        }
        $snapshot = $this->catalog->snapshot($uid);
        if ($snapshot === null || $snapshot['status'] === Catalog::SNAPSHOT_DELETED) {
            throw new RuntimeException('Diese Dateiversion ist nicht (mehr) vorhanden.');
        }
        if ($snapshot['status'] !== Catalog::SNAPSHOT_COMPLETE) {
            throw new RuntimeException('Diese Dateiversion wurde nicht vollständig gesichert.');
        }
        $source = (string) $snapshot['source'];
        $path = (string) $snapshot['path'];
        if ($expectedPath !== null && $expectedPath !== $path) {
            throw new RuntimeException('Die Dateiversion gehört zu einer anderen Datei.');
        }
        if (!isset($this->sources[$source]) || PathRules::isExcluded($source, $path)) {
            throw new RuntimeException('Der Zielpfad ist nicht zulässig.');
        }
        if (!$this->store->available()) {
            throw new RuntimeException('Der Snapshot-Speicher ist nicht erreichbar.');
        }
        $sha = (string) ($snapshot['sha256'] ?? '');
        if (!$this->store->verify($source, $uid, $sha, (int) $snapshot['size'])) {
            throw new RuntimeException('Die gesicherte Version ist beschädigt oder fehlt auf dem Snapshot-Speicher.');
        }

        $local = $this->sources[$source] . '/' . $path;
        $now = ($this->clock)();
        $row = $this->catalog->find($source, $path);
        if ($row !== null && $row['state'] === Catalog::STATE_EVICTED && $source === PathRules::SOURCE_NEXTCLOUD_DATA) {
            $this->tiering->removeMarker($path);
        }
        FileCopier::ensureDir(dirname($local));
        try {
            $this->copier->copy($this->store->dataPath($source, $uid), $local, $now, Catalog::SNAPSHOT, Catalog::LOCAL, null, null, $sha);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('Wiederherstellung fehlgeschlagen: ' . $exception->getMessage());
        }
        clearstatcache(true, $local);
        $stat = stat($local);
        $size = (int) ($stat['size'] ?? 0);
        $mtime = (int) ($stat['mtime'] ?? $now);
        $inode = (int) ($stat['ino'] ?? 0);

        $this->catalog->transaction(function () use ($row, $source, $path, $size, $mtime, $inode, $sha, $now, $uid, $by): void {
            if ($row !== null) {
                $this->catalog->restored((int) $row['id'], $size, $mtime, $inode, $sha, $now);
                $fileId = (int) $row['id'];
            } else {
                $fileId = $this->catalog->insert($source, $path, $size, $mtime, $inode, $now, $this->catalog->nextGeneration(), Catalog::STATE_LOCAL, $sha);
            }
            $this->catalog->updateSnapshot($uid, ['restored_at' => $now, 'restored_by' => $by, 'file_id' => $fileId]);
            $this->catalog->relinkSnapshots($source, $path, $fileId);
        });
        if ($source === PathRules::SOURCE_NEXTCLOUD_DATA) {
            $this->tiering->requestRescan($path);
        }
        ($this->event)('info', 'snapshot', 'Dateiversion wiederhergestellt: ' . $source . '/' . $path . ' (Version ' . $snapshot['version'] . ') durch ' . $by, null);
        $this->refreshIndex($source, $path);

        return $this->catalog->snapshot($uid) ?? $snapshot;
    }

    // --- 4. Aufbewahrung und Pflege -----------------------------------------

    /**
     * @return array{removed:int,failed:int}
     */
    public function prune(int $retentionDays, int $maxVersions): array
    {
        $result = ['removed' => 0, 'failed' => 0];
        if (!$this->enabled || !$this->store->available()) {
            return $result;
        }
        $candidates = [];
        if ($retentionDays > 0) {
            foreach ($this->catalog->snapshotsBefore(($this->clock)() - $retentionDays * 86400) as $snapshot) {
                $candidates[(string) $snapshot['uid']] = $snapshot;
            }
        }
        if ($maxVersions > 0) {
            foreach ($this->catalog->snapshotsExceeding($maxVersions) as $snapshot) {
                $candidates[(string) $snapshot['uid']] = $snapshot;
            }
        }
        $touched = [];
        foreach ($candidates as $uid => $snapshot) {
            $source = (string) $snapshot['source'];
            $this->store->remove($source, $uid);
            if ($this->store->exists($source, $uid)) {
                $result['failed']++;
                continue;
            }
            $this->catalog->updateSnapshot($uid, ['status' => Catalog::SNAPSHOT_DELETED]);
            $touched[$source . "\0" . $snapshot['path']] = [$source, (string) $snapshot['path']];
            $result['removed']++;
        }
        foreach ($touched as [$source, $path]) {
            $this->refreshIndex($source, $path);
        }
        if ($result['removed'] > 0) {
            ($this->event)('info', 'snapshot', $result['removed'] . ' Dateiversion(en) gemäß Aufbewahrung entfernt.', null);
        }

        return $result;
    }

    public function cleanupTemp(): int
    {
        return $this->store->available() ? $this->store->cleanupTemp() : 0;
    }

    /**
     * Katalog aus den Beschreibungen auf dem Snapshot-Speicher ergaenzen
     * (z. B. nach Verlust des Katalogs).
     */
    public function rebuild(): int
    {
        if (!$this->store->available()) {
            throw new RuntimeException('Der Snapshot-Speicher ist nicht erreichbar.');
        }
        $added = 0;
        foreach ($this->store->all() as $meta) {
            $uid = (string) $meta['id'];
            $source = (string) ($meta['source'] ?? '');
            $path = (string) ($meta['path'] ?? '');
            if ($source === '' || $path === '' || PathRules::isExcluded($source, $path)) {
                continue;
            }
            $file = $this->catalog->find($source, $path);
            $created = strtotime((string) ($meta['created_at'] ?? '')) ?: (int) ($meta['created_at'] ?? 0);
            $stored = strtotime((string) ($meta['stored_at'] ?? '')) ?: ($this->clock)();
            $inserted = $this->catalog->addSnapshot([
                'uid' => $uid,
                'file_id' => $file !== null ? (int) $file['id'] : null,
                'source' => $source,
                'path' => $path,
                'user' => (string) ($meta['user'] ?? ''),
                'version' => (int) ($meta['version'] ?? 0),
                'size' => (int) ($meta['size'] ?? 0),
                'mtime' => (int) ($meta['mtime'] ?? 0),
                'sha256' => (string) ($meta['sha256'] ?? ''),
                'status' => Catalog::SNAPSHOT_COMPLETE,
                'created_at' => $created > 0 ? $created : $stored,
            ]);
            if ($inserted) {
                $this->catalog->updateSnapshot($uid, ['stored_at' => $stored]);
                $this->refreshIndex($source, $path);
                $added++;
            }
        }

        return $added;
    }

    /**
     * Schreibt die Versionsliste einer Nextcloud-Datei in das gemeinsame Verzeichnis.
     */
    public function refreshIndex(string $source, string $path): void
    {
        if ($source !== PathRules::SOURCE_NEXTCLOUD_DATA) {
            return;
        }
        $list = [];
        foreach ($this->catalog->snapshotsFor($source, $path) as $snapshot) {
            $list[] = [
                'uid' => $snapshot['uid'],
                'version' => (int) $snapshot['version'],
                'size' => (int) $snapshot['size'],
                'mtime' => (int) $snapshot['mtime'],
                'created_at' => (int) $snapshot['created_at'],
                'user' => (string) $snapshot['user'],
                'restored_at' => $snapshot['restored_at'] !== null ? (int) $snapshot['restored_at'] : null,
            ];
        }
        $this->tiering->writeSnapshotIndex($path, $list);
    }
}
