<?php

declare(strict_types=1);

use App\Exceptions\ValidationException;
use App\Repositories\OrvantaSignatureRepository;
use App\Repositories\PhonebookRepository;
use App\Repositories\SettingsRepository;
use App\Services\Orvanta\MailHtmlSanitizer;
use App\Services\Orvanta\OrvantaSignatureService;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * SQLite-Schema parallel zur Migration 035 pflegen.
 *
 * @param array<string,string> $settings
 * @return array{service:OrvantaSignatureService,repository:OrvantaSignatureRepository,pdo:PDO}
 */
function signatureSetup(array $settings = [], ?string $logoPath = null, string $logoMime = 'image/png'): array
{
    $pdo = officePdo($settings + ['color_text' => '#112233', 'color_accent' => '#44aa55']);
    $pdo->exec('CREATE TABLE orvanta_signatures (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(120) NOT NULL,
        greeting VARCHAR(120) NOT NULL DEFAULT \'Mit freundlichen Grüßen\',
        street VARCHAR(190) NOT NULL DEFAULT \'\',
        postal_city VARCHAR(190) NOT NULL DEFAULT \'\',
        phone_mode VARCHAR(10) NOT NULL DEFAULT \'prefix\',
        phone_prefix VARCHAR(64) NOT NULL DEFAULT \'\',
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
    $pdo->exec("INSERT INTO phonebook (id, samaccount_name, display_name, title, phone, department) VALUES
        (7, 'dreinelt', 'Daniel-André Reinelt', 'Administrator', '+49 5331 934-1849', 'Informationstechnologie (IT / EDV)'),
        (8, 'inaktiv', 'Alt Konto', 'Chef', '0815', 'Leitung')");
    $pdo->exec('UPDATE phonebook SET active = 0 WHERE id = 8');

    $repository = new OrvantaSignatureRepository($pdo);
    $service = new OrvantaSignatureService(
        $repository,
        new PhonebookRepository($pdo),
        new SettingsService(new SettingsRepository($pdo)),
        static fn (): ?array => $logoPath === null ? null : ['path' => $logoPath, 'mime' => $logoMime]
    );

    return ['service' => $service, 'repository' => $repository, 'pdo' => $pdo];
}

/**
 * @return array<string,mixed>
 */
function signatureInput(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Standard',
        'greeting' => 'Mit freundlichen Grüßen',
        'street' => 'Alter Weg 80',
        'postal_city' => '38302 Wolfenbüttel',
        'phone_mode' => 'prefix',
        'phone_prefix' => 'T.: +49 (05331) 934 - ',
        'groups' => 'Orvanta-Standard, IT',
        'sort_order' => '1',
        'active' => '1',
    ];
}

Runner::test('Signaturen: Validierung der Vorlage', function (): void {
    $service = signatureSetup()['service'];

    try {
        $service->validate(signatureInput(['name' => '  ', 'phone_prefix' => '', 'sort_order' => '0']));
        Assert::true(false, 'Validierung haette fehlschlagen muessen.');
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
        Assert::true(isset($errors['name']), 'Name ist Pflicht.');
        Assert::true(isset($errors['phone_prefix']), 'Praefix ist im Praefix-Modus Pflicht.');
        Assert::true(isset($errors['sort_order']), 'Reihenfolge ausserhalb des Bereichs.');
    }

    // Im Modus "komplett" ist kein Praefix noetig; Gruppen werden dedupliziert.
    $clean = $service->validate(signatureInput(['phone_mode' => 'full', 'phone_prefix' => '', 'groups' => 'IT, it , Verwaltung']));
    Assert::same('full', $clean['phone_mode']);
    Assert::same(['IT', 'Verwaltung'], $clean['groups']);
    Assert::true($clean['active']);

    try {
        $service->validate(signatureInput(['phone_mode' => 'magic']));
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['phone_mode']));
    }

    // Abschliessendes Leerzeichen des Praefixes bleibt erhalten.
    Assert::same('T.: +49 (05331) 934 - ', $service->validate(signatureInput())['phone_prefix']);
});

