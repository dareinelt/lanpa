<?php

declare(strict_types=1);

use App\Repositories\SettingsRepository;
use App\Services\SettingsService;
use App\Services\ThemeService;
use App\Support\Color;
use App\Support\TileColors;
use Tests\Support\Assert;
use Tests\Support\Runner;

function tileContrastSettings(array $settings = []): SettingsService
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
 * Liest die CSS-Variablen eines Blocks aus der vom ThemeService erzeugten CSS.
 *
 * @return array<string,string>
 */
function tileContrastBlock(string $css, string $selector): array
{
    $start = strpos($css, $selector);
    Assert::true($start !== false, 'Block ' . $selector . ' fehlt');
    $start += strlen($selector);
    $end = strpos($css, '}', $start);
    Assert::true($end !== false, 'Block ' . $selector . ' ist nicht geschlossen');

    $values = [];
    foreach (explode(';', substr($css, $start, $end - $start)) as $declaration) {
        if (!str_contains($declaration, ':')) {
            continue;
        }
        [$property, $value] = explode(':', $declaration, 2);
        $values[trim($property)] = trim($value);
    }

    return $values;
}

/**
 * Sichtbarer Kachelhintergrund: Kachelfarbe mit Deckkraft ueber dem Seitenhintergrund.
 * Die Kachel-Vorgaben stehen nur im :root-Block und gelten fuer alle Modi.
 *
 * @param array<string,string> $block
 * @param array<string,string> $root
 */
function tileContrastBackground(array $block, array $root): string
{
    $tile = $root['--tile-bg-default'] ?? $block['--color-surface'];
    $opacity = (int) rtrim($root['--tile-bg-opacity-default'] ?? '100%', '%');

    return Color::blend($block['--color-background'], $tile, $opacity / 100);
}

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function tileContrastItem(array $overrides = []): array
{
    return $overrides + [
        'id' => 7,
        'type' => 'internal',
        'title' => 'Beispiel',
        'url' => 'https://example.invalid/',
        'icon' => 'app',
        'description' => 'Ausfuehrliche Beschreibung',
        'short_description' => 'Kurzbeschreibung',
        'protected_access' => 0,
        'override_background' => 0,
        'background_color' => '',
        'background_opacity' => null,
    ];
}

/**
 * @param list<array<string,mixed>> $items
 */
function tileContrastRender(array $items, ?array $tileContrastBase = null, string $descriptionMode = 'both'): string
{
    $GLOBALS['csp_nonce'] = 'test-nonce';
    ob_start();
    require BASE_PATH . '/views/partials/tiles.php';

    return (string) ob_get_clean();
}

/**
 * Liest den Wert einer CSS-Variablen aus einer vom Partial erzeugten Regel.
 */
function tileContrastRuleValue(string $html, string $selector, string $property): string
{
    $start = strpos($html, $selector);
    Assert::true($start !== false, 'Regel ' . $selector . ' fehlt');
    $end = strpos($html, '}', $start);
    $block = substr($html, $start, $end === false ? strlen($html) - $start : $end - $start);
    $pattern = '/' . preg_quote($property, '/') . ':(#[0-9a-f]{6});/';
    Assert::true(preg_match($pattern, $block, $matches) === 1, $property . ' fehlt in ' . $selector);

    return $matches[1];
}

Runner::test('Kontrastpruefung behaelt ausreichende Textfarben bei', static function (): void {
    Assert::true(Color::contrastRatio('#ffffff', '#1b1f23') >= Color::MIN_CONTRAST);
    Assert::same('#1b1f23', Color::ensureContrast('#ffffff', '#1b1f23'));
});

Runner::test('Kontrastpruefung passt unlesbare Textfarben automatisch an', static function (): void {
    $adjusted = Color::ensureContrast('#f8f8f8', '#8e9195');

    Assert::true($adjusted !== '#8e9195');
    Assert::true(Color::contrastRatio('#f8f8f8', $adjusted) >= Color::MIN_CONTRAST, 'Kontrast bleibt unter 4.5:1');
});

