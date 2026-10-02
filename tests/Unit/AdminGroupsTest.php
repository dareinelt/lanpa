<?php

declare(strict_types=1);

use App\Exceptions\ValidationException;
use App\Repositories\AdminGroupRepository;
use App\Repositories\SettingsRepository;
use App\Security\Auth;
use App\Services\AdminGroupService;
use App\Services\Office\NextcloudAdminService;
use App\Services\Office\OfficeHealthService;
use App\Services\Office\OfficeJwt;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\FakeAdminUserStore;
use Tests\Support\Runner;

const ADMINS_URL = 'POST http://nextcloud/office/index.php/apps/intranet_integration/api/admins';

function adminGroupsPdo(): PDO
{
    $pdo = quotaPdo();
    $pdo->exec('CREATE TABLE admin_group_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        target TEXT NOT NULL,
        group_name TEXT NOT NULL,
        created_by TEXT NOT NULL DEFAULT \'\',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (target, group_name)
    )');

    return $pdo;
}

function adminGroupService(PDO $pdo): AdminGroupService
{
    return new AdminGroupService(
        new AdminGroupRepository($pdo),
        static fn (): array => [0 => ['key' => '', 'label' => 'Hauptdomäne'], 7 => ['key' => 'Klinik', 'label' => 'Klinik-AD']]
    );
}

function nextcloudAdminService(PDO $pdo, FakeOfficeProbe $probe): NextcloudAdminService
{
    return new NextcloudAdminService(adminGroupService($pdo), new SettingsService(new SettingsRepository($pdo)), officeConfig(), $probe);
}

Runner::test('AD-Admins: Gruppen je Ziel eintragen, Dubletten und Mehrfachangaben abweisen', static function (): void {
    $service = adminGroupService(adminGroupsPdo());
    $service->addRule('intranet', ' GG_Speicher_2GB ', 'admin');
    $service->addRule('nextcloud', 'GG_Speicher_2GB', 'admin');

    $errors = [];
    try {
        $service->addRule('intranet', 'gg_speicher_2gb', 'admin');
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }
    Assert::same(['intranet_group' => 'Diese AD-Gruppe ist bereits eingetragen.'], $errors);

    $errors = [];
    try {
        $service->addRule('nextcloud', 'A, B', 'admin');
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }
    Assert::same(['nextcloud_group' => 'Bitte genau eine AD-Gruppe angeben.'], $errors);

    $errors = [];
    try {
        $service->addRule('root', 'GG_X', 'admin');
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }
    Assert::true(isset($errors['target']));

    $rules = $service->rules('intranet');
    Assert::same(1, count($rules));
    Assert::same('GG_Speicher_2GB', $rules[0]['group_name']);
    Assert::same(2, $rules[0]['members'], 'amueller und bschmidt.');
    Assert::same('admin', $rules[0]['created_by']);

    $deleted = $service->deleteRule($rules[0]['id']);
    Assert::same('intranet', $deleted['target'] ?? null);
    Assert::same([], $service->rules('intranet'));
    Assert::same(1, count($service->rules('nextcloud')), 'Andere Ziele bleiben unberuehrt.');
    Assert::null($service->deleteRule(999));
});

Runner::test('AD-Admins: Intranet-Rolle nur fuer Mitglieder eingetragener Gruppen', static function (): void {
    $service = adminGroupService(adminGroupsPdo());
    Assert::null($service->intranetRole(['gg_speicher_2gb']), 'Ohne Regel kein Zugriff.');

    $service->addRule('intranet', 'GG_Speicher_2GB', 'admin');
    $service->addRule('nextcloud', 'GG_Speicher_5GB', 'admin');
    Assert::same(Auth::ROLE_ADMIN, $service->intranetRole(['GG_Speicher_2GB']));
    Assert::same(Auth::ROLE_ADMIN, $service->intranetRole(['andere', 'gg_speicher_2gb']), 'Gross-/Kleinschreibung egal.');
    Assert::null($service->intranetRole(['gg_speicher_5gb']), 'Nextcloud-Gruppen berechtigen nicht fuer das Intranet.');
    Assert::null($service->intranetRole([]));
});

