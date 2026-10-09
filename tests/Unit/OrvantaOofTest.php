<?php

declare(strict_types=1);

use App\Core\Config;
use App\Exceptions\ValidationException;
use App\Repositories\OrvantaOofRepository;
use App\Repositories\OrvantaSignatureRepository;
use App\Repositories\PhonebookRepository;
use App\Repositories\SettingsRepository;
use App\Services\Orvanta\OrvantaOofService;
use App\Services\Orvanta\OrvantaSignatureService;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * SQLite-Schema parallel zur Migration 045 pflegen. Der MySQL-Upsert der
 * Abwesenheitseinstellungen wird in die SQLite-Schreibweise uebersetzt.
 *
 * @param array<string,string> $settings
 */
function oofPdo(array $settings = []): PDO
{
    $pdo = new class ('sqlite::memory:') extends PDO {
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            if (str_contains($query, 'ON DUPLICATE KEY UPDATE')) {
                $query = str_replace(
                    'INSERT INTO orvanta_oof_settings',
                    'INSERT OR REPLACE INTO orvanta_oof_settings',
                    (string) preg_replace('~ON DUPLICATE KEY UPDATE.*$~s', '', $query)
                );
            }

            return parent::prepare($query, $options);
        }
    };
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key VARCHAR(64) NOT NULL UNIQUE,
        setting_value TEXT NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $insert = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($settings as $key => $value) {
        $insert->execute([$key, $value]);
    }
    $pdo->exec('CREATE TABLE orvanta_oof_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(120) NOT NULL,
        fixed_text TEXT NOT NULL,
        example_text TEXT NOT NULL,
        ad_groups TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 1,
        active INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec('CREATE TABLE orvanta_oof_settings (
        user_uid VARCHAR(190) NOT NULL PRIMARY KEY,
        template_id INTEGER NULL,
        dynamic_text TEXT NOT NULL,
        external_audience VARCHAR(10) NOT NULL DEFAULT \'none\',
        schedule_mode VARCHAR(20) NOT NULL DEFAULT \'until_off\',
        start_date DATE NULL,
        end_date DATE NULL,
        active INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE orvanta_signatures (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(120) NOT NULL,
        greeting VARCHAR(120) NOT NULL DEFAULT \'Mit freundlichen Grüßen\',
        name_format VARCHAR(20) NOT NULL DEFAULT \'first_last\',
        street VARCHAR(190) NOT NULL DEFAULT \'\',
        postal_city VARCHAR(190) NOT NULL DEFAULT \'\',
        phone_mode VARCHAR(10) NOT NULL DEFAULT \'prefix\',
        phone_prefix VARCHAR(64) NOT NULL DEFAULT \'\',
        text_color VARCHAR(40) NOT NULL DEFAULT \'color_text\',
        separator_color VARCHAR(40) NOT NULL DEFAULT \'color_accent\',
        ad_groups TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 1,
        active INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec('CREATE TABLE phonebook (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        identity_source_id INTEGER NOT NULL DEFAULT 0,
        external_id TEXT NOT NULL DEFAULT \'\',
        samaccount_name TEXT NULL,
        display_name TEXT NOT NULL,
        first_name TEXT NULL,
        last_name TEXT NULL,
        title TEXT NULL,
        phone TEXT NULL,
        phone_digits TEXT NULL,
        mobile TEXT NULL,
        email TEXT NULL,
        department TEXT NULL,
        ad_modified TEXT NULL,
        synced_at TEXT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        visible INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec("INSERT INTO phonebook (id, samaccount_name, display_name, first_name, last_name, title, phone, department) VALUES
        (7, 'dreinelt', 'Daniel-André Reinelt', 'Daniel-André', 'Reinelt', 'Administrator', '+49 5331 934-1849', 'Informationstechnologie (IT / EDV)')");

    return $pdo;
}

/**
 * @param array<string,string> $settings
 * @return array{service:OrvantaOofService,repository:OrvantaOofRepository,pdo:PDO,signatures:OrvantaSignatureService}
 */
function oofSetup(array $settings = []): array
{
    $pdo = oofPdo($settings + ['color_text' => '#112233', 'color_accent' => '#44aa55']);
    $repository = new OrvantaOofRepository($pdo);
    $signatures = new OrvantaSignatureService(
        new OrvantaSignatureRepository($pdo),
        new PhonebookRepository($pdo),
        new SettingsService(new SettingsRepository($pdo)),
        static fn (): ?array => null
    );

    return [
        'service' => new OrvantaOofService($repository, $signatures, orvantaConfig($settings)['config']),
        'repository' => $repository,
        'pdo' => $pdo,
        'signatures' => $signatures,
    ];
}

/**
 * Formulareingaben der Admin-Vorlage.
 *
 * @return array<string,mixed>
 */
function oofInput(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Allgemeine Abwesenheit',
        'fixed_text' => "Sehr geehrte Damen und Herren,\nich befinde mich derzeit nicht im Haus.",
        'example_text' => 'Bei dringenden Themen wenden Sie sich bitte an Herrn XY.',
        'groups' => 'Orvanta-Abwesenheit, IT',
        'sort_order' => '1',
        'active' => '1',
    ];
}

/**
 * Legt eine Vorlage an und liefert die gespeicherte Zeile.
 *
 * @return array<string,mixed>
 */
function oofTemplate(OrvantaOofService $service, array $overrides = []): array
{
    $id = $service->save(null, oofInput($overrides));
    $template = $service->find($id);
    Assert::true($template !== null, 'Vorlage wurde nicht gespeichert.');

    return (array) $template;
}

/**
 * Kennung der zugeordneten Vorlage (0, wenn keine passt).
 *
 * @param list<string> $groups
 */
function oofMatchId(OrvantaOofService $service, array $groups): int
{
    return oofRowId($service->match($groups));
}

/**
 * Kennung einer Vorlagenzeile (0 bei null).
 *
 * @param array<string,mixed>|null $row
 */
function oofRowId(?array $row): int
{
    return $row === null ? 0 : (int) $row['id'];
}

/**
 * Fuehrt $callback im Demomodus aus (APP_ENV != production) und stellt die
 * Konfiguration danach unveraendert wieder her.
 */
function oofWithDemoEnv(callable $callback): void
{
    $previous = getenv('APP_ENV');
    putenv('APP_ENV=local');
    Config::boot(BASE_PATH . '/config');

    try {
        $callback();
    } finally {
        $previous === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previous);
        Config::boot(BASE_PATH . '/config');
    }
}

// ----------------------------------------------------------------------
// Vorlagen
// ----------------------------------------------------------------------

Runner::test('Abwesenheit: Validierung und Speichern der Vorlage', function (): void {
    $setup = oofSetup();
    $service = $setup['service'];

    try {
        $service->validate(oofInput(['name' => '   ', 'fixed_text' => "\n  ", 'sort_order' => '0']));
        Assert::true(false, 'Validierung haette fehlschlagen muessen.');
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
        Assert::true(isset($errors['name']), 'Name ist Pflicht.');
        Assert::true(isset($errors['fixed_text']), 'Der feste Text ist Pflicht.');
        Assert::true(isset($errors['sort_order']), 'Reihenfolge ausserhalb des Bereichs.');
    }

    // Zeilenumbrueche bleiben erhalten, Gruppen werden normalisiert.
    $clean = $service->validate(oofInput([
        'name' => '  Allgemein  ',
        'fixed_text' => "  Zeile eins\r\nZeile zwei  ",
        'example_text' => "  Bei Fragen:\nVertretung  ",
        'groups' => 'IT, it , Verwaltung',
        'sort_order' => '2',
    ]));
    Assert::same('Allgemein', $clean['name']);
    Assert::same("Zeile eins\nZeile zwei", $clean['fixed_text']);
    Assert::same("Bei Fragen:\nVertretung", $clean['example_text']);
    Assert::same(['IT', 'Verwaltung'], $clean['groups']);
    Assert::same(2, $clean['sort_order']);
    Assert::true($clean['active']);

    // Zu lange Texte werden auf die zulaessige Laenge begrenzt.
    $long = $service->validate(oofInput([
        'fixed_text' => str_repeat('a', OrvantaOofService::MAX_FIXED_TEXT + 50),
        'example_text' => str_repeat('b', OrvantaOofService::MAX_DYNAMIC_TEXT + 50),
    ]));
    Assert::same(OrvantaOofService::MAX_FIXED_TEXT, mb_strlen($long['fixed_text']));
    Assert::same(OrvantaOofService::MAX_DYNAMIC_TEXT, mb_strlen($long['example_text']));

    $blank = OrvantaOofService::blank();
    Assert::same(0, $blank['id']);
    Assert::same([], $blank['groups']);
    Assert::true($blank['active']);

    // Speichern, laden, aendern, loeschen.
    $saved = oofTemplate($service, ['groups' => 'Orvanta-Abwesenheit']);
    Assert::same(['Orvanta-Abwesenheit'], $saved['groups']);
    Assert::same(1, count($service->all()));

    $service->save($saved['id'], oofInput(['name' => 'Zweite Fassung', 'active' => '']));
    Assert::same('Zweite Fassung', $service->find($saved['id'])['name']);
    Assert::false($service->find($saved['id'])['active']);

    try {
        $service->save(4711, oofInput());
        Assert::true(false, 'Unbekannte Vorlage haette abgelehnt werden muessen.');
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['id']));
    }

    $service->delete($saved['id']);
    Assert::null($service->find($saved['id']));
    Assert::same([], $service->all());
});