Runner::test('Signaturen: Speichern, Laden, Aktualisieren und Loeschen', function (): void {
    $setup = signatureSetup();
    $service = $setup['service'];

    $id = $service->save(null, signatureInput());
    Assert::true($id > 0);
    $saved = $service->find($id);
    Assert::same('Standard', $saved['name']);
    Assert::same(['Orvanta-Standard', 'IT'], $saved['groups']);
    Assert::same('prefix', $saved['phone_mode']);

    $service->save($id, signatureInput(['name' => 'Geändert', 'active' => '', 'sort_order' => '5']));
    $updated = $service->find($id);
    Assert::same('Geändert', $updated['name']);
    Assert::false($updated['active']);
    Assert::same(5, $updated['sort_order']);
    Assert::same(1, count($service->all()));

    try {
        $service->save(999, signatureInput());
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['id']));
    }

    $service->delete($id);
    Assert::null($service->find($id));
    Assert::same([], $service->all());
});

Runner::test('Signaturen: Zuordnung ueber AD-Gruppen (Reihenfolge, inaktiv, Schreibweise)', function (): void {
    $service = signatureSetup()['service'];
    $service->save(null, signatureInput(['name' => 'Zweite', 'groups' => 'Vertrieb', 'sort_order' => '2']));
    $service->save(null, signatureInput(['name' => 'Erste', 'groups' => 'IT', 'sort_order' => '1']));
    $service->save(null, signatureInput(['name' => 'Aus', 'groups' => 'Geheim', 'active' => '']));

    Assert::same('Erste', $service->match(['vertrieb', 'it'])['name'], 'Kleinste Reihenfolge gewinnt.');
    Assert::same('Zweite', $service->match(['VERTRIEB'])['name'], 'Gruppen ohne Beachtung der Schreibweise.');
    Assert::null($service->match(['Geheim']), 'Inaktive Vorlagen werden nicht zugeordnet.');
    Assert::null($service->match([]));
    Assert::null($service->match(['Unbekannt']));
    Assert::null($service->forUser(null));
    Assert::null($service->forUser(['id' => 7, 'groups' => ['Niemand']]));
});

Runner::test('Signaturen: Darstellung mit AD-Daten, Praefix + Durchwahl, Farben und Logo', function (): void {
    $logo = tempnam(sys_get_temp_dir(), 'ovlogo');
    file_put_contents($logo, 'PNGDATA');
    try {
        $service = signatureSetup([], $logo)['service'];
        $service->save(null, signatureInput());

        $result = $service->forUser(['id' => 7, 'username' => 'dreinelt', 'display_name' => 'x', 'groups' => ['IT']]);
        Assert::true($result !== null);
        $html = $result['html'];
        Assert::contains('class="ov-signature-block"', $html);
        Assert::contains('Mit freundlichen Grüßen', $html);
        Assert::contains('<b>Daniel-André Reinelt</b>', $html);
        Assert::contains('Administrator', $html);
        Assert::contains('Informationstechnologie (IT / EDV)', $html);
        Assert::contains('Alter Weg 80', $html);
        Assert::contains('38302 Wolfenbüttel', $html);
        Assert::contains('T.: +49 (05331) 934 - <b>1849</b>', $html, 'Praefix aus der Vorlage, Durchwahl aus dem AD.');
        Assert::contains('color:#112233', $html, 'Schriftfarbe aus dem Design.');
        Assert::contains('<span style="color:#44aa55">&#9632;</span>', $html, 'Trennzeichen in Akzentfarbe.');
        Assert::contains('src="data:image/png;base64,' . base64_encode('PNGDATA') . '"', $html, 'Logo als data-URI.');
        Assert::false(str_contains($html, '+49 5331 934-1849'), 'Komplette AD-Nummer erscheint im Praefix-Modus nicht.');

        // Der Sanitizer fuer ausgehende Mails laesst die Signatur unveraendert durch.
        $clean = MailHtmlSanitizer::clean($html, false)['html'];
        Assert::contains('ov-signature-block', $clean);
        Assert::contains('data:image/png;base64,', $clean);
        Assert::contains('<b>1849</b>', $clean);
    } finally {
        @unlink($logo);
    }
});