Runner::test('AD-Admins: Mitglieder und Nextcloud-Kennungen aus aktiven Gruppen und bekannten Quellen', static function (): void {
    $pdo = adminGroupsPdo();
    $pdo->exec('INSERT INTO ad_group_members (group_id, phonebook_id) VALUES (2, 6), (2, 4)');
    $pdo->exec("UPDATE ad_groups SET active = 1 WHERE id = 3");
    $pdo->exec("UPDATE ad_groups SET active = 0 WHERE id = 1");
    $service = adminGroupService($pdo);
    $service->addRule('nextcloud', 'GG_Speicher_2GB', 'admin');
    $service->addRule('nextcloud', 'gg_speicher_5gb', 'admin');
    $service->addRule('nextcloud', 'GG_Alt', 'admin');

    $members = [];
    foreach ($service->members('nextcloud') as $member) {
        $members[$member['uid']] = $member;
    }
    Assert::same(['amueller', 'cmeier@klinik'], array_keys($members), 'Inaktive Gruppen/Konten und unbekannte Quellen zaehlen nicht.');
    Assert::same(['gg_speicher_5gb'], $members['amueller']['groups']);
    Assert::same('Klinik-AD', $members['cmeier@klinik']['source_label']);
    Assert::same(['amueller', 'cmeier@klinik'], $service->nextcloudUids());
    Assert::same([], $service->members('intranet'));
});

Runner::test('AD-Admins: Uebertragung an Nextcloud ist signiert und an den Inhalt gebunden', static function (): void {
    $pdo = adminGroupsPdo();
    $probe = new FakeOfficeProbe();
    $probe->responses[ADMINS_URL] = ['status' => 200, 'body' => '{"ok":true,"message":"Administratoren uebernommen: 2 Benutzer."}', 'error' => null];
    $service = nextcloudAdminService($pdo, $probe);
    adminGroupService($pdo)->addRule('nextcloud', 'GG_Speicher_2GB', 'admin');

    $result = $service->pushToNextcloud();
    Assert::true($result['ok']);
    Assert::same(1, count($probe->requests));
    $request = $probe->requests[0];
    $payload = json_decode((string) $request['body'], true);
    Assert::same(['amueller', 'bschmidt'], $payload['users']);
    Assert::same(NextcloudAdminService::fingerprintOf(['bschmidt', 'amueller']), $payload['fingerprint']);

    $token = substr($request['headers']['Authorization'] ?? '', 7);
    $claims = OfficeJwt::decode($token, 'test-secret-0123456789');
    Assert::same(OfficeJwt::ADMINS_AUDIENCE, $claims['aud'] ?? null);
    Assert::same(hash('sha256', (string) $request['body']), $claims['body'] ?? null);

    $pushed = $payload['fingerprint'];
    Assert::true($service->inSync($pushed));
    adminGroupService($pdo)->addRule('nextcloud', 'GG_Speicher_5GB', 'admin');
    Assert::true($service->inSync($pushed), 'amueller ist schon dabei - gleicher Stand.');
    $pdo->exec('INSERT INTO ad_group_members (group_id, phonebook_id) VALUES (2, 3)');
    $pdo->exec('UPDATE phonebook SET identity_source_id = 0 WHERE id = 3');
    Assert::false($service->inSync($pushed), 'Neues Mitglied -> Abgleich ausstehend.');
    Assert::false($service->inSync(null));
    $probe->responses[ADMINS_URL] = ['status' => 404, 'body' => '', 'error' => null];
    $result = $service->pushToNextcloud();
    Assert::false($result['ok']);
    Assert::contains('intranet_integration', $result['message']);
});

