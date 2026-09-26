<?php

declare(strict_types=1);

use App\Contracts\IdentitySourceStoreInterface;
use App\Core\Request;
use App\Repositories\IdentitySourceRepository;
use App\Repositories\SettingsRepository;
use App\Security\SecretBox;
use App\Repositories\PhonebookRepository;
use App\Security\SsoAuth;
use App\Services\AdSyncService;
use App\Services\IdentitySourceService;
use App\Services\LdapClient;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\FakeLdapClient;
use Tests\Support\FakePhonebookStore;
use Tests\Support\FakeSyncLog;
use Tests\Support\Runner;

final class FakeIdentitySourceStore implements IdentitySourceStoreInterface
{
    /**
     * @param array<string,array<string,mixed>> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    public function findActiveByKey(string $key): ?array
    {
        $row = $this->rows[$key] ?? null;

        return $row !== null && (int) $row['active'] === 1 ? $row : null;
    }
}

function multiSourcePdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE phonebook (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            external_id TEXT NULL,
            identity_source_id INTEGER NOT NULL DEFAULT 0,
            samaccount_name TEXT NULL,
            display_name TEXT NULL,
            first_name TEXT NULL,
            last_name TEXT NULL,
            email TEXT NULL,
            department TEXT NULL,
            active INTEGER NOT NULL DEFAULT 1
        )'
    );
    $insert = $pdo->prepare('INSERT INTO phonebook (external_id, identity_source_id, samaccount_name, display_name, active) VALUES (?, ?, ?, ?, 1)');
    $insert->execute(['hq-1', 0, 'mueller', 'Anna Müller (Zentrale)']);
    $insert->execute(['hh-1', 5, 'mueller', 'Jan Müller (Hamburg)']);

    return $pdo;
}

function multiSourceSso(string $trustedProxy = '10.0.0.2'): SsoAuth
{
    $sources = new FakeIdentitySourceStore([
        'HAMBURG' => ['id' => 5, 'source_key' => 'HAMBURG', 'label' => 'Zweigstelle Hamburg', 'active' => 1],
        'ALT' => ['id' => 6, 'source_key' => 'ALT', 'label' => 'Stillgelegt', 'active' => 0],
    ]);

    return new SsoAuth(
        new PhonebookRepository(multiSourcePdo()),
        ['enabled' => true, 'header' => 'X-Remote-User', 'source_header' => 'X-Remote-Source', 'trusted_proxy' => $trustedProxy],
        null,
        $sources,
        'Zentrale'
    );
}

/**
 * @param array<string,string> $headers
 */
function multiSourceRequest(array $headers, string $remote = '10.0.0.2'): Request
{
    return new Request('GET', '/', [], [], ['REMOTE_ADDR' => $remote] + $headers);
}

Runner::test('Hostliste akzeptiert Zeilen, Kommas und Leerzeichen ohne Duplikate', static function (): void {
    Assert::same(
        ['dc01.example.internal', 'dc02.example.internal', '10.0.0.5'],
        SettingsService::splitHostList("dc01.example.internal, dc02.example.internal\n10.0.0.5;dc01.example.internal")
    );
    Assert::same([], SettingsService::splitHostList('  '));
});

Runner::test('LDAP-Client nutzt alle Server in Reihenfolge und klammert IPv6', static function (): void {
    $client = new LdapClient(['hosts' => ['dc01', 'dc02'], 'host' => 'dc01']);
    Assert::same(['dc01', 'dc02'], $client->hosts());
    Assert::same(['dc09'], (new LdapClient(['host' => 'dc09']))->hosts());
    Assert::same('ldaps://dc01:636', LdapClient::uri('dc01', 636, true));
    Assert::same('ldap://[fd00::5]:389', LdapClient::uri('fd00::5', 389, false));
});

Runner::test('Kennung einer Identitätsquelle wird normalisiert und geprüft', static function (): void {
    Assert::same('HAMBURG_2', IdentitySourceService::normalizeKey(' hamburg_2 '));
    Assert::true(IdentitySourceService::isValidKey('HAMBURG'));
    Assert::false(IdentitySourceService::isValidKey('2HAMBURG'));
    Assert::false(IdentitySourceService::isValidKey('HAM-BURG'));
    Assert::same('auth-hamburg-2', IdentitySourceService::serviceName('hamburg_2'));
});

Runner::test('Verbindungsdaten einer Quelle: Beschriftung Pflicht, Serverliste geprüft', static function (): void {
    [$values, $errors] = IdentitySourceService::validateConnection([
        'ldap_label' => '',
        'ldap_host' => "dc01.hh.local\nkein host!",
        'ldap_port' => '636',
        'ldap_filter' => '(objectClass=user)',
    ]);
    Assert::true(isset($errors['ldap_label']));
    Assert::true(isset($errors['ldap_host']));

    // Formular ohne gesetzte Checkbox "Zertifikat pruefen".
    $defaults = IdentitySourceService::formValues(null);
    unset($defaults['ldap_use_tls'], $defaults['ldap_verify_cert']);

    [$values, $errors] = IdentitySourceService::validateConnection(array_merge($defaults, [
        'ldap_label' => 'Zweigstelle Hamburg',
        'ldap_host' => "dc01.hh.local, dc02.hh.local\n10.20.0.10",
        'ldap_port' => '636',
        'ldap_timeout' => '5',
        'ldap_base_dn' => 'DC=hh,DC=local',
        'ldap_filter' => '(objectClass=user)',
        'ldap_use_tls' => '1',
    ]));
    Assert::same([], $errors);
    Assert::same("dc01.hh.local\ndc02.hh.local\n10.20.0.10", $values['ldap_host']);
    Assert::same('1', $values['ldap_use_tls']);
    Assert::same('0', $values['ldap_verify_cert']);
});

Runner::test('Mehrere Quellen: Ausfall einer Quelle lässt die anderen unberührt', static function (): void {
    $store = new FakePhonebookStore();
    $log = new FakeSyncLog();
    $service = new AdSyncService([
        ['id' => 0, 'label' => 'Zentrale', 'client' => new FakeLdapClient([['external_id' => 'hq-1', 'display_name' => 'A']])],
        ['id' => 5, 'label' => 'Hamburg', 'client' => new FakeLdapClient([], true)],
        ['id' => 7, 'label' => 'München', 'client' => new FakeLdapClient([['external_id' => 'muc-1', 'display_name' => 'B']])],
    ], $store, $log, testLogger());

    $result = $service->run();

    Assert::same('partial', $result['status']);
    Assert::same(3, count($result['sources']));
    Assert::same('error', $result['sources'][1]['status']);
    Assert::same([0, 7], $store->deactivatedSources);
    Assert::same([0, 7], array_column($store->upserted, 'identity_source_id'));
    Assert::same(2, $store->commits);
    Assert::same('error', $log->entries[0]['status']);
    Assert::contains('Hamburg', (string) $log->entries[0]['message']);
});

Runner::test('Mehrere Quellen: alle erfolgreich ergibt Status success', static function (): void {
    $store = new FakePhonebookStore();
    $service = new AdSyncService([
        ['id' => 0, 'label' => 'Zentrale', 'client' => new FakeLdapClient([['external_id' => 'hq-1', 'display_name' => 'A']])],
        ['id' => 5, 'label' => 'Hamburg', 'client' => new FakeLdapClient([['external_id' => 'hh-1', 'display_name' => 'B']])],
    ], $store, new FakeSyncLog(), testLogger());

    $result = $service->run();

    Assert::same('success', $result['status']);
    Assert::same(2, $result['processed']);
    Assert::same([0, 5], $store->deactivatedSources);
});

Runner::test('SSO ohne Quellen-Header meldet den Benutzer der Hauptquelle an', static function (): void {
    $user = multiSourceSso()->resolve(multiSourceRequest(['HTTP_X_REMOTE_USER' => 'ZENTRALE\\mueller']));

    Assert::true($user !== null);
    Assert::same('Anna Müller (Zentrale)', $user['display_name']);
    Assert::same(0, $user['source_id']);
    Assert::same('mueller', $user['office_uid']);
});

Runner::test('SSO mit Quellen-Header trennt gleichnamige Konten verschiedener Domänen', static function (): void {
    $user = multiSourceSso()->resolve(multiSourceRequest([
        'HTTP_X_REMOTE_USER' => 'HH\\mueller',
        'HTTP_X_REMOTE_SOURCE' => 'HAMBURG',
    ]));

    Assert::true($user !== null);
    Assert::same('Jan Müller (Hamburg)', $user['display_name']);
    Assert::same(5, $user['source_id']);
    Assert::same('Zweigstelle Hamburg', $user['source_label']);
    Assert::same('mueller@hamburg', $user['office_uid']);
});

Runner::test('SSO mit unbekannter oder deaktivierter Quelle wird abgelehnt', static function (): void {
    $sso = multiSourceSso();
    Assert::null($sso->resolve(multiSourceRequest(['HTTP_X_REMOTE_USER' => 'mueller', 'HTTP_X_REMOTE_SOURCE' => 'MUENCHEN'])));
    Assert::null($sso->resolve(multiSourceRequest(['HTTP_X_REMOTE_USER' => 'mueller', 'HTTP_X_REMOTE_SOURCE' => 'ALT'])));
    Assert::null($sso->resolve(multiSourceRequest(['HTTP_X_REMOTE_USER' => 'mueller', 'HTTP_X_REMOTE_SOURCE' => 'x;y'])));
});

Runner::test('An eine Quelle gebundener Proxy darf keine andere Quelle melden', static function (): void {
    $sso = multiSourceSso('10.0.0.2=,10.0.0.3=HAMBURG');

    // Hauptinstanz: nur Hauptquelle.
    Assert::true($sso->resolve(multiSourceRequest(['HTTP_X_REMOTE_USER' => 'mueller'])) !== null);
    Assert::null($sso->resolve(multiSourceRequest(['HTTP_X_REMOTE_USER' => 'mueller', 'HTTP_X_REMOTE_SOURCE' => 'HAMBURG'])));

    // Instanz der Zweigstelle: nur ihre eigene Quelle.
    $user = $sso->resolve(multiSourceRequest(['HTTP_X_REMOTE_USER' => 'mueller', 'HTTP_X_REMOTE_SOURCE' => 'HAMBURG'], '10.0.0.3'));
    Assert::true($user !== null);
    Assert::same(5, $user['source_id']);
    Assert::null($sso->resolve(multiSourceRequest(['HTTP_X_REMOTE_USER' => 'mueller'], '10.0.0.3')));
    Assert::true($sso->isTrusted(multiSourceRequest([], '10.0.0.3')));
    Assert::false($sso->isTrusted(multiSourceRequest([], '10.0.0.4')));
});

function secretBoxForTest(): SecretBox
{
    $dir = sys_get_temp_dir() . '/lanpa-secret-' . bin2hex(random_bytes(6));

    return new SecretBox($dir . '/keys/secrets.key');
}

function identityServicePdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_key TEXT NOT NULL UNIQUE, setting_value TEXT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec(
        "CREATE TABLE identity_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT, source_key TEXT NOT NULL, label TEXT NOT NULL, hosts TEXT NOT NULL DEFAULT '',
            port INTEGER NOT NULL DEFAULT 636, use_tls INTEGER NOT NULL DEFAULT 1, verify_cert INTEGER NOT NULL DEFAULT 1,
            timeout INTEGER NOT NULL DEFAULT 10, base_dn TEXT NOT NULL DEFAULT '', bind_dn TEXT NOT NULL DEFAULT '',
            bind_password TEXT NULL, user_filter TEXT NOT NULL DEFAULT '', group_base_dn TEXT NOT NULL DEFAULT '',
            group_filter TEXT NOT NULL DEFAULT '', group_name_attribute TEXT NOT NULL DEFAULT '', attributes TEXT NULL,
            sso_enabled INTEGER NOT NULL DEFAULT 0, sso_domain TEXT NOT NULL DEFAULT '', sso_dcs TEXT NULL,
            sso_join_user TEXT NOT NULL DEFAULT '', sso_join_password TEXT NULL, sso_networks TEXT NULL, sso_hostnames TEXT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );

    return $pdo;
}

/**
 * @return array<string,string>
 */
function hamburgSourceInput(array $overrides = []): array
{
    return array_merge(IdentitySourceService::formValues(null), [
        'ldap_key' => 'hamburg',
        'ldap_label' => 'Zweigstelle Hamburg',
        'ldap_host' => "dc01.hh.local\ndc02.hh.local",
        'ldap_base_dn' => 'DC=hh,DC=local',
        'ldap_bind_dn' => 'CN=svc,DC=hh,DC=local',
        'ldap_bind_password' => 'Bind-Geheim!1',
        'sso_enabled' => '1',
        'sso_domain' => 'hh',
        'sso_dcs' => "dc01.hh.local 10.20.0.10\ndc02.hh.local",
        'sso_join_user' => 'svc-join',
        'sso_join_password' => 'Join-Geheim!2',
        'sso_networks' => "10.20.0.0/16, 10.21.5.7",
        'sso_hostnames' => 'Intranet-HH.hh.local',
    ], $overrides);
}

Runner::test('Zugangsdaten werden authentifiziert verschlüsselt, Manipulation wird erkannt', static function (): void {
    $box = secretBoxForTest();
    $cipher = $box->encrypt('Geheim äöü %"');
    Assert::true(SecretBox::isEncrypted($cipher));
    Assert::false(str_contains($cipher, 'Geheim'));
    Assert::same('Geheim äöü %"', $box->decrypt($cipher));
    Assert::false($cipher === $box->encrypt('Geheim äöü %"'));

    $raw = base64_decode(substr($cipher, strlen(SecretBox::PREFIX)), true);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    Assert::same(null, $box->decrypt(SecretBox::PREFIX . base64_encode($raw)));
    Assert::same(null, $box->decrypt('klartext'));
    Assert::same(null, $box->decrypt(''));

    // Anderer Schluessel (z. B. Sicherung einer anderen Installation).
    Assert::same(null, secretBoxForTest()->decrypt($cipher));
});

