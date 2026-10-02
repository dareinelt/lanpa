<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use App\Services\Storage\StorageSettings;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use UnexpectedValueException;

/**
 * Kern der Synchronisation zwischen Hot-Tier (lokales Storage) und Cold-Tier
 * (SMB-Tier):
 *
 * 1. scan(): Aenderungen der Quellen im Katalog erfassen (neu, geaendert,
 *    umbenannt, geloescht). Ein ungewoehnlich grosser Schwund blockiert die
 *    Uebernahme von Loeschungen, bis ein Administrator sie bestaetigt.
 * 2. syncTarget(): Loeschungen/Umbenennungen und neue Versionen auf jedes Ziel
 *    uebertragen. Alle aktiven Ziele erhalten denselben vollstaendigen Stand.
 * 3. tier(): Selten genutzte oder grosse Dateien durch Platzhalter ersetzen und
 *    bei Platzmangel nur noch im Cold-Tier halten; spaeter automatisch
 *    zurueckholen, sobald wieder genug Platz ist.
 */
final class SyncEngine
{
    /** Ab diesem Schwund (Dateien, Anteil) werden Loeschungen nicht ohne Bestaetigung uebernommen. */
    public const MASS_DELETE_MIN = 1000;
    public const MASS_DELETE_RATIO = 0.25;

    /** Pro Durchlauf hoechstens so viele Rueckholungen nach Entlastung anstossen. */
    private const REHYDRATE_BATCH = 50;

    /** @var \Closure(string,string,string,?int):void */
    private \Closure $event;

    private \Closure $clock;

    /** Erkennung auffaelligen Ueberschreibens fuer die aktuell erfasste Quelle aktiv? */
    private bool $detecting = false;