Runner::test('Abwesenheit: Zuordnung ueber AD-Gruppen', function (): void {
    $service = oofSetup()['service'];

    $it = oofTemplate($service, ['name' => 'IT', 'groups' => 'IT', 'sort_order' => '1']);
    $allgemein = oofTemplate($service, ['name' => 'Allgemein', 'groups' => 'Orvanta-Abwesenheit', 'sort_order' => '2']);
    $inaktiv = oofTemplate($service, ['name' => 'Inaktiv', 'groups' => 'IT', 'sort_order' => '3', 'active' => '']);

    // Gross-/Kleinschreibung spielt keine Rolle.
    Assert::same($it['id'], oofMatchId($service, ['it']));
    Assert::same($allgemein['id'], oofMatchId($service, ['ORVANTA-ABWESENHEIT']));
    // Bei mehreren Treffern gewinnt die kleinste Reihenfolge.
    Assert::same($it['id'], oofMatchId($service, ['Orvanta-Abwesenheit', 'IT']));

    // Ohne Gruppen des Benutzers bzw. ohne passende Gruppe gibt es keine Vorlage.
    Assert::same(0, oofMatchId($service, []));
    Assert::same(0, oofMatchId($service, [' ', '']));
    Assert::same(0, oofMatchId($service, ['Verwaltung']));
    Assert::null($service->forUser(null));
    Assert::null($service->forUser(['groups' => 'IT']));
    Assert::same($it['id'], oofRowId($service->forUser(['id' => 7, 'groups' => ['IT']])));

    // Inaktive Vorlagen werden nicht zugeordnet.
    $service->save($inaktiv['id'], oofInput(['name' => 'Inaktiv', 'groups' => 'IT', 'active' => '']));
    $service->save($it['id'], oofInput(['name' => 'IT', 'groups' => 'IT', 'active' => '']));
    Assert::same(0, oofMatchId($service, ['IT']));
    Assert::same($allgemein['id'], oofMatchId($service, ['IT', 'Orvanta-Abwesenheit']));
});

