<?php

declare(strict_types=1);

use App\Services\Storage\Agent\Agent;
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
use App\Services\Storage\Agent\TierLayout;
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

Runner::test('Storage-Agent: S3-Ziele per s3fs (Optionen, Quelle, Fehlermeldungen)', static function (): void {
    $mounter = new Mounter('/mnt/targets', '/run/storage-sync', new App\Security\SecretBox(sys_get_temp_dir() . '/k-' . bin2hex(random_bytes(4)) . '/s.key'), 'abc', s3TempDir: '/var/lib/storage-sync/s3-tmp');
    $target = ['kind' => 's3', 's3_endpoint' => 'https://minio:9000', 's3_region' => 'eu-central-1', 's3_bucket' => 'lanpa',
        's3_prefix' => 'intranet/prod', 's3_path_style' => 1, 's3_verify_tls' => 1, 'username' => 'AKIA', 'password' => 'geheim'];
    Assert::true(Mounter::isS3($target));
    Assert::false(Mounter::isS3(['kind' => 'smb']));
    Assert::false(Mounter::isS3([]));
    Assert::same('lanpa:/intranet/prod', Mounter::s3Source($target));
    Assert::same('lanpa', Mounter::s3Source(['s3_bucket' => 'lanpa', 's3_prefix' => '']));

    $options = $mounter->s3Options($target, '/run/storage-sync/cred-3', false, '/run/storage-sync/s3fs-3.log');
    Assert::contains('passwd_file=/run/storage-sync/cred-3', $options);
    Assert::contains('url=https://minio:9000', $options);
    Assert::contains('endpoint=eu-central-1', $options);
    Assert::contains('use_path_request_style', $options);
    Assert::contains('tmpdir=/var/lib/storage-sync/s3-tmp', $options);
    Assert::contains('uid=33', $options);
    Assert::contains('compat_dir', $options);
    Assert::false(str_contains($options, 'no_check_certificate'));
    Assert::false(str_contains($options, 'geheim'));
    Assert::false(str_starts_with($options, 'ro,'));

    $readOnly = $mounter->s3Options(['s3_endpoint' => 'http://ceph', 's3_path_style' => 0, 's3_verify_tls' => 0], '/p', true);
    Assert::true(str_starts_with($readOnly, 'ro,'));
    Assert::contains('no_check_certificate', $readOnly);
    Assert::false(str_contains($readOnly, 'use_path_request_style'));
    Assert::false(str_contains($readOnly, 'endpoint='));

    Assert::contains('Secret Access Key falsch', Mounter::s3MountError('curl.cpp:RequestPerform(2475): HTTP response code 403, returning EPERM. Body Text: <Code>SignatureDoesNotMatch</Code>', 1));
    Assert::contains('Bucket nicht gefunden', Mounter::s3MountError('s3fs: bucket not found', 1));
    Assert::contains('Bucket nicht gefunden', Mounter::s3MountError('[CRT] s3fs.cpp:s3fs_check_service(4498): Failed to check bucket and directory for mount point : Bucket or directory not found(host=http://minio:9000, message=The specified bucket does not exist)', 0));
    Assert::contains('Region', Mounter::s3MountError('<Code>AuthorizationHeaderMalformed</Code>', 1));
    Assert::contains('/dev/fuse', Mounter::s3MountError('fuse: device not found, try \'modprobe fuse\' first', 1));
    Assert::contains('Zeitüberschreitung', Mounter::s3MountError('', 124));
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

/**
 * Cold-Tier mit Basisziel (1) und Erweiterung (3); Basisziel voll laut $free.
 *
 * @param array<string,mixed> $env
 * @param ArrayObject<string,int> $free freier Platz je Wurzel (Test)
 *
 * @return array{0:SyncEngine,1:string}
 */
function storageAgentExtendedTier(array $env, ArrayObject $free): array
{
    $extension = $env['base'] . '/target1-ext';
    mkdir($extension, 0777, true);
    file_put_contents($extension . '/' . PathRules::TARGET_MARKER, '{"instance":"test"}');
    $root = $env['targets'][0];
    $member = static fn (int $id, string $path): array => ['id' => $id, 'label' => 'Ziel ' . $id, 'root' => $path, 'online' => true,
        'kind' => 'smb', 'total_bytes' => 10 * 1073741824, 'free_bytes' => 0];
    $env['map']->write([$root + ['members' => [$member(1, $root['root']), $member(3, $extension)]]]);
    $layout = new TierLayout(static fn (string $path): ?int => $free[$path] ?? null);
    $engine = new SyncEngine($env['catalog'], $env['store'], $env['map'], $env['copier'], $env['sources'], null, null, null, null, $layout);
    $free[$root['root']] = 0;
    $free[$extension] = 5 * 1073741824;

    return [$engine, $extension];
}

Runner::test('Storage-Agent: TargetMap liefert Ziele je Cold-Tier', static function (): void {
    $env = storageAgentEnv(1);
    try {
        $entry = $env['map']->all()[0];
        Assert::same(1, count($entry['members']));
        Assert::same(1, $entry['members'][0]['id']);
        Assert::same($env['targets'][0]['root'], $entry['members'][0]['root']);

        $free = new ArrayObject();
        [, $extension] = storageAgentExtendedTier($env, $free);
        $members = $env['map']->all()[0]['members'];
        Assert::same([1, 3], array_column($members, 'id'));
        Assert::same($extension, $members[1]['root']);
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Storage-Agent: TierLayout verteilt auf Erweiterungen, wenn das Basisziel voll ist', static function (): void {
    $free = ['/a' => 10 * 1073741824, '/b' => 10 * 1073741824];
    $layout = new TierLayout(static function (string $root) use (&$free): ?int {
        return $free[$root] ?? null;
    });
    $member = static fn (int $id, string $root): array => ['id' => $id, 'label' => '', 'root' => $root, 'online' => true,
        'kind' => 'smb', 'total_bytes' => 20 * 1073741824, 'free_bytes' => 0];
    $tier = ['id' => 1, 'root' => '/a', 'members' => [$member(1, '/a'), $member(2, '/b')]];

    // Einzelnes Ziel: immer das Basisziel, ohne Platzpruefung.
    Assert::same(1, $layout->place(['id' => 1, 'root' => '/a'], PHP_INT_MAX, null)['id']);
    Assert::same(1, $layout->place($tier, 1000, null)['id']);
    // Reserve: 1 % von 20 GB, hoechstens 1 GB.
    Assert::same(214748364, TierLayout::reserve($tier['members'][0]));
    $free['/a'] = 214748364 + 999;
    Assert::same(2, $layout->place($tier, 1000, null)['id'], 'Volles Basisziel -> Erweiterung.');
    Assert::same(1, $layout->place($tier, 999, null)['id']);
    // Kein Ziel hat Platz: das mit dem meisten freien Platz.
    $free = ['/a' => 5, '/b' => 50];
    Assert::same(2, $layout->place($tier, 1073741824, null)['id']);

    // S3 ohne Kapazitaet gilt als unbegrenzt, mit Kapazitaet zaehlen geschriebene Bytes.
    $s3 = ['id' => 4, 'label' => '', 'root' => '/s3', 'online' => true, 'kind' => 's3', 'total_bytes' => 0, 'free_bytes' => 0];
    Assert::null($layout->free($s3));
    $s3['total_bytes'] = 1000;
    $s3['free_bytes'] = 800;
    $layout->placed(4, 300);
    Assert::same(500, $layout->free($s3));
});

Runner::test('Storage-Agent: Erweiterter Cold-Tier - Ueberlauf, Umbenennung, Loeschung und Rueckholung', static function (): void {
    $env = storageAgentEnv(1);
    try {
        $data = $env['data'];
        // Vor der Erweiterung: Datei liegt auf dem Basisziel.
        storageAgentFile($data . '/alice/files/alt.txt', 'alt');
        $env['engine']->scan(null);
        storageAgentSyncAll($env);
        $root = $env['targets'][0]['root'];
        Assert::true(is_file($root . '/nextcloud-data/alice/files/alt.txt'));

        $free = new ArrayObject();
        [$engine, $extension] = storageAgentExtendedTier($env, $free);
        $tier = $env['map']->all()[0];

        storageAgentFile($data . '/alice/files/neu.bin', str_repeat('N', 300000), time() - 60 * 86400);
        $engine->scan(null);
        Assert::null($engine->syncTarget($tier, 30)['error']);
        Assert::true(is_file($extension . '/nextcloud-data/alice/files/neu.bin'), 'Neue Datei landet auf der Erweiterung.');
        Assert::false(is_file($root . '/nextcloud-data/alice/files/neu.bin'));
        Assert::true(is_file($root . '/nextcloud-data/alice/files/alt.txt'), 'Vorhandene Dateien bleiben auf dem vollen Ziel.');
        Assert::same(0, $env['catalog']->pendingStats(1)['files']);
        $stats = $env['catalog']->memberStats(1);
        Assert::same(1, $stats[1]['files']);
        Assert::same(1, $stats[3]['files']);
        Assert::same(300000, $stats[3]['bytes']);

        // Aenderung einer Datei auf dem vollen Basisziel: neue Version wandert auf die Erweiterung.
        storageAgentFile($data . '/alice/files/alt.txt', 'alt, aber laenger', time() + 5);
        $engine->scan(null);
        Assert::null($engine->syncTarget($tier, 30)['error']);
        Assert::same('alt, aber laenger', file_get_contents($extension . '/nextcloud-data/alice/files/alt.txt'));
        Assert::false(is_file($root . '/nextcloud-data/alice/files/alt.txt'), 'Alte Kopie wird nach dem Verschieben entfernt.');
        Assert::same(1, count(TierLayout::copies($tier, 'nextcloud-data/alice/files/alt.txt')));

        // Umbenennung auf dem Ziel, auf dem die Datei liegt.
        rename($data . '/alice/files/neu.bin', $data . '/alice/files/umbenannt.bin');
        $result = $engine->scan([PathRules::SOURCE_NEXTCLOUD_DATA => ['alice/files/neu.bin', 'alice/files/umbenannt.bin']]);
        Assert::same(1, $result['renamed']);
        Assert::null($engine->syncTarget($tier, 30)['error']);
        Assert::true(is_file($extension . '/nextcloud-data/alice/files/umbenannt.bin'));
        Assert::false(is_file($extension . '/nextcloud-data/alice/files/neu.bin'));
        $located = TierLayout::locate($tier, 'nextcloud-data/alice/files/umbenannt.bin');
        Assert::same(3, $located['member']['id']);

        // Rueckholung findet die Datei auf der Erweiterung.
        if ($env['store']->sparseSupported()) {
            $settings = new StorageSettings(['storage_enabled' => '1', 'storage_local_days' => '30']);
            Assert::same(1, $engine->tier($settings, true)['evicted']);
            $recaller = new Recaller($env['catalog'], $env['store'], $env['map'], $env['copier']);
            Assert::true($recaller->recall('alice/files/umbenannt.bin'));
            Assert::same(str_repeat('N', 300000), file_get_contents($data . '/alice/files/umbenannt.bin'));
        }

        // Loeschung entfernt die Kopie auf jedem Ziel des Tiers.
        unlink($data . '/alice/files/umbenannt.bin');
        unlink($data . '/alice/files/alt.txt');
        Assert::same(2, $engine->scan(null)['deleted']);
        Assert::null($engine->syncTarget($tier, 30)['error']);
        Assert::false(is_file($extension . '/nextcloud-data/alice/files/umbenannt.bin'));
        Assert::false(is_file($extension . '/nextcloud-data/alice/files/alt.txt'));
        Assert::same([], $env['catalog']->memberStats(1));
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Storage-Agent: Erweiterter Cold-Tier ist nur mit allen Zielen erreichbar', static function (): void {
    $rows = [
        ['id' => 1, 'label' => 'NAS A', 'kind' => 'smb', 'is_primary' => 1, 'active' => 1, 'parent_id' => null, 'in_sync' => 1],
        ['id' => 2, 'label' => 'NAS B', 'kind' => 'smb', 'is_primary' => 0, 'active' => 1, 'parent_id' => null, 'in_sync' => 1],
        ['id' => 3, 'label' => 'NAS A2', 'kind' => 'smb', 'is_primary' => 0, 'active' => 1, 'parent_id' => 1],
        ['id' => 4, 'label' => 'NAS B2', 'kind' => 'smb', 'is_primary' => 0, 'active' => 1, 'parent_id' => 2],
    ];
    $check = static fn (string $state, string $root): array => ['state' => $state, 'message' => '', 'total_bytes' => 100, 'free_bytes' => 10, 'root' => $root];
    [$map, $evaluated] = Agent::tierMap($rows, [
        1 => $check('online', '/m/1'), 2 => $check('online', '/m/2'), 3 => $check('online', '/m/3'), 4 => $check('offline', '/m/4'),
    ], 2);
    Assert::same([1, 2], array_column($map, 'id'));
    Assert::same([1, 3], array_column($map[0]['members'], 'id'));
    Assert::same('/m/3', $map[0]['members'][1]['root']);
    Assert::true($map[0]['online']);
    Assert::false($map[1]['online'], 'Ein nicht erreichbares Ziel macht den ganzen Tier unerreichbar.');
    Assert::same('offline', $evaluated[1]['state']);
    Assert::true($evaluated[1]['frozen']);
    Assert::false($evaluated[0]['frozen']);
});