    /**
     * @param array<string,string> $sources Quelle => absolutes Verzeichnis
     * @param (callable(string,string,string,?int):void)|null $event (Stufe, Kategorie, Text, Ziel)
     * @param (callable():int)|null $clock
     * @param ThreatDetector|null $detector Erkennung auffaelligen Ueberschreibens (Ransomware)
     */
    public function __construct(
        private readonly Catalog $catalog,
        private readonly TieringStore $store,
        private readonly TargetMap $targets,
        private readonly FileCopier $copier,
        private readonly array $sources,
        ?callable $event = null,
        ?callable $clock = null,
        private readonly ?ThreatDetector $detector = null
    ) {
        $this->event = $event !== null ? \Closure::fromCallable($event) : static function (): void {
        };
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    // --- 1. Erfassen --------------------------------------------------------

    /**
     * Vollstaendiger Abgleich aller Quellen (oder nur der Hinweise aus inotify:
     * Quelle => Liste relativer Pfade).
     *
     * @param array<string,list<string>>|null $hints
     *
     * @return array{new:int,changed:int,renamed:int,deleted:int,blocked:int}
     */
    public function scan(?array $hints = null): array
    {
        $stats = ['new' => 0, 'changed' => 0, 'renamed' => 0, 'deleted' => 0, 'blocked' => 0];
        $generation = $this->catalog->transaction(fn (): int => $this->catalog->nextGeneration());

        foreach ($this->sources as $source => $root) {
            if ($hints !== null && !isset($hints[$source])) {
                continue;
            }
            /** @var array<int,int> $fresh Inode => Katalog-ID neu erfasster Dateien */
            $fresh = [];
            $vanished = [];
            // Der erste Abgleich (leerer Katalog) erfasst den Bestand und ist kein Ueberschreiben.
            $this->detecting = $this->detector !== null && $this->catalog->count($source) > 0;
            if ($hints === null) {
                if (!$this->sourceAvailable($source, $root)) {
                    continue;
                }
                $this->walk($source, $root, '', true, $generation, $stats, $fresh);
                $vanished = $this->catalog->unseen($source, $generation);
            } else {
                foreach ($this->scopes($root, $hints[$source]) as [$scope, $recursive]) {
                    $this->walk($source, $root, $scope, $recursive, $generation, $stats, $fresh);
                    $vanished = array_merge($vanished, $this->vanishedIn($source, $root, $scope, $recursive, $generation));
                }
            }
            $this->settleVanished($source, $vanished, $fresh, $hints === null, $stats);
        }

        return $stats;
    }

    /**
     * Uebernimmt die blockierten Loeschungen nach Bestaetigung durch einen Administrator.
     */
    public function confirmDeletes(): void
    {
        $this->catalog->setMeta('confirm_deletes', '1');
        $this->catalog->setMeta('blocked', '');
    }

    public function blockedMessage(): string
    {
        return $this->catalog->meta('blocked');
    }

    private function sourceAvailable(string $source, string $root): bool
    {
        if (is_dir($root)) {
            return true;
        }
        if ($this->catalog->count($source) > 0) {
            ($this->event)('error', 'sync', 'Quelle ' . $source . ' nicht verfügbar (' . $root . ') – Abgleich übersprungen.', null);
        }

        return false;
    }

    /**
     * Fasst Hinweise zu Verzeichnissen zusammen: Ein geaendertes Verzeichnis wird
     * rekursiv, das Elternverzeichnis einer Datei nur flach geprueft.
     *
     * @param list<string> $paths
     *
     * @return list<array{0:string,1:bool}>
     */
    private function scopes(string $root, array $paths): array
    {
        $scopes = [];
        foreach (array_unique($paths) as $path) {
            $path = trim($path, '/');
            if ($path !== '' && is_dir($root . '/' . $path) && !is_link($root . '/' . $path)) {
                $scopes[$path] = true;
            } else {
                $parent = $path === '' || dirname($path) === '.' ? '' : dirname($path);
                $scopes[$parent] = ($scopes[$parent] ?? false) || $parent === '' && $path === '';
            }
        }
        ksort($scopes);
        $result = [];
        foreach ($scopes as $scope => $recursive) {
            $scope = (string) $scope;
            foreach ($result as [$covered, $coveredRecursive]) {
                if ($coveredRecursive && ($covered === '' || str_starts_with($scope . '/', $covered . '/'))) {
                    continue 2;
                }
            }
            $result[] = [$scope, $recursive];
        }

        return $result;
    }

    /**
     * @param array<string,int> $stats
     * @param array<int,int> $fresh
     */
    private function walk(string $source, string $root, string $scope, bool $recursive, int $generation, array &$stats, array &$fresh): void
    {
        $dir = $scope === '' ? $root : $root . '/' . $scope;
        if (!is_dir($dir) || is_link($dir) || ($scope !== '' && PathRules::isExcluded($source, $scope))) {
            return;
        }
        $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_PATHNAME;
        try {
            $directory = new RecursiveDirectoryIterator($dir, $flags);
            $iterator = $recursive
                ? new RecursiveIteratorIterator(new ExcludeFilter($directory, $source, $root), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD)
                : new \IteratorIterator($directory);
        } catch (UnexpectedValueException $exception) {
            ($this->event)('warning', 'sync', 'Verzeichnis nicht lesbar: ' . $dir, null);

            return;
        }

        $batch = 0;
        $this->catalog->pdo()->exec('BEGIN IMMEDIATE');
        try {
            foreach ($iterator as $path => $info) {
                /** @var \SplFileInfo $info */
                if ($info->isLink() || !$info->isFile()) {
                    continue;
                }
                $rel = PathRules::relative($root, (string) $path);
                if ($rel === null || PathRules::isExcluded($source, $rel)) {
                    continue;
                }
                $stat = @stat((string) $path);
                if ($stat === false) {
                    continue;
                }
                $this->observe($source, $rel, $stat, $generation, $stats, $fresh);
                if (++$batch % 500 === 0) {
                    $this->catalog->pdo()->exec('COMMIT');
                    $this->catalog->pdo()->exec('BEGIN IMMEDIATE');
                }
            }
            $this->catalog->pdo()->exec('COMMIT');
        } catch (\Throwable $exception) {
            $this->catalog->pdo()->exec('ROLLBACK');
            throw $exception;
        }
    }

    /**
     * @param array<int|string,int> $stat
     * @param array<string,int> $stats
     * @param array<int,int> $fresh
     */
    private function observe(string $source, string $rel, array $stat, int $generation, array &$stats, array &$fresh): void
    {
        $now = ($this->clock)();
        $size = (int) $stat['size'];
        $mtime = (int) $stat['mtime'];
        $inode = (int) $stat['ino'];
        $row = $this->catalog->find($source, $rel);
        $tieredSource = $source === PathRules::SOURCE_NEXTCLOUD_DATA;

        if ($row === null) {
            $state = Catalog::STATE_LOCAL;
            $sha = null;
            if ($tieredSource && ($marker = $this->store->readMarker($rel)) !== null) {
                if ($this->store->isStub($rel, $marker)) {
                    // Katalog verloren oder Datei extern verschoben: Platzhalter bleibt ausgelagert.
                    $state = Catalog::STATE_EVICTED;
                    $sha = is_string($marker['sha256'] ?? null) ? $marker['sha256'] : null;
                } else {
                    $this->store->removeMarker($rel);
                }
            }
            $id = $this->catalog->insert($source, $rel, $size, $mtime, $inode, $now, $generation, $state, $sha);
            $fresh[$inode] = $id;
            $stats['new']++;
            if ($state === Catalog::STATE_LOCAL) {
                $this->detect($source, $rel, $size, true);
            }

            return;
        }

        $id = (int) $row['id'];
        if ($row['state'] === Catalog::STATE_EVICTED) {
            if ($this->store->isStub($rel)) {
                $this->catalog->touchSeen($id, $inode, $generation);

                return;
            }
            $marker = $this->store->readMarker($rel);
            if ($marker !== null && $size > 0 && (int) ($marker['size'] ?? -1) === $size && TieringStore::allocated($stat) < 4096) {
                // Nur der Zeitstempel des Platzhalters wurde geaendert (touch) - der Inhalt
                // liegt unveraendert im Cold-Tier; niemals Nullen uebertragen.
                $marker['mtime'] = $mtime;
                $this->store->writeMarker($rel, $marker);
                $this->catalog->touchMtime($id, $mtime, $inode, $generation);

                return;
            }
            // Platzhalter wurde ueberschrieben (neuer Inhalt) oder ausserhalb zurueckgeholt.
            $this->store->removeMarker($rel);
            if ((int) $row['size'] === $size && (int) $row['mtime'] === $mtime && $this->store->readMarker($rel) === null
                && TieringStore::allocated($stat) >= min($size, 4096)) {
                $this->catalog->setState($id, Catalog::STATE_LOCAL, null, $inode);
                $this->catalog->touchSeen($id, $inode, $generation);

                return;
            }
            $this->catalog->changed($id, $size, $mtime, $inode, $now, $generation);
            $stats['changed']++;
            $this->detect($source, $rel, $size, false);

            return;
        }

        if ((int) $row['size'] !== $size || (int) $row['mtime'] !== $mtime) {
            $this->catalog->changed($id, $size, $mtime, $inode, $now, $generation);
            $stats['changed']++;
            $this->detect($source, $rel, $size, false);

            return;
        }
        $this->catalog->touchSeen($id, $inode, $generation);
    }

    private function detect(string $source, string $rel, int $size, bool $isNew): void
    {
        if ($this->detecting && $this->detector !== null) {
            $this->detector->observe($source, $rel, $this->sources[$source] . '/' . $rel, $size, $isNew);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function vanishedIn(string $source, string $root, string $scope, bool $recursive, int $generation): array
    {
        $prefix = $scope === '' ? '' : $scope . '/';
        $rows = $this->catalog->unseen($source, $generation, $prefix);
        $result = [];
        foreach ($rows as $row) {
            $rest = substr((string) $row['path'], strlen($prefix));
            if (!$recursive && str_contains($rest, '/')) {
                // Unterverzeichnis wurde nicht durchsucht: nur pruefen, ob es noch existiert.
                $abs = $root . '/' . $row['path'];
                if (file_exists($abs) || is_link($abs)) {
                    continue;
                }
            }
            if (PathRules::isExcluded($source, (string) $row['path']) || !file_exists($root . '/' . $row['path'])) {
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * Verschwundene Dateien: Umbenennung (gleicher Inode, gleiche Groesse) oder Loeschung.
     *
     * @param list<array<string,mixed>> $vanished
     * @param array<int,int> $fresh
     * @param array<string,int> $stats
     */
    private function settleVanished(string $source, array $vanished, array $fresh, bool $fullScan, array &$stats): void
    {
        if ($vanished === []) {
            return;
        }
        $renames = [];
        $deletes = [];
        foreach ($vanished as $row) {
            $inode = (int) $row['inode'];
            $newId = $fresh[$inode] ?? null;
            $new = $newId !== null ? $this->catalog->get($newId) : null;
            if ($new !== null && (int) $new['size'] === (int) $row['size'] && (int) $new['mtime'] === (int) $row['mtime']) {
                $renames[] = [$row, $new];
                unset($fresh[$inode]);
            } else {
                $deletes[] = $row;
            }
        }

        if (count($deletes) > 0 && $this->catalog->meta('confirm_deletes') !== '1') {
            $known = max(1, $this->catalog->count($source));
            $limit = max(self::MASS_DELETE_MIN, (int) ceil($known * self::MASS_DELETE_RATIO));
            if (count($deletes) > $limit) {
                $message = sprintf(
                    '%d von %d Dateien in %s sind verschwunden. Löschungen werden zum Schutz der Kopien im Cold-Tier (SMB-Tier) erst nach Bestätigung übernommen.',
                    count($deletes),
                    $known,
                    $source
                );
                if ($this->catalog->meta('blocked') !== $message) {
                    ($this->event)('error', 'sync', $message, null);
                }
                $this->catalog->setMeta('blocked', $message);
                $stats['blocked'] += count($deletes);
                $deletes = [];
            }
        }
        if ($fullScan && $this->catalog->meta('confirm_deletes') === '1') {
            $this->catalog->setMeta('confirm_deletes', '');
            $this->catalog->setMeta('blocked', '');
        }

        $now = ($this->clock)();
        $targetIds = $this->allTargetIds();
        $this->catalog->transaction(function () use ($source, $renames, $deletes, $targetIds, $now, &$stats): void {
            foreach ($renames as [$old, $new]) {
                $oldId = (int) $old['id'];
                $this->catalog->delete((int) $new['id']);
                foreach ($targetIds as $targetId) {
                    if ($this->catalog->hasOnTarget($targetId, $oldId)) {
                        $this->catalog->addOp($targetId, 'rename', $source, (string) $old['path'], (string) $new['path'], $now);
                    }
                }
                $this->catalog->rename($oldId, (string) $new['path'], (int) $new['seen']);
                if ($old['state'] === Catalog::STATE_EVICTED && $source === PathRules::SOURCE_NEXTCLOUD_DATA) {
                    $this->store->moveMarker((string) $old['path'], (string) $new['path']);
                }
                $stats['renamed']++;
                $stats['new'] = max(0, $stats['new'] - 1);
            }
            foreach ($deletes as $row) {
                $id = (int) $row['id'];
                foreach ($targetIds as $targetId) {
                    if ($this->catalog->hasOnTarget($targetId, $id)) {
                        $this->catalog->addOp($targetId, 'delete', $source, (string) $row['path'], null, $now);
                    }
                }
                if ($row['state'] === Catalog::STATE_EVICTED && $source === PathRules::SOURCE_NEXTCLOUD_DATA) {
                    $this->store->removeMarker((string) $row['path']);
                }
                $this->catalog->delete($id);
                $stats['deleted']++;
            }
        });
    }

    /**
     * @return list<int>
     */
    private function allTargetIds(): array
    {
        return array_values(array_map(static fn (array $t): int => $t['id'], $this->targets->all()));
    }

    // --- 2. Uebertragen -----------------------------------------------------

    /**
     * Ueberträgt ausstehende Aenderungen auf ein Ziel (bis das Zeitbudget
     * aufgebraucht ist).
     *
     * @param array{id:int,label:string,root:string,online:bool,primary:bool,active:bool} $target
     *
     * @return array{ops:int,copied:int,adopted:int,bytes:int,failed:int,more:bool,error:?string}
     */
    public function syncTarget(array $target, int $budgetSeconds = 30): array
    {
        $result = ['ops' => 0, 'copied' => 0, 'adopted' => 0, 'bytes' => 0, 'failed' => 0, 'more' => false, 'error' => null];
        $deadline = microtime(true) + max(1, $budgetSeconds);
        $id = $target['id'];
        $root = rtrim($target['root'], '/');

        try {
            foreach ($this->catalog->ops($id) as $op) {
                if (microtime(true) > $deadline) {
                    $result['more'] = true;

                    return $result;
                }
                $this->assertReachable($root);
                $this->applyOp($id, $root, $op);
                $this->catalog->removeOp((int) $op['id']);
                $result['ops']++;
            }

            $online = $this->targets->online();
            $after = null;
            while (($pending = $this->catalog->pending($id, 200, $after)) !== []) {
                foreach ($pending as $file) {
                    if (microtime(true) > $deadline) {
                        $result['more'] = true;

                        return $result;
                    }
                    $after = [(int) $file['changed_at'], (int) $file['id']];
                    $outcome = $this->copyFile($target, $root, $file, $online);
                    if ($outcome === 'copied' || $outcome === 'adopted') {
                        $result[$outcome]++;
                        $result['bytes'] += $outcome === 'copied' ? (int) $file['size'] : 0;
                    } else {
                        // Spaeter erneut (geaendert, Quelle fehlt oder Fehler).
                        $result['more'] = true;
                        if ($outcome === 'failed') {
                            $result['failed']++;
                        }
                    }
                }
            }
        } catch (TargetUnavailable $exception) {
            $result['error'] = $exception->getMessage();
            $result['more'] = true;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $op
     */
    private function applyOp(int $targetId, string $root, array $op): void
    {
        $base = $root . '/' . $op['source'];
        $from = $base . '/' . $op['path'];
        if ($op['op'] === 'delete') {
            if (is_file($from) && !@unlink($from)) {
                $this->assertReachable($root);
                ($this->event)('warning', 'sync', 'Löschen fehlgeschlagen: ' . $op['source'] . '/' . $op['path'], $targetId);
            }
            $this->pruneDirs(dirname($from), $base);
            $this->copier->count($targetId, 0, 0, 0, 1);

            return;
        }

        $to = $base . '/' . $op['new_path'];
        $moved = false;
        if (is_file($from)) {
            FileCopier::ensureDir(dirname($to));
            $moved = @rename($from, $to);
            $this->copier->count($targetId, 0, 0, 0, 1);
        }
        if (!$moved) {
            $this->assertReachable($root);
            // Datei fehlt am alten Ort: neu uebertragen.
            $file = $this->catalog->find((string) $op['source'], (string) $op['new_path']);
            if ($file !== null) {
                $this->catalog->unsync($targetId, (int) $file['id']);
            }
        }
        $this->pruneDirs(dirname($from), $base);
    }

    /**
     * @param array{id:int,label:string,root:string} $target
     * @param array<string,mixed> $file
     * @param list<array{id:int,label:string,root:string,online:bool,primary:bool,active:bool}> $online
     *
     * @return string copied|adopted|skipped|failed
     */
    private function copyFile(array $target, string $root, array $file, array $online): string
    {
        $fileId = (int) $file['id'];
        $version = (int) $file['version'];
        $source = (string) $file['source'];
        $rel = (string) $file['path'];
        $remote = $root . '/' . $source . '/' . $rel;
        $size = (int) $file['size'];
        $mtime = (int) $file['mtime'];

        $existing = @stat($remote);
        if ($existing !== false && (int) $existing['size'] === $size && abs((int) $existing['mtime'] - $mtime) <= 1) {
            // Ziel hat die Datei bereits (z. B. vorbefuellt oder Katalog neu aufgebaut).
            $this->catalog->markSynced($target['id'], $fileId, $version);

            return 'adopted';
        }

        if ($file['state'] === Catalog::STATE_EVICTED) {
            // Nur noch im Cold-Tier: von einem anderen Ziel kopieren.
            $holders = $this->catalog->targetsWithCurrent($fileId);
            foreach ($online as $other) {
                if ($other['id'] === $target['id'] || !in_array($other['id'], $holders, true)) {
                    continue;
                }
                try {
                    $copy = $this->copier->copy($other['root'] . '/' . $source . '/' . $rel, $remote, $mtime, $other['id'], $target['id'], null, null, (string) ($file['sha256'] ?? ''));
                } catch (RuntimeException $exception) {
                    $this->assertReachable($root);
                    continue;
                }
                $this->catalog->markSynced($target['id'], $fileId, $version);
                if (($file['sha256'] ?? null) === null) {
                    $this->catalog->setHash($fileId, $version, $copy['sha256']);
                }

                return 'copied';
            }

            return 'skipped';
        }

        $local = $this->sources[$source] . '/' . $rel;
        clearstatcache(true, $local);
        $before = @stat($local);
        if ($before === false || (int) $before['size'] !== $size || (int) $before['mtime'] !== $mtime) {
            // Wurde inzwischen geaendert oder geloescht: naechster Abgleich erfasst es.
            return 'skipped';
        }
        try {
            $copy = $this->copier->copy($local, $remote, $mtime, Catalog::LOCAL, $target['id']);
        } catch (RuntimeException $exception) {
            $this->assertReachable($root);
            ($this->event)('warning', 'sync', 'Übertragung fehlgeschlagen: ' . $source . '/' . $rel . ' – ' . $exception->getMessage(), $target['id']);

            return 'failed';
        }
        clearstatcache(true, $local);
        $after = @stat($local);
        if ($after === false || (int) $after['size'] !== $size || (int) $after['mtime'] !== $mtime || $copy['bytes'] !== $size) {
            return 'skipped';
        }
        $this->catalog->transaction(function () use ($target, $fileId, $version, $copy): void {
            $current = $this->catalog->get($fileId);
            if ($current !== null && (int) $current['version'] === $version) {
                $this->catalog->markSynced($target['id'], $fileId, $version);
                $this->catalog->setHash($fileId, $version, $copy['sha256']);
            }
        });

        return 'copied';
    }

    private function assertReachable(string $root): void
    {
        clearstatcache(true, $root . '/' . PathRules::TARGET_MARKER);
        if (!is_file($root . '/' . PathRules::TARGET_MARKER)) {
            throw new TargetUnavailable('Speicherziel nicht mehr erreichbar.');
        }
    }

    private function pruneDirs(string $dir, string $stop): void
    {
        while ($dir !== $stop && str_starts_with($dir, $stop . '/') && @rmdir($dir)) {
            $dir = dirname($dir);
        }
    }

    // --- 3. Hot-Tier / Cold-Tier -------------------------------------------

    /**
     * Wendet die Vorhalte-Regeln an.
     *
     * @return array{mode:string,mode_reason:string,evicted:int,evicted_bytes:int,rehydrate:int}
     */
    public function tier(StorageSettings $settings, bool $sparseSupported): array
    {
        $now = ($this->clock)();
        $result = ['mode' => $this->catalog->meta('mode', Pressure::NORMAL), 'mode_reason' => $this->catalog->meta('mode_reason'),
            'evicted' => 0, 'evicted_bytes' => 0, 'rehydrate' => 0];

        $pressure = $this->pressure($settings);
        $next = $pressure->next($result['mode']);
        if ($next['mode'] !== $result['mode']) {
            ($this->event)(
                $next['mode'] === Pressure::REMOTE_ONLY ? 'warning' : 'info',
                'tiering',
                $next['mode'] === Pressure::REMOTE_ONLY
                    ? 'Hot-Tier (lokales Storage) ist voll – Daten werden nur noch im Cold-Tier (SMB-Tier) gehalten. ' . $next['reason']
                    : 'Hot-Tier (lokales Storage) hat wieder Platz – häufig genutzte Dateien werden zurückgeholt.',
                null
            );
            $this->catalog->setMeta('mode', $next['mode']);
            $this->catalog->setMeta('mode_since', (string) $now);
        }
        $this->catalog->setMeta('mode_reason', $next['reason']);
        $result['mode'] = $next['mode'];
        $result['mode_reason'] = $next['reason'];

        $all = $this->targets->all();
        $active = array_values(array_filter($all, static fn (array $t): bool => $t['active']));
        $onlineIds = array_values(array_map(static fn (array $t): int => $t['id'], $this->targets->online()));
        $allOnline = $active !== [] && count($onlineIds) === count($active);
        $evictionAllowed = $settings->evictionEnabled() && $sparseSupported && $onlineIds !== [];

        if ($evictionAllowed) {
            $candidates = $this->catalog->evictable($onlineIds, $now, 5000);
            $toFree = $result['mode'] === Pressure::REMOTE_ONLY ? $pressure->bytesToFree() : 0;
            foreach ($candidates as $file) {
                $activity = (int) $file['activity'];
                if ($now - $activity < StorageSettings::GRACE_SECONDS) {
                    continue;
                }
                $reason = $this->policyReason($settings, $file, $now);
                $syncedOnline = (int) $file['synced_targets'];
                if ($reason !== null && $allOnline && $syncedOnline >= count($active)) {
                    // Regelbetrieb: erst wenn alle Ziele die aktuelle Version haben.
                } elseif ($toFree > 0 && $syncedOnline >= 1) {
                    $reason = 'pressure';
                } else {
                    continue;
                }
                if ($this->evict($file, $reason, $onlineIds)) {
                    $result['evicted']++;
                    $result['evicted_bytes'] += (int) $file['size'];
                    if ($reason === 'pressure' || $toFree > 0) {
                        $toFree -= (int) $file['size'];
                    }
                }
            }
        }

        if ($result['mode'] === Pressure::NORMAL && $onlineIds !== []) {
            $result['rehydrate'] = $this->rehydrate($settings, $pressure->withChange(-$result['evicted_bytes']), $now);
        }

        return $result;
    }

    public function pressure(StorageSettings $settings): Pressure
    {
        $totals = $this->catalog->totals();
        $dataDir = $this->store->dataDir();
        $total = (int) (@disk_total_space($dataDir) ?: 0);
        $free = (int) (@disk_free_space($dataDir) ?: 0);

        return new Pressure($settings->localLimitBytes(), $totals['bytes_local'], $total, $free);
    }

    /**
     * Grund fuer die Auslagerung nach den Regeln (oder null = bleibt im Hot-Tier).
     *
     * @param array<string,mixed> $file
     */
    private function policyReason(StorageSettings $settings, array $file, int $now): ?string
    {
        $max = $settings->maxLocalFileBytes();
        if ($max > 0 && (int) $file['size'] > $max) {
            return 'size';
        }
        $activity = max((int) $file['mtime'], (int) $file['last_access']);
        if ($activity < $now - $settings->localDays() * 86400 && (int) ($file['access_days'] ?? 0) < $settings->hotAccessDays()) {
            return 'age';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $file
     * @param list<int> $onlineIds
     */
    private function evict(array $file, string $reason, array $onlineIds): bool
    {
        $id = (int) $file['id'];
        $version = (int) $file['version'];
        $rel = (string) $file['path'];
        $holders = array_values(array_intersect($this->catalog->targetsWithCurrent($id), $onlineIds));
        if ($holders === []) {
            return false;
        }
        $sha = $file['sha256'] ?? null;
        if ($sha === null) {
            // Uebernommene Kopie: Inhalt einmal pruefen, bevor die lokale Datei entfaellt.
            try {
                $sha = $this->copier->hash($this->store->dataDir() . '/' . $rel, Catalog::LOCAL);
                $holder = $this->targetRoot($holders[0]);
                if ($holder === null || $this->copier->hash($holder . '/' . PathRules::SOURCE_NEXTCLOUD_DATA . '/' . $rel, $holders[0]) !== $sha) {
                    $this->catalog->unsync($holders[0], $id);

                    return false;
                }
            } catch (RuntimeException) {
                return false;
            }
            $this->catalog->setHash($id, $version, $sha);
        }

        $marker = [
            'path' => $rel,
            'size' => (int) $file['size'],
            'mtime' => (int) $file['mtime'],
            'sha256' => $sha,
            'version' => $version,
            'targets' => $holders,
            'evicted_at' => ($this->clock)(),
            'reason' => $reason,
        ];
        try {
            $inode = $this->store->makeStub($rel, $marker);
        } catch (RuntimeException $exception) {
            ($this->event)('warning', 'tiering', $exception->getMessage(), null);

            return false;
        }
        if ($inode === null) {
            return false;
        }
        $this->catalog->setState($id, Catalog::STATE_EVICTED, $reason, $inode);

        return true;
    }

    private function targetRoot(int $id): ?string
    {
        foreach ($this->targets->online() as $target) {
            if ($target['id'] === $id) {
                return $target['root'];
            }
        }

        return null;
    }

    /**
     * Holt ausgelagerte Dateien zurueck, die nach den Regeln wieder in den
     * Hot-Tier gehoeren (z. B. nach Platzmangel oder geaenderten Einstellungen).
     */
    private function rehydrate(StorageSettings $settings, Pressure $pressure, int $now): int
    {
        $queued = 0;
        foreach ($this->catalog->evicted(null, $now, 500) as $file) {
            if ($queued >= self::REHYDRATE_BATCH) {
                break;
            }
            if ($settings->evictionEnabled() && $this->policyReason($settings, $file, $now) !== null) {
                continue;
            }
            $size = (int) $file['size'];
            if (!$pressure->roomFor($size)) {
                break;
            }
            $this->store->enqueue((string) $file['path']);
            $pressure = $pressure->withChange($size);
            $queued++;
        }

        return $queued;
    }
}