Runner::test('Kontrastpruefung faengt ungueltige Farbwerte ab', static function (): void {
    Assert::same('#000000', Color::ensureContrast('#ffffff', 'unsinn'));
    Assert::same('#ffffff', Color::ensureContrast('#000000', 'unsinn'));
});

Runner::test('Kacheltexte werden auf heller Kachelfarbe im dunklen Modus angepasst', static function (): void {
    $theme = new ThemeService(tileContrastSettings([]));
    $css = $theme->css();
    $dark = tileContrastBlock($css, ':root[data-theme="dark"]{');
    $background = Color::blend($dark['--color-background'], '#ffffff', 1.0);

    $variables = TileColors::variables([
        'background' => $dark['--color-background'],
        'text' => $dark['--color-text'],
        'muted' => $dark['--color-muted'],
        'accent' => $dark['--color-primary'],
        'surface' => $dark['--color-surface'],
        'surfaceHover' => $dark['--color-surface-hover'],
    ], '#ffffff', 100);

    Assert::true(Color::contrastRatio($background, $variables['tileText']) >= Color::MIN_CONTRAST, 'Titel');
    Assert::true(Color::contrastRatio($background, $variables['tileMuted']) >= Color::MIN_CONTRAST, 'Kurzbeschreibung');
    Assert::true(Color::contrastRatio($background, $variables['tileAccent']) >= Color::MIN_CONTRAST, 'Detail-Schaltflaeche');
    Assert::true(Color::contrastRatio($variables['tileHoverBg'], $variables['tileMuted']) >= Color::MIN_CONTRAST, 'Kurzbeschreibung auf der Hover-Flaeche');
    Assert::true(Color::contrastRatio($variables['tileHoverBg'], $variables['tileAccent']) >= Color::MIN_CONTRAST, 'Schaltflaeche auf der Hover-Flaeche');
});

Runner::test('Designfarben: Kacheltexte bleiben nach Anpassung im dunklen Modus lesbar', static function (): void {
    // Helle Designfarben fuer den dunklen Modus plus helle Kachelfarbe: ohne
    // Anpassung waeren Titel und Beschreibung unlesbar.
    $css = (new ThemeService(tileContrastSettings([
        'color_background_dark' => '#f4f6f8',
        'color_text_dark' => '#8e9195',
        'tile_background_color' => '#ffffff',
        'tile_background_opacity' => '100',
    ])))->css();

    foreach ([
        'heller Modus' => tileContrastBlock($css, ':root{'),
        'dunkler Modus (System)' => tileContrastBlock($css, ':root:not([data-theme="light"]){'),
        'dunkler Modus (Auswahl)' => tileContrastBlock($css, ':root[data-theme="dark"]{'),
    ] as $label => $block) {
        $background = tileContrastBackground($block, tileContrastBlock($css, ':root{'));
        Assert::true(Color::contrastRatio($background, $block['--tile-text']) >= Color::MIN_CONTRAST, 'Titel (' . $label . ')');
        Assert::true(Color::contrastRatio($background, $block['--tile-muted']) >= Color::MIN_CONTRAST, 'Kurzbeschreibung (' . $label . ')');
        Assert::true(Color::contrastRatio($background, $block['--tile-accent']) >= Color::MIN_CONTRAST, 'Detail-Schaltflaeche (' . $label . ')');
        Assert::true(isset($block['--tile-hover-bg']), 'Hover-Flaeche fehlt (' . $label . ')');
        // Die aufgeklappte Beschreibung liegt auf der Design-Oberflaeche.
        Assert::true(
            Color::contrastRatio($block['--color-surface-hover'], $block['--tile-details-text']) >= Color::MIN_CONTRAST,
            'Aufgeklappte Beschreibung (' . $label . ')'
        );
    }
});

