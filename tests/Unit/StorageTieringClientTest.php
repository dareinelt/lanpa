<?php

declare(strict_types=1);

use App\Services\Storage\Agent\Catalog;
use App\Services\Storage\Agent\PathRules;
use App\Services\Storage\Agent\Recaller;
use App\Services\Storage\Agent\TieringStore;
use App\Services\Storage\StorageHealth;
use App\Services\Storage\StorageService;
use App\Services\Storage\StorageSettings;
use OCA\IntranetIntegration\Storage\TieringClient;
use OCA\IntranetIntegration\Storage\TieringException;
use Tests\Support\Assert;
use Tests\Support\Runner;

// Nextcloud-seitiger Teil (ohne Nextcloud-Abhaengigkeiten) gegen den Agenten.
require_once dirname(__DIR__, 2) . '/docker/nextcloud/apps/intranet_integration/lib/Storage/TieringException.php';
require_once dirname(__DIR__, 2) . '/docker/nextcloud/apps/intranet_integration/lib/Storage/TieringClient.php';

/**
 * @return array{0:array<string,mixed>,1:TieringClient}
 */
function tieringClientEnv(?callable $sleeper = null): array
{
    $env = storageAgentEnv(1);
    $env['store']->writeConfig(['enabled' => true, 'recall_timeout' => 60]);
    $env['store']->heartbeat();

    return [$env, new TieringClient($env['base'] . '/tiering', $env['data'], $sleeper)];
}

/**
 * Lagert alice/files/<name> mit dem Agenten aus (Rueckgabe: Inhalt).
 *
 * @param array<string,mixed> $env
 */
function tieringClientEvict(array $env, string $name): string
{
    $content = random_bytes(200000);
    storageAgentFile($env['data'] . '/alice/files/' . $name, $content, time() - 90 * 86400);
    $env['engine']->scan(null);
    storageAgentSyncAll($env);
    $env['engine']->tier(new StorageSettings(['storage_enabled' => '1', 'storage_local_days' => '30']), true);

    return $content;
}

