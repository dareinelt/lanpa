<?php

declare(strict_types=1);

use App\Core\View;
use App\Repositories\StorageRepository;
use App\Services\Storage\Agent\Catalog;
use App\Services\Storage\Agent\FileCopier;
use App\Services\Storage\Agent\PathRules;
use App\Services\Storage\Agent\Recaller;
use App\Services\Storage\Agent\SnapshotEngine;
use App\Services\Storage\Agent\SnapshotStore;
use App\Services\Storage\Agent\SyncEngine;
use App\Services\Storage\Agent\TargetMap;
use App\Services\Storage\Agent\TieringStore;
use App\Services\Storage\SnapshotService;
use App\Services\Storage\SnapshotSettings;
use App\Services\Storage\StorageSettings;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Testumgebung wie storageAgentEnv(), zusaetzlich mit Snapshot-Speicher
 * (Verzeichnis) und SnapshotEngine als neuntem Argument der SyncEngine.
 *
 * @return array<string,mixed>
 */
function storageSnapshotEnv(int $targetCount = 2, bool $enabled = true): array
{
    $base = sys_get_temp_dir() . '/lanpa-snap-' . bin2hex(random_bytes(5));
    $sources = [
        PathRules::SOURCE_NEXTCLOUD_DATA => $base . '/nc-data',
        PathRules::SOURCE_NEXTCLOUD_CONFIG => $base . '/nc-config',
        PathRules::SOURCE_EUROOFFICE_DATA => $base . '/eo-data',
        PathRules::SOURCE_NEXTCLOUD_DB => $base . '/state/dumps',
    ];
    foreach ($sources as $dir) {
        mkdir($dir, 0777, true);
    }
    $targets = [];
    for ($i = 1; $i <= $targetCount; $i++) {
        $root = $base . '/target' . $i;
        mkdir($root, 0777, true);
        file_put_contents($root . '/' . PathRules::TARGET_MARKER, '{"instance":"test"}');
        $targets[] = ['id' => $i, 'label' => 'Ziel ' . $i, 'root' => $root, 'online' => true, 'primary' => $i === 1, 'active' => true];
    }
    $catalog = new Catalog($base . '/state/catalog.sqlite');
    $store = new TieringStore($base . '/tiering', $sources[PathRules::SOURCE_NEXTCLOUD_DATA]);
    $store->prepare();
    $map = new TargetMap($base . '/state/targets.json');
    $map->write($targets);
    $copier = new FileCopier($catalog);
    $events = new ArrayObject();
    $event = static function (string $level, string $category, string $message) use ($events): void {
        $events[] = $level . ':' . $category . ': ' . $message;
    };
    $snapshotRoot = $base . '/snapshots';
    mkdir($snapshotRoot, 0777, true);
    $snapshotStore = new SnapshotStore($snapshotRoot, $copier);
    Assert::null($snapshotStore->prepare('test'));
    $clock = new ArrayObject(['offset' => 0]);
    $snapshots = new SnapshotEngine($catalog, $snapshotStore, $store, $copier, $sources, $enabled, $event, static fn (): int => time() + (int) $clock['offset']);
    $engine = new SyncEngine($catalog, $store, $map, $copier, $sources, $event, null, null, $snapshots);

    return compact('base', 'sources', 'targets', 'catalog', 'store', 'map', 'copier', 'engine', 'events', 'snapshots') + [
        'data' => $sources[PathRules::SOURCE_NEXTCLOUD_DATA],
        'snapshot_root' => $snapshotRoot,
        'snapshot_store' => $snapshotStore,
        'clock' => $clock,
    ];
}

/**
 * Datei anlegen/aendern, Abgleich und Synchronisation auf alle Ziele.
 *
 * @param array<string,mixed> $env
 */
function storageSnapshotWrite(array $env, string $rel, string $content, ?int $mtime = null): void
{
    storageAgentFile($env['data'] . '/' . $rel, $content, $mtime);
    $env['engine']->scan(null);
    storageAgentSyncAll($env);
}

/**
 * @param array<string,mixed> $env
 *
 * @return list<array<string,mixed>>
 */
function storageSnapshotsOf(array $env, string $rel): array
{
    return $env['catalog']->snapshotsFor(PathRules::SOURCE_NEXTCLOUD_DATA, $rel);
}

/**
 * Alle Vormerkungen/Versionen einer Datei unabhaengig vom Status (neueste zuerst).
 *
 * @param array<string,mixed> $env
 *
 * @return list<array<string,mixed>>
 */
function storageSnapshotRows(array $env, string $rel): array
{
    return array_values(array_filter($env['catalog']->snapshots($rel, 100), static fn (array $row): bool => $row['path'] === $rel));
}

