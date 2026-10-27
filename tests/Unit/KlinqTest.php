<?php

declare(strict_types=1);

use App\Controllers\KlinqController;
use App\Core\Request;
use App\Core\View;
use App\Repositories\SettingsRepository;
use App\Services\Klinq\KlinqAiService;
use App\Services\Klinq\KlinqConfigService;
use App\Services\Klinq\KlinqThemeService;
use App\Services\Klinq\KlinqTokenService;
use App\Services\SettingsService;
use App\Services\ThemeService;
use App\Support\Color;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * KLINQ-Integration: Einstiegsschein, Rechteauflösung, Designfarben, KI-Prüfung.
 *
 * Der Einstiegsschein ist die Vertrauensgrenze zwischen Intranet und KLINQ;
 * diese Tests halten Signatur, Aussteller, Ablauf und Zielpfade fest.
 */
function klinqPdo(array $settings = []): PDO
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

    return $pdo;
}

function klinqSettings(array $settings = []): SettingsService
{
    return new SettingsService(new SettingsRepository(klinqPdo($settings)));
}

function klinqConfig(array $settings = [], array $config = []): KlinqConfigService
{
    return new KlinqConfigService(klinqSettings($settings), $config + [
        'enabled' => true,
        'sso_secret' => str_repeat('s', 40),
        'public_path' => '/klinq',
        'public_url' => '',
        'entry_lifetime' => 300,
    ]);
}

function klinqToken(string $secret = ''): KlinqTokenService
{
    return new KlinqTokenService(klinqConfig([], ['sso_secret' => $secret !== '' ? $secret : str_repeat('s', 40)]));
}

Runner::test('KLINQ: Einstiegsschein wird ausgestellt und wieder geprüft', static function (): void {
    $tokens = klinqToken();
    $token = $tokens->issue(['sub' => 'erika.muster', 'role' => 'helpdesk_agent', 'areas' => ['helpdesk']]);

    $claims = $tokens->verify($token);

    Assert::true($claims !== null);
    Assert::same('erika.muster', $claims['sub'] ?? null);
    Assert::same('helpdesk_agent', $claims['role'] ?? null);
    Assert::same('lanpa', $claims['iss'] ?? null);
    Assert::same('klinq', $claims['aud'] ?? null);
    Assert::true(($claims['exp'] ?? 0) > time());
    Assert::true(strlen((string) ($claims['jti'] ?? '')) === 32);
});

Runner::test('KLINQ: manipulierter Schein wird abgelehnt', static function (): void {
    $tokens = klinqToken();
    $token = $tokens->issue(['sub' => 'erika.muster']);
    [$body, $signature] = explode('.', $token);

    $claims = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
    $claims['role'] = 'admin';
    $forged = KlinqTokenService::base64Url((string) json_encode($claims));

    Assert::same(null, $tokens->verify($forged . '.' . $signature));
    Assert::same(null, $tokens->verify($token . 'x'));
    Assert::same(null, $tokens->verify(''));
});

Runner::test('KLINQ: fremdes Geheimnis und abgelaufener Schein werden abgelehnt', static function (): void {
    $foreign = klinqToken(str_repeat('f', 40))->issue(['sub' => 'erika.muster']);
    Assert::same(null, klinqToken()->verify($foreign));

    $secret = str_repeat('s', 40);
    $body = KlinqTokenService::base64Url((string) json_encode([
        'sub' => 'erika.muster',
        'iss' => 'lanpa',
        'aud' => 'klinq',
        'exp' => time() - 10,
    ]));
    $signature = KlinqTokenService::base64Url(hash_hmac('sha256', $body, $secret, true));

    Assert::same(null, klinqToken()->verify($body . '.' . $signature));
});

Runner::test('KLINQ: ohne ausreichend langes Geheimnis wird kein Schein geprüft', static function (): void {
    $short = new KlinqConfigService(klinqSettings(), ['enabled' => true, 'sso_secret' => 'zu-kurz']);
    $tokens = new KlinqTokenService($short);

    Assert::false($tokens->isConfigured());
    Assert::same(null, $tokens->verify($tokens->issue(['sub' => 'erika.muster'])));
});

