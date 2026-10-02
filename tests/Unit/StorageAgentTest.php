<?php

declare(strict_types=1);

use App\Services\Storage\Agent\Catalog;
use App\Services\Storage\Agent\FileCopier;
use App\Services\Storage\Agent\Metrics;
use App\Services\Storage\Agent\Mounter;
use App\Services\Storage\Agent\PathRules;
use App\Services\Storage\Agent\Pressure;
use App\Services\Storage\Agent\Recaller;
use App\Services\Storage\Agent\SyncEngine;
use App\Services\Storage\Agent\TargetMap;
use App\Services\Storage\Agent\TieringStore;
use App\Services\Storage\StorageSettings;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Testumgebung: Quellen, zwei Speicherziele (Verzeichnisse) und Katalog.
 *
 * @return array{base:string,data:string,sources:array<string,string>,targets:list<array{id:int,label:string,root:string,online:bool,primary:bool,active:bool}>,catalog:Catalog,store:TieringStore,map:TargetMap,copier:FileCopier,engine:SyncEngine,events:ArrayObject}
 */
function storageAgentEnv(int $targetCount = 2): array
{
    $base = sys_get_temp_dir() . '/lanpa-agent-' . bin2hex(random_bytes(5));
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
    $engine = new SyncEngine($catalog, $store, $map, $copier, $sources, static function (string $level, string $category, string $message) use ($events): void {
        $events[] = $level . ': ' . $message;
    });

    return compact('base', 'sources') + ['data' => $sources[PathRules::SOURCE_NEXTCLOUD_DATA], 'targets' => $targets, 'catalog' => $catalog,
        'store' => $store, 'map' => $map, 'copier' => $copier, 'engine' => $engine, 'events' => $events];
}

function storageAgentFile(string $path, string $content, ?int $mtime = null): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $content);
    if ($mtime !== null) {
        touch($path, $mtime);
    }
}

/**
 * @param array<string,mixed> $env
 */
function storageAgentSyncAll(array $env): void
{
    foreach ($env['targets'] as $target) {
        $result = $env['engine']->syncTarget($target, 30);
        Assert::null($result['error']);
    }
}

function storageAgentRemove(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir((string) $item) : unlink((string) $item);
    }
    rmdir($dir);
}