// ----------------------------------------------------------------------
// Einstellungen des Benutzers
// ----------------------------------------------------------------------

Runner::test('Abwesenheit: Einstellungen des Benutzers', function (): void {
    $setup = oofSetup();
    $service = $setup['service'];
    $repository = $setup['repository'];
    $template = oofTemplate($service, ['example_text' => 'Beispieltext der Vorlage']);

    // Ohne gespeicherte Einstellungen dient der Beispieltext der Vorlage als
    // Vorgabe; die Notiz ist nicht aktiv.
    $fresh = $service->settings('dreinelt', $template);
    Assert::same('Beispieltext der Vorlage', $fresh['dynamic_text']);
    Assert::same($template['id'], $fresh['template_id']);
    Assert::same('none', $fresh['external_audience']);
    Assert::same('until_off', $fresh['schedule_mode']);
    Assert::false($fresh['active']);

    // Ohne Benutzerkennung wird nichts gespeichert und nichts gelesen.
    Assert::same('Beispieltext der Vorlage', $service->settings('', $template)['dynamic_text']);

    // Gespeicherte Eingaben haben Vorrang.
    $repository->saveSettings('dreinelt', [
        'template_id' => $template['id'],
        'dynamic_text' => 'Meine eigene Vertretung',
        'external_audience' => 'all',
        'schedule_mode' => 'range',
        'start_date' => '2026-03-02',
        'end_date' => '2026-03-06',
        'active' => true,
    ]);
    $stored = $service->settings('dreinelt', $template);
    Assert::same('Meine eigene Vertretung', $stored['dynamic_text']);
    Assert::same('all', $stored['external_audience']);
    Assert::same('2026-03-02', $stored['start_date']);
    Assert::same('2026-03-06', $stored['end_date']);
    Assert::true($stored['active']);

    // Erneutes Speichern aktualisiert die Zeile (Upsert).
    $repository->saveSettings('dreinelt', [
        'template_id' => $template['id'],
        'dynamic_text' => 'Zweite Fassung',
        'external_audience' => 'none',
        'schedule_mode' => 'until_off',
        'start_date' => '',
        'end_date' => '',
        'active' => false,
    ]);
    $updated = $repository->settings('dreinelt');
    Assert::same('Zweite Fassung', $updated['dynamic_text']);
    Assert::same('', $updated['start_date']);
    Assert::same('', $updated['end_date']);
    Assert::false($updated['active']);
    Assert::same(1, (int) $setup['pdo']->query('SELECT COUNT(*) FROM orvanta_oof_settings')->fetchColumn());

    // Eine neu zugewiesene Vorlage bringt ihren Beispieltext mit und schaltet
    // die Notiz nicht ungefragt wieder ein.
    $repository->saveSettings('dreinelt', [
        'template_id' => $template['id'],
        'dynamic_text' => 'Alt',
        'external_audience' => 'all',
        'schedule_mode' => 'until_off',
        'start_date' => '',
        'end_date' => '',
        'active' => true,
    ]);
    $other = oofTemplate($service, ['name' => 'Andere Vorlage', 'example_text' => 'Neuer Beispieltext', 'groups' => 'IT']);
    $switched = $service->settings('dreinelt', $other);
    Assert::same($other['id'], $switched['template_id']);
    Assert::same('Neuer Beispieltext', $switched['dynamic_text']);
    Assert::false($switched['active']);
    Assert::same('all', $switched['external_audience'], 'Empfaengerkreis bleibt erhalten.');
});