Runner::test('KLINQ: höchste AD-Gruppe bestimmt die Rolle', static function (): void {
    $config = klinqConfig([
        'klinq_helpdesk_agent_groups' => 'GG-Helpdesk',
        'klinq_helpdesk_lead_groups' => 'GG-Helpdesk-Leitung',
        'klinq_asset_groups' => 'GG-Assets',
    ]);

    $access = $config->resolve(['gg-assets', 'GG-HELPDESK']);

    Assert::same('helpdesk_agent', $access['role']);
    Assert::same(['helpdesk', 'assets'], $access['areas']);
    Assert::true($access['elevated']);
    Assert::same(['klinq_helpdesk_agent_groups', 'klinq_asset_groups'], $access['matched']);
});

Runner::test('KLINQ: ohne Treffer gilt die Vorgaberolle', static function (): void {
    $config = klinqConfig(['klinq_helpdesk_agent_groups' => 'GG-Helpdesk']);

    $access = $config->resolve(['gg-irgendwas']);

    Assert::same('readonly', $access['role']);
    Assert::same([], $access['areas']);
    Assert::false($access['elevated']);
});

Runner::test('KLINQ: Statistiken und Ticketaufruf sind unabhängig von der Rolle', static function (): void {
    $config = klinqConfig([
        'klinq_statistics_groups' => 'GG-Statistik',
        'klinq_ticket_history_groups' => 'GG-Ticketverlauf',
    ]);

    $access = $config->resolve(['gg-statistik', 'gg-ticketverlauf']);

    Assert::same('readonly', $access['role']);
    Assert::same(['statistik', 'ticketaufruf'], $access['permission_groups']);
    Assert::same(['statistics', 'tickets'], $access['areas']);
    Assert::true($access['elevated']);
});

Runner::test('KLINQ: Löschen und Stornieren sind eigene Berechtigungsgruppen', static function (): void {
    $config = klinqConfig([
        'klinq_helpdesk_agent_groups' => 'GG-Helpdesk',
        'klinq_ticket_delete_groups' => 'GG-Ticket-Loeschen',
        'klinq_ticket_cancel_groups' => 'GG-Ticket-Stornieren',
    ]);

    $delete = $config->resolve(['gg-helpdesk', 'GG-Ticket-Loeschen']);
    Assert::same('helpdesk_agent', $delete['role']);
    Assert::same(['ticket_loeschen'], $delete['permission_groups']);
    Assert::same(['helpdesk'], $delete['areas']);

    $both = $config->resolve(['gg-ticket-loeschen', 'gg-ticket-stornieren']);
    Assert::same('readonly', $both['role']);
    Assert::same(['ticket_loeschen', 'ticket_stornieren'], $both['permission_groups']);

    $agent = $config->resolve(['gg-helpdesk']);
    Assert::same([], $agent['permission_groups'], 'Der Agent erhält keine der beiden Freigaben automatisch');
});

Runner::test('KLINQ: Administration umfasst alle Bereiche', static function (): void {
    $access = klinqConfig(['klinq_admin_groups' => 'GG-Intranet-Admin'])->resolve(['gg-intranet-admin']);

    Assert::same('admin', $access['role']);
    Assert::same(['admin', 'helpdesk', 'assets', 'statistics', 'tickets'], $access['areas']);
});

Runner::test('KLINQ: Gruppen werden aus Textfeldern gelesen', static function (): void {
    Assert::same(['GG-A', 'GG-B', 'GG-C'], KlinqConfigService::parseGroups("GG-A, GG-B;\nGG-C"));
    Assert::same(['GG-A'], KlinqConfigService::parseGroups('  GG-A  '));
    Assert::same([], KlinqConfigService::parseGroups(''));
    Assert::same(['GG-A', 'GG-B'], KlinqConfigService::parseGroups(['GG-A', 'GG-B', 42]));
});

Runner::test('KLINQ: Freigabeformular wird geprüft', static function (): void {
    $config = klinqConfig();
    $result = $config->validate([
        'klinq_helpdesk_agent_groups' => 'GG-A, GG-B',
        'klinq_default_role' => 'unbekannt',
        'klinq_quick_report_open' => '',
    ]);

    Assert::same('GG-A, GG-B', $result['values']['klinq_helpdesk_agent_groups']);
    Assert::same('readonly', $result['values']['klinq_default_role']);
    Assert::same('0', $result['values']['klinq_quick_report_open']);
    Assert::true(isset($result['errors']['klinq_default_role']));

    $control = $config->validate(['klinq_default_role' => 'readonly', 'klinq_quick_report_open' => '1']);
    Assert::same('1', $control['values']['klinq_quick_report_open']);
    Assert::same([], $control['errors']);
});