Runner::test('Storage-Agent: Pfadregeln fuer Hot-/Cold-Tier', static function (): void {
    Assert::true(PathRules::isTiered(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files/Bericht.docx'));
    Assert::true(PathRules::isTiered(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files_versions/Bericht.docx.v1'));
    Assert::false(PathRules::isTiered(PathRules::SOURCE_NEXTCLOUD_DATA, 'appdata_abc/avatar/x.png'));
    Assert::false(PathRules::isTiered(PathRules::SOURCE_EUROOFFICE_DATA, 'alice/files/x'));
    Assert::true(PathRules::isExcluded(PathRules::SOURCE_NEXTCLOUD_DATA, 'appdata_abc/preview/1/2.png'));
    Assert::true(PathRules::isExcluded(PathRules::SOURCE_NEXTCLOUD_DATA, '.lanpa-recall/x.lanpa-tmp'));
    Assert::true(PathRules::isExcluded(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/uploads/chunk'));
    Assert::true(PathRules::isExcluded(PathRules::SOURCE_NEXTCLOUD_DATA, 'nextcloud.log'));
    Assert::true(PathRules::isExcluded(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files/../../etc/passwd'));
    Assert::false(PathRules::isExcluded(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files/a.txt'));
    Assert::true(PathRules::isExcluded(PathRules::SOURCE_EUROOFFICE_DATA, '.private/key'));
});

Runner::test('Storage-Agent: Fuellstand des Hot-Tiers mit Hysterese', static function (): void {
    $gib = 1073741824;
    // Limit 10 GiB, 11 GiB belegt -> nur noch Cold-Tier
    $full = new Pressure(10 * $gib, 11 * $gib, 100 * $gib, 50 * $gib);
    Assert::same(Pressure::REMOTE_ONLY, $full->next(Pressure::NORMAL)['mode']);
    Assert::true($full->bytesToFree() >= 2 * $gib);
    // 9,5 GiB: ueber der unteren Schwelle (9 GiB) -> bleibt im Notbetrieb
    $between = new Pressure(10 * $gib, (int) (9.5 * $gib), 100 * $gib, 50 * $gib);
    Assert::same(Pressure::REMOTE_ONLY, $between->next(Pressure::REMOTE_ONLY)['mode']);
    Assert::same(Pressure::NORMAL, $between->next(Pressure::NORMAL)['mode']);
    // 8 GiB -> wieder normal
    $ok = new Pressure(10 * $gib, 8 * $gib, 100 * $gib, 50 * $gib);
    Assert::same(Pressure::NORMAL, $ok->next(Pressure::REMOTE_ONLY)['mode']);
    Assert::false($ok->roomFor(2 * $gib));
    Assert::true($ok->roomFor(100 * 1048576));
    // Ohne Limit: nach freiem Platz des Datentraegers
    Assert::same(Pressure::REMOTE_ONLY, (new Pressure(0, 0, 100 * $gib, 5 * $gib))->next(Pressure::NORMAL)['mode']);
    Assert::same(Pressure::REMOTE_ONLY, (new Pressure(0, 0, 100 * $gib, 12 * $gib))->next(Pressure::REMOTE_ONLY)['mode']);
    Assert::same(Pressure::NORMAL, (new Pressure(0, 0, 100 * $gib, 20 * $gib))->next(Pressure::REMOTE_ONLY)['mode']);
});

Runner::test('Storage-Agent: Datenrate, IOPS und CIFS-Statistik', static function (): void {
    $stats = "Resources in use\nCIFS Session: 1\n1) \\\\NAS01\\Backup\nSMBs: 120\nBytes read: 1048576  Bytes written: 2097152\n"
        . "Reads: 40 sent 0 failed\nWrites: 60 sent 0 failed\n2) \\\\nas02\\daten\nSMBs: 3\n";
    $parsed = Metrics::parseCifsStats($stats);
    Assert::same([1048576, 2097152, 40, 60], $parsed['nas01\\backup']);
    Assert::true(isset($parsed['nas02\\daten']));
    Assert::same('nas01\\backup', Mounter::shareKey('\\\\NAS01\\Backup\\lanpa'));
    Assert::same([1024 * 512, 2048 * 512, 10, 20], Metrics::parseBlockStat('10 0 1024 5 20 0 2048 7 0 0 0'));

    $metrics = new Metrics();
    $metrics->rates('x', [0, 0, 0, 0], 100.0);
    $rates = $metrics->rates('x', [10485760, 5242880, 100, 50], 110.0);
    Assert::same(1048576, $rates['read_bps']);
    Assert::same(524288, $rates['write_bps']);
    Assert::same(10.0, $rates['read_iops']);
    Assert::same(0, $metrics->rates('x', [0, 0, 0, 0], 120.0)['read_bps']);
});

Runner::test('Storage-Agent: Mount-Optionen und Fehlermeldungen ohne Zugangsdaten', static function (): void {
    $mounter = new Mounter('/mnt/targets', '/run/storage-sync', new App\Security\SecretBox(sys_get_temp_dir() . '/k-' . bin2hex(random_bytes(4)) . '/s.key'), 'abc');
    $options = $mounter->options(['smb_version' => '3.0'], '/run/storage-sync/cred-1');
    Assert::contains('credentials=/run/storage-sync/cred-1', $options);
    Assert::contains('vers=3.0', $options);
    Assert::contains('soft', $options);
    Assert::false(str_contains($options, 'password'));
    Assert::contains('guest', $mounter->options(['smb_version' => 'auto'], null));
    Assert::contains('Anmeldung abgelehnt', Mounter::mountError('mount error(13): Permission denied', 32));
});

Runner::test('Storage-Agent: Synchronisation, Umbenennung und Loeschung auf allen Zielen', static function (): void {
    $env = storageAgentEnv();
    try {
        $data = $env['data'];
        storageAgentFile($data . '/alice/files/a.bin', str_repeat('A', 100000));
        storageAgentFile($data . '/alice/files/doc.txt', 'Hallo');
        storageAgentFile($data . '/appdata_x/preview/p.png', 'preview');
        storageAgentFile($env['sources'][PathRules::SOURCE_EUROOFFICE_DATA] . '/cache/x.bin', 'eo');
        storageAgentFile($env['sources'][PathRules::SOURCE_NEXTCLOUD_CONFIG] . '/config.php', '<?php $CONFIG = [];');

        $stats = $env['engine']->scan(null);
        Assert::same(4, $stats['new']);
        storageAgentSyncAll($env);
        foreach ($env['targets'] as $target) {
            Assert::same(str_repeat('A', 100000), file_get_contents($target['root'] . '/nextcloud-data/alice/files/a.bin'));
            Assert::true(is_file($target['root'] . '/eurooffice-data/cache/x.bin'));
            Assert::true(is_file($target['root'] . '/nextcloud-config/config.php'));
            Assert::false(is_file($target['root'] . '/nextcloud-data/appdata_x/preview/p.png'));
            Assert::same(0, $env['catalog']->pendingStats($target['id'])['files']);
        }
        $file = $env['catalog']->find(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files/a.bin');
        Assert::same(hash('sha256', str_repeat('A', 100000)), $file['sha256']);
        Assert::same(filemtime($data . '/alice/files/a.bin'), filemtime($env['targets'][1]['root'] . '/nextcloud-data/alice/files/a.bin'));

        // Umbenennung (Hinweis aus inotify): Ziel wird umbenannt statt neu kopiert
        mkdir($data . '/alice/files/Ordner');
        rename($data . '/alice/files/a.bin', $data . '/alice/files/Ordner/b.bin');
        $stats = $env['engine']->scan([PathRules::SOURCE_NEXTCLOUD_DATA => ['alice/files/a.bin', 'alice/files/Ordner']]);
        Assert::same(1, $stats['renamed']);
        Assert::same(0, $stats['new']);
        $written = $env['catalog']->counters()[1]['write_bytes'];
        storageAgentSyncAll($env);
        Assert::same($written, $env['catalog']->counters()[1]['write_bytes'], 'Umbenennung darf keine Kopie ausloesen.');
        foreach ($env['targets'] as $target) {
            Assert::true(is_file($target['root'] . '/nextcloud-data/alice/files/Ordner/b.bin'));
            Assert::false(is_file($target['root'] . '/nextcloud-data/alice/files/a.bin'));
        }

        // Aenderung -> neue Version
        storageAgentFile($data . '/alice/files/doc.txt', 'Hallo Welt', time() + 5);
        Assert::same(1, $env['engine']->scan(null)['changed']);
        storageAgentSyncAll($env);
        Assert::same('Hallo Welt', file_get_contents($env['targets'][0]['root'] . '/nextcloud-data/alice/files/doc.txt'));

        // Loeschung
        unlink($data . '/alice/files/doc.txt');
        Assert::same(1, $env['engine']->scan(null)['deleted']);
        storageAgentSyncAll($env);
        foreach ($env['targets'] as $target) {
            Assert::false(is_file($target['root'] . '/nextcloud-data/alice/files/doc.txt'));
        }
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Storage-Agent: Massenhaftes Verschwinden wird nicht ohne Bestaetigung uebernommen', static function (): void {
    $env = storageAgentEnv(1);
    try {
        $data = $env['data'];
        for ($i = 0; $i < SyncEngine::MASS_DELETE_MIN + 50; $i++) {
            storageAgentFile($data . '/bob/files/f' . $i . '.txt', 'x' . $i);
        }
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        rename($data . '/bob', $env['base'] . '/bob-weg');

        $stats = $env['engine']->scan(null);
        Assert::same(0, $stats['deleted']);
        Assert::true($stats['blocked'] > 0);
        Assert::contains('Bestätigung', $env['engine']->blockedMessage());
        storageAgentSyncAll($env);
        Assert::true(is_file($env['targets'][0]['root'] . '/nextcloud-data/bob/files/f1.txt'), 'Kopien im Cold-Tier muessen erhalten bleiben.');

        $env['engine']->confirmDeletes();
        $stats = $env['engine']->scan(null);
        Assert::same(SyncEngine::MASS_DELETE_MIN + 50, $stats['deleted']);
        Assert::same('', $env['engine']->blockedMessage());
        storageAgentSyncAll($env);
        Assert::false(is_file($env['targets'][0]['root'] . '/nextcloud-data/bob/files/f1.txt'));
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Storage-Agent: Auslagerung in den Cold-Tier und Rueckholung', static function (): void {
    $env = storageAgentEnv();
    try {
        if (!$env['store']->sparseSupported()) {
            return;
        }
        $data = $env['data'];
        $old = time() - 60 * 86400;
        $content = random_bytes(300000);
        storageAgentFile($data . '/alice/files/alt.bin', $content, $old);
        storageAgentFile($data . '/alice/files/neu.bin', random_bytes(300000));
        storageAgentFile($data . '/alice/files/klein.txt', 'klein', $old);
        $env['engine']->scan(null);

        $settings = new StorageSettings(['storage_enabled' => '1', 'storage_local_days' => '30']);
        // Noch nicht synchronisiert: nichts auslagern
        Assert::same(0, $env['engine']->tier($settings, true)['evicted']);
        storageAgentSyncAll($env);
        $result = $env['engine']->tier($settings, true);
        Assert::same(1, $result['evicted']);
        Assert::same(Pressure::NORMAL, $result['mode']);

        $stat = stat($data . '/alice/files/alt.bin');
        Assert::same(300000, $stat['size']);
        Assert::same($old, $stat['mtime']);
        Assert::true(TieringStore::allocated($stat) < 4096, 'Platzhalter darf keinen Speicher belegen.');
        $marker = $env['store']->readMarker('alice/files/alt.bin');
        Assert::same(hash('sha256', $content), $marker['sha256']);
        Assert::same([1, 2], $marker['targets']);
        Assert::same(Catalog::STATE_EVICTED, $env['catalog']->find(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files/alt.bin')['state']);

        // Ein erneuter Abgleich erkennt den Platzhalter und aendert nichts
        $stats = $env['engine']->scan(null);
        Assert::same(0, $stats['changed']);
        Assert::same(0, $stats['deleted']);

        // Rueckholung vom zweiten Ziel, wenn das primaere fehlt
        unlink($env['targets'][0]['root'] . '/nextcloud-data/alice/files/alt.bin');
        $progress = [];
        $recaller = new Recaller($env['catalog'], $env['store'], $env['map'], $env['copier']);
        Assert::true($recaller->recall('alice/files/alt.bin', static function (int $bytes, int $total) use (&$progress): void {
            $progress[] = [$bytes, $total];
        }));
        Assert::same($content, file_get_contents($data . '/alice/files/alt.bin'));
        Assert::same($old, filemtime($data . '/alice/files/alt.bin'));
        Assert::false($env['store']->hasMarker('alice/files/alt.bin'));
        Assert::same(300000, end($progress)[0]);
        $row = $env['catalog']->find(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files/alt.bin');
        Assert::same(Catalog::STATE_LOCAL, $row['state']);
        Assert::true((int) $row['last_access'] > $old, 'Rueckholung zaehlt als Zugriff.');
        // Zugriff gerade erst: bleibt im Hot-Tier
        Assert::same(0, $env['engine']->tier($settings, true)['evicted']);
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Storage-Agent: Ueberschriebener Platzhalter wird neue Version', static function (): void {
    $env = storageAgentEnv(1);
    try {
        if (!$env['store']->sparseSupported()) {
            return;
        }
        $data = $env['data'];
        storageAgentFile($data . '/alice/files/x.bin', random_bytes(100000), time() - 90 * 86400);
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        $settings = new StorageSettings(['storage_enabled' => '1', 'storage_local_days' => '30']);
        Assert::same(1, $env['engine']->tier($settings, true)['evicted']);

        storageAgentFile($data . '/alice/files/x.bin', 'neuer Inhalt');
        Assert::same(1, $env['engine']->scan(null)['changed']);
        Assert::false($env['store']->hasMarker('alice/files/x.bin'));
        storageAgentSyncAll($env);
        Assert::same('neuer Inhalt', file_get_contents($env['targets'][0]['root'] . '/nextcloud-data/alice/files/x.bin'));
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Storage-Agent: Hot-Tier voll -> nur Cold-Tier, danach automatische Rueckkehr', static function (): void {
    $env = storageAgentEnv(1);
    try {
        if (!$env['store']->sparseSupported()) {
            return;
        }
        $data = $env['data'];
        $recent = time() - 3600;
        storageAgentFile($data . '/alice/files/gross1.bin', random_bytes(1500000), $recent - 100);
        storageAgentFile($data . '/alice/files/gross2.bin', random_bytes(1500000), $recent);
        $env['engine']->scan(null);
        storageAgentSyncAll($env);

        // Limit 2 MB, belegt 3 MB -> Notbetrieb, aelteste Datei zuerst
        $tight = new StorageSettings(['storage_enabled' => '1', 'storage_local_limit_mb' => '2']);
        $result = $env['engine']->tier($tight, true);
        Assert::same(Pressure::REMOTE_ONLY, $result['mode']);
        Assert::true($result['evicted'] >= 1);
        $first = $env['catalog']->find(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files/gross1.bin');
        Assert::same(Catalog::STATE_EVICTED, $first['state']);
        Assert::same('pressure', $first['evict_reason']);
        Assert::true((bool) array_filter((array) $env['events'], static fn (string $e): bool => str_contains($e, 'Hot-Tier (lokales Storage) ist voll')));

        // Limit erhoeht -> normaler Betrieb, Rueckholung wird angestossen
        $roomy = new StorageSettings(['storage_enabled' => '1', 'storage_local_limit_mb' => '1000']);
        $result = $env['engine']->tier($roomy, true);
        Assert::same(Pressure::NORMAL, $result['mode']);
        Assert::true($result['rehydrate'] >= 1);
        Assert::true(in_array(TieringStore::recallId('alice/files/gross1.bin'), $env['store']->queuedIds(), true));
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Storage-Agent: Katalogverlust - vorhandene Kopien werden uebernommen', static function (): void {
    $env = storageAgentEnv(1);
    try {
        $data = $env['data'];
        storageAgentFile($data . '/alice/files/a.bin', str_repeat('Z', 70000));
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        $written = $env['catalog']->counters()[1]['write_bytes'];
        $env['catalog']->reset();
        $env['engine']->scan(null);
        $result = $env['engine']->syncTarget($env['targets'][0]);
        Assert::same(1, $result['adopted']);
        Assert::same(0, $result['copied']);
        Assert::same($written, $env['catalog']->counters()[1]['write_bytes']);
    } finally {
        storageAgentRemove($env['base']);
    }
});