Runner::test('Abwesenheit: Validierung der Einstellungen', function (): void {
    $service = oofSetup()['service'];

    try {
        $service->validateSettings(['external_audience' => 'fremd', 'schedule_mode' => 'manchmal']);
        Assert::true(false, 'Unbekannte Werte haetten abgelehnt werden muessen.');
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
        Assert::true(isset($errors['external_audience']));
        Assert::true(isset($errors['schedule_mode']));
    }

    // Zeitraum ist nur beim Aktivieren Pflicht.
    $inactive = $service->validateSettings(['active' => false, 'schedule_mode' => 'range']);
    Assert::same('', $inactive['start_date']);
    Assert::false($inactive['active']);

    try {
        $service->validateSettings(['active' => true, 'schedule_mode' => 'range', 'start_date' => '', 'end_date' => '2026-03-06']);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['start_date']));
    }

    try {
        $service->validateSettings(['active' => true, 'schedule_mode' => 'range', 'start_date' => '2026-03-06', 'end_date' => '2026-03-02']);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['end_date']));
    }

    $range = $service->validateSettings([
        'active' => true,
        'schedule_mode' => 'range',
        'start_date' => '2026-03-02',
        'end_date' => '2026-03-06',
        'external_audience' => 'all',
        'dynamic_text' => "  Vertretung:\r\nFrau Muster  ",
    ]);
    Assert::same('2026-03-02', $range['start_date']);
    Assert::same('2026-03-06', $range['end_date']);
    Assert::same("Vertretung:\nFrau Muster", $range['dynamic_text']);
    Assert::true($range['active']);

    // Ungueltige Datumsangaben werden verworfen, zu lange Texte begrenzt.
    $invalid = $service->validateSettings(['start_date' => '2026-13-05', 'end_date' => '02.03.2026', 'dynamic_text' => str_repeat('x', 2500)]);
    Assert::same('', $invalid['start_date']);
    Assert::same('', $invalid['end_date']);
    Assert::same(OrvantaOofService::MAX_DYNAMIC_TEXT, mb_strlen($invalid['dynamic_text']));
});

