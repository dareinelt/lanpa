<?php

declare(strict_types=1);

use App\Exceptions\ValidationException;
use App\Repositories\SettingsRepository;
use App\Repositories\StorageQuotaRepository;
use App\Services\Office\OfficeHealthService;
use App\Services\Office\OfficeJwt;
use App\Services\Office\StorageQuotaService;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

const QUOTA_URL = 'POST http://nextcloud/office/index.php/apps/intranet_integration/api/quota';

function quotaPdo(array $settings = []): PDO
{
    $pdo = officePdo($settings);
    $pdo->exec('CREATE TABLE phonebook (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        identity_source_id INTEGER NOT NULL DEFAULT 0,
        samaccount_name TEXT NULL,
        display_name TEXT NOT NULL DEFAULT \'\',
        department TEXT NOT NULL DEFAULT \'\',
        email TEXT NOT NULL DEFAULT \'\',
        active INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec('CREATE TABLE ad_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE TABLE ad_group_members (group_id INTEGER NOT NULL, phonebook_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE storage_quota_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_name TEXT NOT NULL UNIQUE,
        quota_mb INTEGER NOT NULL,
        reason TEXT NOT NULL DEFAULT \'\',
        updated_by TEXT NOT NULL DEFAULT \'\',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE storage_quota_overrides (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_uid TEXT NOT NULL UNIQUE,
        display_name TEXT NOT NULL DEFAULT \'\',
        quota_mb INTEGER NOT NULL,
        reason TEXT NOT NULL,
        created_by TEXT NOT NULL DEFAULT \'\',
        updated_by TEXT NOT NULL DEFAULT \'\',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE storage_quota_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        subject_type TEXT NOT NULL,
        subject TEXT NOT NULL DEFAULT \'\',
        subject_label TEXT NOT NULL DEFAULT \'\',
        action TEXT NOT NULL,
        old_quota_mb INTEGER NULL,
        new_quota_mb INTEGER NULL,
        reason TEXT NOT NULL DEFAULT \'\',
        admin_username TEXT NOT NULL DEFAULT \'\',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $person = $pdo->prepare('INSERT INTO phonebook (id, identity_source_id, samaccount_name, display_name, department, active) VALUES (?, ?, ?, ?, ?, ?)');
    $person->execute([1, 0, 'amueller', 'Anna Müller', 'IT', 1]);
    $person->execute([2, 0, 'bschmidt', 'Bernd Schmidt', 'Bauamt', 1]);
    $person->execute([3, 7, 'cmeier', 'Carla Meier', 'Klinik', 1]);
    $person->execute([4, 0, 'dalt', 'Dieter Alt', 'IT', 0]);
    $person->execute([5, 0, '', 'Empfang', '', 1]);
    $person->execute([6, 99, 'xunbekannt', 'Unbekannte Quelle', '', 1]);

    $pdo->exec("INSERT INTO ad_groups (id, name, active) VALUES (1, 'GG_Speicher_2GB', 1), (2, 'GG_Speicher_5GB', 1), (3, 'GG_Alt', 0)");
    $pdo->exec('INSERT INTO ad_group_members (group_id, phonebook_id) VALUES (1, 1), (2, 1), (1, 2), (3, 3)');

    return $pdo;
}

function quotaService(PDO $pdo, ?FakeOfficeProbe $probe = null): StorageQuotaService
{
    return new StorageQuotaService(
        new StorageQuotaRepository($pdo),
        new SettingsService(new SettingsRepository($pdo)),
        officeConfig(),
        $probe ?? new FakeOfficeProbe(),
        static fn (): array => [0 => ['key' => '', 'label' => 'Hauptdomäne'], 7 => ['key' => 'Klinik', 'label' => 'Klinik-AD']]
    );
}

function quotaValidationErrors(callable $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

Runner::test('Speicherplatz: Groessenangaben werden geparst und formatiert', static function (): void {
    Assert::same(500, StorageQuotaService::parseSize('500'));
    Assert::same(500, StorageQuotaService::parseSize(' 500 MB '));
    Assert::same(2048, StorageQuotaService::parseSize('2 GB'));
    Assert::same(1536, StorageQuotaService::parseSize('1,5 GB'));
    Assert::same(1536, StorageQuotaService::parseSize('1.5g'));
    Assert::same(1048576, StorageQuotaService::parseSize('1 TB'));
    Assert::null(StorageQuotaService::parseSize(''));
    Assert::null(StorageQuotaService::parseSize('0'));
    Assert::null(StorageQuotaService::parseSize('-5 GB'));
    Assert::null(StorageQuotaService::parseSize('unbegrenzt'));
    Assert::null(StorageQuotaService::parseSize('11 TB'));

    Assert::same('500 MB', StorageQuotaService::formatMb(500));
    Assert::same('2 GB', StorageQuotaService::formatMb(2048));
    Assert::same('1,5 GB', StorageQuotaService::formatMb(1536));
    Assert::same('1000 MB', StorageQuotaService::formatMb(1000));
    Assert::same('1 TB', StorageQuotaService::formatMb(1048576));
    Assert::same('–', StorageQuotaService::formatMb(null));
});

Runner::test('Speicherplatz: Standard 500 MB, groesste AD-Gruppe, individuelles Kontingent hat Vorrang', static function (): void {
    $pdo = quotaPdo();
    $service = quotaService($pdo);
    Assert::same(500, $service->defaultMb());

    $service->saveGroupRule('GG_Speicher_2GB', '2 GB', 'Fachbereiche mit Plänen', 'admin');
    $service->saveGroupRule('gg_speicher_5gb', '5 GB', '', 'admin');
    $service->saveGroupRule('GG_Alt', '9 GB', '', 'admin');

    $users = [];
    foreach ($service->users() as $user) {
        $users[$user['uid']] = $user;
    }
    Assert::same(['amueller', 'bschmidt', 'cmeier@klinik'], array_keys($users), 'Nur aktive Konten mit Kennung und bekannter Quelle.');
    Assert::same(5120, $users['amueller']['effective_mb'], 'Bei mehreren Gruppen gilt das groesste Kontingent.');
    Assert::same('group', $users['amueller']['origin']);
    Assert::same(['GG_Speicher_2GB', 'gg_speicher_5gb'], $users['amueller']['groups']);
    Assert::same(2048, $users['bschmidt']['effective_mb']);
    Assert::same(500, $users['cmeier@klinik']['effective_mb'], 'Inaktive AD-Gruppen zaehlen nicht.');
    Assert::same('default', $users['cmeier@klinik']['origin']);
    Assert::same('Klinik-AD', $users['cmeier@klinik']['source_label']);

    $service->saveOverride(3, '1,5 GB', 'Projekt Bildarchiv', 'admin');
    $service->saveOverride(1, '1 GB', 'Reduziert auf Wunsch', 'chef');
    $carla = $service->findUser(3);
    Assert::same(1536, $carla['effective_mb']);
    Assert::same('override', $carla['origin']);
    Assert::same(1024, $service->findUser(1)['effective_mb'], 'Individuell hat Vorrang vor der Gruppe – auch nach unten.');

    Assert::same(['bschmidt', 'cmeier@klinik', 'amueller'], array_column($service->aboveDefault(), 'uid'));
    Assert::same([], $service->overridesNotAboveDefault());
    Assert::same(['total' => 3, 'above' => 3, 'overrides' => 2, 'groups' => 3], $service->summary());
    Assert::same(['amueller' => 1024, 'bschmidt' => 2048, 'cmeier@klinik' => 1536], $service->nextcloudUsers());
    Assert::same(1, count($service->searchUsers('meier klinik')));
});

Runner::test('Speicherplatz: Begruendung ist Pflicht, Verlauf protokolliert wer wem was gab', static function (): void {
    $pdo = quotaPdo();
    $service = quotaService($pdo);

    Assert::true(isset(quotaValidationErrors(static fn () => $service->saveOverride(2, '2 GB', '', 'admin'))['reason']));
    Assert::true(isset(quotaValidationErrors(static fn () => $service->saveOverride(2, '2 GB', 'kurz', 'admin'))['reason']));
    Assert::true(isset(quotaValidationErrors(static fn () => $service->saveOverride(2, 'viel', 'Begründung ok', 'admin'))['quota']));
    Assert::true(isset(quotaValidationErrors(static fn () => $service->saveOverride(5, '2 GB', 'Begründung ok', 'admin'))['user']), 'Ohne Nextcloud-Kennung kein Kontingent.');
    Assert::true(isset(quotaValidationErrors(static fn () => $service->saveGroupRule('A, B', '2 GB', '', 'admin'))['group_name']));
    Assert::same(0, $service->countHistory());

    $service->saveOverride(2, '2 GB', 'Bauakten digitalisiert', 'admin');
    $service->saveOverride(2, '2 GB', 'Bauakten digitalisiert', 'admin');
    $service->saveOverride(2, '3 GB', 'Weitere Bauakten', 'chef');
    Assert::true(isset(quotaValidationErrors(static fn () => $service->removeOverride('bschmidt', '', 'admin'))['remove_reason']));
    $service->removeOverride('BSCHMIDT', 'Projekt abgeschlossen', 'admin');
    Assert::null($service->findOverride('bschmidt'));

    $history = $service->history(10, 0, 'user', 'bschmidt');
    Assert::same(3, count($history), 'Unveraenderte Speicherung erzeugt keinen Eintrag.');
    $byAction = array_column($history, null, 'action');
    Assert::same('admin', $byAction['set']['admin_username']);
    Assert::same('Bernd Schmidt', $byAction['set']['subject_label']);
    Assert::same(500, (int) $byAction['set']['old_quota_mb']);
    Assert::same(2048, (int) $byAction['set']['new_quota_mb']);
    Assert::same('chef', $byAction['change']['admin_username']);
    Assert::same('Weitere Bauakten', $byAction['change']['reason']);
    Assert::same(3072, (int) $byAction['remove']['old_quota_mb']);
    Assert::same(500, (int) $byAction['remove']['new_quota_mb'], 'Nach dem Entfernen gilt wieder der Standard.');
    Assert::same(3, $service->countHistory('user'));
    Assert::same(0, $service->countHistory('group'));
});

Runner::test('Speicherplatz: individuelle Kontingente ausgeschiedener Benutzer bleiben sichtbar und wirksam', static function (): void {
    $pdo = quotaPdo();
    $service = quotaService($pdo);
    $service->saveOverride(1, '4 GB', 'Leitung IT', 'admin');
    $service->saveOverride(2, '200 MB', 'Nur Austauschordner', 'admin');
    $pdo->exec('UPDATE phonebook SET active = 0 WHERE id = 1');
    $service = quotaService($pdo);

    $above = $service->aboveDefault();
    Assert::same(['amueller'], array_column($above, 'uid'));
    Assert::false($above[0]['active']);
    Assert::same(0, $above[0]['id']);
    Assert::same(['bschmidt'], array_column($service->overridesNotAboveDefault(), 'uid'));
    Assert::same(['amueller' => 4096, 'bschmidt' => 200], $service->nextcloudUsers());

    $service->removeOverride('amueller', 'Mitarbeiterin ausgeschieden', 'admin');
    Assert::same(['bschmidt' => 200], $service->nextcloudUsers());
});

Runner::test('Speicherplatz: Uebertragung an Nextcloud ist signiert und an den Inhalt gebunden', static function (): void {
    $pdo = quotaPdo([StorageQuotaService::DEFAULT_SETTING => '1024']);
    $probe = new FakeOfficeProbe();
    $probe->responses[QUOTA_URL] = ['status' => 200, 'error' => null, 'body' => json_encode(['ok' => true, 'message' => 'Kontingente übernommen.'])];
    $service = quotaService($pdo, $probe);
    $service->saveGroupRule('GG_Speicher_2GB', '2 GB', '', 'admin');

    $result = $service->pushToNextcloud();
    Assert::true($result['ok'], $result['message']);
    $request = $probe->requests[0];
    $payload = json_decode((string) $request['body'], true);
    Assert::same(1024, $payload['default_mb']);
    Assert::same(['amueller' => 2048, 'bschmidt' => 2048], $payload['users']);
    Assert::same($service->fingerprint(), $payload['fingerprint']);
    Assert::same(StorageQuotaService::fingerprintOf(1024, ['bschmidt' => 2048, 'amueller' => 2048]), $payload['fingerprint'], 'Fingerabdruck ist reihenfolgeunabhaengig.');

    $claims = OfficeJwt::decode(substr($request['headers']['Authorization'], 7), 'test-secret-0123456789');
    Assert::same(OfficeJwt::QUOTA_AUDIENCE, $claims['aud'] ?? null);
    Assert::same(hash('sha256', (string) $request['body']), $claims['body'] ?? null);

    $empty = json_decode((string) json_encode(quotaService(quotaPdo(), $probe)->payload(), JSON_FORCE_OBJECT));
    Assert::true($empty->users instanceof stdClass, 'Leere Benutzerliste wird als JSON-Objekt uebertragen.');

    $probe->responses[QUOTA_URL] = ['status' => 401, 'error' => null, 'body' => ''];
    Assert::contains('JWT', $service->pushToNextcloud()['message']);
    $probe->responses[QUOTA_URL] = ['status' => 404, 'error' => null, 'body' => ''];
    Assert::contains('veraltet', $service->pushToNextcloud()['message']);
});

Runner::test('Speicherplatz: Gesundheitspruefung gleicht bei Abweichung ab', static function (): void {
    $secret = 'test-secret-0123456789';
    $diagnosticsUrl = 'GET http://nextcloud/office/index.php/apps/intranet_integration/api/diagnostics';
    $withQuota = static function (FakeOfficeProbe $probe, ?array $quota) use ($diagnosticsUrl): FakeOfficeProbe {
        $body = json_decode($probe->responses[$diagnosticsUrl]['body'], true);
        if ($quota !== null) {
            $body['quota'] = $quota;
        }
        $probe->responses[$diagnosticsUrl]['body'] = json_encode($body);

        return $probe;
    };
    $health = static fn (FakeOfficeProbe $probe, StorageQuotaService $quotas): OfficeHealthService => new OfficeHealthService(
        officeConfig(), $probe, officeTempDir() . '/health.json', 30, null, null, $quotas
    );
    $pushes = static fn (FakeOfficeProbe $probe): int => count(array_filter($probe->requests, static fn (array $r): bool => str_ends_with($r['url'], '/api/quota')));

    // Gleicher Stand: keine Uebertragung, Office bleibt "ok".
    $pdo = quotaPdo();
    $probe = FakeOfficeProbe::healthy($secret);
    $quotas = quotaService($pdo, $probe);
    $quotas->saveOverride(1, '2 GB', 'Leitung IT', 'admin');
    $withQuota($probe, ['fingerprint' => $quotas->fingerprint(), 'pending' => 1]);
    $result = $health($probe, $quotas)->check();
    Assert::same(OfficeHealthService::OK, $result['components']['quota']['status']);
    Assert::contains('noch nie in Nextcloud angemeldet', $result['components']['quota']['message']);
    Assert::same('ok', $result['state']);
    Assert::same(0, $pushes($probe));

    // Geaenderte Gruppenmitgliedschaft (AD-Sync): wird nachgetragen.
    $probe = $withQuota(FakeOfficeProbe::healthy($secret), ['fingerprint' => 'alt']);
    $probe->responses[QUOTA_URL] = ['status' => 200, 'error' => null, 'body' => json_encode(['ok' => true, 'message' => 'ok'])];
    $result = $health($probe, quotaService($pdo, $probe))->check();
    Assert::same(OfficeHealthService::OK, $result['components']['quota']['status']);
    Assert::true(!empty($result['diagnostics']['quota_pushed']));
    Assert::same(1, $pushes($probe));

    // Fehlschlag ist nur informativ: Office bleibt "ok".
    $probe = $withQuota(FakeOfficeProbe::healthy($secret), ['fingerprint' => 'alt']);
    $result = $health($probe, quotaService($pdo, $probe))->check();
    Assert::same(OfficeHealthService::WARN, $result['components']['quota']['status']);
    Assert::same('ok', $result['state']);

    // Veraltete Nextcloud-App ohne Kontingent-Status.
    $probe = FakeOfficeProbe::healthy($secret);
    $result = $health($probe, quotaService($pdo, $probe))->check();
    Assert::contains('veraltet', $result['components']['quota']['message']);
});