Runner::test('KLINQ: öffentlicher Pfad wird normalisiert', static function (): void {
    Assert::same('/klinq', KlinqConfigService::normalizePath(''));
    Assert::same('/klinq', KlinqConfigService::normalizePath('/'));
    Assert::same('/klinq', KlinqConfigService::normalizePath('/klinq/'));
    Assert::same('/helpdesk', KlinqConfigService::normalizePath('helpdesk'));
    Assert::same('/klinq', KlinqConfigService::normalizePath('/a/../b'));
    Assert::same('/klinq', KlinqConfigService::normalizePath('/a b'));
});

Runner::test('KLINQ: Meldeseite erscheint nur bei aktiver Integration als Navigationsziel', static function (): void {
    Assert::same(['/stoerung' => 'KLINQ-Meldeseite (Störmeldung IT)'], klinqConfig()->internalTargets());

    $inactive = new KlinqConfigService(klinqSettings(), ['enabled' => false]);
    Assert::same([], $inactive->internalTargets());
});

Runner::test('KLINQ: Rücksprungziel bleibt in der Anwendung', static function (): void {
    Assert::same('/dashboard', KlinqController::safeTarget(''));
    Assert::same('/tickets/42', KlinqController::safeTarget('/tickets/42'));
    Assert::same('/dashboard', KlinqController::safeTarget('//example.invalid'));
    Assert::same('/dashboard', KlinqController::safeTarget('https://example.invalid'));
    Assert::same('/dashboard', KlinqController::safeTarget('/tickets/../../admin'));
    Assert::same('/dashboard', KlinqController::safeTarget('/sso/lanpa'));
    Assert::same('/dashboard', KlinqController::safeTarget('/login'));
    Assert::same('/dashboard', KlinqController::safeTarget('/' . str_repeat('a', 600)));
});

Runner::test('KLINQ: Einstiegsadresse behaelt Schema und Port des Aufrufs', static function (): void {
    // KLINQ_PUBLIC_URL mit "http://" darf den Aufruf nicht auf den TLS-Port
    // ohne TLS schicken: bei gleichem Host bleibt die Adresse relativ.
    $sameOrigin = klinqConfig([], ['public_url' => 'http://intranet.example:8443/klinq']);
    Assert::same('/klinq/sso/lanpa', $sameOrigin->entryUrlFor('intranet.example:8443'));
    Assert::same('/klinq/sso/lanpa', $sameOrigin->entryUrlFor('INTRANET.example'));

    // Anderer Host: die konfigurierte Adresse bleibt unveraendert.
    $ownHost = klinqConfig([], ['public_url' => 'https://klinq.example/klinq']);
    Assert::same('https://klinq.example/klinq/sso/lanpa', $ownHost->entryUrlFor('intranet.example:8443'));

    // Ohne KLINQ_PUBLIC_URL gilt der Unterpfad.
    Assert::same('/klinq/sso/lanpa', klinqConfig()->entryUrlFor('intranet.example'));
});

Runner::test('KLINQ: eigene Tickets sind Selbstbedienung, andere Ziele nicht', static function (): void {
    Assert::true(KlinqController::isSelfServiceTarget('/portal'));
    Assert::true(KlinqController::isSelfServiceTarget('/portal/tickets'));
    Assert::true(KlinqController::isSelfServiceTarget('/portal/tickets/4'));
    Assert::false(KlinqController::isSelfServiceTarget('/portalx'));
    Assert::false(KlinqController::isSelfServiceTarget('/dashboard'));
    Assert::false(KlinqController::isSelfServiceTarget('/helpdesk/tickets/4'));
});

Runner::test('KLINQ: Designfarben werden als CSS-Variablen ausgegeben', static function (): void {
    $css = (new KlinqThemeService(new ThemeService(klinqSettings())))->css();

    Assert::true(str_contains($css, ':root{'));
    Assert::true(str_contains($css, '--klinq-lanpa-accent-strong:'));
    Assert::true(str_contains($css, '--klinq-lanpa-accent-dark:'));
    Assert::true(str_contains($css, '--klinq-lanpa-on-accent:'));
    Assert::true(str_contains($css, 'prefers-color-scheme: dark'));
    Assert::true(str_contains($css, ':root[data-theme="light"]'));
});