// ----------------------------------------------------------------------
// Uebertragung auf den Exchange-Server
// ----------------------------------------------------------------------

Runner::test('Abwesenheit: Notiz auf dem Exchange-Server setzen', function (): void {
    $setup = oofSetup();
    $service = $setup['service'];
    $repository = $setup['repository'];
    $exchange = orvantaExchange();
    $template = oofTemplate($service, [
        'fixed_text' => "Sehr geehrte Damen und Herren,\nich befinde mich derzeit nicht im Haus.",
        'example_text' => 'Bei dringenden Themen wenden Sie sich bitte an Herrn XY.',
    ]);

    $saved = $service->apply('dreinelt', 'reinelt@example.local', $template, [
        'active' => true,
        'dynamic_text' => "Vertretung: Frau Muster\n<test@example.local>",
        'external_audience' => 'all',
        'schedule_mode' => 'until_off',
    ], $exchange['exchange'], null);

    $xml = $exchange['transport']->last();
    Assert::contains('SetUserOofSettingsRequest', $xml);
    Assert::contains('<t:OofState>Enabled</t:OofState>', $xml);
    Assert::contains('<t:ExternalAudience>All</t:ExternalAudience>', $xml);
    Assert::contains('<t:EmailAddress>reinelt@example.local</t:EmailAddress>', $xml);
    Assert::false(str_contains($xml, '<t:Duration>'), 'Ohne Zeitraum darf keine Dauer gesendet werden.');
    // Fester Text, dynamischer Text und Escaping: der Text ist HTML-escaped
    // und wird fuer XML ein zweites Mal escaped.
    Assert::contains('Sehr geehrte Damen und Herren,&lt;br&gt;ich befinde mich derzeit nicht im Haus.', $xml);
    Assert::contains('Vertretung: Frau Muster', $xml);
    Assert::contains('&amp;lt;test@example.local&amp;gt;', $xml);
    Assert::false(str_contains($xml, '<test@example.local>'), 'Der Text darf nicht als Markup ankommen.');
    Assert::true($saved['active']);
    Assert::true($repository->settings('dreinelt')['active']);

    // Abschalten uebertraegt Disabled ohne Text.
    $service->apply('dreinelt', 'reinelt@example.local', $template, [
        'active' => false,
        'dynamic_text' => 'Vertretung: Frau Muster',
        'external_audience' => 'none',
        'schedule_mode' => 'until_off',
    ], $exchange['exchange'], null);
    $off = $exchange['transport']->last();
    Assert::contains('<t:OofState>Disabled</t:OofState>', $off);
    Assert::contains('<t:ExternalAudience>None</t:ExternalAudience>', $off);
    Assert::false(str_contains($off, 'Vertretung: Frau Muster'), 'Beim Abschalten wird kein Text uebertragen.');
    Assert::false($repository->settings('dreinelt')['active']);

    // Zeitraum: Scheduled mit Dauer vom Beginn bis zum Ende des Tages (lokale Zeit).
    $service->apply('dreinelt', 'reinelt@example.local', $template, [
        'active' => true,
        'dynamic_text' => 'Im Urlaub',
        'external_audience' => 'none',
        'schedule_mode' => 'range',
        'start_date' => '2026-03-02',
        'end_date' => '2026-03-06',
    ], $exchange['exchange'], null);
    $scheduled = $exchange['transport']->last();
    Assert::contains('<t:OofState>Scheduled</t:OofState>', $scheduled);
    Assert::contains('<t:Duration>', $scheduled);
    Assert::contains('<t:StartTime>' . gmdate('Y-m-d\TH:i:s\Z', (int) strtotime('2026-03-02 00:00:00')) . '</t:StartTime>', $scheduled);
    Assert::contains('<t:EndTime>' . gmdate('Y-m-d\TH:i:s\Z', (int) strtotime('2026-03-06 23:59:59')) . '</t:EndTime>', $scheduled);
    Assert::same('2026-03-02', $repository->settings('dreinelt')['start_date']);
    Assert::same('range', $repository->settings('dreinelt')['schedule_mode']);
});

