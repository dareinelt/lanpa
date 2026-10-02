<?php

declare(strict_types=1);

use App\Core\View;
use App\Repositories\IncidentRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\StorageRepository;
use App\Services\SettingsService;
use App\Services\Storage\Agent\Agent;
use App\Services\Storage\Agent\Catalog;
use App\Services\Storage\Agent\Mounter;
use App\Services\Storage\Agent\PathRules;
use App\Services\Storage\Agent\SyncEngine;
use App\Services\Storage\Agent\ThreatDetector;
use App\Services\Storage\IncidentService;
use App\Services\Storage\IncidentSettings;
use App\Services\Storage\StorageHealth;
use OCA\IntranetIntegration\Storage\TieringClient;
use Tests\Support\Assert;
use Tests\Support\Runner;

require_once dirname(__DIR__, 2) . '/docker/nextcloud/apps/intranet_integration/lib/Storage/TieringException.php';
require_once dirname(__DIR__, 2) . '/docker/nextcloud/apps/intranet_integration/lib/Storage/TieringClient.php';

function incidentPdo(): PDO
{
    // MySQL-Funktion NOW() fuer StorageRepository::addEvent
    $now = static fn (): string => date('Y-m-d H:i:s');
    if (class_exists(Pdo\Sqlite::class)) {
        $pdo = new Pdo\Sqlite('sqlite::memory:');
        $pdo->createFunction('NOW', $now, 0);
    } else {
        $pdo = new PDO('sqlite::memory:');
        $pdo->sqliteCreateFunction('NOW', $now, 0);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_key VARCHAR(64) NOT NULL UNIQUE,
        setting_value TEXT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("CREATE TABLE storage_incidents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        status TEXT NOT NULL DEFAULT 'open',
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        uid TEXT NOT NULL DEFAULT '',
        attribution TEXT NOT NULL DEFAULT 'owner',
        rules TEXT NOT NULL DEFAULT '',
        summary TEXT NOT NULL DEFAULT '',
        source TEXT NOT NULL DEFAULT 'nextcloud-data',
        files_changed INTEGER NOT NULL DEFAULT 0,
        files_suspicious INTEGER NOT NULL DEFAULT 0,
        files_extension INTEGER NOT NULL DEFAULT 0,
        bytes INTEGER NOT NULL DEFAULT 0,
        first_seen TEXT NULL,
        last_seen TEXT NULL,
        details TEXT NULL,
        user_restricted INTEGER NOT NULL DEFAULT 0,
        frozen_target_id INTEGER NULL,
        frozen_target_label TEXT NOT NULL DEFAULT '',
        resolved_at TEXT NULL,
        resolved_by TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec('CREATE TABLE storage_targets (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE TABLE storage_events (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, level TEXT, category TEXT, message TEXT, target_id INTEGER NULL)');

    return $pdo;
}

/**
 * Agent-Testumgebung mit Erkennung.
 *
 * @param array<string,string> $settings
 *
 * @return array{0:array<string,mixed>,1:ThreatDetector,2:SyncEngine,3:ArrayObject}
 */
function incidentEnv(array $settings = [], ?callable $clock = null): array
{
    $env = storageAgentEnv(2);
    $detector = new ThreatDetector($env['catalog'], new IncidentSettings($settings), $clock);
    $events = new ArrayObject();
    $engine = new SyncEngine($env['catalog'], $env['store'], $env['map'], $env['copier'], $env['sources'], static function (string $level, string $category, string $message) use ($events): void {
        $events[] = $level . ': ' . $message;
    }, null, $detector);

    return [$env, $detector, $engine, $events];
}

Runner::test('Vorfälle: Ransomware-Endungen und Muster', static function (): void {
    $settings = new IncidentSettings([]);
    Assert::same('makop', $settings->matchName('Bericht.docx.makop'));
    Assert::same('makop', $settings->matchName('BERICHT.DOCX.MAKOP'));
    Assert::null($settings->matchName('Bericht.docx'));
    Assert::null($settings->matchName('makop'));
    Assert::same('*.id-*.[*@*].*', $settings->matchName('Bild.jpg.id-1A2B3C.[restore@mail.cc].qwx'));
    Assert::same('how_to_decrypt*', $settings->matchName('HOW_TO_DECRYPT.html'));
    Assert::same('readme-warning.txt', $settings->matchName('readme-warning.txt'));

    // Eigene Liste: "*.abc" und ".abc" werden zur Endung, Muster bleiben Muster.
    Assert::same(['abc', 'def', 'x?y.*'], IncidentSettings::splitPatterns("*.abc\n.DEF, x?y.*;abc"));
    $custom = new IncidentSettings(['incident_extensions' => "abc\nx?y.*"]);
    Assert::same('abc', $custom->matchName('a.abc'));
    Assert::same('x?y.*', $custom->matchName('x1y.txt'));
    Assert::null($custom->matchName('a.makop'));
    // Punkt im Muster ist kein Platzhalter.
    Assert::null($custom->matchName('x1yZtxt'));

    $result = IncidentSettings::validate(['incident_extensions' => "makop\n../x\n*", 'incident_window_minutes' => '0']);
    Assert::true(isset($result['errors']['incident_extensions']));
    Assert::true(isset($result['errors']['incident_window_minutes']));
    $empty = IncidentSettings::validate(['incident_extensions' => ' ']);
    Assert::true(isset($empty['errors']['incident_extensions']));
    $ok = IncidentSettings::validate(['incident_extensions' => 'makop', 'incident_support_contact' => " IT  1234 ", 'incident_detection_enabled' => '1']);
    Assert::same([], $ok['errors']);
    Assert::same('IT 1234', $ok['values']['incident_support_contact']);
    Assert::contains('(IT 1234)', (new IncidentSettings($ok['values']))->userMessage());
});

Runner::test('Vorfälle: Inhaltsprüfung erkennt verschlüsselte Dateien', static function (): void {
    $dir = sys_get_temp_dir() . '/lanpa-threat-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir . '/ok.docx', "PK\x03\x04" . random_bytes(4000));
    file_put_contents($dir . '/bad.docx', random_bytes(4000));
    file_put_contents($dir . '/plain.txt', str_repeat('Hallo Welt, dies ist ein Text. ', 200));
    file_put_contents($dir . '/enc.txt', random_bytes(4000));
    file_put_contents($dir . '/x.unknown', random_bytes(4000));
    Assert::null(ThreatDetector::inspectContent($dir . '/ok.docx', 'ok.docx', 4004));
    Assert::contains('.docx', (string) ThreatDetector::inspectContent($dir . '/bad.docx', 'bad.docx', 4000));
    Assert::null(ThreatDetector::inspectContent($dir . '/plain.txt', 'plain.txt', 6200));
    Assert::contains('Textdatei', (string) ThreatDetector::inspectContent($dir . '/enc.txt', 'enc.txt', 4000));
    Assert::null(ThreatDetector::inspectContent($dir . '/x.unknown', 'x.unknown', 4000));
    Assert::true(ThreatDetector::entropy(random_bytes(8192)) > 7.5);
    Assert::true(ThreatDetector::entropy(str_repeat('a', 100)) < 0.1);
    storageAgentRemove($dir);
});

Runner::test('Vorfälle: Abgleich erkennt Ransomware-Endung, Verschlüsselung und Massenüberschreiben', static function (): void {
    [$env, $detector, $engine] = incidentEnv(['incident_overwrite_files' => '10', 'incident_content_files' => '3']);
    $data = $env['data'];
    for ($i = 0; $i < 12; $i++) {
        storageAgentFile($data . '/alice/files/doc' . $i . '.docx', "PK\x03\x04" . str_repeat('Inhalt ', 100), time() - 3600);
        storageAgentFile($data . '/bob/files/doc' . $i . '.txt', str_repeat('Text ', 100), time() - 3600);
    }
    // Erster Abgleich erfasst nur den Bestand.
    $engine->scan(null);
    Assert::same([], $detector->evaluate());

    // Alice: Dateien werden verschluesselt ueberschrieben, eine bekommt eine Ransomware-Endung.
    for ($i = 0; $i < 12; $i++) {
        file_put_contents($data . '/alice/files/doc' . $i . '.docx', random_bytes(2048));
    }
    storageAgentFile($data . '/alice/files/doc0.docx.makop', random_bytes(1024));
    // Bob aendert nur zwei Dateien normal.
    file_put_contents($data . '/bob/files/doc1.txt', str_repeat('Neu ', 300));
    file_put_contents($data . '/bob/files/doc2.txt', str_repeat('Neu ', 300));
    clearstatcache();
    $engine->scan(null);

    $findings = $detector->evaluate();
    Assert::same(['alice'], array_keys($findings));
    $alice = $findings['alice'];
    Assert::same(['extension', 'content', 'overwrite'], $alice['rules']);
    Assert::same(12, $alice['changed']);
    Assert::same(12, $alice['suspicious']);
    Assert::same(1, $alice['extension']);
    Assert::same('owner', $alice['attribution']);
    Assert::same(['makop' => 1], $alice['patterns']);
    Assert::same('alice/files/doc0.docx.makop', $alice['samples'][0]['path']);

    // Floor (z. B. nach Erledigung) blendet die bisherige Aktivitaet aus.
    Assert::same([], $detector->evaluate(['alice' => time() + 1]));
    storageAgentRemove($env['base']);
});

Runner::test('Vorfälle: Zuordnung zum angemeldeten Benutzer über das Nextcloud-Schreibprotokoll', static function (): void {
    [$env, $detector, $engine] = incidentEnv();
    $data = $env['data'];
    storageAgentFile($data . '/alice/files/Projekt/a.docx', "PK\x03\x04" . str_repeat('x', 500), time() - 3600);
    $engine->scan(null);

    // Bob schreibt ueber eine Freigabe in Alices Ordner.
    $client = new TieringClient($env['base'] . '/tiering', $data);
    $client->logWrite('alice/files/Projekt/a.docx.locked', 'bob', '10.0.0.7', "Mozilla/5.0\n(Test)");
    $client->logWrite('alice/files/Projekt/a.docx.locked', 'bob', '10.0.0.7', 'doppelt');
    $client->logWrite('appdata_x/preview/1.png', 'bob');
    $client->logWrite('alice/files/b.txt', '');
    storageAgentFile($data . '/alice/files/Projekt/a.docx.locked', random_bytes(600));
    $entries = $env['store']->takeWriteLog();
    Assert::same(1, count($entries));
    Assert::same('Mozilla/5.0 (Test)', $entries[0]['ua']);
    Assert::same([], $env['store']->takeWriteLog());
    $detector->ingestWrites($entries);
    $engine->scan(null);

    $findings = $detector->evaluate();
    Assert::same(['bob'], array_keys($findings));
    Assert::same('session', $findings['bob']['attribution']);
    Assert::same(['alice' => 1], $findings['bob']['owners']);
    Assert::same('10.0.0.7', $findings['bob']['clients'][0]['ip']);
    storageAgentRemove($env['base']);
});

Runner::test('Vorfälle: Erkennung abschaltbar, Erstabgleich und neue Dateien lösen nichts aus', static function (): void {
    [$env, $detector, $engine] = incidentEnv(['incident_detection_enabled' => '0']);
    storageAgentFile($env['data'] . '/alice/files/a.makop', 'x');
    $engine->scan(null);
    storageAgentFile($env['data'] . '/alice/files/b.makop', 'x');
    $engine->scan(null);
    Assert::same([], $detector->evaluate());
    storageAgentRemove($env['base']);

    [$env, $detector, $engine] = incidentEnv(['incident_overwrite_files' => '10']);
    storageAgentFile($env['data'] . '/alice/files/a.makop', 'Bestand');
    $engine->scan(null);
    Assert::same([], $detector->evaluate());
    for ($i = 0; $i < 20; $i++) {
        storageAgentFile($env['data'] . '/alice/files/neu' . $i . '.txt', 'neu');
    }
    $engine->scan(null);
    Assert::same([], $detector->evaluate());
    storageAgentRemove($env['base']);
});

Runner::test('Vorfälle: Schutzziel-Auswahl, Zusammenfassung und HA-Bewertung', static function (): void {
    $rows = [
        ['id' => 1, 'label' => 'Primär', 'active' => 1, 'is_primary' => 1, 'state' => 'online', 'in_sync' => 1, 'lag_seconds' => 0],
        ['id' => 2, 'label' => 'Zweit', 'active' => 1, 'is_primary' => 0, 'state' => 'online', 'in_sync' => 1, 'lag_seconds' => 0],
        ['id' => 3, 'label' => 'Aus', 'active' => 0, 'is_primary' => 0, 'state' => 'disabled', 'in_sync' => 0, 'lag_seconds' => 0],
    ];
    Assert::same(2, Agent::chooseProtectTarget($rows)['id']);
    Assert::same(1, Agent::chooseProtectTarget($rows, 1)['id']);
    Assert::same(2, Agent::chooseProtectTarget($rows, 3)['id']);
    $rows[1]['in_sync'] = 0;
    $rows[1]['lag_seconds'] = 50;
    Assert::same(1, Agent::chooseProtectTarget($rows)['id']);
    $rows[0]['state'] = 'offline';
    Assert::same(2, Agent::chooseProtectTarget($rows)['id']);
    Assert::null(Agent::chooseProtectTarget([$rows[2]]));

    $values = Agent::incidentValues(['changed' => 300, 'suspicious' => 0, 'extension' => 2, 'patterns' => ['makop' => 2], 'first' => 1000, 'last' => 2000], ['extension', 'overwrite']);
    Assert::contains('2 Datei(en) mit Ransomware-Endung (makop)', $values['summary']);
    Assert::contains('300 Datei(en) in kurzer Zeit überschrieben', $values['summary']);
    Assert::same('extension,overwrite', $values['rules']);

    $targets = [
        ['id' => 1, 'label' => 'A', 'active' => true, 'state' => 'online', 'in_sync' => true, 'lag_seconds' => 0],
        ['id' => 2, 'label' => 'B', 'active' => true, 'state' => 'online', 'in_sync' => false, 'lag_seconds' => 99999, 'frozen' => true],
    ];
    $health = StorageHealth::evaluate(true, $targets, 1, 1, ['sync_state' => 'in_sync'], 900);
    Assert::same('degraded', $health['ha']['state']);
    Assert::contains('Speicherziel B wegen Sicherheitsvorfall schreibgeschützt', $health['ha']['message']);
    Assert::false(str_contains($health['ha']['message'], 'Rückstand'));
    Assert::same(['B'], $health['frozen_labels']);
});

Runner::test('Vorfälle: schreibgeschützte Einbindung des Schutzziels', static function (): void {
    $file = sys_get_temp_dir() . '/lanpa-mountinfo-' . bin2hex(random_bytes(4));
    file_put_contents($file, "36 35 0:30 / /mnt/targets/1 rw,relatime shared:1 - cifs //nas/a rw\n37 35 0:31 / /mnt/targets/2 ro,relatime shared:2 - cifs //nas/b ro\n");
    Assert::false(Mounter::mountedReadOnly('/mnt/targets/1', $file));
    Assert::true(Mounter::mountedReadOnly('/mnt/targets/2', $file));
    Assert::null(Mounter::mountedReadOnly('/mnt/targets/3', $file));
    Assert::true(Mounter::isMounted('/mnt/targets/2', $file));
    unlink($file);

    $mounter = new Mounter('/mnt/targets', '/run/storage-sync', new \App\Security\SecretBox(sys_get_temp_dir() . '/lanpa-k-' . bin2hex(random_bytes(4)) . '/k.key'), 'inst');
    Assert::contains('ro,', $mounter->options(['smb_version' => 'auto'], null, true));
    Assert::false(str_contains($mounter->options(['smb_version' => 'auto'], null), 'ro,'));
});

Runner::test('Vorfälle: Repository und Erledigung heben die Einschränkung auf', static function (): void {
    $pdo = incidentPdo();
    $repository = new IncidentRepository($pdo);
    $service = new IncidentService($repository, new StorageRepository($pdo), new SettingsService(new SettingsRepository($pdo)));
    Assert::null($service->dashboardAlert());

    $id = $repository->create(Agent::incidentValues([
        'changed' => 5, 'suspicious' => 5, 'extension' => 1, 'bytes' => 4096, 'first' => time() - 60, 'last' => time(),
        'patterns' => ['makop' => 1], 'samples' => [['path' => 'alice/files/a.makop', 'kind' => 'extension', 'detail' => 'makop', 'size' => 1, 'at' => time()]],
        'clients' => [['ip' => '10.0.0.7', 'ua' => 'Firefox', 'writes' => 3, 'last' => time()]],
    ], ['extension']) + ['uid' => 'alice', 'user_restricted' => 1, 'source' => PathRules::SOURCE_NEXTCLOUD_DATA]);
    $second = $repository->create(['uid' => 'bob', 'rules' => 'overwrite', 'user_restricted' => 1]);
    $repository->assignFrozenTarget(2, 'NAS B');
    Assert::same(2, $service->openCount());
    $alert = $service->dashboardAlert();
    Assert::same(['alice', 'bob'], $alert['users']);
    Assert::same('NAS B', $alert['target']);
    Assert::true($alert['freeze']);

    // Einstellung "nur Benutzer einschraenken": kein Schutzziel, Sync laeuft weiter.
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('incident_freeze_target', '0')");
    $userOnly = new IncidentService($repository, new StorageRepository($pdo), new SettingsService(new SettingsRepository($pdo)));
    Assert::false($userOnly->settings()->freezeTarget());
    $alert = $userOnly->dashboardAlert();
    Assert::false($alert['freeze']);
    Assert::same('', $alert['target']);
    Assert::contains('weiter synchronisiert', $alert['message']);
    $repository->releaseFrozenTarget();
    foreach ($repository->open() as $row) {
        Assert::null($row['frozen_target_id']);
        Assert::same('', (string) $row['frozen_target_label']);
    }
    $repository->assignFrozenTarget(2, 'NAS B');
    $pdo->exec("DELETE FROM settings WHERE setting_key = 'incident_freeze_target'");
    Assert::same(['incident_freeze_target' => '1'], array_intersect_key(IncidentSettings::validate(['incident_freeze_target' => '1'])['values'], ['incident_freeze_target' => 1]));
    Assert::same('0', IncidentSettings::validate(['incident_freeze_target' => '0'])['values']['incident_freeze_target']);

    $list = $service->list();
    Assert::same($id, $list[1]['id'] === $id ? $list[1]['id'] : $list[0]['id']);
    $found = $service->find($id);
    Assert::true($found['open']);
    Assert::same(['Ransomware-Endung'], $found['rule_labels']);
    Assert::same('10.0.0.7', $found['clients'][0]['ip']);

    $result = $service->resolve($id, 'admin');
    Assert::true($result['resolved']);
    Assert::same(1, $result['remaining']);
    Assert::false($service->resolve($id, 'admin')['resolved']);
    Assert::same(['alice'], array_keys($repository->resolvedSince(date('Y-m-d H:i:s', time() - 60))));
    $result = $service->resolve($second, 'admin');
    Assert::same(0, $result['remaining']);
    Assert::same('NAS B', $result['target']);
    Assert::null($service->dashboardAlert());
    $events = $pdo->query('SELECT message FROM storage_events ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    Assert::contains('Synchronisation des Speicherziels „NAS B“ wird fortgesetzt', (string) end($events));
    Assert::same(IncidentSettings::DEFAULT_PATTERNS, $service->settings()->patterns());
});

Runner::test('Vorfälle: Nextcloud erkennt eingeschränkte Benutzer', static function (): void {
    $env = storageAgentEnv(1);
    $env['store']->writeConfig(['enabled' => true, 'restricted' => ['alice'], 'restriction_message' => 'Bitte Support anrufen.']);
    $client = new TieringClient($env['base'] . '/tiering', $env['data']);
    Assert::true($client->isRestricted('alice'));
    Assert::false($client->isRestricted('bob'));
    Assert::false($client->isRestricted(''));
    Assert::same('Bitte Support anrufen.', $client->restrictionMessage());

    $env['store']->writeConfig(['enabled' => true]);
    $fresh = new TieringClient($env['base'] . '/tiering', $env['data']);
    Assert::false($fresh->isRestricted('alice'));
    Assert::contains('Support', $fresh->restrictionMessage());
    storageAgentRemove($env['base']);
});

Runner::test('Vorfälle: Adminseite mit Tabelle und Bestätigungs-Overlay', static function (): void {
    View::setViewPath(BASE_PATH . '/views');
    $incident = IncidentService::present([
        'id' => 4, 'status' => 'open', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:05:00', 'uid' => 'alice<x>',
        'attribution' => 'session', 'rules' => 'extension,content', 'summary' => '3 Datei(en) mit Ransomware-Endung (makop).', 'source' => 'nextcloud-data',
        'files_changed' => 3, 'files_suspicious' => 2, 'files_extension' => 3, 'bytes' => 2048, 'first_seen' => '2026-10-01 09:59:00',
        'last_seen' => '2026-10-01 10:00:00', 'user_restricted' => 1, 'frozen_target_id' => 2, 'frozen_target_label' => 'NAS B',
        'resolved_at' => null, 'resolved_by' => '',
        'details' => json_encode(['samples' => [['path' => 'alice/files/a.makop', 'kind' => 'extension', 'detail' => 'makop', 'size' => 10, 'at' => 1]],
            'patterns' => ['makop' => 3], 'reasons' => [], 'owners' => ['alice' => 3], 'clients' => [['ip' => '10.0.0.7', 'ua' => 'Firefox', 'writes' => 3, 'last' => 1]]]),
    ]);
    $data = [
        'incidents' => [$incident],
        'alert' => ['count' => 1, 'users' => ['alice<x>'], 'target' => 'NAS B', 'freeze' => true, 'title' => 'Sicherheitsvorfall', 'message' => 'Text'],
        'confirm' => null,
        'settings' => new IncidentSettings([]),
        'targets' => [2 => 'NAS B'],
        'tieringEnabled' => true,
        'errors' => [],
        'values' => [],
    ];
    $html = View::render('admin.incidents', $data);
    Assert::contains('alice&lt;x&gt;', $html);
    Assert::contains('Ransomware-Endung', $html);
    Assert::contains('IP 10.0.0.7', $html);
    Assert::contains('Ziel „NAS B“ schreibgeschützt', $html);
    Assert::contains('href="/admin/vorfaelle?erledigen=4#liste"', $html);
    Assert::contains('name="incident_extensions"', $html);
    Assert::false(str_contains($html, 'data-incident-overlay'));
    Assert::false(str_contains($html, 'style="'));
    Assert::contains('id="incident_freeze_target_1" name="incident_freeze_target" value="1" checked', $html);
    Assert::contains('Nur den Benutzer einschränken', $html);

    $userOnly = View::render('admin.incidents', [
        'alert' => ['count' => 1, 'users' => ['alice'], 'target' => '', 'freeze' => false, 'title' => 'Sicherheitsvorfall', 'message' => 'Text'],
        'settings' => new IncidentSettings(['incident_freeze_target' => '0']),
    ] + $data);
    Assert::contains('value="0" checked', $userOnly);
    Assert::contains('werden weiter synchronisiert', $userOnly);
    Assert::false(str_contains($userOnly, 'Geschütztes Speicherziel</strong>'));

    $html = View::render('admin.incidents', ['confirm' => $incident] + $data);
    Assert::contains('Event wirklich als erledigt markieren?', $html);
    Assert::contains('name="confirm" value="ja"', $html);
    Assert::contains('>Ja</button>', $html);
    Assert::contains('data-incident-overlay-cancel>Nein</a>', $html);
});
