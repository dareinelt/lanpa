<?php

declare(strict_types=1);

use App\Exceptions\ValidationException;
use App\Repositories\SettingsRepository;
use App\Services\LogoService;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * SQLite-Schema parallel zur Migration der Einstellungen pflegen. Der MySQL-Upsert
 * des Einstellungs-Repositories wird in die SQLite-Schreibweise uebersetzt.
 */
function headerLogoPdo(): PDO
{
    $pdo = new class ('sqlite::memory:') extends PDO {
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            if (str_contains($query, 'ON DUPLICATE KEY UPDATE')) {
                $query = str_replace(
                    'INSERT INTO settings',
                    'INSERT OR REPLACE INTO settings',
                    (string) preg_replace('~ON DUPLICATE KEY UPDATE.*$~s', '', $query)
                );
            }

            return parent::prepare($query, $options);
        }
    };
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key VARCHAR(64) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    return $pdo;
}

function headerLogoTempDir(): string
{
    $dir = sys_get_temp_dir() . '/lanpa-header-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o775, true);

    return $dir;
}

function cleanupHeaderLogoDir(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

function headerLogoSvg(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1379 169">'
        . '<rect width="1379" height="169" fill="#3a3767"/></svg>';
}

/**
 * @return array<string,mixed>
 */
function headerLogoUploadFile(string $contents, string $name): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'lanpa-upload-');
    if ($tmp === false) {
        throw new RuntimeException('Temporäre Datei konnte nicht erstellt werden.');
    }
    file_put_contents($tmp, $contents);

    return [
        'name' => $name,
        'tmp_name' => $tmp,
        'size' => strlen($contents),
        'error' => UPLOAD_ERR_OK,
    ];
}

function headerLogoService(SettingsService $settings, string $dir): LogoService
{
    return new LogoService(
        $settings,
        $dir,
        512 * 1024,
        true,
        'logo_complete_file',
        'logo_complete_mime',
        'logo-komplett',
        LogoService::SVG_ONLY
    );
}

/**
 * Rendert das Grundlayout, um die Darstellung oben links zu prüfen.
 *
 * @param array<string,mixed> $variables
 */
function headerLogoRenderLayout(array $variables): string
{
    $defaults = [
        'content' => '<p>Inhalt</p>',
        'themeCss' => '',
        'assetVersion' => 'test',
        'appName' => 'lanpa',
        'siteTitle' => 'lanpa',
        'siteSubtitle' => 'Intranet',
    ];

    extract(array_merge($defaults, $variables), EXTR_SKIP);
    $GLOBALS['csp_nonce'] = 'test-nonce';

    ob_start();
    require BASE_PATH . '/views/layouts/base.php';

    return (string) ob_get_clean();
}

Runner::test('Komplettes Logo wird als SVG gespeichert und getrennt vom Logo geführt', static function (): void {
    $dir = headerLogoTempDir();

    try {
        $settings = new SettingsService(new SettingsRepository(headerLogoPdo()));
        $service = headerLogoService($settings, $dir);

        $service->store(headerLogoUploadFile(headerLogoSvg(), 'komplett.svg'));

        $current = $service->current();
        Assert::true($current !== null, 'Das komplette Logo sollte hinterlegt sein.');
        if ($current === null) {
            return;
        }

        Assert::same('image/svg+xml', $current['mime']);
        Assert::true(
            preg_match('/^logo-komplett-[a-f0-9]{16}\.svg$/', basename($current['path'])) === 1,
            'Der Dateiname muss dem Muster logo-komplett-<hex>.svg folgen.'
        );
        Assert::same(headerLogoSvg(), (string) file_get_contents($current['path']));

        // Das Logo für die E-Mail-Signaturen bleibt unberührt.
        Assert::same('', $settings->get('logo_file'));
        Assert::same('', $settings->get('logo_mime'));
        Assert::same('image/svg+xml', $settings->get('logo_complete_mime'));
    } finally {
        cleanupHeaderLogoDir($dir);
    }
});

Runner::test('Komplettes Logo akzeptiert ausschließlich SVG', static function (): void {
    $dir = headerLogoTempDir();

    try {
        $settings = new SettingsService(new SettingsRepository(headerLogoPdo()));
        $service = headerLogoService($settings, $dir);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        $thrown = false;

        try {
            $service->store(headerLogoUploadFile((string) $png, 'logo.png'));
        } catch (ValidationException $exception) {
            $thrown = true;
            Assert::contains('SVG', implode(' ', $exception->errors()));
        }

        Assert::true($thrown, 'Ein PNG-Upload muss für das komplette Logo abgelehnt werden.');
        Assert::null($service->current());
    } finally {
        cleanupHeaderLogoDir($dir);
    }
});