Runner::test('Abwesenheit: Signatur als Abschluss der Notiz', function (): void {
    $setup = oofSetup();
    $service = $setup['service'];
    $exchange = orvantaExchange();

    // Signaturvorlage fuer die Gruppe IT; der Benutzer ist Mitglied dieser Gruppe.
    $setup['signatures']->save(null, [
        'name' => 'IT',
        'greeting' => 'Mit freundlichen Grüßen',
        'name_format' => 'first_last',
        'street' => '',
        'postal_city' => '',
        'phone_mode' => 'full',
        'phone_prefix' => '',
        'text_color' => 'color_text',
        'separator_color' => 'color_accent',
        'groups' => 'IT',
        'sort_order' => '1',
        'active' => '1',
    ]);
    $template = oofTemplate($service, ['groups' => 'IT']);
    $ssoUser = ['id' => 7, 'username' => 'dreinelt', 'display_name' => 'Daniel-André Reinelt', 'groups' => ['IT']];

    $service->apply('dreinelt', 'reinelt@example.local', $template, [
        'active' => true,
        'dynamic_text' => 'Vertretung: Frau Muster',
        'external_audience' => 'none',
        'schedule_mode' => 'until_off',
    ], $exchange['exchange'], $ssoUser);

    $xml = $exchange['transport']->last();
    Assert::contains(OrvantaSignatureService::MARKER_CLASS, $xml);
    Assert::contains('Daniel-André Reinelt', $xml);
    // Der dynamische Text steht vor der Signatur.
    Assert::true(
        strpos($xml, 'Vertretung: Frau Muster') < strpos($xml, OrvantaSignatureService::MARKER_CLASS),
        'Die Signatur folgt dem Text der Abwesenheitsnotiz.'
    );
    // Auch der Banner kennt die Signatur.
    $status = $service->status('dreinelt', 'reinelt@example.local', $exchange['exchange'], $ssoUser);
    Assert::contains(OrvantaSignatureService::MARKER_CLASS, $status['signature']);
    Assert::true($status['available']);
});

Runner::test('Abwesenheit: Zustand und Status des Postfachs', function (): void {
    $setup = oofSetup();
    $service = $setup['service'];
    $exchange = orvantaExchange();
    $template = oofTemplate($service, ['groups' => 'IT']);

    // Ohne Vorlage steht die Abwesenheitsnotiz nicht zur Verfuegung.
    $withoutTemplate = $service->status('dreinelt', 'reinelt@example.local', $exchange['exchange'], ['groups' => ['Verwaltung']]);
    Assert::false($withoutTemplate['available']);
    Assert::null($withoutTemplate['template']);
    Assert::same('', $withoutTemplate['fixed_text']);

    $ssoUser = ['id' => 7, 'groups' => ['IT']];
    $status = $service->status('dreinelt', 'reinelt@example.local', $exchange['exchange'], $ssoUser);
    Assert::true($status['available']);
    Assert::same($template['id'], $status['template']['id']);
    Assert::same($template['name'], $status['template']['name']);
    Assert::contains('ich befinde mich derzeit nicht im Haus.', $status['fixed_text']);
    Assert::same($template['example_text'], $status['dynamic_text']);
    // Ohne aktive Notiz meldet der Server Disabled.
    Assert::same('Disabled', $status['state']);
    Assert::false($status['active']);
    Assert::false($status['scheduled']);
});