Runner::test('Tiering-Client (Nextcloud): Rueckholung ueber die Warteschlange des Agenten', static function (): void {
    $agent = null;
    $calls = 0;
    $sleeper = static function () use (&$agent, &$calls): void {
        $calls++;
        ($agent)();
    };
    [$env, $client] = tieringClientEnv($sleeper);
    try {
        if (!$env['store']->sparseSupported()) {
            return;
        }
        $content = tieringClientEvict($env, 'bericht.pdf');
        $rel = 'alice/files/bericht.pdf';
        Assert::true($client->enabled());
        Assert::true($client->isStub($rel));
        Assert::same($rel, $client->relative($env['data'] . '/' . $rel));
        Assert::null($client->relative($env['data'] . '/../etc/passwd'));
        Assert::false($client->isStub('alice/files/fehlt.pdf'));

        // Agent: Auftrag aus der Warteschlange holen und abarbeiten (wie recall-one).
        $agent = static function () use ($env): void {
            $store = $env['store'];
            foreach ($store->queuedIds() as $id) {
                $request = $store->request($id);
                $base = ['path' => $request['path'], 'uid' => $request['uid'], 'request' => (int) $request['requested_at'], 'started' => time()];
                (new Recaller($env['catalog'], $store, $env['map'], $env['copier']))->recall($request['path']);
                $store->writeStatus($id, $base + ['state' => 'done', 'bytes' => 200000, 'total' => 200000, 'finished' => time()]);
                $store->dequeue($id);
            }
        };
        $client->recall($rel, 'alice');
        Assert::same(1, $calls);
        Assert::false($client->isStub($rel));
        Assert::same($content, file_get_contents($env['data'] . '/' . $rel));

        $recalls = $client->recallsFor('alice');
        Assert::same(1, count($recalls));
        Assert::same('bericht.pdf', $recalls[0]['name']);
        Assert::same('done', $recalls[0]['state']);
        Assert::same(100, $recalls[0]['percent']);
        Assert::same([], $client->recallsFor('bob'));
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Tiering-Client (Nextcloud): Fehler und ausgefallener Agent', static function (): void {
    [$env, $client] = tieringClientEnv(static function (): void {
    });
    try {
        if (!$env['store']->sparseSupported()) {
            return;
        }
        tieringClientEvict($env, 'a.bin');
        $rel = 'alice/files/a.bin';

        // Agent meldet einen Fehler
        $id = TieringClient::recallId($rel);
        $requested = $client->enqueue($id, $rel, 'alice');
        $env['store']->writeStatus($id, ['path' => $rel, 'uid' => 'alice', 'request' => $requested, 'state' => 'failed', 'message' => 'Ziel offline']);
        $failed = null;
        try {
            $client->recall($rel, 'alice');
        } catch (TieringException $exception) {
            $failed = $exception->getMessage();
        }
        Assert::true($failed !== null && str_contains($failed, 'Ziel offline'));

        // Lebenszeichen veraltet: sofort abbrechen statt zu warten
        touch($env['base'] . '/tiering/agent.alive', time() - 300);
        $failed = null;
        try {
            $client->recall($rel, 'alice');
        } catch (TieringException $exception) {
            $failed = $exception->getMessage();
        }
        Assert::true($failed !== null && str_contains($failed, 'storage-sync'));
        Assert::true($client->isStub($rel));
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Tiering-Client (Nextcloud): Kennzeichen folgen Umbenennen, Verschieben und Loeschen', static function (): void {
    [$env, $client] = tieringClientEnv();
    try {
        if (!$env['store']->sparseSupported()) {
            return;
        }
        tieringClientEvict($env, 'Projekt/plan.bin');
        $data = $env['data'];

        // Ordner umbenennen (wie Local::rename) und Kennzeichen mitnehmen
        rename($data . '/alice/files/Projekt', $data . '/alice/files/Archiv');
        $client->move('alice/files/Projekt', 'alice/files/Archiv');
        Assert::true($client->isStub('alice/files/Archiv/plan.bin'));
        Assert::same('alice/files/Archiv/plan.bin', $client->readMarker('alice/files/Archiv/plan.bin')['path']);
        Assert::false(is_dir($env['base'] . '/tiering/stubs/alice/files/Projekt'));
        // Der Agent erkennt den Platzhalter am neuen Ort und uebertraegt keine Nullen
        $stats = $env['engine']->scan(null);
        Assert::same(0, $stats['changed']);
        Assert::same(Catalog::STATE_EVICTED, $env['catalog']->find(PathRules::SOURCE_NEXTCLOUD_DATA, 'alice/files/Archiv/plan.bin')['state']);

        // In den Papierkorb (Datei)
        mkdir($data . '/alice/files_trashbin/files', 0777, true);
        rename($data . '/alice/files/Archiv/plan.bin', $data . '/alice/files_trashbin/files/plan.bin.d1');
        $client->move('alice/files/Archiv/plan.bin', 'alice/files_trashbin/files/plan.bin.d1');
        Assert::true($client->isStub('alice/files_trashbin/files/plan.bin.d1'));

        // Endgueltig loeschen
        unlink($data . '/alice/files_trashbin/files/plan.bin.d1');
        $client->removeMarker('alice/files_trashbin/files/plan.bin.d1');
        Assert::false($client->hasMarker('alice/files_trashbin/files/plan.bin.d1'));
        Assert::same([], glob($env['base'] . '/tiering/stubs/alice/*') ?: []);
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Tiering-Client (Nextcloud): Zeitstempel eines Platzhalters, Zugriffsprotokoll', static function (): void {
    [$env, $client] = tieringClientEnv();
    try {
        if (!$env['store']->sparseSupported()) {
            return;
        }
        $content = tieringClientEvict($env, 'touch.bin');
        $rel = 'alice/files/touch.bin';
        $remote = $env['targets'][0]['root'] . '/nextcloud-data/' . $rel;

        // touch() auf den Platzhalter: bleibt ausgelagert, Ziel behaelt den Inhalt
        touch($env['data'] . '/' . $rel, time() - 100);
        $stats = $env['engine']->scan(null);
        Assert::same(0, $stats['changed']);
        Assert::same(time() - 100, (int) $env['store']->readMarker($rel)['mtime']);
        Assert::true($env['store']->isStub($rel));
        storageAgentSyncAll($env);
        Assert::same($content, file_get_contents($remote));

        $client->logAccess($rel);
        $client->logAccess($rel);
        $client->logAccess('appdata_x/preview/1.png');
        $log = $env['store']->takeAccessLog();
        Assert::same([$rel], array_keys($log));
    } finally {
        storageAgentRemove($env['base']);
    }
});

Runner::test('Speicher-Tiering: SNMP-Ausgabe (Status, Fuellstand, Kennzahlen)', static function (): void {
    $settings = new StorageSettings(['storage_enabled' => '1', 'storage_local_limit_mb' => '1000']);
    $targets = [
        ['id' => 1, 'label' => 'NAS 1', 'active' => true, 'is_primary' => true, 'state' => 'online', 'in_sync' => true, 'lag_seconds' => 0,
            'total_bytes' => 1000, 'free_bytes' => 100, 'fill' => StorageHealth::fill(1000, 100, 85, 95), 'read_bps' => 2097152, 'write_bps' => 0,
            'read_iops' => 10.0, 'write_iops' => 2.5, 'pending_files' => 0],
        ['id' => 2, 'label' => 'NAS 2', 'active' => true, 'is_primary' => false, 'state' => 'offline', 'in_sync' => false, 'lag_seconds' => 0,
            'total_bytes' => 0, 'free_bytes' => 0, 'fill' => StorageHealth::fill(0, 0, 85, 95), 'read_bps' => 0, 'write_bps' => 0,
            'read_iops' => 0.0, 'write_iops' => 0.0, 'pending_files' => 4],
    ];
    $status = ['sync_state' => 'syncing', 'pending_files' => 4, 'lag_seconds' => 30, 'files_total' => 10, 'recalls_active' => 1];
    $overview = [
        'settings' => $settings,
        'status' => $status,
        'targets' => $targets,
        'health' => StorageHealth::evaluate(true, $targets, 5, 5, $status, 900),
        'local' => ['total_bytes' => 10000, 'free_bytes' => 8000, 'used_bytes' => 2000, 'fill' => StorageHealth::fill(10000, 8000, 85, 95),
            'limit_bytes' => 1000 * StorageSettings::MIB, 'bytes_local' => 900 * StorageSettings::MIB, 'read_bps' => 1048576, 'write_bps' => 0,
            'read_iops' => 3.0, 'write_iops' => 1.0],
        'mode' => 'normal',
        'forecast' => ['days_free' => 12.5],
    ];
    $service = (new ReflectionClass(StorageService::class))->newInstanceWithoutConstructor();

    $ha = $service->snmp('storage_ha', $overview);
    Assert::same(1, $ha['exit']);
    Assert::true(str_starts_with($ha['lines'][0], 'storage_ha: degraded'));
    Assert::same(1, preg_match('/^[\x20-\x7E]+$/', $ha['lines'][0]), 'SNMP-Text nur ASCII.');

    // Hot-Tier: Limit zu 90 % belegt (Volume nur 20 %) -> Warnung
    $hot = $service->snmp('storage_hot_fill', $overview);
    Assert::same(1, $hot['exit']);
    Assert::true(str_starts_with($hot['lines'][0], 'storage_hot_fill: 90.0%'));

    // Cold-Tier: erreichbares Ziel zu 90 % belegt, zweites offline
    $cold = $service->snmp('storage_cold_fill', $overview);
    Assert::same(1, $cold['exit']);
    Assert::true(str_contains($cold['lines'][0], '1 Ziel(e) nicht erreichbar'));

    $metrics = $service->snmp('storage_metrics', $overview)['lines'];
    Assert::true(in_array('hot_read_mbps=1', $metrics, true));
    Assert::true(in_array('cold_read_mbps=2', $metrics, true));
    Assert::true(in_array('targets_online=1', $metrics, true));
    Assert::true(in_array('forecast_days=12.5', $metrics, true));

    $lines = $service->snmp('storage_targets', $overview)['lines'];
    Assert::same(2, count($lines));
    Assert::true(str_starts_with($lines[0], 'id=1 label=NAS_1 state=online'));

    Assert::same(3, $service->snmp('unbekannt', $overview)['exit']);
    $overview['settings'] = new StorageSettings([]);
    Assert::same(3, $service->snmp('storage_hot_fill', $overview)['exit']);
});
