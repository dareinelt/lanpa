<?php

declare(strict_types=1);

use App\Exceptions\ValidationException;
use App\Repositories\OfficeAppRepository;
use App\Repositories\SettingsRepository;
use App\Services\Office\OfficeAppService;
use App\Services\Office\OfficeConfigService;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

function officeAppsService(array $settings = [], bool $enabled = true): OfficeAppService
{
    $pdo = officePdo($settings);
    $pdo->exec('CREATE TABLE office_app_packages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(120) NOT NULL UNIQUE,
        description VARCHAR(255) NOT NULL DEFAULT \'\'
    )');
    $pdo->exec('CREATE TABLE office_app_package_apps (
        package_id INTEGER NOT NULL,
        app_key VARCHAR(32) NOT NULL,
        PRIMARY KEY (package_id, app_key)
    )');
    $pdo->exec('CREATE TABLE office_app_permissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_name VARCHAR(190) NOT NULL,
        app_key VARCHAR(32) NULL,
        package_id INTEGER NULL
    )');

    $settingsService = new SettingsService(new SettingsRepository($pdo));

    return new OfficeAppService(
        new OfficeAppRepository($pdo),
        new OfficeConfigService($settingsService, ['enabled' => $enabled, 'public_path' => '/office/']),
        $settingsService
    );
}

/**
 * @param list<string> $groups
 *
 * @return array{id:int,username:string,display_name:string,groups:list<string>}
 */
function officeUser(array $groups, int $id = 5): array
{
    return ['id' => $id, 'username' => 'u' . $id, 'display_name' => 'U' . $id, 'groups' => $groups];
}

/**
 * @param list<array<string,mixed>> $apps
 *
 * @return list<string>
 */
function officeAppKeys(array $apps): array
{
    return array_map(static fn (array $app): string => (string) $app['key'], $apps);
}

Runner::test('Office-Apps: nicht angemeldete Nutzer erhalten keine Apps', static function (): void {
    $service = officeAppsService(['office_owa_url' => 'https://mail.example.com/owa/']);
    $service->saveDirectGroups(['document' => 'Alle', 'files' => 'Alle', 'owa' => 'Alle']);

    Assert::same([], $service->allowedFor(null));
    Assert::same(['document', 'files', 'owa'], officeAppKeys($service->allowedFor(officeUser(['alle']))));
});

Runner::test('Office-Apps: ohne Freigabe sieht niemand eine App', static function (): void {
    $service = officeAppsService();

    Assert::same([], $service->allowedFor(officeUser(['verwaltung', 'it'])));
    Assert::same([], $service->allowedFor(officeUser([])));
});

Runner::test('Office-Apps: direkte Gruppenfreigabe ohne Gross-/Kleinschreibung', static function (): void {
    $service = officeAppsService();
    $service->saveDirectGroups(['spreadsheet' => 'Controlling, Geschäftsleitung', 'document' => 'Verwaltung']);

    Assert::same(['spreadsheet'], officeAppKeys($service->allowedFor(officeUser(['controlling']))));
    Assert::same(['document', 'spreadsheet'], officeAppKeys($service->allowedFor(officeUser(['VERWALTUNG', 'geschäftsleitung']))));
    Assert::same(['Controlling', 'Geschäftsleitung'], $service->directGroups()['spreadsheet']);
});

Runner::test('Office-Apps: Pakete geben mehrere Apps frei, Loeschen entzieht sie', static function (): void {
    $service = officeAppsService();
    $id = $service->savePackage(null, [
        'name' => 'Office Basis',
        'description' => 'Text, Tabelle, Dateien',
        'apps' => ['files', 'document', 'spreadsheet', 'unbekannt'],
        'groups' => 'Mitarbeitende',
    ]);

    $packageApps = $service->findPackage($id)['apps'] ?? [];
    Assert::same(["document", "spreadsheet", "files"], $packageApps);
    Assert::same(['document', 'spreadsheet', 'files'], officeAppKeys($service->allowedFor(officeUser(['mitarbeitende']))));
    Assert::same(['Mitarbeitende'], $service->effectiveGroups()['files']);

    $service->deletePackage($id);
    Assert::same([], $service->allowedFor(officeUser(['mitarbeitende'])));
});

Runner::test('Office-Apps: Paket ohne App oder mit doppeltem Namen wird abgelehnt', static function (): void {
    $service = officeAppsService();
    $service->savePackage(null, ['name' => 'Basis', 'apps' => ['document'], 'groups' => '']);

    $errors = [];
    try {
        $service->savePackage(null, ['name' => 'basis', 'apps' => [], 'groups' => 'X']);
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }

    Assert::true(isset($errors['name']));
    Assert::true(isset($errors['apps']));
});

Runner::test('Office-Apps: Outlook Web App erscheint nur mit gueltigem Link', static function (): void {
    $service = officeAppsService();
    $service->saveDirectGroups(['owa' => 'Alle']);
    Assert::same([], $service->allowedFor(officeUser(['alle'])));

    $rejected = false;
    try {
        $service->saveOwaUrl('javascript:alert(1)');
    } catch (ValidationException) {
        $rejected = true;
    }
    Assert::true($rejected);
    Assert::false(OfficeAppService::isValidOwaUrl('https:///owa'));

    $invalid = officeAppsService(['office_owa_url' => 'ftp://mail.example.com/']);
    $invalid->saveDirectGroups(['owa' => 'Alle']);
    Assert::same([], $invalid->allowedFor(officeUser(['alle'])));

    $service = officeAppsService(['office_owa_url' => 'https://mail.example.com/owa/']);
    $service->saveDirectGroups(['owa' => 'Alle']);
    $apps = $service->allowedFor(officeUser(['alle']));
    Assert::same(['owa'], officeAppKeys($apps));
    Assert::same('https://mail.example.com/owa/', $apps[0]['target']);
    Assert::true($apps[0]['external']);
});

Runner::test('Office-Apps: Euro-Office-Webapps starten ueber den Connector, Dateien in Nextcloud', static function (): void {
    $service = officeAppsService();

    Assert::same('/office/index.php/apps/eurooffice/new?name=Neues%20Dokument.docx&dir=%2F', $service->targetFor('document'));
    Assert::contains('name=Neue%20Tabelle.xlsx', $service->targetFor('spreadsheet'));
    Assert::contains('.pptx', $service->targetFor('presentation'));
    Assert::contains('.pdf', $service->targetFor('pdf'));
    Assert::same('/office/index.php/apps/files/', $service->targetFor('files'));
    Assert::same('', $service->targetFor('unbekannt'));
});

Runner::test('Office-Apps: Office-Kachel wird ohne freigegebene App ausgeblendet', static function (): void {
    $service = officeAppsService();
    $service->saveDirectGroups(['files' => 'Verwaltung']);
    $items = [
        ['id' => 1, 'type' => 'internal', 'url' => '/telefonliste'],
        ['id' => 2, 'type' => 'internal', 'url' => '/office-starten'],
    ];

    $ids = static fn (array $list): array => array_map(static fn (array $item): int => (int) $item['id'], $list);

    Assert::same([1], $ids($service->filterNavigation($items, null)));
    Assert::same([1], $ids($service->filterNavigation($items, officeUser(['it']))));
    Assert::same([1, 2], $ids($service->filterNavigation($items, officeUser(['verwaltung']))));

    $disabled = officeAppsService([], false);
    $disabled->saveDirectGroups(['files' => 'Verwaltung']);
    Assert::same([1], $ids($disabled->filterNavigation($items, officeUser(['verwaltung']))));
});
