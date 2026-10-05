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
 * SQLite-Schema parallel zu den Migrationen 035–037 pflegen.
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

    // Farben: Standard = Text-/Akzentfarbe, nur Designfarben erlaubt.
    $clean = $service->validate(signatureInput());
    Assert::same('color_text', $clean['text_color']);
    Assert::same('color_accent', $clean['separator_color']);
    $clean = $service->validate(signatureInput(['text_color' => 'color_primary', 'separator_color' => 'color_secondary']));
    Assert::same('color_primary', $clean['text_color']);
    Assert::same('color_secondary', $clean['separator_color']);
    try {
        $service->validate(signatureInput(['text_color' => '#ff0000', 'separator_color' => 'red']));
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['text_color']));
        Assert::true(isset($exception->errors()['separator_color']));
    }
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

Runner::test('Signaturen: Logo in Hoehe der Textzeilen, waehlbare Designfarben', function (): void {
    $logo = tempnam(sys_get_temp_dir(), 'ovlogo');
    // 1000 x 500 px PNG (Signatur + IHDR reichen fuer getimagesize).
    file_put_contents($logo, "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 1000, 500) . "\x08\x02\x00\x00\x00" . pack('N', 0));
    try {
        $service = signatureSetup(['color_primary' => '#0000aa', 'color_secondary' => '#00aa00'], $logo)['service'];
        $id = $service->save(null, signatureInput(['text_color' => 'color_primary', 'separator_color' => 'color_secondary']));
        Assert::same('color_primary', $service->find($id)['text_color']);

        $html = $service->forUser(['id' => 7, 'groups' => ['IT']])['html'];
        $height = 3 * OrvantaSignatureService::LINE_HEIGHT;
        Assert::contains('width="' . ($height * 2) . '" height="' . $height . '"', $html, 'Logo skaliert auf drei Textzeilen.');
        Assert::contains('line-height:' . OrvantaSignatureService::LINE_HEIGHT . 'px', $html);
        Assert::contains('color:#0000aa', $html, 'Gewaehlte Schriftfarbe.');
        Assert::contains('<span style="color:#00aa00">&#9632;</span>', $html, 'Gewaehlte Trennzeichenfarbe.');
        Assert::false(str_contains($html, '#112233'));
    } finally {
        @unlink($logo);
    }

    // Sehr breite Logos werden auf LOGO_MAX_WIDTH begrenzt, unbekannte Masse nur in der Hoehe gesetzt.
    Assert::same([OrvantaSignatureService::LOGO_MAX_WIDTH, 24], OrvantaSignatureService::logoSize(1000, 100, 3));
    Assert::same([0, 36], OrvantaSignatureService::logoSize(0, 0, 2));
    Assert::same([36, 54], OrvantaSignatureService::logoSize(200, 300, 3));

    Assert::same([120, 60], OrvantaSignatureService::imageDimensions('<svg xmlns="http://www.w3.org/2000/svg" width="120px" height="60"></svg>', 'image/svg+xml'));
    Assert::same([300, 150], OrvantaSignatureService::imageDimensions('<svg viewBox="0 0 300 150"></svg>', 'image/svg+xml'));
    Assert::same([0, 0], OrvantaSignatureService::imageDimensions('PNGDATA', 'image/png'));
});

Runner::test('Signaturen: Logo wird selbst auf die Zielgroesse verkleinert', function (): void {
    // SVG: width/height des Wurzelelements, viewBox aus den alten Massen.
    $svg = OrvantaSignatureService::scaleImage('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="1000" height="500"><rect/></svg>', 'image/svg+xml', 108, 54);
    Assert::same('image/svg+xml', $svg['mime']);
    Assert::contains('<svg width="108" height="54" viewBox="0 0 1000 500" xmlns="http://www.w3.org/2000/svg">', $svg['contents']);
    Assert::same([108, 54], OrvantaSignatureService::imageDimensions($svg['contents'], 'image/svg+xml'));
    $svg = OrvantaSignatureService::scaleImage('<svg viewBox="0 0 300 150" width=\'100%\'></svg>', 'image/svg+xml', 72, 36);
    Assert::same('<svg width="72" height="36" viewBox="0 0 300 150"></svg>', $svg['contents']);

    // Unbekannte Masse oder unlesbare Bilder bleiben unveraendert.
    Assert::same(['contents' => 'PNGDATA', 'mime' => 'image/png'], OrvantaSignatureService::scaleImage('PNGDATA', 'image/png', 0, 36));
    Assert::same(['contents' => 'PNGDATA', 'mime' => 'image/png'], OrvantaSignatureService::scaleImage('PNGDATA', 'image/png', 72, 36));

    if (!function_exists('imagecreatetruecolor')) {
        return;
    }
    $image = imagecreatetruecolor(1000, 500);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();
    $logo = tempnam(sys_get_temp_dir(), 'ovlogo');
    file_put_contents($logo, $jpeg);
    try {
        $service = signatureSetup([], $logo, 'image/jpeg')['service'];
        $service->save(null, signatureInput());
        $html = $service->forUser(['id' => 7, 'groups' => ['IT']])['html'];
        $height = 3 * OrvantaSignatureService::LINE_HEIGHT;
        Assert::true(preg_match('~src="data:image/png;base64,([^"]+)"~', $html, $match) === 1, 'Skaliertes Logo als PNG.');
        $size = getimagesizefromstring((string) base64_decode($match[1], true));
        Assert::same([$height * 2, $height], [$size[0], $size[1]], 'Pixelgroesse entspricht der Anzeigegroesse.');
        Assert::contains('width="' . ($height * 2) . '" height="' . $height . '"', $html);
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

Runner::test('Signaturen: Darstellung des Namens (Vorname Nachname / Nachname, Vorname)', function (): void {
    $setup = signatureSetup();
    $service = $setup['service'];
    $setup['pdo']->exec("UPDATE phonebook SET first_name = 'Daniel-André', last_name = 'Reinelt' WHERE id = 7");

    // Validierung: Standard "Vorname Nachname", nur bekannte Formate.
    Assert::same('first_last', $service->validate(signatureInput())['name_format']);
    Assert::same('last_first', $service->validate(signatureInput(['name_format' => 'last_first']))['name_format']);
    try {
        $service->validate(signatureInput(['name_format' => 'egal']));
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['name_format']));
    }

    $id = $service->save(null, signatureInput());
    Assert::same('first_last', $service->find($id)['name_format']);
    Assert::contains('<b>Daniel-André Reinelt</b>', $service->forUser(['id' => 7, 'groups' => ['IT']])['html']);

    $service->save($id, signatureInput(['name_format' => 'last_first']));
    Assert::same('last_first', $service->find($id)['name_format']);
    Assert::contains('<b>Reinelt, Daniel-André</b>', $service->forUser(['id' => 7, 'groups' => ['IT']])['html']);

    // Vorschau mit Beispieldaten.
    Assert::contains('<b>Musterfrau, Erika</b>', $service->preview($service->find($id)));

    // Ohne Vor- oder Nachname bleibt der Anzeigename erhalten.
    Assert::same('Test Person', OrvantaSignatureService::formatName(['display_name' => 'Test Person', 'first_name' => '', 'last_name' => 'Person', 'title' => '', 'department' => '', 'phone' => ''], 'last_first'));
    $html = $service->forUser(['id' => 0, 'username' => 'test', 'display_name' => 'Test Person', 'groups' => ['IT']])['html'];
    Assert::contains('<b>Test Person</b>', $html);
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