Runner::test('Signaturen: komplette Rufnummer aus dem AD, ohne Logo, fehlende Felder', function (): void {
    $service = signatureSetup()['service'];
    $service->save(null, signatureInput(['phone_mode' => 'full', 'phone_prefix' => '', 'greeting' => '', 'street' => '']));

    $html = $service->forUser(['id' => 7, 'groups' => ['IT']])['html'];
    Assert::contains('T.: +49 5331 934-1849', $html);
    Assert::false(str_contains($html, '<img'), 'Ohne hochgeladenes Logo kein Bild.');
    Assert::false(str_contains($html, '<p'), 'Ohne Grussformel kein Absatz.');
    Assert::false(str_contains($html, '&#9632;</span> 38302'), 'Ohne Strasse kein Trennzeichen vor dem Ort.');
    Assert::contains('38302 Wolfenbüttel', $html);

    // Testbenutzer ohne Telefonbucheintrag: nur Anzeigename, keine Rufnummernzeile.
    $html = $service->forUser(['id' => 0, 'username' => 'test', 'display_name' => 'Test Person', 'groups' => ['IT']])['html'];
    Assert::contains('<b>Test Person</b>', $html);
    Assert::false(str_contains($html, 'T.:'));

    // Inaktive Telefonbucheintraege werden nicht verwendet.
    $html = $service->forUser(['id' => 8, 'username' => 'inaktiv', 'display_name' => 'Fallback', 'groups' => ['IT']])['html'];
    Assert::contains('<b>Fallback</b>', $html);
    Assert::false(str_contains($html, 'Chef'));
});

Runner::test('Signaturen: Durchwahl aus verschiedenen Rufnummernformaten', function (): void {
    Assert::same('1849', OrvantaSignatureService::extension('+49 (05331) 934 - 1849'));
    Assert::same('1849', OrvantaSignatureService::extension('1849'));
    Assert::same('12', OrvantaSignatureService::extension('12'));
    Assert::same('', OrvantaSignatureService::extension('keine'));
});

Runner::test('Signaturen: Anfuegen ersetzt vorhandene Signatur und steht vor dem Zitat', function (): void {
    $signature = '<div class="ov-signature-block"><table><tr><td>Sig</td></tr></table></div>';

    Assert::same('<p>Hallo</p>' . $signature, OrvantaSignatureService::append('<p>Hallo</p>', $signature));
    Assert::same('<p>Hallo</p>', OrvantaSignatureService::append('<p>Hallo</p>', ''), 'Ohne Vorlage bleibt der Text unveraendert.');

    // Alte Signatur (z. B. aus einem Entwurf) wird entfernt, nicht verdoppelt.
    $old = '<div class="ov-signature-block"><p>alt</p><table><tr><td>Alt</td></tr></table></div>';
    $result = OrvantaSignatureService::append('<p>Text</p>' . $old, $signature);
    Assert::same('<p>Text</p>' . $signature, $result);
    Assert::same(1, substr_count($result, 'ov-signature-block'));

    // Vor einem Zitatblock des Editors.
    $quote = '<div class="ov-quote"><hr><p><b>Von:</b> x</p></div>';
    Assert::same('<p>Antwort</p>' . $signature . $quote, OrvantaSignatureService::append('<p>Antwort</p>' . $quote, $signature));

    // strip() laesst Texte ohne Marker unangetastet.
    Assert::same('<p>Nur Text</p>', OrvantaSignatureService::strip('<p>Nur Text</p>'));
});
