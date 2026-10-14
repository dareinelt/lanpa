<?php

declare(strict_types=1);

use App\Repositories\SettingsRepository;
use App\Services\Orvanta\MailBodyContrast;
use App\Services\Orvanta\MailHtmlSanitizer;
use App\Services\SettingsService;
use App\Services\ThemeService;
use App\Support\Color;
use Tests\Support\Assert;
use Tests\Support\Runner;

function orvantaMailContrastSettings(array $settings = []): SettingsService
{
    $pdo = new PDO('sqlite::memory:');
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

    return new SettingsService(new SettingsRepository($pdo));
}

/**
 * Designfarben beider Modi, wie sie der Controller an MailBodyContrast gibt.
 *
 * @param array<string,mixed> $settings
 * @return array{light:array<string,string>,dark:array<string,string>}
 */
function orvantaMailContrastModes(array $settings = []): array
{
    return (new ThemeService(orvantaMailContrastSettings($settings)))->contrastModes();
}

/**
 * @param array<string,mixed> $settings
 */
function orvantaMailContrastApply(string $html, array $settings = []): string
{
    $modes = orvantaMailContrastModes($settings);

    return MailBodyContrast::apply($html, $modes['light'], $modes['dark']);
}

/**
 * Ersatzfarbe einer Modusangabe aus dem erzeugten HTML lesen.
 */
function orvantaMailContrastValue(string $html, string $property): ?string
{
    return preg_match('/' . preg_quote($property, '/') . ':(#[0-9a-f]{6})/', $html, $match) === 1 ? $match[1] : null;
}

Runner::test('Nachrichtentext: dunkle Absenderfarbe wird im dunklen Modus ersetzt', static function (): void {
    $modes = orvantaMailContrastModes();
    $html = orvantaMailContrastApply('<p style="font-size:14px">Guten Tag</p><p><span style="color:#5a6475;font-weight:bold">D. Andre</span></p>');

    // In der Anzeige des dunklen Modus gilt die Ersatzfarbe, im hellen Modus
    // bleibt die Originalfarbe des Absenders stehen.
    Assert::contains('data-ov-dark-color=""', $html);
    Assert::contains('color:#5a6475', $html);
    Assert::contains('font-weight:bold', $html);
    Assert::false(str_contains($html, 'data-ov-light-color'), 'Im hellen Modus ist die Farbe lesbar');

    $dark = orvantaMailContrastValue($html, '--ov-dark-color');
    Assert::true($dark !== null, 'Ersatzfarbe fehlt');
    Assert::true($dark !== '#5a6475', 'Die unlesbare Farbe wurde uebernommen');
    Assert::true(
        Color::contrastRatio($modes['dark']['background'], (string) $dark) >= Color::MIN_CONTRAST,
        'Ersatzfarbe erreicht den Mindestkontrast nicht'
    );
});

Runner::test('Nachrichtentext: helle Absenderfarbe wird im hellen Modus ersetzt', static function (): void {
    $modes = orvantaMailContrastModes();
    $html = orvantaMailContrastApply('<p style="color:#ffffff">Weisse Schrift</p>');

    Assert::contains('data-ov-light-color=""', $html);
    Assert::false(str_contains($html, 'data-ov-dark-color'), 'Im dunklen Modus ist die Farbe lesbar');
    $light = orvantaMailContrastValue($html, '--ov-light-color');
    Assert::true($light !== null, 'Ersatzfarbe fehlt');
    Assert::true(
        Color::contrastRatio($modes['light']['background'], (string) $light) >= Color::MIN_CONTRAST,
        'Ersatzfarbe erreicht den Mindestkontrast nicht'
    );
});

Runner::test('Nachrichtentext: eigener Hintergrund bestimmt die Ersatzfarbe', static function (): void {
    // Text in einer weissen Tabellenzelle bleibt im dunklen Modus dunkel,
    // sonst waere er auf dem weissen Feld nicht lesbar.
    $white = orvantaMailContrastApply('<table><tr><td style="background-color:#ffffff;color:#1b1f23">Serverwartung</td></tr></table>');
    $darkOnWhite = orvantaMailContrastValue($white, '--ov-dark-color') ?? '#1b1f23';
    Assert::true(Color::contrastRatio('#ffffff', $darkOnWhite) >= Color::MIN_CONTRAST, 'Text auf der weissen Zelle');
    Assert::true(Color::relativeLuminance($darkOnWhite) < 0.2, 'Der Text auf der weissen Zelle bleibt dunkel');
    Assert::false(str_contains($white, 'data-ov-light-color'), 'Auf weissem Grund ist die Farbe im hellen Modus lesbar');

    // Umgekehrt: dunkle Flaeche mit dunkler Schrift braucht in beiden Modi eine
    // Ersatzfarbe, weil die Flaeche unabhaengig vom Design dunkel bleibt.
    $dark = orvantaMailContrastApply('<div style="background-color:#12161c;color:#5a6475">Hinweis</div>');
    Assert::true(Color::contrastRatio('#12161c', '#5a6475') < Color::MIN_CONTRAST, 'Testfall ist bereits lesbar');
    foreach (['--ov-light-color', '--ov-dark-color'] as $property) {
        $effective = orvantaMailContrastValue($dark, $property);
        Assert::true($effective !== null, 'Ersatzfarbe fehlt (' . $property . ')');
        Assert::true(
            Color::contrastRatio('#12161c', (string) $effective) >= Color::MIN_CONTRAST,
            'Text auf der dunklen Flaeche (' . $property . ')'
        );
    }
});