Runner::test('KLINQ: Designfarben folgen der Designwahl des Intranets', static function (): void {
    $service = new KlinqThemeService(new ThemeService(klinqSettings()));

    $light = $service->css('light');
    Assert::true(str_contains($light, '--klinq-lanpa-background:#f4f6f8;'));
    Assert::false(str_contains($light, '#12161c'), 'heller Modus ohne dunkle Farben');
    Assert::false(str_contains($light, 'prefers-color-scheme'), 'heller Modus ohne Systemabfrage');

    $dark = $service->css('dark');
    Assert::true(str_contains($dark, '--klinq-lanpa-background:#12161c;'));
    Assert::false(str_contains($dark, 'prefers-color-scheme'), 'dunkler Modus ohne Systemabfrage');

    // Unbekannte oder fehlende Angaben gelten als helles Standarddesign.
    Assert::true(str_contains($service->css('auto'), 'prefers-color-scheme: dark'));
    Assert::true(str_contains($service->css('unbekannt'), '--klinq-lanpa-background:#f4f6f8;'));
    Assert::false(str_contains($service->css('unbekannt'), 'prefers-color-scheme'));
});

Runner::test('KLINQ: Statusfarben bleiben im dunklen Modus lesbar', static function (): void {
    $service = new KlinqThemeService(new ThemeService(klinqSettings()));

    // Im hellen Modus bleiben die KLINQ-Standardfarben unverändert.
    $light = $service->css('light');
    Assert::true(str_contains($light, '--klinq-lanpa-warning:#9a6a00;'));
    Assert::true(str_contains($light, '--klinq-lanpa-warning-soft:#fdf3d7;'));
    Assert::true(str_contains($light, '--klinq-lanpa-color-scheme:light;'));

    $dark = $service->css('dark');
    Assert::true(str_contains($dark, '--klinq-lanpa-color-scheme:dark;'));

    $value = static function (string $css, string $name): string {
        Assert::same(1, preg_match('/--' . preg_quote($name, '/') . ':([^;}]+);/', $css, $match), $name . ' fehlt');

        return $match[1];
    };

    // Text auf der gedaempften Flaeche (interne Notiz, Hinweise, Badges).
    Assert::true(
        Color::contrastRatio($value($dark, 'klinq-lanpa-warning-soft'), $value($dark, 'klinq-lanpa-text')) >= Color::MIN_CONTRAST,
        'Notiztext auf gedaempfter Flaeche ist zu kontrastarm'
    );

    foreach (['success', 'warning', 'danger', 'neutral'] as $name) {
        $soft = $value($dark, 'klinq-lanpa-' . $name . '-soft');
        $status = $value($dark, 'klinq-lanpa-' . $name);

        Assert::false($soft === $value($light, 'klinq-lanpa-' . $name . '-soft'), $name . ': Flaeche bleibt hell');
        Assert::true(
            Color::contrastRatio($soft, $status) >= Color::MIN_CONTRAST,
            $name . ': Statusfarbe hebt sich nicht von der Flaeche ab'
        );
        Assert::true(
            Color::contrastRatio($status, $value($dark, 'klinq-lanpa-on-' . $name)) >= Color::MIN_CONTRAST,
            $name . ': Schriftfarbe auf der Vollflaeche ist zu kontrastarm'
        );
    }
});

Runner::test('KLINQ: Designwahl wird aus dem Cookie gelesen', static function (): void {
    $request = new Request('GET', '/klinq-design.css', [], [], [], [], [
        KlinqController::THEME_COOKIE => 'dark',
    ]);

    Assert::same('dark', $request->cookie(KlinqController::THEME_COOKIE));
    Assert::same(null, $request->cookie('fehlt'));
});

Runner::test('KLINQ: KI-Einstellungen werden geprüft', static function (): void {
    $missing = KlinqAiService::validate(['klinq_ai_enabled' => '1']);
    Assert::true(isset($missing['errors']['klinq_ai_url']));
    Assert::true(isset($missing['errors']['klinq_ai_model']));

    $invalid = KlinqAiService::validate([
        'klinq_ai_enabled' => '1',
        'klinq_ai_url' => 'http://user:secret@ki.invalid/v1?x=1',
        'klinq_ai_model' => 'modell mit leerzeichen',
        'klinq_ai_timeout' => '2',
    ]);
    Assert::true(isset($invalid['errors']['klinq_ai_url']));
    Assert::true(isset($invalid['errors']['klinq_ai_model']));
    Assert::true(isset($invalid['errors']['klinq_ai_timeout']));

    $valid = KlinqAiService::validate([
        'klinq_ai_enabled' => '1',
        'klinq_ai_url' => 'http://ki-server:11434/v1/',
        'klinq_ai_model' => 'llama3.1:8b',
        'klinq_ai_timeout' => '60',
        'klinq_ai_api_key' => 'geheim-123',
    ]);
    Assert::same([], $valid['errors']);
    Assert::same('http://ki-server:11434/v1', $valid['values']['klinq_ai_url']);
    Assert::same('geheim-123', $valid['values']['klinq_ai_api_key']);

    $cleared = KlinqAiService::validate([
        'klinq_ai_enabled' => '0',
        'klinq_ai_api_key' => 'geheim-123',
        'klinq_ai_api_key_clear' => '1',
    ]);
    Assert::same('', $cleared['values']['klinq_ai_api_key']);
});