Runner::test('Ohne eigene Kachelfarbe bleibt die Design-Hover-Flaeche erhalten', static function (): void {
    $css = (new ThemeService(tileContrastSettings([])))->css();

    Assert::false(array_key_exists('--tile-hover-bg', tileContrastBlock($css, ':root{')), 'Hover-Flaeche ohne eigene Kachelfarbe');
    Assert::false(array_key_exists('--tile-hover-bg', tileContrastBlock($css, ':root[data-theme="dark"]{')), 'Hover-Flaeche ohne eigene Kachelfarbe');
});

Runner::test('Kachel mit eigener Hintergrundfarbe erhaelt kontrastsichere Textfarben', static function (): void {
    $theme = new ThemeService(tileContrastSettings([]));
    $css = $theme->css();
    $light = tileContrastBlock($css, ':root{');
    $dark = tileContrastBlock($css, ':root[data-theme="dark"]{');

    $html = tileContrastRender([
        tileContrastItem([
            'override_background' => 1,
            'background_color' => '#ffffff',
            'background_opacity' => 100,
        ]),
    ], $theme->tileContrastBase());

    Assert::contains('.tile[data-tile-id="7"]{--tile-bg:#ffffff;--tile-bg-opacity:100%;}', $html);

    $lightRule = '.tile[data-tile-id="7"]{--tile-text:';
    $darkRule = ':root[data-theme="dark"] .tile[data-tile-id="7"]{--tile-text:';
    Assert::contains($lightRule, $html);
    Assert::contains('@media (prefers-color-scheme: dark){:root:not([data-theme="light"]) .tile[data-tile-id="7"]{--tile-text:', $html);

    Assert::true(
        Color::contrastRatio('#ffffff', tileContrastRuleValue($html, $lightRule, '--tile-muted')) >= Color::MIN_CONTRAST,
        'Kurzbeschreibung (heller Modus)'
    );
    $darkMuted = tileContrastRuleValue($html, $darkRule, '--tile-muted');
    Assert::true(
        Color::contrastRatio(Color::blend($dark['--color-background'], '#ffffff', 1.0), $darkMuted) >= Color::MIN_CONTRAST,
        'Kurzbeschreibung (dunkler Modus)'
    );
    Assert::true($darkMuted !== $dark['--color-muted'], 'Beschreibungsfarbe wurde nicht angepasst');
    Assert::true(
        Color::contrastRatio(Color::blend($dark['--color-background'], '#ffffff', 1.0), tileContrastRuleValue($html, $darkRule, '--tile-text')) >= Color::MIN_CONTRAST,
        'Titel (dunkler Modus)'
    );
    Assert::true(
        Color::contrastRatio(Color::blend($dark['--color-background'], '#ffffff', 1.0), tileContrastRuleValue($html, $darkRule, '--tile-accent')) >= Color::MIN_CONTRAST,
        'Detail-Schaltflaeche (dunkler Modus)'
    );
    Assert::true(
        Color::contrastRatio($light['--color-surface'], tileContrastRuleValue($html, $lightRule, '--tile-muted')) >= Color::MIN_CONTRAST,
        'Kurzbeschreibung (heller Modus)'
    );
});

Runner::test('Kacheln ohne eigene Hintergrundfarbe erhalten keine eigenen Regeln', static function (): void {
    $theme = new ThemeService(tileContrastSettings([]));

    $html = tileContrastRender([
        tileContrastItem(),
        tileContrastItem(['id' => 8]),
    ], $theme->tileContrastBase());

    Assert::false(str_contains($html, '<style'), 'Es darf kein Style-Block erzeugt werden');
    Assert::contains('data-tile-id="8"', $html);
});

Runner::test('Ungueltige Kachelfarbe erzeugt keine CSS-Regel', static function (): void {
    $theme = new ThemeService(tileContrastSettings([]));

    $html = tileContrastRender([
        tileContrastItem(['override_background' => 1, 'background_color' => 'javascript:alert(1)']),
    ], $theme->tileContrastBase());

    Assert::false(str_contains($html, '<style'), 'Ungueltige Farbe darf nicht ausgegeben werden');
    Assert::false(str_contains($html, 'javascript'), 'Ungueltige Farbe darf nicht ausgegeben werden');
});