Runner::test('Schlüsseldatei wird einmalig mit restriktiven Rechten angelegt', static function (): void {
    $file = sys_get_temp_dir() . '/lanpa-secret-' . bin2hex(random_bytes(6)) . '/keys/secrets.key';
    $first = new SecretBox($file);
    $cipher = $first->encrypt('x');
    Assert::true(is_file($file));
    Assert::same(0600, fileperms($file) & 0777);
    Assert::same('x', (new SecretBox($file))->decrypt($cipher));
});

Runner::test('Passwortfelder: leer = unverändert, Entfernen per Checkbox, Steuerzeichen abgelehnt', static function (): void {
    [$changes, $errors] = IdentitySourceService::secretInput(['ldap_bind_password' => '', 'sso_join_password' => 'neu']);
    Assert::same(['sso_join_password' => 'neu'], $changes);
    Assert::same([], $errors);

    [$changes] = IdentitySourceService::secretInput(['ldap_bind_password' => 'ignoriert', 'ldap_bind_password_clear' => '1']);
    Assert::same(['ldap_bind_password' => ''], $changes);

    [, $errors] = IdentitySourceService::secretInput(['ldap_bind_password' => "a\nb"]);
    Assert::true(isset($errors['ldap_bind_password']));

    Assert::true(IdentitySourceService::willHaveSecret('x', [], ['x' => 'set']));
    Assert::false(IdentitySourceService::willHaveSecret('x', ['x' => ''], ['x' => 'set']));
    Assert::false(IdentitySourceService::willHaveSecret('x', [], ['x' => 'invalid']));
});