Runner::test('Komplettes Logo entfernt Datei und Einstellungen', static function (): void {
    $dir = headerLogoTempDir();

    try {
        $settings = new SettingsService(new SettingsRepository(headerLogoPdo()));
        $service = headerLogoService($settings, $dir);

        $service->store(headerLogoUploadFile(headerLogoSvg(), 'komplett.svg'));
        $current = $service->current();
        Assert::true($current !== null, 'Das komplette Logo sollte hinterlegt sein.');

        $service->remove();

        Assert::null($service->current());
        Assert::same('', $settings->get('logo_complete_file'));
        Assert::same('', $settings->get('logo_complete_mime'));
        if ($current !== null) {
            Assert::false(is_file($current['path']), 'Die Datei sollte gelöscht sein.');
        }
    } finally {
        cleanupHeaderLogoDir($dir);
    }
});

Runner::test('Kopfzeilen-Darstellung wird geprüft und fällt auf Logo mit Text zurück', static function (): void {
    $settings = new SettingsService(new SettingsRepository(headerLogoPdo()));

    Assert::same('logo_text', $settings->headerLogoMode());

    $settings->update(['header_logo_mode' => 'complete_svg']);
    Assert::same('complete_svg', $settings->headerLogoMode());

    $settings->update(['header_logo_mode' => 'unbekannt']);
    Assert::same('logo_text', $settings->headerLogoMode());
});

Runner::test('Kopfbereich zeigt bei Auswahl das komplette SVG ohne Seitentitel', static function (): void {
    $html = headerLogoRenderLayout([
        'headerLogoMode' => 'complete_svg',
        'hasCompleteLogo' => true,
        'hasLogo' => true,
    ]);

    Assert::contains('brand__logo--complete', $html);
    Assert::contains('src="/logo-komplett"', $html);
    Assert::contains('alt="lanpa"', $html);
    Assert::contains('site-header--complete', $html);
    Assert::false(str_contains($html, 'brand__title'), 'Der zusätzliche Seitentitel darf nicht erscheinen.');
    Assert::false(str_contains($html, 'src="/logo"'), 'Das einfache Logo darf nicht erscheinen.');
});

Runner::test('Kopfbereich bleibt ohne komplettes SVG bei Logo und Text', static function (): void {
    $html = headerLogoRenderLayout([
        'headerLogoMode' => 'complete_svg',
        'hasCompleteLogo' => false,
        'hasLogo' => true,
    ]);

    Assert::contains('src="/logo"', $html);
    Assert::contains('brand__title', $html);
    Assert::contains('brand__subtitle', $html);
    Assert::false(str_contains($html, 'logo-komplett'), 'Ohne Datei darf nicht auf das komplette Logo verwiesen werden.');
    Assert::false(str_contains($html, 'site-header--complete'), 'Ohne Grafik bleibt der Kopfbereich unverändert.');
});

Runner::test('Kopfbereich zeigt in der Standarddarstellung Logo und Seitentitel', static function (): void {
    $html = headerLogoRenderLayout([
        'hasCompleteLogo' => true,
        'hasLogo' => true,
    ]);

    Assert::contains('src="/logo"', $html);
    Assert::contains('brand__title', $html);
    Assert::false(str_contains($html, 'brand__logo--complete'), 'Das komplette Logo darf nur bei Auswahl erscheinen.');
    Assert::false(str_contains($html, 'site-header--complete'), 'Der helle Untergrund darf nur mit Grafik erscheinen.');
});

Runner::test('Kopfbereich erhaelt im dunklen Farbschema einen hellen Untergrund unter dem kompletten Logo', static function (): void {
    $css = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');

    Assert::contains('.site-header--complete::before', $css);
    Assert::contains('--header-complete-light', $css);
    Assert::contains('background: var(--header-complete-fade, transparent);', $css);

    // Der Verlauf wird ausschliesslich fuer das dunkle Schema aktiviert (Attribut und Systemvorgabe).
    Assert::same(
        2,
        substr_count($css, '--header-complete-fade: var(--header-complete-gradient);'),
        'Der helle Untergrund muss genau fuer beide Dunkelmodus-Varianten gesetzt sein.'
    );
    Assert::contains(':root[data-theme="dark"]', $css);
    Assert::contains(':root:not([data-theme="light"])', $css);
});
