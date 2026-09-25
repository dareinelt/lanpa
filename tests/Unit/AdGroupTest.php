<?php

declare(strict_types=1);

use App\Core\Request;
use App\Repositories\PhonebookRepository;
use App\Security\SsoAuth;
use App\Services\AdSyncService;
use App\Services\LdapAttributeMapper;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\FakeAdGroupStore;
use Tests\Support\FakeLdapClient;
use Tests\Support\FakePhonebookStore;
use Tests\Support\FakeSyncLog;
use Tests\Support\Runner;

/**
 * @return list<array<string,mixed>>
 */
function adTestGroups(): array
{
    return [
        ['dn' => 'CN=GG-Office,OU=Gruppen,DC=example,DC=internal', 'name' => 'GG-Office', 'description' => 'Office-Nutzer', 'members' => ['guid-1']],
        ['dn' => 'CN=GG-Verwaltung,OU=Gruppen,DC=example,DC=internal', 'name' => 'GG-Verwaltung', 'description' => null, 'members' => []],
    ];
}

Runner::test('Synchronisation übernimmt AD-Gruppen aus dem konfigurierten Pfad', static function (): void {
    $groups = new FakeAdGroupStore();
    $users = [['external_id' => 'guid-1', 'display_name' => 'Erika Muster']];
    $service = new AdSyncService(new FakeLdapClient($users, false, adTestGroups()), new FakePhonebookStore(), new FakeSyncLog(), testLogger(), $groups);

    $result = $service->run();

    Assert::same('success', $result['status']);
    Assert::same(2, $result['groups']);
    Assert::same(1, count($groups->replaced));
    Assert::same('GG-Office', $groups->replaced[0][0]['name']);
});

Runner::test('Fehler beim Gruppenabruf lässt Benutzer-Sync laufen und alte Gruppen unverändert', static function (): void {
    $groups = new FakeAdGroupStore();
    $store = new FakePhonebookStore();
    $users = [['external_id' => 'guid-1', 'display_name' => 'Erika Muster']];
    $service = new AdSyncService(new FakeLdapClient($users, false, null, true), $store, new FakeSyncLog(), testLogger(), $groups);

    $result = $service->run();

    Assert::same('success', $result['status']);
    Assert::null($result['groups']);
    Assert::same(0, count($groups->replaced));
    Assert::same(1, count($store->upserted));
});

Runner::test('Gruppen-Pfade werden getrennt, bereinigt und dedupliziert', static function (): void {
    Assert::same(
        ['OU=Gruppen,DC=example,DC=internal', 'OU=Rollen,DC=example,DC=internal'],
        SettingsService::splitDnList(" OU=Gruppen,DC=example,DC=internal ;\nOU=Rollen,DC=example,DC=internal\r\nou=gruppen,dc=example,dc=internal;;")
    );
    Assert::same([], SettingsService::splitDnList(''));
});

Runner::test('SSO ergänzt die Gruppen des Benutzers aus dem synchronisierten Datenbestand', static function (): void {
    $sso = new SsoAuth(new PhonebookRepository(ssoPhonebookPdo()), ssoConfig(), new FakeAdGroupStore([1 => ['gg-office', 'verwaltung']]));
    $user = $sso->resolve(new Request('GET', '/', [], [], [
        'REMOTE_ADDR' => '10.0.0.2',
        'HTTP_X_REMOTE_USER' => 'erika.muster',
        'HTTP_X_REMOTE_GROUPS' => 'Verwaltung',
    ]));

    Assert::true(is_array($user));
    Assert::same(['verwaltung', 'gg-office'], $user['groups']);
});

Runner::test('Nicht erreichbarer Gruppenbestand verhindert die SSO-Anmeldung nicht', static function (): void {
    $sso = new SsoAuth(new PhonebookRepository(ssoPhonebookPdo()), ssoConfig(), new FakeAdGroupStore([], true));
    $user = $sso->resolve(new Request('GET', '/', [], [], [
        'REMOTE_ADDR' => '10.0.0.2',
        'HTTP_X_REMOTE_USER' => 'erika.muster',
    ]));

    Assert::true(is_array($user));
    Assert::same([], $user['groups']);
});

Runner::test('Windows-Anmeldename wird aus dem AD übernommen', static function (): void {
    $mapper = new LdapAttributeMapper(['display_name' => 'displayName', 'unique_id' => 'objectGUID', 'samaccount_name' => 'sAMAccountName']);
    Assert::true(in_array('sAMAccountName', $mapper->attributes(), true));
    $mapped = $mapper->map([
        'dn' => 'CN=Muster,OU=Benutzer,DC=example,DC=internal',
        'displayname' => ['count' => 1, 0 => 'Erika Muster'],
        'samaccountname' => ['count' => 1, 0 => 'Erika.Muster'],
        'objectguid' => ['count' => 1, 0 => 'guid-raw'],
    ]);

    Assert::true(is_array($mapped));
    Assert::same('erika.muster', strtolower((string) $mapped['samaccount_name']));
});