Runner::test('Windows-Anmeldung einer Quelle wird geprüft und normalisiert', static function (): void {
    [$values, $errors] = IdentitySourceService::validateSso(hamburgSourceInput(), true);
    Assert::same([], $errors);
    Assert::same('HH', $values['sso_domain']);
    Assert::same("dc01.hh.local 10.20.0.10\ndc02.hh.local", $values['sso_dcs']);
    Assert::same("10.20.0.0/16\n10.21.5.7/32", $values['sso_networks']);
    Assert::same('intranet-hh.hh.local', $values['sso_hostnames']);

    [, $errors] = IdentitySourceService::validateSso(hamburgSourceInput([
        'sso_domain' => 'VIEL-ZU-LANGER-NAME',
        'sso_dcs' => 'dc01 kein-ip',
        'sso_join_user' => 'a%b',
        'sso_networks' => '10.0.0.0/33',
        'sso_hostnames' => 'bad_host',
    ]), true);
    foreach (['sso_domain', 'sso_dcs', 'sso_join_user', 'sso_networks', 'sso_hostnames'] as $field) {
        Assert::true(isset($errors[$field]), $field);
    }

    [, $errors] = IdentitySourceService::validateSso(['sso_enabled' => '1'], true);
    Assert::true(isset($errors['sso_domain'], $errors['sso_dcs'], $errors['sso_join_user'], $errors['sso_networks']));

    // Deaktiviert: keine Pflichtfelder.
    [$values, $errors] = IdentitySourceService::validateSso([], true);
    Assert::same([], $errors);
    Assert::same('0', $values['sso_enabled']);

    // Hauptquelle: aktiv, sobald eine Domaene angegeben ist.
    [, $errors] = IdentitySourceService::validateSso(['sso_domain' => 'FIRMA'], false);
    Assert::true(isset($errors['sso_dcs'], $errors['sso_join_user']));
    Assert::same('fd00::/8', IdentitySourceService::normalizeNetwork('fd00::/8'));
    Assert::same(null, IdentitySourceService::normalizeNetwork('fd00::/129'));
});