/**
 * SQLite-PDO mit denselben Tabellen wie storagePdo(), das MySQL-Upserts der
 * Einstellungen in die SQLite-Schreibweise uebersetzt.
 */
function storageSnapshotPdo(): PDO
{
    $pdo = new class ('sqlite::memory:') extends PDO {
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            return parent::prepare(str_replace(
                'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                'ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value',
                $query
            ), $options);
        }
    };
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $template = storagePdo();
    foreach ($template->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $sql) {
        $pdo->exec((string) $sql);
    }

    return $pdo;
}

Runner::test('Snapshots: Pfadregel und Kennung', static function (): void {
    Assert::true(PathRules::isSnapshotted(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files/Projekte/plan.docx'));
    Assert::false(PathRules::isSnapshotted(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files_versions/plan.docx.v1'));
    Assert::false(PathRules::isSnapshotted(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files_trashbin/files/x.txt'));
    Assert::false(PathRules::isSnapshotted(PathRules::SOURCE_NEXTCLOUD_DATA, 'appdata_abc/preview/1.png'));
    Assert::false(PathRules::isSnapshotted(PathRules::SOURCE_EUROOFFICE_DATA, 'alice/files/x.txt'));
    Assert::false(PathRules::isSnapshotted(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files/../../etc/passwd'));

    $uid = SnapshotStore::uid(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files/a.txt', 3, 10, 1700000000);
    Assert::true(SnapshotStore::validUid($uid));
    Assert::same($uid, SnapshotStore::uid(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files/a.txt', 3, 10, 1700000000));
    Assert::false(SnapshotStore::validUid(strtoupper($uid)));
    Assert::false(SnapshotStore::validUid('../' . substr($uid, 3)));
    Assert::false(SnapshotStore::validUid(''));
});

Runner::test('Snapshots: inhaltliche Aenderung sichert die Vorgaengerversion unveraenderlich', static function (): void {
    $env = storageSnapshotEnv();
    try {
        $old = time() - 3600;
        storageSnapshotWrite($env, 'alice/files/Projekte/plan.docx', 'Fassung 1', $old);
        // Erstanlage: keine Vorgaengerversion
        Assert::same([], storageSnapshotsOf($env, 'alice/files/Projekte/plan.docx'));

        storageSnapshotWrite($env, 'alice/files/Projekte/plan.docx', 'Fassung 2 (länger)', $old + 60);
        $list = storageSnapshotsOf($env, 'alice/files/Projekte/plan.docx');
        Assert::same(1, count($list));
        $snapshot = $list[0];
        Assert::same(Catalog::SNAPSHOT_COMPLETE, $snapshot['status']);
        Assert::same(1, (int) $snapshot['version']);
        Assert::same(9, (int) $snapshot['size']);
        Assert::same($old, (int) $snapshot['mtime']);
        Assert::same(hash('sha256', 'Fassung 1'), $snapshot['sha256']);
        $data = $env['snapshot_store']->dataPath(PathRules::SOURCE_NEXTCLOUD_DATA, (string) $snapshot['uid']);
        Assert::same('Fassung 1', file_get_contents($data));
        Assert::true(str_starts_with($data, $env['snapshot_root'] . '/versions/' . PathRules::SOURCE_NEXTCLOUD_DATA . '/' . substr((string) $snapshot['uid'], 0, 2) . '/'));
        $meta = $env['snapshot_store']->readMeta(PathRules::SOURCE_NEXTCLOUD_DATA, (string) $snapshot['uid']);
        Assert::same('alice/files/Projekte/plan.docx', $meta['path']);
        Assert::same(hash('sha256', 'Fassung 1'), $meta['sha256']);
        // Cold-Tier traegt die neue Fassung
        Assert::same('Fassung 2 (länger)', file_get_contents($env['targets'][0]['root'] . '/nextcloud-data/alice/files/Projekte/plan.docx'));
        // Nextcloud-Index veroeffentlicht
        $index = json_decode((string) file_get_contents($env['base'] . '/tiering/snapshots/index/' . sha1('alice/files/Projekte/plan.docx') . '.json'), true);
        Assert::same((string) $snapshot['uid'], $index['snapshots'][0]['uid']);

        // Mehrere Aenderungen -> mehrere Versionen, neueste zuerst, keine Duplikate bei erneutem Abgleich
        storageSnapshotWrite($env, 'alice/files/Projekte/plan.docx', 'Fassung 3', $old + 120);
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        $list = storageSnapshotsOf($env, 'alice/files/Projekte/plan.docx');
        Assert::same(2, count($list));
        Assert::same(2, (int) $list[0]['version']);
        Assert::same(1, (int) $list[1]['version']);
        Assert::same('Fassung 2 (länger)', file_get_contents($env['snapshot_store']->dataPath(PathRules::SOURCE_NEXTCLOUD_DATA, (string) $list[0]['uid'])));
        Assert::same(2, $env['catalog']->snapshotStats()['complete']);
        Assert::true(count(array_filter((array) $env['events'], static fn (string $e): bool => str_contains($e, 'snapshot'))) >= 0);
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Snapshots: keine Version bei reiner mtime-Aenderung, Umbenennung oder ausserhalb von files/', static function (): void {
    $env = storageSnapshotEnv();
    try {
        $old = time() - 3600;
        storageSnapshotWrite($env, 'alice/files/a.txt', 'gleich', $old);
        storageSnapshotWrite($env, 'alice/files_versions/a.txt.v1', 'v1', $old);
        storageAgentFile($env['sources'][PathRules::SOURCE_EUROOFFICE_DATA] . '/bob/files/x.txt', 'eo');
        $env['engine']->scan(null);
        storageAgentSyncAll($env);

        // Nur mtime geaendert, Inhalt gleich
        touch($env['data'] . '/alice/files/a.txt', $old + 100);
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        Assert::same([], storageSnapshotsOf($env, 'alice/files/a.txt'));

        // Umbenennung
        rename($env['data'] . '/alice/files/a.txt', $env['data'] . '/alice/files/b.txt');
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        Assert::same([], storageSnapshotsOf($env, 'alice/files/a.txt'));
        Assert::same([], storageSnapshotsOf($env, 'alice/files/b.txt'));

        // Aenderungen ausserhalb von <user>/files/ bzw. in anderen Quellen
        storageSnapshotWrite($env, 'alice/files_versions/a.txt.v1', 'v1-neu', $old + 10);
        storageAgentFile($env['sources'][PathRules::SOURCE_EUROOFFICE_DATA] . '/bob/files/x.txt', 'eo-neu');
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        Assert::same(0, $env['catalog']->snapshotStats()['complete']);

        // Nach Umbenennung folgt die Versionshistorie der Datei
        storageSnapshotWrite($env, 'alice/files/b.txt', 'anders', $old + 200);
        rename($env['data'] . '/alice/files/b.txt', $env['data'] . '/alice/files/c.txt');
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        Assert::same([], storageSnapshotsOf($env, 'alice/files/b.txt'));
        Assert::same(1, count(storageSnapshotsOf($env, 'alice/files/c.txt')));
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Snapshots: Auslagern, Zurueckholen und erneuter Abgleich erzeugen keine Versionen', static function (): void {
    $env = storageSnapshotEnv();
    try {
        if (!$env['store']->sparseSupported()) {
            return;
        }
        $old = time() - 60 * 86400;
        $content = random_bytes(200000);
        storageSnapshotWrite($env, 'alice/files/alt.bin', $content, $old);
        $settings = new StorageSettings(['storage_enabled' => '1', 'storage_local_days' => '30']);
        Assert::same(1, $env['engine']->tier($settings, true)['evicted']);
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        Assert::same([], storageSnapshotsOf($env, 'alice/files/alt.bin'));

        $recaller = new Recaller($env['catalog'], $env['store'], $env['map'], $env['copier']);
        Assert::true($recaller->recall('alice/files/alt.bin'));
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        Assert::same([], storageSnapshotsOf($env, 'alice/files/alt.bin'));
        Assert::same(0, $env['catalog']->snapshotStats()['complete']);
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Snapshots: Loeschung sichert die letzte Fassung, Wiederherstellung legt die Datei neu an', static function (): void {
    $env = storageSnapshotEnv();
    try {
        $old = time() - 3600;
        storageSnapshotWrite($env, 'alice/files/weg.txt', 'letzter Stand', $old);
        unlink($env['data'] . '/alice/files/weg.txt');
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        Assert::false(file_exists($env['targets'][0]['root'] . '/nextcloud-data/alice/files/weg.txt'));
        $list = storageSnapshotsOf($env, 'alice/files/weg.txt');
        Assert::same(1, count($list));
        Assert::same(Catalog::SNAPSHOT_COMPLETE, $list[0]['status']);
        Assert::null($list[0]['file_id']);
        Assert::same('letzter Stand', file_get_contents($env['snapshot_store']->dataPath(PathRules::SOURCE_NEXTCLOUD_DATA, (string) $list[0]['uid'])));

        $restored = $env['snapshots']->restore((string) $list[0]['uid'], 'admin');
        Assert::same('alice/files/weg.txt', $restored['path']);
        Assert::same('letzter Stand', file_get_contents($env['data'] . '/alice/files/weg.txt'));
        $row = $env['catalog']->find(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files/weg.txt');
        Assert::true($row !== null);
        Assert::same(hash('sha256', 'letzter Stand'), $row['sha256']);
        $after = storageSnapshotsOf($env, 'alice/files/weg.txt');
        Assert::same((int) $row['id'], (int) $after[0]['file_id']);
        Assert::true($after[0]['restored_at'] !== null);
        Assert::same('admin', $after[0]['restored_by']);
        Assert::true(file_exists($env['base'] . '/tiering/snapshots/rescan/' . sha1('alice/files/weg.txt') . '.json'));

        // Folgeabgleich: keine neue Version durch die Wiederherstellung
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        Assert::same(1, count(storageSnapshotsOf($env, 'alice/files/weg.txt')));
        Assert::same('letzter Stand', file_get_contents($env['targets'][1]['root'] . '/nextcloud-data/alice/files/weg.txt'));
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Snapshots: Wiederherstellung ersetzt Inhalt ohne neuen Snapshot (harter Akzeptanztest)', static function (): void {
    $env = storageSnapshotEnv();
    try {
        $old = time() - 7200;
        storageSnapshotWrite($env, 'alice/files/p.txt', 'Version 10', $old);
        storageSnapshotWrite($env, 'alice/files/p.txt', 'Version 11', $old + 60);
        $list = storageSnapshotsOf($env, 'alice/files/p.txt');
        Assert::same(1, count($list));
        $v10 = $list[0];
        Assert::same(Catalog::SNAPSHOT_COMPLETE, $v10['status']);

        // Falscher Pfad wird abgewiesen
        $rejected = false;
        try {
            $env['snapshots']->restore((string) $v10['uid'], 'admin', 'alice/files/anders.txt');
        } catch (RuntimeException) {
            $rejected = true;
        }
        Assert::true($rejected);
        $restored = $env['snapshots']->restore((string) $v10['uid'], 'nextcloud:admin', 'alice/files/p.txt');
        Assert::same('Version 10', file_get_contents($env['data'] . '/alice/files/p.txt'));
        Assert::same(hash('sha256', 'Version 10'), $env['catalog']->find(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files/p.txt')['sha256']);
        Assert::same(10, (int) $restored['size']);

        // Weder "Version 11" noch eine durch den Restore erzeugte Version
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        $after = storageSnapshotsOf($env, 'alice/files/p.txt');
        Assert::same(1, count($after), 'Restore darf keinen Snapshot erzeugen.');
        Assert::same((string) $v10['uid'], (string) $after[0]['uid']);
        Assert::same('Version 10', file_get_contents($env['targets'][0]['root'] . '/nextcloud-data/alice/files/p.txt'));
        Assert::same('Version 10', file_get_contents($env['targets'][1]['root'] . '/nextcloud-data/alice/files/p.txt'));
        Assert::same(1, $env['catalog']->snapshotStats()['complete']);

        // Erst eine echte Aenderung danach sichert "Version 10" erneut (neue Version)
        storageSnapshotWrite($env, 'alice/files/p.txt', 'Version 12', $old + 600);
        Assert::same(2, count(storageSnapshotsOf($env, 'alice/files/p.txt')));
        Assert::true(count(array_filter((array) $env['events'], static fn (string $e): bool => str_contains($e, 'wiederhergestellt'))) >= 1);
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Snapshots: Speicher nicht erreichbar -> Datei wird zurueckgehalten und spaeter nachgeholt', static function (): void {
    $env = storageSnapshotEnv();
    try {
        $old = time() - 3600;
        storageSnapshotWrite($env, 'alice/files/h.txt', 'eins', $old);
        storageSnapshotWrite($env, 'alice/files/frei.txt', 'frei', $old);
        // Snapshot-Speicher "aushaengen": Kennzeichen entfernen
        $marker = $env['snapshot_root'] . '/' . SnapshotStore::MARKER;
        $markerContent = file_get_contents($marker);
        unlink($marker);
        Assert::false($env['snapshot_store']->available());

        storageAgentFile($env['data'] . '/alice/files/h.txt', 'zwei', $old + 60);
        storageAgentFile($env['data'] . '/alice/files/frei.txt', 'frei2', $old + 60);
        storageAgentFile($env['data'] . '/alice/files/neu.txt', 'neu');
        $env['engine']->scan(null);
        $result = $env['engine']->syncTarget($env['targets'][0], 30);
        Assert::null($result['error']);
        Assert::true($result['held'] >= 2);
        Assert::true($result['more']);
        // Alte Fassungen bleiben auf dem Cold-Tier, bis die Version gesichert ist; neue Dateien laufen weiter
        Assert::same('eins', file_get_contents($env['targets'][0]['root'] . '/nextcloud-data/alice/files/h.txt'));
        Assert::same('neu', file_get_contents($env['targets'][0]['root'] . '/nextcloud-data/alice/files/neu.txt'));
        Assert::same([], storageSnapshotsOf($env, 'alice/files/h.txt'));
        $pending = storageSnapshotRows($env, 'alice/files/h.txt');
        Assert::same(1, count($pending));
        Assert::same(Catalog::SNAPSHOT_PENDING, $pending[0]['status']);
        Assert::true((int) $pending[0]['attempts'] >= 1);
        Assert::true(count(array_filter((array) $env['events'], static fn (string $e): bool => str_contains($e, 'Snapshot-Speicher'))) >= 1);

        // Speicher wieder da, aber Wiederholfrist noch nicht abgelaufen -> weiter zurueckgehalten
        file_put_contents($marker, $markerContent);
        $result = $env['engine']->syncTarget($env['targets'][0], 30);
        Assert::true($result['held'] >= 1);
        Assert::same('eins', file_get_contents($env['targets'][0]['root'] . '/nextcloud-data/alice/files/h.txt'));

        // Nach der Wiederholfrist -> Version gesichert, Datei uebertragen
        $env['clock']['offset'] = SnapshotEngine::RETRY_SECONDS + 1;
        $result = $env['engine']->syncTarget($env['targets'][0], 30);
        Assert::same(0, $result['held']);
        storageAgentSyncAll($env);
        Assert::same('zwei', file_get_contents($env['targets'][0]['root'] . '/nextcloud-data/alice/files/h.txt'));
        $done = storageSnapshotsOf($env, 'alice/files/h.txt');
        Assert::same(Catalog::SNAPSHOT_COMPLETE, $done[0]['status']);
        Assert::same('eins', file_get_contents($env['snapshot_store']->dataPath(PathRules::SOURCE_NEXTCLOUD_DATA, (string) $done[0]['uid'])));
        Assert::same(0, $env['catalog']->snapshotStats()['pending']);
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Snapshots: Abbruch hinterlaesst keine halben Versionen, Temp-Dateien werden bereinigt', static function (): void {
    $env = storageSnapshotEnv();
    try {
        $old = time() - 3600;
        storageSnapshotWrite($env, 'alice/files/t.txt', 'alt', $old);
        // Cold-Kopie beschaedigen: Pruefsumme passt nicht mehr zum Katalog -> Schreiben schlaegt fehl
        file_put_contents($env['targets'][0]['root'] . '/nextcloud-data/alice/files/t.txt', 'xyz');
        file_put_contents($env['targets'][1]['root'] . '/nextcloud-data/alice/files/t.txt', 'xyz');
        storageAgentFile($env['data'] . '/alice/files/t.txt', 'neu', $old + 60);
        $env['engine']->scan(null);
        $result = $env['engine']->syncTarget($env['targets'][0], 30);
        Assert::true($result['held'] >= 1);
        Assert::same([], storageSnapshotsOf($env, 'alice/files/t.txt'));
        $list = storageSnapshotRows($env, 'alice/files/t.txt');
        Assert::same(1, count($list));
        Assert::true(in_array($list[0]['status'], [Catalog::SNAPSHOT_PENDING, Catalog::SNAPSHOT_FAILED], true));
        Assert::true((int) $list[0]['attempts'] >= 1);
        Assert::false($env['snapshot_store']->exists(PathRules::SOURCE_NEXTCLOUD_DATA, (string) $list[0]['uid']));
        Assert::false(file_exists($env['snapshot_store']->metaPath(PathRules::SOURCE_NEXTCLOUD_DATA, (string) $list[0]['uid'])));

        // Verwaiste Temp-Dateien
        $dir = $env['snapshot_store']->dir(PathRules::SOURCE_NEXTCLOUD_DATA, (string) $list[0]['uid']);
        @mkdir($dir, 0775, true);
        $temp = $dir . '/.data.abc.lanpa-tmp';
        file_put_contents($temp, 'halb');
        touch($temp, time() - 7200);
        Assert::same(1, $env['snapshots']->cleanupTemp());
        Assert::false(file_exists($temp));
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Snapshots: Aufbewahrung nach Alter und Anzahl, Neuaufbau aus der Freigabe', static function (): void {
    $env = storageSnapshotEnv();
    try {
        $old = time() - 3600;
        for ($i = 1; $i <= 5; $i++) {
            storageSnapshotWrite($env, 'alice/files/r.txt', 'Fassung ' . $i, $old + $i * 10);
        }
        Assert::same(4, count(storageSnapshotsOf($env, 'alice/files/r.txt')));
        $result = $env['snapshots']->prune(0, 2);
        Assert::same(2, $result['removed']);
        $kept = storageSnapshotsOf($env, 'alice/files/r.txt');
        Assert::same(2, count($kept));
        Assert::same(4, (int) $kept[0]['version']);
        Assert::same(3, (int) $kept[1]['version']);
        Assert::same(2, count(glob($env['snapshot_root'] . '/versions/' . PathRules::SOURCE_NEXTCLOUD_DATA . '/*/*/' . SnapshotStore::META)));

        // Alter: alle Versionen aelter als 0 Tage? created_at liegt in der Gegenwart -> 1 Tag bewahrt alles
        Assert::same(0, $env['snapshots']->prune(1, 0)['removed']);

        // Neuaufbau: Katalog leeren, aus der Freigabe wiederherstellen
        foreach ($kept as $snapshot) {
            $env['catalog']->removeSnapshot((string) $snapshot['uid']);
        }
        Assert::same([], storageSnapshotsOf($env, 'alice/files/r.txt'));
        Assert::same(2, $env['snapshots']->rebuild());
        $rebuilt = storageSnapshotsOf($env, 'alice/files/r.txt');
        Assert::same(2, count($rebuilt));
        Assert::same(Catalog::SNAPSHOT_COMPLETE, $rebuilt[0]['status']);
        Assert::same(0, $env['snapshots']->rebuild());
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Snapshots: deaktiviert -> keine Versionen, keine Verzoegerung', static function (): void {
    $env = storageSnapshotEnv(1, false);
    try {
        $old = time() - 3600;
        storageSnapshotWrite($env, 'alice/files/o.txt', 'eins', $old);
        unlink($env['snapshot_root'] . '/' . SnapshotStore::MARKER);
        storageSnapshotWrite($env, 'alice/files/o.txt', 'zwei', $old + 60);
        Assert::same([], storageSnapshotsOf($env, 'alice/files/o.txt'));
        Assert::same('zwei', file_get_contents($env['targets'][0]['root'] . '/nextcloud-data/alice/files/o.txt'));
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Snapshots: Einstellungen werden geprueft (UNC, Cold-Tier-Konflikt, Zugangsdaten)', static function (): void {
    $errors = SnapshotSettings::validate(['storage_snapshot_enabled' => '1'])['errors'];
    Assert::true(isset($errors['storage_snapshot_unc_path']));

    $conflict = SnapshotSettings::validate(['storage_snapshot_enabled' => '1', 'storage_snapshot_unc_path' => '\\\\nas01\\backup'], ['\\\\NAS01\\Backup'])['errors'];
    Assert::true(isset($conflict['storage_snapshot_unc_path']));

    $bad = SnapshotSettings::validate([
        'storage_snapshot_enabled' => '1', 'storage_snapshot_unc_path' => 'nas01/snap',
        'storage_snapshot_username' => 'FIRMA\\svc', 'storage_snapshot_password' => "a\nb", 'storage_snapshot_retention_days' => '99999',
        'storage_snapshot_smb_version' => '1.0',
    ])['errors'];
    foreach (['storage_snapshot_unc_path', 'storage_snapshot_username', 'storage_snapshot_password', 'storage_snapshot_retention_days', 'storage_snapshot_smb_version'] as $key) {
        Assert::true(isset($bad[$key]), $key);
    }

    $ok = SnapshotSettings::validate([
        'storage_snapshot_enabled' => '1', 'storage_snapshot_unc_path' => '\\\\nas03\\snapshots\\lanpa',
        'storage_snapshot_username' => 'svc-snap', 'storage_snapshot_domain' => 'FIRMA', 'storage_snapshot_password' => 'geheim',
        'storage_snapshot_retention_days' => '30', 'storage_snapshot_max_versions' => '0',
    ], ['\\\\nas01\\backup']);
    Assert::same([], $ok['errors']);
    $settings = new SnapshotSettings($ok['values']);
    Assert::true($settings->enabled());
    Assert::same(30, $settings->retentionDays());
    Assert::same(0, $settings->maxVersions());
    Assert::same(0, $settings->mountRow()['id']);
    Assert::same(1, $settings->mountRow()['active']);

    // Deaktiviert ohne UNC ist zulaessig
    Assert::same([], SnapshotSettings::validate(['storage_snapshot_enabled' => '0'])['errors']);
    Assert::false((new SnapshotSettings([]))->enabled());
});

Runner::test('Snapshots: Liste im Intranet filtert sicher und Wiederherstellung wird beauftragt', static function (): void {
    $pdo = storageSnapshotPdo();
    $repository = new StorageRepository($pdo);
    $now = time();
    $rows = [
        ['uid' => str_repeat('a', 40), 'source' => 'nextcloud-data', 'path' => 'alice/files/Projekte/plan.docx', 'user' => 'alice', 'version' => 3, 'size' => 100, 'mtime' => $now - 100, 'sha256' => str_repeat('0', 64), 'status' => 'complete', 'file_id' => 1, 'error' => '', 'created_at' => $now - 50, 'stored_at' => $now - 49, 'restored_at' => null, 'restored_by' => ''],
        ['uid' => str_repeat('b', 40), 'source' => 'nextcloud-data', 'path' => "bob/files/<script>alert('x')</script>.txt", 'user' => 'bob', 'version' => 1, 'size' => 5, 'mtime' => $now - 100, 'sha256' => str_repeat('0', 64), 'status' => 'complete', 'file_id' => null, 'error' => '', 'created_at' => $now - 40, 'stored_at' => $now - 39, 'restored_at' => null, 'restored_by' => ''],
        ['uid' => str_repeat('c', 40), 'source' => 'nextcloud-data', 'path' => 'alice/files/100%_fertig.txt', 'user' => 'alice', 'version' => 2, 'size' => 7, 'mtime' => $now - 100, 'sha256' => str_repeat('0', 64), 'status' => 'failed', 'file_id' => 2, 'error' => 'kaputt', 'created_at' => $now - 30, 'stored_at' => null, 'restored_at' => null, 'restored_by' => ''],
    ];
    foreach ($rows as $row) {
        $repository->upsertSnapshot($row);
    }
    // Aktualisierung derselben Kennung legt keine zweite Zeile an
    $repository->upsertSnapshot(array_replace($rows[2], ['status' => 'complete', 'error' => '', 'stored_at' => $now]));
    Assert::same(3, $repository->snapshots(SnapshotService::filter([]))['total']);

    $filter = SnapshotService::filter(['limit' => '999', 'from' => '2020-13-45', 'to' => "2024-01-01' OR 1=1 --", 'user' => "' OR '1'='1", 'path' => '%', 'status' => 'bogus; DROP TABLE storage_snapshots', 'deleted' => '']);
    Assert::same(25, $filter['limit']);
    Assert::same('', $filter['from']);
    Assert::same('', $filter['to']);
    Assert::same('', $filter['status']);
    Assert::same('%', $filter['path']);
    // "%" wird wortwoertlich gesucht (ESCAPE), nicht als Platzhalter
    $result = $repository->snapshots($filter);
    Assert::same(0, $result['total']);
    Assert::same(1, $repository->snapshots(SnapshotService::filter(['path' => '100%_fertig']))['total']);
    Assert::same(0, $repository->snapshots(SnapshotService::filter(['user' => "' OR '1'='1"]))['total']);
    Assert::same(2, $repository->snapshots(SnapshotService::filter(['user' => 'alice']))['total']);
    Assert::same(1, $repository->snapshots(SnapshotService::filter(['deleted' => '1']))['total']);
    Assert::same(str_repeat('b', 40), $repository->snapshots(SnapshotService::filter(['deleted' => '1']))['rows'][0]['uid']);
    Assert::same(['alice', 'bob'], $repository->snapshotUsers());
    Assert::same(3, count($repository->snapshots(SnapshotService::filter(['limit' => '10']))['rows']));
    Assert::same(1, count($repository->snapshots(SnapshotService::filter(['limit' => '10', 'status' => 'complete', 'user' => 'bob']))['rows']));

    // Ansicht: Pfade werden maskiert, Wiederherstellen nur fuer vollstaendige Versionen
    $list = $repository->snapshots(SnapshotService::filter([]));
    $html = View::render('admin.storage_versions', [
        'filter' => SnapshotService::filter([]), 'rows' => $list['rows'], 'total' => $list['total'], 'users' => $repository->snapshotUsers(),
        'snapshot' => ['enabled' => true, 'state' => 'online', 'state_label' => 'Erreichbar', 'message' => ''], 'results' => [str_repeat('a', 40) => ''],
    ]);
    Assert::contains('&lt;script&gt;', $html);
    Assert::false(str_contains($html, "<script>alert('x')"));
    Assert::contains('Datei gelöscht', $html);
    Assert::contains('Wiederherstellung läuft', $html);
    Assert::contains('data-restore-form', $html);
    Assert::contains('restore-dialog', $html);
    Assert::false(str_contains($html, 'style='));

    // Wiederherstellung beauftragen (Service)
    $service = new SnapshotService($repository, new \App\Services\SettingsService(new \App\Repositories\SettingsRepository($pdo)), new \App\Security\SecretBox(sys_get_temp_dir() . '/lanpa-snap-' . bin2hex(random_bytes(4)) . '/k.key'));
    $service->saveSettings(['storage_snapshot_enabled' => '1', 'storage_snapshot_unc_path' => '\\\\nas03\\snapshots', 'storage_snapshot_password' => 'pw']);
    Assert::true($service->settings()->hasPassword());
    Assert::true($service->settings()->encryptedPassword() !== 'pw');
    // Leeres Kennwort behaelt das gespeicherte
    $service->saveSettings(['storage_snapshot_enabled' => '1', 'storage_snapshot_unc_path' => '\\\\nas03\\snapshots', 'storage_snapshot_password' => '']);
    Assert::true($service->settings()->hasPassword());
    // Cold-Tier-Freigabe als Snapshot-Speicher wird abgelehnt
    $pdo->exec("INSERT INTO storage_targets (label, unc_path) VALUES ('Cold', '\\\\nas01\\cold')");
    $rejected = false;
    try {
        $service->saveSettings(['storage_snapshot_enabled' => '1', 'storage_snapshot_unc_path' => '\\\\NAS01\\cold\\']);
    } catch (\App\Exceptions\ValidationException $exception) {
        $rejected = isset($exception->errors()['storage_snapshot_unc_path']);
    }
    Assert::true($rejected);
    // Ungueltige, unbekannte oder unvollstaendige Versionen koennen nicht beauftragt werden
    foreach (['..', str_repeat('z', 40)] as $bad) {
        $rejected = false;
        try {
            $service->requestRestore($bad, 'admin');
        } catch (\App\Exceptions\ValidationException) {
            $rejected = true;
        }
        Assert::true($rejected, $bad);
    }
    $repository->upsertSnapshot(array_replace($rows[2], ['status' => 'failed']));
    $rejected = false;
    try {
        $service->requestRestore(str_repeat('c', 40), 'admin');
    } catch (\App\Exceptions\ValidationException) {
        $rejected = true;
    }
    Assert::true($rejected);

    // Status ohne Agentenmeldung
    $status = $service->status();
    Assert::same('unknown', $status['state']);
    Assert::same(0, $status['snapshots_total']);
});

Runner::test('Snapshots: Cold-Tier-Ziel darf nicht auf der Snapshot-Freigabe liegen', static function (): void {
    $pdo = storagePdo();
    $service = storageService($pdo);
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('storage_snapshot_unc_path', '\\\\nas03\\snapshots'), ('storage_snapshot_enabled', '1')");
    $rejected = false;
    try {
        $service->validateTarget(['label' => 'Snap', 'kind' => 'smb', 'unc_path' => '\\\\NAS03\\Snapshots\\'], null);
    } catch (\App\Exceptions\ValidationException $exception) {
        $rejected = isset($exception->errors()['unc_path']);
    }
    Assert::true($rejected);
    Assert::same('\\\\nas04\\cold', $service->validateTarget(['label' => 'Cold', 'kind' => 'smb', 'unc_path' => '\\\\nas04\\cold'], null)['unc_path']);
});

Runner::test('Snapshots: nicht erreichbarer Snapshot-Speicher erscheint als Hinweis im Dashboard', static function (): void {
    $overview = storageOverviewFixture(false);
    $overview['targets'][0]['state'] = 'online';
    $overview['health'] = \App\Services\Storage\StorageHealth::evaluate(true, $overview['targets'], 5, 5, ['pending_files' => 0], 900);
    $service = storageService(storagePdo());
    Assert::null($service->dashboardAlert($overview));

    $overview['snapshot'] = array_replace($overview['snapshot'], ['enabled' => true, 'state' => 'offline', 'unc_path' => '\\\\nas03\\snapshots', 'message' => 'mount error(113)']);
    $alert = $service->dashboardAlert($overview);
    Assert::same('warning', $alert['level'] ?? null);
    Assert::contains('Snapshot-Speicher', $alert['title']);
    Assert::contains('mount error(113)', $alert['message']);
    Assert::contains('zurückgehalten', $alert['message']);

    // Deaktiviert oder erreichbar: kein Hinweis
    $overview['snapshot']['state'] = 'online';
    Assert::null($service->dashboardAlert($overview));
    $overview['snapshot'] = array_replace($overview['snapshot'], ['enabled' => false, 'state' => 'disabled']);
    Assert::null($service->dashboardAlert($overview));
});