Runner::test('KLINQ: Berechtigungsseite rendert das Ergebnis ohne Gruppenzuordnung', static function (): void {
    $config = klinqConfig();

    $html = View::render('admin.klinq', [
        'section' => 'access',
        'groupKeys' => KlinqConfigService::GROUP_KEYS,
        'roleLabels' => KlinqConfigService::ROLE_LABELS,
        'areas' => KlinqConfigService::AREA_LABELS,
        'groups' => $config->allGroups(),
        'values' => [],
        'errors' => [],
        'defaultRole' => $config->defaultRole(),
        'quickReportOpen' => $config->quickReportOpen(),
        'exampleAccess' => $config->resolve([]),
        'reportPath' => KlinqConfigService::REPORT_PATH,
        'pages' => ['access' => ['/admin/klinq/berechtigungen', 'klinq_access', 'KLINQ – Berechtigungen']],
        'assetVersion' => '1',
        'tile' => null,
    ]);

    Assert::contains('Ergebnis ohne Gruppenzuordnung', $html);
    Assert::contains('Nur Lesen', $html);
    Assert::contains('data-group-suggest', $html);
});

Runner::test('KLINQ: Unterpfad wird in HTML, CSS und Manifest ergaenzt', static function (): void {
    $conf = file_get_contents(dirname(__DIR__, 2) . '/klinq/docker/php/apache-vhost.conf');
    Assert::true(is_string($conf), 'apache-vhost.conf fehlt.');
    $conf = str_replace("\r\n", "\n", (string) $conf);

    $filter = '';
    foreach (explode("\n", $conf) as $line) {
        if (str_contains($line, 'AddOutputFilterByType')) { $filter = trim($line); break; }
    }
    Assert::contains('SUBSTITUTE text/html text/css application/manifest+json', $filter);
    // JavaScript darf nicht ersetzt werden: die Ersetzung greift auch in
    // Regex-Literalen (.replace(/&/g, ...), /\.(css|js)$/) und macht daraus
    // "Invalid regular expression flags"; AppUI fehlt danach komplett.
    Assert::false(stripos($filter, 'javascript') !== false, 'JavaScript darf nicht gefiltert werden.');
    // Das "n"-Flag behandelt das Muster als feste Zeichenkette. Der Unterpfad
    // wuerde dann nie ergaenzt und KLINQ ohne CSS/JS ausgeliefert.
    Assert::false(str_contains($conf, "|ni'"), 'Substitute darf kein "n"-Flag verwenden.');
    // \1 wird von mod_substitute nicht als Rueckverweis ausgewertet (literales "1").
    Assert::false(str_contains($conf, '|\1${KLINQ_BASE_PATH}/|'), 'Rueckverweis muss $1 sein.');
    Assert::contains('|$1${KLINQ_BASE_PATH}/|i\'', $conf);
    // Ohne (?!>) wuerde das schliessende "/>" selbstschliessender Tags (SVG-Sprites) ersetzt.
    Assert::contains('(?!/)(?!>)', $conf);
});

Runner::test('KLINQ: JavaScript und Service Worker ermitteln den Unterpfad selbst', static function (): void {
    $root = dirname(__DIR__, 2);

    $ui = (string) file_get_contents($root . '/klinq/public/js/ui.js');
    Assert::contains('basePath: BASE_PATH', $ui);
    Assert::contains('url: withBase', $ui);

    $sw = (string) file_get_contents($root . '/klinq/public/sw.js');
    Assert::contains('const BASE = self.location.pathname', $sw);
    Assert::contains('BASE + "/m"', $sw);

    // Der Browser laedt das Manifest ohne Anmeldedaten; hinter der Anmeldepflicht
    // des Intranets antwortet der Proxy sonst mit 401.
    $layout = (string) file_get_contents($root . '/klinq/resources/views/partials/layout.php');
    Assert::contains('rel="manifest"', $layout);
    Assert::contains('crossorigin="use-credentials"', $layout);
});