Runner::test('Quelle speichert Passwörter nur verschlüsselt und liefert die auth-Konfiguration', static function (): void {
    $pdo = identityServicePdo();
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('sso_domain', 'FIRMA'), ('sso_dcs', 'dc01.firma.local'), ('sso_join_user', 'join')");
    $repository = new IdentitySourceRepository($pdo);
    $service = new IdentitySourceService($repository, new SettingsService(new SettingsRepository($pdo)), secretBoxForTest());

    [$values, $errors] = $service->validateAdditional(hamburgSourceInput());
    Assert::same([], $errors);
    [$changes] = IdentitySourceService::secretInput(hamburgSourceInput());
    $id = $service->saveAdditional(null, $values, $changes);

    $row = $repository->find($id);
    Assert::true(SecretBox::isEncrypted((string) $row['bind_password']));
    Assert::true(SecretBox::isEncrypted((string) $row['sso_join_password']));
    $dump = implode('|', array_map('strval', $row));
    Assert::false(str_contains($dump, 'Bind-Geheim'));
    Assert::false(str_contains($dump, 'Join-Geheim'));
    Assert::same(['ldap_bind_password' => 'set', 'sso_join_password' => 'set'], $service->secretStates($row));

    $config = $service->configs()[1];
    Assert::same('Bind-Geheim!1', $config['password']);

    // Speichern ohne Passworteingabe laesst die Passwoerter unveraendert.
    [$values] = $service->validateAdditional(hamburgSourceInput(['ldap_bind_password' => '', 'sso_join_password' => '', 'ldap_label' => 'HH']), $id);
    $service->saveAdditional($id, $values, []);
    Assert::same((string) $row['bind_password'], (string) $repository->find($id)['bind_password']);

    // Aktive SSO ohne Passwort ist nicht speicherbar.
    [, $errors] = $service->validateAdditional(hamburgSourceInput(['ldap_key' => 'KIEL', 'sso_join_password' => '']));
    Assert::true(isset($errors['sso_join_password']));

    Assert::same('HAMBURG|auth-hamburg|10.20.0.0/16,10.21.5.7/32|intranet-hh.hh.local', $service->ssoRoutes());
    Assert::same(['auth-hamburg=HAMBURG'], $service->trustedWorkerProxies());

    $env = $service->authEnvironment('hamburg');
    Assert::same('HH', $env['SSO_DOMAIN']);
    Assert::same('dc01.hh.local dc02.hh.local', $env['SSO_DC']);
    Assert::same('10.20.0.10 -', $env['SSO_DC_IP']);
    Assert::same('svc-join', $env['SSO_JOIN_USER']);
    Assert::same('Join-Geheim!2', $env['SSO_JOIN_PASSWORD']);
    Assert::same('1', $env['SSO_CONFIGURED']);

    $primary = $service->authEnvironment('');
    Assert::same('FIRMA', $primary['SSO_DOMAIN']);
    Assert::same('', $primary['SSO_DC_IP']);
    Assert::same('', $primary['SSO_JOIN_PASSWORD']);
    Assert::same($service->ssoRoutes(), $primary['SSO_ROUTES']);

    Assert::same(null, $service->authEnvironment('UNBEKANNT'));
    Assert::same(null, $service->authEnvironment('../x'));
});