Runner::test('Nachrichtentext: Hintergrund des Elternelements gilt fuer Kinder', static function (): void {
    $html = orvantaMailContrastApply('<div style="background-color:#12161c"><span style="color:#5a6475">Hinweis</span></div>');

    $dark = orvantaMailContrastValue($html, '--ov-dark-color');
    Assert::true($dark !== null, 'Ersatzfarbe fehlt');
    Assert::true(
        Color::contrastRatio('#12161c', (string) $dark) >= Color::MIN_CONTRAST,
        'Ersatzfarbe wurde gegen die Seitenflaeche statt gegen den geerbten Hintergrund geprueft'
    );
    Assert::true(Color::relativeLuminance((string) $dark) > 0.2, 'Auf dunklem Grund wird die Schrift hell');
});

Runner::test('Nachrichtentext: rgb()-Angaben und Farbnamen werden verarbeitet', static function (): void {
    $rgb = orvantaMailContrastApply('<p style="color:rgb(90, 100, 117)">Grau</p>');
    $named = orvantaMailContrastApply('<font color="navy">Dunkelblau</font>');

    Assert::true(orvantaMailContrastValue($rgb, '--ov-dark-color') !== null, 'rgb()-Farbe ohne Ersatzfarbe');
    Assert::true(orvantaMailContrastValue($named, '--ov-dark-color') !== null, 'Farbname ohne Ersatzfarbe');
    Assert::contains('color="navy"', $named);
});

Runner::test('Nachrichtentext: ungueltige Farbwerte bleiben unveraendert', static function (): void {
    $html = orvantaMailContrastApply('<p style="color:unsinn">Text</p>');

    Assert::contains('color:unsinn', $html);
    Assert::false(str_contains($html, '--ov-'), 'Ungueltige Farben duerfen keine Ersatzfarbe erhalten');
});

Runner::test('Nachrichtentext: Umlaute und Maskierungen bleiben erhalten', static function (): void {
    $html = orvantaMailContrastApply('<p style="color:#5a6475">Grüße &amp; Termine &lt;test&gt;</p>');

    Assert::contains('Grüße &amp; Termine &lt;test&gt;', $html);
});

Runner::test('Nachrichtentext: ohne Farbangabe bleibt das HTML unveraendert', static function (): void {
    $html = '<p>Guten Tag</p><table><tr><td>Serverwartung</td></tr></table>';

    Assert::same($html, orvantaMailContrastApply($html));
});

Runner::test('Versand entfernt die Ersatzfarben fuer den dunklen Modus', static function (): void {
    $html = orvantaMailContrastApply('<p style="color:#5a6475;font-weight:bold">Grüße</p>');
    $sent = MailHtmlSanitizer::clean($html, false)['html'];

    Assert::contains('color:#5a6475', $sent);
    Assert::contains('font-weight:bold', $sent);
    Assert::false(str_contains($sent, '--ov-'), 'Hilfsangaben gehoeren nicht in die versendete Nachricht');
    Assert::false(str_contains($sent, 'data-ov-'), 'Hilfsangaben gehoeren nicht in die versendete Nachricht');
});

Runner::test('Versand entfernt style-Attribute, die nur Hilfsangaben enthalten', static function (): void {
    $sent = MailHtmlSanitizer::clean('<p style="--ov-dark-color:#e8eaed">Text</p>', false)['html'];

    Assert::contains('<p>Text</p>', $sent);
    Assert::false(str_contains($sent, '--ov-'), 'Hilfsangaben gehoeren nicht in die versendete Nachricht');
});

Runner::test('Nachrichtenansicht: Stylesheet faerbt die Ersatzfarben je Modus ein', static function (): void {
    $css = (string) file_get_contents(BASE_PATH . '/public/assets/css/orvanta.css');

    foreach ([
        '.ov-mail__body [data-ov-dark-color]',
        '.ov-mail__body [data-ov-light-color]',
        '.ov-quote [data-ov-dark-color]',
        '.ov-quote [data-ov-light-color]',
    ] as $selector) {
        Assert::contains($selector, $css);
    }
    Assert::contains('color: var(--ov-dark-color, inherit) !important;', $css);
    Assert::contains('color: var(--ov-light-color, inherit) !important;', $css);
});