Runner::test('Abwesenheit: Zustand im Demomodus folgt den gespeicherten Einstellungen', function (): void {
    oofWithDemoEnv(function (): void {
        $setup = oofSetup();
        $service = $setup['service'];
        $repository = $setup['repository'];
        $exchange = orvantaExchange();
        $template = oofTemplate($service, ['groups' => 'IT']);

        // Ohne Einstellungen: abgeschaltet.
        $off = $service->state('reinelt@example.local', $exchange['exchange'], $service->settings('dreinelt', $template));
        Assert::same('Disabled', $off['state']);
        Assert::false($off['active']);

        // Bis zum Abschalten aktiv.
        $repository->saveSettings('dreinelt', [
            'template_id' => $template['id'],
            'dynamic_text' => '',
            'external_audience' => 'all',
            'schedule_mode' => 'until_off',
            'start_date' => '',
            'end_date' => '',
            'active' => true,
        ]);
        $untilOff = $service->state('reinelt@example.local', $exchange['exchange'], $repository->settings('dreinelt'));
        Assert::same('Enabled', $untilOff['state']);
        Assert::true($untilOff['active']);
        Assert::false($untilOff['scheduled']);
        Assert::same('All', $untilOff['external_audience']);

        // Laufender Zeitraum: geplant und aktiv.
        $repository->saveSettings('dreinelt', [
            'template_id' => $template['id'],
            'dynamic_text' => '',
            'external_audience' => 'none',
            'schedule_mode' => 'range',
            'start_date' => date('Y-m-d', time() - 86400),
            'end_date' => date('Y-m-d', time() + 86400),
            'active' => true,
        ]);
        $running = $service->state('reinelt@example.local', $exchange['exchange'], $repository->settings('dreinelt'));
        Assert::same('Scheduled', $running['state']);
        Assert::true($running['active']);
        Assert::true($running['scheduled']);
        Assert::same('None', $running['external_audience']);

        // Abgelaufener bzw. kuenftiger Zeitraum: nicht aktiv.
        $repository->saveSettings('dreinelt', [
            'template_id' => $template['id'],
            'dynamic_text' => '',
            'external_audience' => 'none',
            'schedule_mode' => 'range',
            'start_date' => date('Y-m-d', time() - 5 * 86400),
            'end_date' => date('Y-m-d', time() - 86400),
            'active' => true,
        ]);
        $expired = $service->state('reinelt@example.local', $exchange['exchange'], $repository->settings('dreinelt'));
        Assert::same('Disabled', $expired['state']);
        Assert::false($expired['active']);

        // Der Banner kennt den Zustand aus den gespeicherten Einstellungen.
        $repository->saveSettings('dreinelt', [
            'template_id' => $template['id'],
            'dynamic_text' => 'Vertretung: Frau Muster',
            'external_audience' => 'all',
            'schedule_mode' => 'until_off',
            'start_date' => '',
            'end_date' => '',
            'active' => true,
        ]);
        $status = $service->status('dreinelt', 'reinelt@example.local', $exchange['exchange'], ['id' => 7, 'groups' => ['IT']]);
        Assert::same('Enabled', $status['state']);
        Assert::true($status['active']);
        Assert::same('All', $status['server_audience']);
        Assert::same('Vertretung: Frau Muster', $status['dynamic_text']);
    });
});

// ----------------------------------------------------------------------
// Text der Notiz
// ----------------------------------------------------------------------

Runner::test('Abwesenheit: Text und HTML der Notiz', function (): void {
    $setup = oofSetup();
    $service = $setup['service'];
    $template = oofTemplate($service, ['fixed_text' => "Sehr geehrte Damen und Herren,\nich bin nicht im Haus."]);

    // Fester und dynamischer Teil werden durch eine Leerzeile getrennt.
    Assert::same(
        "Sehr geehrte Damen und Herren,\nich bin nicht im Haus.\n\nVertretung: Frau Muster",
        $service->text($template, 'Vertretung: Frau Muster')
    );
    Assert::same("Sehr geehrte Damen und Herren,\nich bin nicht im Haus.", $service->text($template, '   '));

    $html = $service->html($template, 'Vertretung: <Frau Muster>', '');
    Assert::contains('Sehr geehrte Damen und Herren,<br>ich bin nicht im Haus.', $html);
    Assert::contains('&lt;Frau Muster&gt;', $html);
    Assert::false(str_contains($html, '<Frau Muster>'));
    Assert::false(str_contains($html, OrvantaSignatureService::MARKER_CLASS));

    $withSignature = $service->html($template, 'Vertretung: Frau Muster', '<div class="' . OrvantaSignatureService::MARKER_CLASS . '">Signatur</div>');
    Assert::contains(OrvantaSignatureService::MARKER_CLASS, $withSignature);
    Assert::true(
        strpos($withSignature, 'Vertretung: Frau Muster') < strpos($withSignature, OrvantaSignatureService::MARKER_CLASS),
        'Die Signatur wird angehaengt.'
    );

    // Markup in der Vorlage wird escaped.
    $escaped = $service->html(oofTemplate($service, ['name' => 'Markup', 'fixed_text' => 'Zeile <b>fett</b>']), '', '');
    Assert::contains('Zeile &lt;b&gt;fett&lt;/b&gt;', $escaped);
    Assert::false(str_contains($escaped, '<b>'));
});