Runner::test('AD-Admins: Gesundheitspruefung gleicht Administratoren mit Nextcloud ab', static function (): void {
    $secret = 'test-secret-0123456789';
    $diagnosticsUrl = 'GET http://nextcloud/office/index.php/apps/intranet_integration/api/diagnostics';
    $withAdmins = static function (FakeOfficeProbe $probe, ?array $admins) use ($diagnosticsUrl): FakeOfficeProbe {
        $body = json_decode($probe->responses[$diagnosticsUrl]['body'], true);
        if ($admins !== null) {
            $body['admins'] = $admins;
        }
        $probe->responses[$diagnosticsUrl]['body'] = json_encode($body);
        $probe->responses[ADMINS_URL] = ['status' => 200, 'body' => '{"ok":true,"message":"uebernommen"}', 'error' => null];

        return $probe;
    };
    $health = static fn (FakeOfficeProbe $probe, NextcloudAdminService $admins): OfficeHealthService => new OfficeHealthService(
        officeConfig(), $probe, officeTempDir() . '/health.json', 30, null, null, null, $admins
    );
    $pushes = static fn (FakeOfficeProbe $probe): int => count(array_filter($probe->requests, static fn (array $r): bool => str_ends_with($r['url'], '/api/admins')));

    $pdo = adminGroupsPdo();
    adminGroupService($pdo)->addRule('nextcloud', 'GG_Speicher_2GB', 'admin');

    $probe = $withAdmins(FakeOfficeProbe::healthy($secret), null);
    $admins = nextcloudAdminService($pdo, $probe);
    $probe = $withAdmins($probe, ['fingerprint' => $admins->fingerprint(), 'pending' => 1]);
    $result = $health($probe, $admins)->check();
    Assert::same(OfficeHealthService::OK, $result['components']['admins']['status']);
    Assert::contains('2 Administrator(en)', $result['components']['admins']['message']);
    Assert::same(0, $pushes($probe));

    $probe = $withAdmins(FakeOfficeProbe::healthy($secret), ['fingerprint' => str_repeat('0', 64)]);
    $admins = nextcloudAdminService($pdo, $probe);
    $result = $health($probe, $admins)->check();
    Assert::same(OfficeHealthService::OK, $result['components']['admins']['status']);
    Assert::contains('Aktualisiert', $result['components']['admins']['message']);
    Assert::same(1, $pushes($probe));

    $probe = $withAdmins(FakeOfficeProbe::healthy($secret), null);
    $admins = nextcloudAdminService($pdo, $probe);
    $result = $health($probe, $admins)->check();
    Assert::same(OfficeHealthService::WARN, $result['components']['admins']['status']);
    Assert::contains('veraltet', $result['components']['admins']['message']);
});

Runner::test('AD-Admins: Anmeldung per Windows-Anmeldung, Rechte werden bei jeder Anfrage geprueft', static function (): void {
    $_SESSION = [];
    $role = Auth::ROLE_ADMIN;
    $seen = [];
    $directoryRole = static function (array $identity) use (&$role, &$seen): ?string {
        $seen[] = $identity;

        return $role;
    };
    $auth = new Auth(new FakeAdminUserStore(), 3600, $directoryRole);
    Assert::false($auth->check());

    $auth->loginDirectory([
        'username' => 'cmeier',
        'source_key' => 'klinik',
        'display_name' => 'Carla Meier',
    ], Auth::ROLE_ADMIN);
    Assert::true($auth->check());
    Assert::true($auth->isAdmin());
    Assert::true($auth->isDirectoryUser());
    Assert::null($auth->id());
    Assert::same('cmeier@klinik', $auth->username());
    Assert::same('Carla Meier', $auth->displayName());
    Assert::same([], $seen, 'Direkt nach der Anmeldung keine erneute Pruefung.');

    // Naechste Anfrage: neue Auth-Instanz, Pruefung ueber die Gruppen.
    $auth = new Auth(new FakeAdminUserStore(), 3600, $directoryRole);
    Assert::true($auth->check());
    Assert::true($auth->check());
    Assert::same([['username' => 'cmeier', 'source_key' => 'KLINIK']], $seen, 'Nur einmal je Anfrage.');

    // Aus der Gruppe entfernt -> Sitzung endet.
    $role = null;
    $auth = new Auth(new FakeAdminUserStore(), 3600, $directoryRole);
    Assert::false($auth->check());
    Assert::false($auth->isDirectoryUser());
    Assert::null($auth->username());

    // Ohne Pruffunktion keine AD-Anmeldung.
    $_SESSION = [];
    $auth = new Auth(new FakeAdminUserStore());
    $auth->loginDirectory(['username' => 'cmeier', 'source_key' => ''], Auth::ROLE_ADMIN);
    $auth = new Auth(new FakeAdminUserStore());
    Assert::false($auth->check());

    // Ungueltige Rolle wird ignoriert.
    $_SESSION = [];
    $auth = new Auth(new FakeAdminUserStore(), 3600, $directoryRole);
    $auth->loginDirectory(['username' => 'cmeier', 'source_key' => ''], 'root');
    Assert::false($auth->check());
    $_SESSION = [];
});
