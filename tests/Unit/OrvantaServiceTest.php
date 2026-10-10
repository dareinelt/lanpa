<?php

declare(strict_types=1);

use App\Contracts\ExchangeTransportInterface;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\IdentitySourceRepository;
use App\Repositories\OrvantaExchangeHostRepository;
use App\Repositories\OrvantaRepository;
use App\Repositories\SettingsRepository;
use App\Security\SecretBox;
use App\Services\IdentitySourceService;
use App\Services\LdapClient;
use App\Services\Office\NextcloudFilesService;
use App\Services\Orvanta\DemoExchangeTransport;
use App\Services\Orvanta\EwsXml;
use App\Services\Orvanta\MailHtmlSanitizer;
use App\Services\Orvanta\OrvantaAttachmentService;
use App\Services\Orvanta\OrvantaConfigService;
use App\Services\Orvanta\OrvantaException;
use App\Services\Orvanta\OrvantaExchangePool;
use App\Services\Orvanta\OrvantaExchangeService;
use App\Services\Orvanta\OrvantaHostHealthService;
use App\Services\Orvanta\OrvantaMailboxResolver;
use App\Services\Orvanta\OrvantaNotificationService;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Zeichnet alle EWS-Anfragen auf und liefert vorbereitete Antworten
 * (standardmaessig die Demo-Daten).
 */
final class RecordingExchangeTransport implements ExchangeTransportInterface
{
    /** @var list<array{url:string,xml:string,options:array<string,mixed>}> */
    public array $requests = [];

    /** @var array{status:int,body:string,error:?string}|null */
    public ?array $forced = null;

    /** @var array<string,array{status:int,body:string,error:?string}> Antworten je Host (Ausfall einzelner DAG-Hosts) */
    public array $byUrl = [];

    private DemoExchangeTransport $demo;

    public function __construct()
    {
        $this->demo = new DemoExchangeTransport();
    }

    public function post(string $url, string $xml, array $options): array
    {
        $this->requests[] = ['url' => $url, 'xml' => $xml, 'options' => $options];

        return $this->byUrl[$url] ?? $this->forced ?? $this->demo->post($url, $xml, $options);
    }

    public function last(): string
    {
        return $this->requests[array_key_last($this->requests)]['xml'] ?? '';
    }

    /** @return list<string> */
    public function xmls(): array
    {
        return array_column($this->requests, 'xml');
    }
}

function orvantaPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE orvanta_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE orvanta_reminders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_uid VARCHAR(100) NOT NULL,
        item_id VARCHAR(512) NOT NULL,
        item_hash CHAR(40) NOT NULL,
        subject VARCHAR(255) NOT NULL DEFAULT \'\',
        location VARCHAR(255) NOT NULL DEFAULT \'\',
        starts_at DATETIME NOT NULL,
        remind_at DATETIME NOT NULL,
        state VARCHAR(20) NOT NULL DEFAULT \'pending\',
        delivered_at DATETIME NULL,
        dismissed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (user_uid, item_hash)
    )');
    $pdo->exec('CREATE TABLE orvanta_cache_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_uid VARCHAR(100) NOT NULL,
        kind VARCHAR(20) NOT NULL DEFAULT \'attachment\',
        item_hash CHAR(40) NOT NULL,
        name VARCHAR(255) NOT NULL,
        path VARCHAR(512) NOT NULL,
        content_type VARCHAR(190) NOT NULL DEFAULT \'application/octet-stream\',
        size_bytes INTEGER NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (user_uid, item_hash)
    )');
    $pdo->exec('CREATE TABLE orvanta_ai_usage (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_uid VARCHAR(100) NOT NULL,
        kind VARCHAR(20) NOT NULL,
        model VARCHAR(100) NOT NULL DEFAULT \'\',
        input_tokens INTEGER NOT NULL DEFAULT 0,
        output_tokens INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL
    )');
    $pdo->exec('CREATE TABLE orvanta_exchange_hosts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        host VARCHAR(190) NOT NULL UNIQUE,
        ews_url VARCHAR(2048) NOT NULL DEFAULT \'\',
        is_primary INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        latency_ms INTEGER NOT NULL DEFAULT 0,
        latency_samples INTEGER NOT NULL DEFAULT 0,
        last_latency_ms INTEGER NOT NULL DEFAULT 0,
        last_session_at DATETIME NULL,
        last_check_at DATETIME NULL,
        last_ok INTEGER NOT NULL DEFAULT 1,
        last_error VARCHAR(500) NOT NULL DEFAULT \'\',
        failures INTEGER NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE orvanta_exchange_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_hash CHAR(40) NOT NULL UNIQUE,
        user_uid VARCHAR(190) NOT NULL DEFAULT \'\',
        client_ip VARCHAR(45) NOT NULL DEFAULT \'\',
        client_host VARCHAR(190) NOT NULL DEFAULT \'\',
        host VARCHAR(190) NOT NULL,
        failovers INTEGER NOT NULL DEFAULT 0,
        requests INTEGER NOT NULL DEFAULT 0,
        started_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL
    )');

    return $pdo;
}

function orvantaSecrets(): SecretBox
{
    $dir = sys_get_temp_dir() . '/orvanta-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return new SecretBox($dir . '/secrets.key');
}

/**
 * Identitaetsquellen ohne LDAP-Konfiguration (nur die Hauptquelle, id 0).
 */
function orvantaIdentitySources(): IdentitySourceService
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_key TEXT NOT NULL UNIQUE, setting_value TEXT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

    return new IdentitySourceService(new IdentitySourceRepository($pdo), new SettingsService(new SettingsRepository($pdo)), orvantaSecrets());
}

/**
 * @param array<string,string> $settings
 * @return array{config:OrvantaConfigService,repository:OrvantaRepository,pdo:PDO}
 */
function orvantaConfig(array $settings = []): array
{
    $pdo = orvantaPdo();
    $repository = new OrvantaRepository($pdo);
    $repository->saveSettings($settings + ['exchange_enabled' => '1', 'exchange_host' => 'demo']);

    return ['config' => new OrvantaConfigService($repository, orvantaSecrets()), 'repository' => $repository, 'pdo' => $pdo];
}

/**
 * @param array<string,string> $settings
 * @return array{exchange:OrvantaExchangeService,transport:RecordingExchangeTransport,config:OrvantaConfigService,repository:OrvantaRepository}
 */
function orvantaExchange(array $settings = []): array
{
    $parts = orvantaConfig($settings);
    $transport = new RecordingExchangeTransport();

    return [
        'exchange' => new OrvantaExchangeService($transport, $parts['config']),
        'transport' => $transport,
        'config' => $parts['config'],
        'repository' => $parts['repository'],
    ];
}

/**
 * Orvanta mit Lastverteilung ueber die Hosts einer Exchange-DAG: der
 * konfigurierte Server (mail01) ist als primaerer Host eingetragen, dazu die
 * weiteren Mitglieder. setSession() wechselt die simulierte PHP-Sitzung, der
 * simulierte Client des Aufrufs ist 10.20.30.40 (pc-anna.example.local).
 *
 * @param array<string,string> $settings
 * @param list<string> $extraHosts
 *
 * @return array{pool:OrvantaExchangePool,hosts:OrvantaExchangeHostRepository,config:OrvantaConfigService,exchange:OrvantaExchangeService,transport:RecordingExchangeTransport,repository:OrvantaRepository,pdo:PDO,setSession:callable(string):void}
 */
function orvantaPool(array $settings = [], array $extraHosts = ['mail02.example.local', 'mail03.example.local']): array
{
    $primary = $settings['exchange_host'] ?? 'mail01.example.local';
    $parts = orvantaConfig($settings + ['exchange_host' => $primary]);
    $hosts = new OrvantaExchangeHostRepository($parts['pdo']);
    $hosts->insert($primary, '', true, 0);
    foreach (array_values($extraHosts) as $index => $host) {
        $hosts->insert($host, '', false, $index + 1);
    }
    $session = ['key' => 'session-a'];
    $pool = new OrvantaExchangePool($hosts, $parts['config'], static function () use (&$session): string {
        return $session['key'];
    }, static fn (): array => ['ip' => '10.20.30.40', 'host' => 'pc-anna.example.local']);
    $transport = new RecordingExchangeTransport();

    return [
        'pool' => $pool,
        'hosts' => $hosts,
        'config' => $parts['config'],
        'repository' => $parts['repository'],
        'exchange' => new OrvantaExchangeService($transport, $parts['config'], null, $pool),
        'transport' => $transport,
        'pdo' => $parts['pdo'],
        'setSession' => static function (string $key) use (&$session): void {
            $session['key'] = $key;
        },
    ];
}

/**
 * Gueltiger Einstellungssatz fuer OrvantaConfigService::save() mit einem
 * Exchange-Host der DAG.
 *
 * @return array<string,string>
 */
function dagSettings(string $host): array
{
    return [
        'exchange_enabled' => '1',
        'exchange_host' => $host,
        'exchange_auth' => 'negotiate',
        'exchange_service_user' => 'FIRMA\\svc-orvanta',
        'exchange_service_password' => 'geheim',
        'exchange_identity' => 'smtp',
        'exchange_verify_tls' => '1',
        'exchange_timeout' => '20',
        'cache_quota_mb' => '250',
        'cache_folder' => 'Orvanta',
        'reminder_lead_minutes' => '15',
        'poll_interval' => '30',
        'default_folder' => 'inbox',
    ];
}

/**
 * @param array<string,string> $settings
 * @return array{attachments:OrvantaAttachmentService,probe:FakeOfficeProbe,repository:OrvantaRepository,config:OrvantaConfigService}
 */
function orvantaAttachments(array $settings = [], bool $nextcloudOk = true): array
{
    $parts = orvantaExchange($settings);
    $probe = new FakeOfficeProbe();
    $probe->responses['POST http://nextcloud/office/index.php/apps/intranet_integration/api/files'] = $nextcloudOk
        ? ['status' => 200, 'body' => json_encode(['ok' => true, 'message' => 'gespeichert']), 'error' => null]
        : ['status' => 500, 'body' => json_encode(['ok' => false, 'message' => 'kaputt']), 'error' => null];
    $probe->responses['DELETE http://nextcloud/office/index.php/apps/intranet_integration/api/files'] = ['status' => 200, 'body' => json_encode(['ok' => true]), 'error' => null];
    $office = officeConfig();
    $service = new OrvantaAttachmentService(
        $parts['repository'],
        $parts['config'],
        $office,
        new NextcloudFilesService($office, $probe),
        $parts['exchange'],
        orvantaSecrets()
    );

    return ['attachments' => $service, 'probe' => $probe, 'repository' => $parts['repository'], 'config' => $parts['config']];
}

// ----------------------------------------------------------------------
// Konfiguration
// ----------------------------------------------------------------------

Runner::test('Orvanta: Standardwerte und EWS-Adresse aus dem Hostnamen', function (): void {
    $config = orvantaConfig(['exchange_host' => 'mail.example.local'])['config'];
    Assert::same('https://mail.example.local/EWS/Exchange.asmx', $config->ewsUrl());
    Assert::true($config->isEnabled());
    Assert::false($config->isDemo());
    Assert::same(250 * 1024 * 1024, $config->cacheQuotaBytes());
    Assert::same('Orvanta', $config->cacheFolder());
    Assert::same(15, $config->reminderLeadMinutes());
    Assert::true($config->reminderHeaderEnabled());
});

Runner::test('Orvanta: explizite EWS-URL hat Vorrang und Deaktivierung greift', function (): void {
    $config = orvantaConfig(['exchange_host' => 'mail.example.local', 'exchange_ews_url' => 'https://ews.example.local/EWS/Exchange.asmx'])['config'];
    Assert::same('https://ews.example.local/EWS/Exchange.asmx', $config->ewsUrl());

    $off = orvantaConfig(['exchange_enabled' => '0'])['config'];
    Assert::false($off->isEnabled());
    Assert::same('', orvantaConfig(['exchange_host' => ''])['config']->ewsUrl());
});

Runner::test('Orvanta: Validierung der Admin-Einstellungen', function (): void {
    $config = orvantaConfig()['config'];
    try {
        $config->save([
            'exchange_enabled' => '1',
            'exchange_host' => 'mail.example.local',
            'exchange_ews_url' => 'ftp://falsch',
            'exchange_timeout' => '999',
            'cache_quota_mb' => '-1',
            'mailbox_quota_mb' => '-5',
            'cache_folder' => 'Orv/anta',
            'reminder_lead_minutes' => '5000',
            'poll_interval' => '1',
        ]);
        Assert::true(false, 'ValidationException erwartet.');
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
        foreach (['exchange_ews_url', 'exchange_timeout', 'cache_quota_mb', 'mailbox_quota_mb', 'cache_folder', 'reminder_lead_minutes', 'poll_interval'] as $field) {
            Assert::true(isset($errors[$field]), 'Fehler erwartet für ' . $field);
        }
    }
});

Runner::test('Orvanta: Einstellungen werden gespeichert, Passwort verschluesselt', function (): void {
    $parts = orvantaConfig();
    $parts['config']->save([
        'exchange_enabled' => '1',
        'exchange_host' => 'mail.example.local',
        'exchange_auth' => 'ntlm',
        'exchange_service_user' => 'svc-intranet',
        'exchange_service_password' => 'geheim',
        'exchange_identity' => 'upn',
        'exchange_upn_domain' => 'example.local',
        'exchange_verify_tls' => '1',
        'exchange_timeout' => '30',
        'cache_quota_mb' => '100',
        'cache_folder' => 'Orvanta',
        'reminder_lead_minutes' => '10',
        'poll_interval' => '45',
        'default_folder' => 'calendar',
    ]);
    $stored = $parts['repository']->settings();
    Assert::same('ntlm', $stored['exchange_auth']);
    Assert::true($stored['exchange_service_password'] !== 'geheim', 'Passwort darf nicht im Klartext stehen.');

    $config = $parts['config'];
    Assert::same('geheim', $config->servicePassword(), 'Passwort wird entschluesselt.');
    Assert::same(100 * 1024 * 1024, $config->cacheQuotaBytes());
    Assert::same('0', $config->get('reminder_header'));
    Assert::false($config->reminderHeaderEnabled());
    Assert::same('0', $config->get('flow_ai_user_names'), 'Standard ist die pseudonyme Anzeige');
    Assert::same('calendar', $config->get('default_folder'));
    Assert::same(45, $config->pollInterval());
    Assert::same('jdoe@example.local', $config->impersonationAddress(['username' => 'jdoe', 'email' => 'john@mail.example']));
    Assert::same('john@mail.example', orvantaConfig()['config']->impersonationAddress(['username' => 'jdoe', 'email' => 'john@mail.example']));
});

Runner::test('Orvanta: Namensanzeige der KI-Nutzer im Nachrichtenfluss ist zuschaltbar', function (): void {
    $config = orvantaConfig()['config'];
    $base = [
        'exchange_enabled' => '0',
        'exchange_host' => 'mail.example.local',
        'exchange_auth' => 'negotiate',
        'exchange_identity' => 'smtp',
        'exchange_verify_tls' => '1',
        'exchange_timeout' => '20',
        'cache_quota_mb' => '250',
        'cache_folder' => 'Orvanta',
        'reminder_lead_minutes' => '15',
        'poll_interval' => '60',
        'default_folder' => 'inbox',
    ];

    $config->save($base + ['flow_ai_user_names' => '1']);
    Assert::same('1', $config->get('flow_ai_user_names'));

    $config->save($base);
    Assert::same('0', $config->get('flow_ai_user_names'), 'Ohne Häkchen bleibt die Anzeige pseudonym.');
});

// ----------------------------------------------------------------------
// Exchange-Dienst (EWS)
// ----------------------------------------------------------------------

Runner::test('Orvanta: EWS-Anfragen tragen Impersonation und Version', function (): void {
    $parts = orvantaExchange(['exchange_host' => 'mail.example.local', 'exchange_version' => 'Exchange2016']);
    $folders = $parts['exchange']->folders('anna@example.local');
    Assert::true(count($folders) >= 5, 'Standardordner erwartet.');
    $kinds = array_map(static fn (array $f): string => (string) $f['kind'], $folders);
    Assert::true(in_array('inbox', $kinds, true));
    Assert::contains('<t:ExchangeImpersonation>', $parts['transport']->last());
    Assert::contains('anna@example.local', $parts['transport']->last());
    Assert::contains('Version="Exchange2016"', $parts['transport']->last());
    Assert::same('https://mail.example.local/EWS/Exchange.asmx', $parts['transport']->requests[0]['url']);
});

Runner::test('Orvanta: Nachrichtenliste und Nachricht werden gelesen', function (): void {
    $parts = orvantaExchange();
    $list = $parts['exchange']->messages('demo@demo.local', 'inbox', 0, 25);
    Assert::true(count($list['items']) > 0, 'Demo-Posteingang ist nicht leer.');
    $first = $list['items'][0];
    Assert::true(isset($first['id'], $first['subject'], $first['from']));
    Assert::contains('item:IconIndex', $parts['transport']->last());
    Assert::contains('PropertyTag="0x1081"', $parts['transport']->last());

    $byId = [];
    foreach ($list['items'] as $item) {
        $byId[(string) $item['id']] = $item;
    }
    Assert::false($byId['demo-msg-1']['replied'], 'Unbeantwortete Nachricht bleibt ohne Indikator.');
    Assert::false($byId['demo-msg-1']['forwarded']);
    Assert::true($byId['demo-msg-3']['replied'], 'Antwort wird ueber PR_LAST_VERB_EXECUTED erkannt.');
    Assert::true($byId['demo-msg-4']['replied'], 'Antwort wird ueber den Symbolindex erkannt.');
    Assert::true($byId['demo-msg-4']['forwarded'], 'Weiterleitung wird ueber PR_LAST_VERB_EXECUTED erkannt.');
    Assert::true($byId['demo-msg-6']['forwarded'], 'Weiterleitung wird ueber den Symbolindex erkannt.');

    $message = $parts['exchange']->message('demo@demo.local', (string) $first['id']);
    Assert::same((string) $first['id'], (string) $message['id']);
    Assert::true(is_string($message['body_html']));
    Assert::false(str_contains($message['body_html'], '<script'), 'Skripte werden entfernt.');

    $detail = $parts['exchange']->message('demo@demo.local', 'demo-msg-4');
    Assert::true($detail['replied'] && $detail['forwarded'], 'Einzelansicht liefert die Indikatoren ebenfalls.');
});

Runner::test('Orvanta: Info liefert rohe Kopfzeilen aus dem MIME-Inhalt', function (): void {
    $parts = orvantaExchange();
    $info = $parts['exchange']->messageHeaders('demo@demo.local', 'demo-msg-1');
    Assert::same('demo-msg-1', $info['id']);
    Assert::same('mime', $info['source']);
    Assert::contains('item:MimeContent', $parts['transport']->last());
    Assert::true(str_starts_with($info['headers'], 'Received: '), 'Kopf beginnt mit Received.');
    Assert::contains("\r\nMessage-ID: <demo-msg-1@", $info['headers']);
    Assert::false(str_contains($info['headers'], '<div'), 'Nachrichtentext ist nicht enthalten.');
});

Runner::test('Orvanta: Ordnereigenschaften liefern Anzahl und Groesse inkl. Unterordner', function (): void {
    $parts = orvantaExchange();
    $info = $parts['exchange']->folderProperties('demo@demo.local', 'inbox');
    $xmls = $parts['transport']->xmls();
    Assert::contains('<m:GetFolder>', $xmls[0]);
    Assert::contains('<t:DistinguishedFolderId Id="inbox"/>', $xmls[0]);
    Assert::contains('PropertyTag="0x0E08"', $xmls[0]);
    Assert::contains('<m:FindFolder Traversal="Deep">', $xmls[1]);
    Assert::contains('<m:ParentFolderIds><t:DistinguishedFolderId Id="inbox"/></m:ParentFolderIds>', $xmls[1]);
    Assert::same('Posteingang', $info['name']);
    Assert::same(8, $info['total']);
    Assert::same(3, $info['unread']);
    Assert::same(912261120, $info['size']);
    Assert::same(1, $info['subfolders']);
    Assert::same(8 + 17, $info['total_with_subfolders']);
    Assert::same(912261120 + 48113254, $info['size_with_subfolders']);

    $plain = $parts['exchange']->folderProperties('demo@demo.local', 'demo-rechnungen');
    Assert::same(0, $plain['subfolders']);
    Assert::same($plain['size'], $plain['size_with_subfolders']);

    try {
        $parts['exchange']->folderProperties('demo@demo.local', 'demo-unbekannt');
        Assert::true(false, 'Unbekannter Ordner muss fehlschlagen.');
    } catch (OrvantaException $exception) {
        Assert::contains('nicht gefunden', $exception->getMessage());
    }
});

Runner::test('Orvanta: Neuer Ordner und alle als gelesen markieren', function (): void {
    $parts = orvantaExchange();
    $created = $parts['exchange']->createFolder('demo@demo.local', 'demo-projekte', "  Q3 <Berichte>\n ");
    $xml = $parts['transport']->last();
    Assert::contains('<m:CreateFolder><m:ParentFolderId><t:FolderId Id="demo-projekte"/></m:ParentFolderId>', $xml);
    Assert::contains('<t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Q3 &lt;Berichte&gt;</t:DisplayName>', $xml);
    Assert::same('Q3 <Berichte>', $created['name']);
    Assert::true(str_starts_with($created['id'], 'demo-folder-'), 'Id des neuen Ordners.');

    $parts['exchange']->createFolder('demo@demo.local', '', 'Oben');
    Assert::contains('<t:DistinguishedFolderId Id="msgfolderroot"/>', $parts['transport']->last());

    try {
        $parts['exchange']->createFolder('demo@demo.local', 'inbox', '   ');
        Assert::true(false, 'Leerer Name muss abgelehnt werden.');
    } catch (OrvantaException $exception) {
        Assert::same(422, $exception->status());
    }

    $parts['exchange']->markFolderRead('demo@demo.local', 'inbox');
    Assert::contains('<m:MarkAllItemsAsRead><m:ReadFlag>true</m:ReadFlag><m:SuppressReadReceipts>true</m:SuppressReadReceipts><m:FolderIds><t:DistinguishedFolderId Id="inbox"/></m:FolderIds></m:MarkAllItemsAsRead>', $parts['transport']->last());
});

Runner::test('Orvanta: Senden erzeugt CreateItem mit Empfaengern', function (): void {
    $parts = orvantaExchange();
    $parts['exchange']->send('demo@demo.local', [
        'to' => ['max@example.local'],
        'cc' => [],
        'bcc' => [],
        'subject' => 'Testbetreff',
        'body' => '<p>Hallo</p>',
        'html' => true,
        'attachments' => [],
    ]);
    $xml = $parts['transport']->last();
    Assert::contains('<m:CreateItem', $xml);
    Assert::contains('max@example.local', $xml);
    Assert::contains('Testbetreff', $xml);
    Assert::contains('SendAndSaveCopy', $xml);
});

Runner::test('Orvanta: KI-Markierungen verlassen den Editor nie - weder beim Senden noch im Termin', function (): void {
    $parts = orvantaExchange();
    $marked = '<p>Hallo <span class="ov-ai-block" data-ov-ai-id="ai7" title="Von der KI erzeugter Text">liebe <b>Welt</b></span></p>';
    $parts['exchange']->send('demo@demo.local', [
        'to' => ['max@example.local'], 'cc' => [], 'bcc' => [], 'subject' => 'KI', 'body' => $marked, 'html' => true, 'attachments' => [],
    ]);
    $xml = html_entity_decode($parts['transport']->last());
    Assert::false(str_contains($xml, 'ov-ai'), 'Marker-Klasse im gesendeten HTML: ' . $xml);
    Assert::false(str_contains($xml, 'data-ov-ai'));
    Assert::contains('Hallo liebe <b>Welt</b>', $xml);

    $parts['exchange']->saveDraft('demo@demo.local', ['to' => [], 'cc' => [], 'bcc' => [], 'subject' => 'E', 'body' => $marked, 'html' => true, 'attachments' => []]);
    Assert::false(str_contains(html_entity_decode($parts['transport']->last()), 'ov-ai'), 'Auch Entwuerfe tragen keine Marker.');

    $now = time();
    $parts['exchange']->createEvent('demo@demo.local', [
        'subject' => 'T', 'body' => $marked, 'location' => '', 'start' => $now + 3600, 'end' => $now + 7200,
        'all_day' => false, 'free_busy' => 'Busy', 'reminder_minutes' => 15, 'required' => [], 'optional' => [],
    ]);
    Assert::false(str_contains(html_entity_decode($parts['transport']->last()), 'ov-ai'), 'Termine tragen keine Marker.');
});

Runner::test('Orvanta: Fehler des Transports werden als OrvantaException gemeldet', function (): void {
    $parts = orvantaExchange(['exchange_host' => 'mail.example.local']);
    $parts['transport']->forced = ['status' => 0, 'body' => '', 'error' => 'Connection refused'];
    try {
        $parts['exchange']->folders('anna@example.local');
        Assert::true(false, 'Exception erwartet.');
    } catch (OrvantaException $exception) {
        Assert::same(502, $exception->status());
        Assert::contains('nicht erreichbar', $exception->getMessage());
    }

    $parts['transport']->forced = ['status' => 401, 'body' => '', 'error' => ''];
    try {
        $parts['exchange']->folders('anna@example.local');
        Assert::true(false, 'Exception erwartet.');
    } catch (OrvantaException $exception) {
        Assert::contains('Anmeldung abgelehnt', $exception->getMessage());
        Assert::contains('kein Dienstkonto hinterlegt', $exception->getMessage());
    }
});

Runner::test('Orvanta: 401 mit Dienstkonto nennt Konto und angebotene Verfahren', function (): void {
    $parts = orvantaConfig(['exchange_host' => 'mail.example.local']);
    $parts['config']->save([
        'exchange_enabled' => '1', 'exchange_host' => 'mail.example.local', 'exchange_auth' => 'negotiate',
        'exchange_service_user' => 'FIRMA\svc-orvanta', 'exchange_service_password' => 'geheim',
        'exchange_timeout' => '20', 'cache_quota_mb' => '250', 'cache_folder' => 'Orvanta',
        'reminder_lead_minutes' => '15', 'poll_interval' => '60',
    ]);
    $transport = new RecordingExchangeTransport();
    $exchange = new OrvantaExchangeService($transport, $parts['config']);

    $transport->forced = ['status' => 401, 'body' => '', 'error' => null, 'auth_offered' => ['Negotiate']];
    try {
        $exchange->folders('anna@example.local');
        Assert::true(false, 'Exception erwartet.');
    } catch (OrvantaException $exception) {
        Assert::contains('FIRMA\svc-orvanta', $exception->getMessage());
        Assert::contains('Der Server bietet: Negotiate.', $exception->getMessage());
        Assert::contains('NTLM ist am EWS-Verzeichnis nicht aktiviert', $exception->getMessage());
    }

    $transport->forced = ['status' => 401, 'body' => '', 'error' => null, 'auth_offered' => ['Negotiate', 'NTLM']];
    try {
        $exchange->folders('anna@example.local');
        Assert::true(false, 'Exception erwartet.');
    } catch (OrvantaException $exception) {
        Assert::contains('Kennwort und Schreibweise', $exception->getMessage());
        Assert::false(str_contains($exception->getMessage(), 'nicht aktiviert'), 'NTLM wird angeboten.');
    }

    $transport->forced = ['status' => 403, 'body' => '', 'error' => null];
    try {
        $exchange->folders('anna@example.local');
        Assert::true(false, 'Exception erwartet.');
    } catch (OrvantaException $exception) {
        Assert::contains('HTTP 403', $exception->getMessage());
        Assert::contains('darf EWS aber nicht verwenden', $exception->getMessage());
    }
});

Runner::test('Orvanta: Aktivierung ohne Dienstkonto wird abgelehnt (ausser Demo)', function (): void {
    $config = orvantaConfig()['config'];
    $base = [
        'exchange_enabled' => '1', 'exchange_host' => 'mail.example.local', 'exchange_auth' => 'negotiate',
        'exchange_timeout' => '20', 'cache_quota_mb' => '250', 'cache_folder' => 'Orvanta',
        'reminder_lead_minutes' => '15', 'poll_interval' => '60',
    ];
    try {
        $config->save($base);
        Assert::true(false, 'ValidationException erwartet.');
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['exchange_service_user']));
        Assert::true(isset($exception->errors()['exchange_service_password']));
    }

    $config->save($base + ['exchange_service_user' => 'svc', 'exchange_service_password' => 'geheim']);
    // Gespeichertes Kennwort bleibt bei leerem Feld erhalten und genuegt.
    $config->save($base + ['exchange_service_user' => 'svc']);
    Assert::same('geheim', $config->servicePassword());
    try {
        $config->save($base + ['exchange_service_user' => 'svc', 'exchange_service_password_clear' => '1']);
        Assert::true(false, 'ValidationException erwartet.');
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['exchange_service_password']));
    }

    $config->save(['exchange_host' => 'demo'] + $base);
});

Runner::test('Orvanta: Alias-Adresse wird auf die primaere SMTP-Adresse umgestellt', function (): void {
    $fault = '<?xml version="1.0" encoding="utf-8"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault>'
        . '<faultcode xmlns:a="http://schemas.microsoft.com/exchange/services/2006/types">a:ErrorNonPrimarySmtpAddress</faultcode>'
        . '<faultstring xml:lang="de-DE">Die primäre SMTP-Adresse muss angegeben werden, wenn Sie auf ein Postfach verweisen.</faultstring>'
        . '<detail><e:ResponseCode xmlns:e="http://schemas.microsoft.com/exchange/services/2006/errors">ErrorNonPrimarySmtpAddress</e:ResponseCode>'
        . '<e:MessageXml xmlns:e="http://schemas.microsoft.com/exchange/services/2006/errors"><t:Value xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types" Name="Primary">daniel.reinelt@example.local</t:Value></e:MessageXml>'
        . '</detail></s:Fault></s:Body></s:Envelope>';
    $transport = new class ($fault) implements ExchangeTransportInterface {
        /** @var list<string> */
        public array $xmls = [];
        private DemoExchangeTransport $demo;

        public function __construct(private readonly string $fault)
        {
            $this->demo = new DemoExchangeTransport();
        }

        public function post(string $url, string $xml, array $options): array
        {
            $this->xmls[] = $xml;

            $lower = strtolower($xml);

            return str_contains($lower, 'reinelt@example.local</t:primarysmtpaddress>') && !str_contains($lower, 'daniel.reinelt@')
                ? ['status' => 500, 'body' => $this->fault, 'error' => null]
                : $this->demo->post($url, $xml, $options);
        }
    };
    $cache = sys_get_temp_dir() . '/orvanta-primary-' . bin2hex(random_bytes(4)) . '/map.json';
    $config = orvantaConfig(['exchange_host' => 'mail.example.local'])['config'];

    $exchange = new OrvantaExchangeService($transport, $config, $cache);
    Assert::true($exchange->testConnection('Reinelt@example.local')['ok'], 'Erfolg nach Wiederholung erwartet.');
    Assert::same(2, count($transport->xmls), 'Genau eine Wiederholung.');
    Assert::contains('<t:PrimarySmtpAddress>daniel.reinelt@example.local</t:PrimarySmtpAddress>', $transport->xmls[1]);

    // Gelernte Zuordnung gilt fuer weitere Aufrufe und ueber die Instanz hinaus.
    $exchange->testConnection('reinelt@example.local');
    Assert::same(3, count($transport->xmls));
    $again = new OrvantaExchangeService($transport, $config, $cache);
    $again->testConnection('reinelt@example.local');
    Assert::same(4, count($transport->xmls));
    Assert::contains('daniel.reinelt@example.local', $transport->xmls[3]);

    // Ohne genannte primaere Adresse: verstaendliche Meldung statt Endlosschleife.
    $plain = new RecordingExchangeTransport();
    $plain->forced = ['status' => 500, 'error' => null, 'body' => str_replace('<t:Value xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types" Name="Primary">daniel.reinelt@example.local</t:Value>', '', $fault)];
    try {
        (new OrvantaExchangeService($plain, $config))->testConnection('reinelt@example.local');
        Assert::true(false, 'Exception erwartet.');
    } catch (OrvantaException $exception) {
        Assert::contains('nicht die primäre SMTP-Adresse', $exception->getMessage());
        Assert::same(1, count($plain->requests));
    }
});

Runner::test('Orvanta: Ohne konfigurierten Server wird 503 gemeldet', function (): void {
    $parts = orvantaExchange(['exchange_host' => '', 'exchange_ews_url' => '']);
    try {
        $parts['exchange']->folders('anna@example.local');
        Assert::true(false, 'Exception erwartet.');
    } catch (OrvantaException $exception) {
        Assert::same(503, $exception->status());
    }
});

Runner::test('Orvanta: Kalender, Kontakte, Aufgaben und Notizen liefern Elemente', function (): void {
    $parts = orvantaExchange();
    $now = time();
    $events = $parts['exchange']->calendar('demo@demo.local', $now - 86400 * 7, $now + 86400 * 30);
    Assert::true(count($events) > 0, 'Termine erwartet.');
    Assert::true(isset($events[0]['start'], $events[0]['end'], $events[0]['subject']));

    Assert::true(count($parts['exchange']->contacts('demo@demo.local')) > 0);
    Assert::true(count($parts['exchange']->tasks('demo@demo.local')) > 0);
    Assert::true(count($parts['exchange']->notes('demo@demo.local')) > 0);

    $created = $parts['exchange']->createEvent('demo@demo.local', [
        'subject' => 'Neuer Termin', 'body' => '', 'location' => 'Raum 1',
        'start' => $now + 3600, 'end' => $now + 7200, 'all_day' => false,
        'free_busy' => 'Busy', 'reminder_minutes' => 15, 'required' => [], 'optional' => [],
    ]);
    Assert::true($created['id'] !== '');
    Assert::contains('<t:CalendarItem>', $parts['transport']->last());
    Assert::contains('Raum 1', $parts['transport']->last());
});

Runner::test('Orvanta: Termin verschieben aendert nur Beginn und Ende', function (): void {
    $parts = orvantaExchange();
    $start = strtotime('2025-05-12 09:00:00') ?: 0;
    $end = strtotime('2025-05-12 10:00:00') ?: 0;
    $parts['exchange']->moveEvent('demo@demo.local', 'demo-ev-1', $start, $end, 'CK1');
    $xml = $parts['transport']->last();
    Assert::contains('<m:UpdateItem', $xml);
    Assert::contains('<t:ItemId Id="demo-ev-1" ChangeKey="CK1"/>', $xml);
    Assert::contains('<t:FieldURI FieldURI="calendar:Start"/>', $xml);
    Assert::contains('<t:FieldURI FieldURI="calendar:End"/>', $xml);
    Assert::contains('<t:Start>' . EwsXml::dateTime($start) . '</t:Start>', $xml);
    Assert::contains('<t:End>' . EwsXml::dateTime($end) . '</t:End>', $xml);
    // Nur der Zeitraum wird gesetzt; alles andere am Termin bleibt erhalten.
    Assert::false(str_contains($xml, 'item:Subject'), 'Der Betreff darf nicht ueberschrieben werden.');
    Assert::false(str_contains($xml, 'IsAllDayEvent'), 'Das Ganztagig-Kennzeichen darf nicht ueberschrieben werden.');

    foreach ([[0, $end], [$start, $start - 60]] as $range) {
        try {
            $parts['exchange']->moveEvent('demo@demo.local', 'demo-ev-1', $range[0], $range[1]);
            Assert::true(false, 'Exception fuer ungueltigen Zeitraum erwartet.');
        } catch (OrvantaException $exception) {
            Assert::same(422, $exception->status());
        }
    }
});

Runner::test('Orvanta: Besprechungsantwort akzeptiert Gross- und Kleinschreibung', function (): void {
    $parts = orvantaExchange();
    // Das Frontend sendet die EWS-Schreibweise (Accept/Tentative/Decline).
    foreach (['Accept' => 'AcceptItem', 'Tentative' => 'TentativelyAcceptItem', 'Decline' => 'DeclineItem'] as $response => $element) {
        $parts['exchange']->respondToMeeting('demo@demo.local', 'demo-ev-1', $response);
        $xml = $parts['transport']->last();
        Assert::contains('<m:CreateItem MessageDisposition="SendAndSaveCopy">', $xml);
        Assert::contains('<t:' . $element . '>', $xml);
        Assert::contains('<t:ReferenceItemId Id="demo-ev-1"', $xml);
    }
    // Die dokumentierte Kleinschreibung bleibt unveraendert gueltig.
    $parts['exchange']->respondToMeeting('demo@demo.local', 'demo-ev-1', 'accept');
    Assert::contains('<t:AcceptItem>', $parts['transport']->last());

    try {
        $parts['exchange']->respondToMeeting('demo@demo.local', 'demo-ev-1', 'vielleicht');
        Assert::true(false, 'Exception erwartet.');
    } catch (OrvantaException $exception) {
        Assert::same(422, $exception->status());
        Assert::contains('Unbekannte Antwort', $exception->getMessage());
    }
});

Runner::test('Orvanta: Postfachbelegung summiert alle Ordner (Quota in KB)', function (): void {
    $parts = orvantaExchange();
    $usage = $parts['exchange']->mailboxUsage('demo@demo.local');
    $first = $parts['transport']->requests[0]['xml'];
    Assert::contains('DistinguishedFolderId Id="root"', $first);
    Assert::contains('DistinguishedFolderId Id="recoverableitemsroot"', $first);
    Assert::contains('PropertyTag="0x0E08"', $first);
    Assert::contains('<m:FindFolder Traversal="Deep">', $parts['transport']->last());
    Assert::contains('PropertyTag="0x0E08"', $parts['transport']->last());
    // Stammordner + alle Unterordner, ohne Suchordner und Wiederherstellbare Elemente
    Assert::same(1449551462, $usage['used']);
    Assert::same(2097152 * 1024, $usage['quota']);
    Assert::same(1992294 * 1024, $usage['warning']);
    Assert::same(2411724 * 1024, $usage['receive_limit']);
    Assert::same(2097152 * 1024, $usage['limit']);
    Assert::same(67, $usage['percent']);
    Assert::same('exchange', $usage['source']);

    // Ohne Grenzen: nur Groesse, Prozent 0
    $parts['transport']->forced = ['status' => 200, 'error' => null, 'body' => '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types">'
        . '<m:GetFolderResponse><m:ResponseMessages><m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="r" ChangeKey="A"/>'
        . '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0xe08" PropertyType="Long"/><t:Value>4096</t:Value></t:ExtendedProperty></t:Folder></m:Folders></m:GetFolderResponseMessage></m:ResponseMessages></m:GetFolderResponse></s:Body></s:Envelope>'];
    $usage = $parts['exchange']->mailboxUsage('demo@demo.local');
    Assert::same(4096, $usage['used']);
    Assert::same(0, $usage['quota']);
    Assert::same(0, $usage['limit']);
    Assert::same(0, $usage['percent']);

    // Ohne Grenzen von Exchange: Postfachgroesse aus dem Adminbereich
    $parts = orvantaExchange(['mailbox_quota_mb' => '1']);
    $parts['transport']->forced = ['status' => 200, 'error' => null, 'body' => '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types">'
        . '<m:GetFolderResponse><m:ResponseMessages><m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="r" ChangeKey="A"/>'
        . '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0xe08" PropertyType="Long"/><t:Value>262144</t:Value></t:ExtendedProperty></t:Folder></m:Folders></m:GetFolderResponseMessage></m:ResponseMessages></m:GetFolderResponse></s:Body></s:Envelope>'];
    $usage = $parts['exchange']->mailboxUsage('demo@demo.local');
    Assert::same(0, $usage['quota']);
    Assert::same(1048576, $usage['limit']);
    Assert::same(25, $usage['percent']);
    Assert::same('setting', $usage['source']);

    // Grenzen aus dem AD haben Vorrang vor der Postfachgroesse aus dem Adminbereich
    $usage = $parts['exchange']->mailboxUsage('demo@demo.local', ['warning' => 1536 * 1024, 'send' => 2048 * 1024, 'receive' => 4096 * 1024]);
    Assert::same(2048 * 1024, $usage['quota']);
    Assert::same(1536 * 1024, $usage['warning']);
    Assert::same(4096 * 1024, $usage['receive_limit']);
    Assert::same(2048 * 1024, $usage['limit']);
    Assert::same(13, $usage['percent']);
    Assert::same('directory', $usage['source']);

    // AD ohne Grenzen (unbegrenzt): Postfachgroesse aus dem Adminbereich
    $usage = $parts['exchange']->mailboxUsage('demo@demo.local', ['warning' => 0, 'send' => 0, 'receive' => 0]);
    Assert::same(1048576, $usage['limit']);
    Assert::same('setting', $usage['source']);
});

Runner::test('Orvanta: Postfachgrenzen aus dem AD (Benutzer bzw. Postfachdatenbank, Werte in KB)', function (): void {
    $database = ['mdbstoragequota' => ['count' => 1, '1900000'], 'mdboverquotalimit' => ['count' => 1, '2000000'], 'mdboverhardquotalimit' => ['count' => 1, '2300000']];
    $home = ['count' => 1, 'CN=DB01,CN=Databases,CN=Exchange Administrative Group,CN=Administrative Groups,CN=Firma,CN=Microsoft Exchange,CN=Services,CN=Configuration,DC=firma,DC=local'];

    // mDBUseDefaults = TRUE: Werte der Datenbank
    $quota = LdapClient::mailboxQuotaFromEntries(['homemdb' => $home, 'mdbusedefaults' => ['count' => 1, 'TRUE'], 'mdboverquotalimit' => ['count' => 1, '5']], $database);
    Assert::same(['warning' => 1900000 * 1024, 'send' => 2000000 * 1024, 'receive' => 2300000 * 1024, 'defaults' => true], $quota);

    // mDBUseDefaults = FALSE: eigene Werte am Benutzer, fehlende = unbegrenzt
    $quota = LdapClient::mailboxQuotaFromEntries(['homemdb' => $home, 'mdbusedefaults' => ['count' => 1, 'FALSE'], 'mdboverquotalimit' => ['count' => 1, '10485760']], $database);
    Assert::same(['warning' => 0, 'send' => 10485760 * 1024, 'receive' => 0, 'defaults' => false], $quota);

    // Kein On-Premise-Postfach bzw. Datenbank nicht lesbar
    Assert::same(null, LdapClient::mailboxQuotaFromEntries(['mdbusedefaults' => ['count' => 1, 'TRUE']], $database));
    Assert::same(null, LdapClient::mailboxQuotaFromEntries(['homemdb' => $home, 'mdbusedefaults' => ['count' => 1, 'TRUE']], null));
});

Runner::test('Orvanta: primaere Postfachadresse aus dem AD (proxyAddresses)', function (): void {
    // Nur der Eintrag mit "SMTP:" ist die primaere Adresse – auch wenn er
    // hinter Aliasen ("smtp:") und fremden Praefixen steht.
    $entry = ['proxyaddresses' => ['count' => 3, 0 => 'smtp:anna.alt@firma.local', 1 => 'X400:c=DE;a=firma;', 2 => 'SMTP:anna.neu@firma.local']];
    Assert::same('anna.neu@firma.local', LdapClient::primarySmtpFromProxyAddresses($entry));

    // Einzelner Wert statt Liste (abweichende LDAP-Treiber)
    Assert::same('anna@firma.local', LdapClient::primarySmtpFromProxyAddresses(['proxyaddresses' => 'SMTP:anna@firma.local']));

    // Nur Aliase: keine Postfachkennung (genau der Fall der neuen Benutzer)
    Assert::same(null, LdapClient::primarySmtpFromProxyAddresses(['proxyaddresses' => ['count' => 1, 0 => 'smtp:anna@firma.local']]));
    // Fremde Praefixe, ungueltige Adresse, fehlendes Attribut
    Assert::same(null, LdapClient::primarySmtpFromProxyAddresses(['proxyaddresses' => ['count' => 1, 0 => 'SIP:anna@firma.local']]));
    Assert::same(null, LdapClient::primarySmtpFromProxyAddresses(['proxyaddresses' => ['count' => 1, 0 => 'SMTP:keine-adresse']]));
    Assert::same(null, LdapClient::primarySmtpFromProxyAddresses([]));
});

Runner::test('Orvanta: Postfachadresse ohne AD-Treffer bleibt die Konfiguration', function (): void {
    $parts = orvantaConfig(['exchange_host' => 'mail.example.local', 'exchange_identity' => 'upn', 'exchange_upn_domain' => 'example.local']);
    $resolver = new OrvantaMailboxResolver($parts['config'], orvantaIdentitySources());

    // Testbenutzer (kein Telefonbucheintrag) wird nicht im AD gesucht
    Assert::same('jdoe@example.local', $resolver->address(['username' => 'jdoe', 'email' => '', 'fake' => true, 'id' => 0, 'source_id' => 0]));
    // Ohne lesbare Quelle (hier unkonfiguriertes AD) gilt weiter die Konfiguration
    Assert::same('jdoe@example.local', $resolver->address(['username' => 'jdoe', 'email' => '', 'id' => 5, 'source_id' => 0]));

    // Im SMTP-Modus ist die konfigurierte Adresse die aus dem AD-Attribut "mail"
    $parts = orvantaConfig(['exchange_host' => 'mail.example.local', 'exchange_identity' => 'smtp']);
    $resolver = new OrvantaMailboxResolver($parts['config'], orvantaIdentitySources());
    Assert::same('anna@firma.local', $resolver->address(['username' => 'anna', 'email' => 'anna@firma.local', 'id' => 6, 'source_id' => 0]));
});

Runner::test('Orvanta: EwsXml-Hilfsfunktionen', function (): void {
    Assert::same('<t:DistinguishedFolderId Id="inbox"/>', EwsXml::folderId('inbox'));
    Assert::contains('<t:FolderId Id="AAMk', EwsXml::folderId('AAMkAGI2'));
    Assert::same('a&amp;b &lt;c&gt;', EwsXml::escape('a&b <c>'));
    $ts = EwsXml::timestamp('2024-05-01T10:00:00Z');
    Assert::same(gmdate('Y-m-d\TH:i:s\Z', $ts), EwsXml::dateTime($ts));
    Assert::same(0, EwsXml::timestamp(''));
});

// ----------------------------------------------------------------------
// HTML-Bereinigung
// ----------------------------------------------------------------------

Runner::test('Orvanta: Mail-HTML wird bereinigt und externe Bilder blockiert', function (): void {
    $result = MailHtmlSanitizer::clean('<html><head><style>body{}</style></head><body onload="x()">'
        . '<p style="color:red" onclick="evil()">Hallo <a href="javascript:alert(1)">Link</a> <a href="https://example.org">OK</a></p>'
        . '<img src="https://tracker.example/pixel.gif"><img src="cid:logo"><script>alert(1)</script><iframe src="//x"></iframe></body></html>');
    $html = $result['html'];
    Assert::false(str_contains($html, '<script'));
    Assert::false(str_contains($html, '<iframe'));
    Assert::false(str_contains($html, 'onclick'));
    Assert::false(str_contains($html, 'onload'));
    Assert::false(str_contains($html, 'javascript:'));
    Assert::contains('https://example.org', $html);
    Assert::contains('target="_blank"', $html);
    Assert::false(str_contains($html, ' src="https://tracker.example'), 'Externe Bildquelle wird nicht direkt geladen.');
    Assert::contains('data-blocked-src="https://tracker.example/pixel.gif"', $html, 'Externe Quelle bleibt zum Nachladen erhalten.');
    Assert::contains('data-cid="logo"', $html, 'Eingebettete Bilder werden per Content-ID markiert.');
    Assert::false(str_contains($html, ' src="cid:'), 'cid:-Quelle wird fuer den Browser entfernt.');
    Assert::true($result['blocked_images'] >= 1, 'Blockierte Bilder werden gezaehlt.');
});

Runner::test('Orvanta: Ausgehendes HTML behaelt externe Bilder und stellt cid:-Quellen wieder her', function (): void {
    $display = MailHtmlSanitizer::clean('<p><img src="https://bilder.example/a.png"><img src="cid:logo@x"></p>')['html'];
    $outgoing = MailHtmlSanitizer::clean($display, false);
    Assert::same(0, $outgoing['blocked_images']);
    Assert::contains('src="https://bilder.example/a.png"', $outgoing['html']);
    Assert::contains('src="cid:logo@x"', $outgoing['html']);
    Assert::false(str_contains($outgoing['html'], 'data-blocked-src'));
    Assert::false(str_contains($outgoing['html'], 'data-cid'));
});

Runner::test('Orvanta: Eingebettete Bilder werden ueber den Anhang-Token aufgeloest', function (): void {
    $parts = orvantaAttachments();
    $attachments = $parts['attachments'];
    $exchange = orvantaExchange()['exchange'];
    $message = $exchange->message('demo@demo.local', 'demo-msg-1');
    Assert::contains('data-cid="orvanta-logo@demo"', $message['body_html']);
    $inline = array_values(array_filter($message['attachments'], static fn (array $a): bool => $a['content_id'] === 'orvanta-logo@demo'));
    Assert::same(1, count($inline), 'Inline-Anhang traegt seine Content-ID.');

    $resolved = $attachments->embedInlineImages('u1', 'demo@demo.local', $message);
    Assert::true((bool) preg_match('~<img[^>]*src="[^"]*/anhang/oeffnen\?token=[^"]+"[^>]*data-cid="orvanta-logo@demo"~', $resolved['body_html']), 'Bildquelle zeigt auf den Anhang-Endpunkt.');
    $flagged = array_values(array_filter($resolved['attachments'], static fn (array $a): bool => $a['content_id'] === 'orvanta-logo@demo'));
    Assert::true($flagged[0]['inline']);
});

// ----------------------------------------------------------------------
// S/MIME-signierte Nachrichten
// ----------------------------------------------------------------------

function orvantaSignedMime(): string
{
    return "From: Absender <a@example.org>\r\nSubject: Signiert\r\nMIME-Version: 1.0\r\n"
        . "Content-Type: multipart/signed; protocol=\"application/pkcs7-signature\"; micalg=sha-256; boundary=\"sig\"\r\n\r\n"
        . "--sig\r\nContent-Type: multipart/mixed; boundary=\"mix\"\r\n\r\n"
        . "--mix\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>Hallo <b>signiert</b><img src=\"cid:logo@x\"></p>\r\n"
        . "--mix\r\nContent-Type: application/pdf; name=\"vertrag.pdf\"\r\nContent-Disposition: attachment; filename=\"vertrag.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . base64_encode('%PDF-1.4 Vertrag') . "\r\n"
        . "--mix\r\nContent-Type: image/png; name=\"logo.png\"\r\nContent-Disposition: inline; filename=\"logo.png\"\r\nContent-ID: <logo@x>\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . base64_encode('PNGDATA') . "\r\n--mix--\r\n\r\n"
        . "--sig\r\nContent-Type: application/pkcs7-signature; name=\"smime.p7s\"\r\nContent-Disposition: attachment; filename=\"smime.p7s\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . base64_encode('SIGNATUR') . "\r\n--sig--\r\n";
}

Runner::test('Orvanta: Signierte Mail wird wie eine normale Mail mit Hinweis dargestellt', function (): void {
    $parts = orvantaExchange();
    $parts['transport']->forced = ['status' => 200, 'error' => null, 'body' => '<?xml version="1.0" encoding="utf-8"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types">'
        . '<m:GetItemResponse><m:ResponseMessages><m:GetItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Items><t:Message>'
        . '<t:MimeContent CharacterSet="UTF-8">' . base64_encode(orvantaSignedMime()) . '</t:MimeContent>'
        . '<t:ItemId Id="sig-1" ChangeKey="CK1"/><t:ItemClass>IPM.Note.SMIME.MultipartSigned</t:ItemClass><t:Subject>Signiert</t:Subject>'
        . '<t:Body BodyType="HTML"></t:Body><t:HasAttachments>true</t:HasAttachments>'
        . '<t:Attachments><t:FileAttachment><t:AttachmentId Id="att-p7m"/><t:Name>Signiert</t:Name><t:ContentType>multipart/signed</t:ContentType><t:Size>146432</t:Size><t:IsInline>false</t:IsInline></t:FileAttachment></t:Attachments>'
        . '</t:Message></m:Items></m:GetItemResponseMessage></m:ResponseMessages></m:GetItemResponse></s:Body></s:Envelope>'];

    $message = $parts['exchange']->message('demo@demo.local', 'sig-1');
    Assert::true($message['signed'], 'Signierte Nachricht wird gekennzeichnet.');
    Assert::contains('<b>signiert</b>', $message['body_html'], 'Signierter Inhalt erscheint als Nachrichtentext.');
    Assert::contains('data-cid="logo@x"', $message['body_html']);
    $names = array_column($message['attachments'], 'name');
    Assert::same(['vertrag.pdf', 'logo.png'], $names, 'Weder Wrapper (smime.p7m) noch Signatur (smime.p7s) erscheinen als Anhang.');
    Assert::true($message['has_attachments']);
    Assert::same('orvanta-signed:0:sig-1', $message['attachments'][0]['id']);
    Assert::same('logo@x', $message['attachments'][1]['content_id']);

    $attachment = $parts['exchange']->attachment('demo@demo.local', 'orvanta-signed:0:sig-1');
    Assert::same('vertrag.pdf', $attachment['name']);
    Assert::same('application/pdf', $attachment['content_type']);
    Assert::same('%PDF-1.4 Vertrag', $attachment['content']);
    Assert::contains('IncludeMimeContent', $parts['transport']->last(), 'Anhang wird aus dem MIME-Inhalt gelesen.');

    try {
        $parts['exchange']->attachment('demo@demo.local', 'orvanta-signed:9:sig-1');
        Assert::true(false, 'Unbekannter Index muss scheitern.');
    } catch (OrvantaException $exception) {
        Assert::same(404, $exception->status());
    }
});

Runner::test('Orvanta: Als Outlook-Element angehaengte signierte Mail wird direkt angezeigt', function (): void {
    $envelope = static fn (string $body): array => ['status' => 200, 'error' => null, 'body' => '<?xml version="1.0" encoding="utf-8"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types">' . $body . '</s:Body></s:Envelope>'];
    $transport = new class ($envelope) implements ExchangeTransportInterface {
        /** @var list<string> */
        public array $xmls = [];

        public function __construct(private Closure $envelope)
        {
        }

        public function post(string $url, string $xml, array $options): array
        {
            $this->xmls[] = $xml;
            if (str_contains($xml, '<m:GetAttachment>')) {
                return ($this->envelope)('<m:GetAttachmentResponse><m:ResponseMessages><m:GetAttachmentResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Attachments><t:ItemAttachment><t:AttachmentId Id="att-item"/><t:Name>Signiert</t:Name>'
                    . '<t:Message><t:MimeContent CharacterSet="UTF-8">' . base64_encode(orvantaSignedMime()) . '</t:MimeContent><t:ItemClass>IPM.Note.SMIME.MultipartSigned</t:ItemClass></t:Message></t:ItemAttachment></m:Attachments></m:GetAttachmentResponseMessage></m:ResponseMessages></m:GetAttachmentResponse>');
            }

            return ($this->envelope)('<m:GetItemResponse><m:ResponseMessages><m:GetItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Items><t:Message>'
                . '<t:ItemId Id="outer-1" ChangeKey="CK1"/><t:ItemClass>IPM.Note</t:ItemClass><t:Subject>[EXTERN][filtered] Signiert</t:Subject><t:Body BodyType="HTML">&lt;p&gt;EXTERNE NACHRICHT! - Sei vorsichtig beim Öffnen von Links oder Anlagen!&lt;/p&gt;</t:Body><t:HasAttachments>true</t:HasAttachments>'
                . '<t:Attachments><t:ItemAttachment><t:AttachmentId Id="att-item"/><t:Name>[EXTERN][filtered] Signiert</t:Name><t:Size>146432</t:Size><t:IsInline>false</t:IsInline></t:ItemAttachment>'
                . '<t:FileAttachment><t:AttachmentId Id="att-file"/><t:Name>hinweis.txt</t:Name><t:ContentType>text/plain</t:ContentType><t:Size>5</t:Size><t:IsInline>false</t:IsInline></t:FileAttachment></t:Attachments>'
                . '</t:Message></m:Items></m:GetItemResponseMessage></m:ResponseMessages></m:GetItemResponse>');
        }
    };
    $exchange = new OrvantaExchangeService($transport, orvantaConfig()['config']);

    $message = $exchange->message('demo@demo.local', 'outer-1');
    Assert::true($message['signed']);
    Assert::contains('<b>signiert</b>', $message['body_html']);
    Assert::true(strpos($message['body_html'], 'EXTERNE NACHRICHT!') < strpos($message['body_html'], '<b>signiert</b>'), 'Banner bleibt oben, signierter Inhalt folgt direkt.');
    Assert::false(str_contains($message['body_html'], '<hr'), 'Keine Trennlinie zwischen Banner und Inhalt.');
    Assert::same(['hinweis.txt', 'vertrag.pdf', 'logo.png'], array_column($message['attachments'], 'name'), 'Outlook-Element wird durch seine Anhaenge ersetzt.');
    Assert::same('orvanta-signeditem:0:att-item', $message['attachments'][1]['id']);
    Assert::contains('<t:IncludeMimeContent>true</t:IncludeMimeContent>', implode("\n", $transport->xmls));

    $attachment = $exchange->attachment('demo@demo.local', 'orvanta-signeditem:0:att-item');
    Assert::same('vertrag.pdf', $attachment['name']);
    Assert::same('%PDF-1.4 Vertrag', $attachment['content']);
});

Runner::test('Orvanta: MIME-Parser zeigt angehaengte signierte Nachricht direkt an', function (): void {
    $raw = "From: Gateway <gw@example.org>\r\nSubject: [EXTERN] Signiert\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"outer\"\r\n\r\n"
        . "--outer\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nVom Gateway geprueft.\r\n"
        . "--outer\r\nContent-Type: message/rfc822\r\nContent-Disposition: attachment; filename=\"original.eml\"\r\n\r\n" . orvantaSignedMime() . "\r\n--outer--\r\n";
    $parsed = (new App\Services\Orvanta\MimeMessageParser())->parse($raw);
    Assert::true($parsed['signed']);
    Assert::contains('Vom Gateway geprueft.', $parsed['html']);
    Assert::false(str_contains($parsed['html'], '<hr>'), 'Signierter Inhalt folgt ohne Trennlinie.');
    Assert::true(strpos($parsed['html'], 'Vom Gateway geprueft.') < strpos($parsed['html'], '<b>signiert</b>'), 'Banner steht vor dem signierten Inhalt.');
    Assert::contains('<b>signiert</b>', $parsed['html']);
    Assert::same(['vertrag.pdf', 'logo.png'], array_column($parsed['attachments'], 'name'));

    $unsigned = str_replace(orvantaSignedMime(), "Subject: Normal\r\nContent-Type: text/plain\r\n\r\nHallo\r\n", $raw);
    $plain = (new App\Services\Orvanta\MimeMessageParser())->parse($unsigned);
    Assert::false($plain['signed']);
    Assert::same(['original.eml'], array_column($plain['attachments'], 'name'), 'Unsignierte angehaengte Mail bleibt ein Anhang.');
});

Runner::test('Orvanta: Normale Mail wird nicht als signiert gekennzeichnet', function (): void {
    $message = orvantaExchange()['exchange']->message('demo@demo.local', 'demo-msg-1');
    Assert::false($message['signed']);
});

Runner::test('Orvanta: MIME-Parser entpackt opak signierte S/MIME-Nachrichten', function (): void {
    if (!function_exists('openssl_pkcs7_sign')) {
        return;
    }
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'Absender', 'emailAddress' => 'a@example.org'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 1);
    $in = (string) tempnam(sys_get_temp_dir(), 'ovt');
    $out = (string) tempnam(sys_get_temp_dir(), 'ovt');
    file_put_contents($in, "Content-Type: text/plain; charset=utf-8\r\n\r\nGeheimer Gruss\r\n");
    Assert::true(openssl_pkcs7_sign($in, $out, $cert, $key, ['From' => 'a@example.org', 'Subject' => 'Opak'], 0));
    $signed = (string) file_get_contents($out);
    @unlink($in);
    @unlink($out);
    Assert::contains('application/', $signed);

    $parsed = (new App\Services\Orvanta\MimeMessageParser())->parse($signed);
    Assert::true($parsed['signed']);
    Assert::contains('Geheimer Gruss', $parsed['text']);
    Assert::same([], $parsed['attachments'], 'smime.p7m erscheint nicht als Anhang.');
});

// ----------------------------------------------------------------------
// Antworten und Entwuerfe
// ----------------------------------------------------------------------

Runner::test('Orvanta: Antwort uebernimmt Cc und referenziert die Originalmail', function (): void {
    $parts = orvantaExchange();
    $result = $parts['exchange']->respond('demo@demo.local', 'demo-msg-1', 'replyall', [
        'to' => ['a@example.org'],
        'cc' => ['c@example.org'],
        'body' => '<p>Danke</p>',
        'html' => true,
    ]);
    $xml = $parts['transport']->last();
    Assert::true($result['id'] !== '');
    Assert::contains('<m:CreateItem MessageDisposition="SendAndSaveCopy"', $xml);
    Assert::contains('<t:ReplyAllToItem>', $xml);
    Assert::contains('<t:CcRecipients><t:Mailbox><t:EmailAddress>c@example.org</t:EmailAddress>', $xml);
    Assert::contains('<t:ReferenceItemId Id="demo-msg-1" ChangeKey="CK1"/>', $xml);
    Assert::true(strpos($xml, '<t:CcRecipients>') < strpos($xml, '<t:ReferenceItemId'), 'Schemareihenfolge: Empfaenger vor ReferenceItemId.');
    Assert::true(strpos($xml, '<t:ReferenceItemId') < strpos($xml, '<t:NewBodyContent'), 'Schemareihenfolge: ReferenceItemId vor NewBodyContent.');
});

Runner::test('Orvanta: Antwort mit Anhang bleibt eine Antwort und wird aus dem Entwurf gesendet', function (): void {
    $parts = orvantaExchange();
    $transport = $parts['transport'];
    $parts['exchange']->respond('demo@demo.local', 'demo-msg-1', 'reply', [
        'to' => ['a@example.org'],
        'body' => 'Anbei',
        'attachments' => [['name' => 'x.txt', 'content_type' => 'text/plain', 'content' => base64_encode('hi')]],
    ]);
    $calls = implode("\n", $transport->xmls());
    Assert::contains('<t:ReplyToItem>', $calls);
    Assert::contains('<t:ReferenceItemId Id="demo-msg-1" ChangeKey="CK1"/>', $calls);
    Assert::contains('<m:CreateAttachment>', $calls);
    Assert::contains('<m:SendItem SaveItemToFolder="true">', $transport->last());
    Assert::contains('<t:ItemId Id="demo-new-', $transport->last());
});

Runner::test('Orvanta: Entwurf wird beim erneuten Speichern aktualisiert statt dupliziert', function (): void {
    $parts = orvantaExchange();
    $transport = $parts['transport'];
    $draft = $parts['exchange']->saveDraft('demo@demo.local', ['subject' => 'Erst', 'body' => 'Text', 'to' => []]);
    Assert::contains('<m:CreateItem MessageDisposition="SaveOnly"', $transport->last());
    Assert::contains('<t:DistinguishedFolderId Id="drafts"', $transport->last());
    Assert::true($draft['id'] !== '' && $draft['change_key'] !== '');

    $updated = $parts['exchange']->saveDraft('demo@demo.local', [
        'subject' => 'Zweit',
        'body' => 'Mehr',
        'to' => ['a@example.org'],
        'attachments' => [['name' => 'x.txt', 'content_type' => 'text/plain', 'content' => base64_encode('hi')]],
    ], $draft['id'], $draft['change_key']);
    $calls = $transport->xmls();
    $update = $calls[count($calls) - 2];
    Assert::contains('<m:UpdateItem', $update);
    Assert::contains('<t:ItemId Id="' . $draft['id'] . '"', $update);
    Assert::contains('<t:FieldURI FieldURI="message:ToRecipients"/>', $update);
    Assert::contains('<t:DeleteItemField><t:FieldURI FieldURI="message:CcRecipients"/></t:DeleteItemField>', $update);
    Assert::false(str_contains($update, '<m:CreateItem'), 'Kein zweiter Entwurf.');
    Assert::contains('<m:CreateAttachment>', $transport->last());
    Assert::same($draft['id'], $updated['id']);
});

Runner::test('Orvanta: Senden eines gespeicherten Entwurfs nutzt SendItem', function (): void {
    $parts = orvantaExchange();
    $transport = $parts['transport'];
    $parts['exchange']->send('demo@demo.local', ['subject' => 'S', 'body' => 'B', 'to' => ['a@example.org']], 'draft-1', 'CK0');
    $calls = $transport->xmls();
    Assert::contains('<m:UpdateItem', $calls[count($calls) - 2]);
    Assert::contains('<m:SendItem SaveItemToFolder="true">', $transport->last());
    Assert::contains('<t:ItemId Id="draft-1"', $transport->last());
});

// ----------------------------------------------------------------------
// Erinnerungen
// ----------------------------------------------------------------------

Runner::test('Orvanta: Vorlaufzeit gilt nur fuer Termine ohne eigene Erinnerung', function (): void {
    $now = time();
    $without = orvantaExchange(['reminder_lead_minutes' => '0']);
    $service = new OrvantaNotificationService($without['repository'], $without['exchange'], $without['config']);
    $service->sync('u1', 'demo@demo.local', $now);
    $ids = array_column($service->poll('u1', $now + 3600)['due'], 'item_id');
    Assert::true(in_array('demo-ev-6', $ids, true), 'Termin mit eigener Erinnerung wird gemeldet.');
    Assert::false(in_array('demo-ev-9', $ids, true), 'Ohne Vorlauf keine Erinnerung fuer Termine ohne eigene Erinnerung.');

    $with = orvantaExchange(['reminder_lead_minutes' => '30']);
    $service = new OrvantaNotificationService($with['repository'], $with['exchange'], $with['config']);
    $service->sync('u1', 'demo@demo.local', $now);
    $due = [];
    foreach ($service->poll('u1', $now)['due'] as $row) {
        $due[$row['item_id']] = $row;
    }
    Assert::true(isset($due['demo-ev-9']), 'Vorlauf erzeugt Erinnerung fuer Termin ohne eigene Erinnerung.');
    Assert::same($due['demo-ev-9']['start'] - 30 * 60, $due['demo-ev-9']['remind_at']);
    Assert::true(isset($due['demo-ev-6']));
    Assert::same($due['demo-ev-6']['start'] - 15 * 60, $due['demo-ev-6']['remind_at'], 'Eigene Erinnerung bleibt unveraendert.');
});

Runner::test('Orvanta: Erinnerungen werden synchronisiert, faellig und erledigt', function (): void {
    $parts = orvantaExchange(['reminder_lead_minutes' => '0']);
    $repository = $parts['repository'];
    $service = new OrvantaNotificationService($repository, $parts['exchange'], $parts['config']);
    $now = time();

    Assert::null($service->sync('u1', 'demo@demo.local', $now));
    $before = $service->poll('u1', $now - 86400 * 3);
    Assert::same([], $before['due']);

    // Demo-Termin in 10 Minuten mit 15 Minuten Vorlauf ist jetzt faellig.
    $poll = $service->poll('u1', $now);
    Assert::true(count($poll['due']) >= 1, 'Mindestens eine faellige Erinnerung.');
    $due = $poll['due'][0];
    Assert::same('delivered', $due['state']);
    Assert::true($due['relative'] !== '');

    // Zweiter Poll liefert dieselbe nicht erneut als faellig, aber als aktiv.
    $again = $service->poll('u1', $now);
    Assert::same([], $again['due']);
    Assert::true(count($again['active']) >= 1);

    Assert::true($service->dismiss('u1', (int) $due['id'], $now));
    $ids = array_map(static fn (array $r): int => (int) $r['id'], $service->poll('u1', $now)['active']);
    Assert::false(in_array((int) $due['id'], $ids, true), 'Erledigte Erinnerung verschwindet.');
    Assert::false($service->dismiss('u2', (int) $due['id'], $now), 'Fremde Erinnerungen koennen nicht erledigt werden.');
});

Runner::test('Orvanta: Snooze verschiebt die Faelligkeit, Sync-Fehler wird gemeldet', function (): void {
    $parts = orvantaExchange(['reminder_lead_minutes' => '0']);
    $service = new OrvantaNotificationService($parts['repository'], $parts['exchange'], $parts['config']);
    $now = time();
    $service->sync('u1', 'demo@demo.local', $now);
    $due = $service->poll('u1', $now)['due'][0];

    Assert::true($service->snooze('u1', (int) $due['id'], 5, $now));
    Assert::same([], $service->poll('u1', $now + 60)['due']);
    $later = $service->poll('u1', $now + 6 * 60)['due'];
    Assert::same((int) $due['id'], (int) $later[0]['id'], 'Nach Ablauf erneut faellig.');

    $parts['transport']->forced = ['status' => 0, 'body' => '', 'error' => 'timeout'];
    $message = $service->sync('u1', 'demo@demo.local', $now);
    Assert::true(is_string($message) && $message !== '', 'Fehler wird als Text gemeldet, nicht geworfen.');
});

Runner::test('Orvanta: Repository-Sync aktualisiert verschobene Termine und entfernt alte', function (): void {
    $repository = new OrvantaRepository(orvantaPdo());
    $repository->syncReminders('u1', [
        ['item_id' => 'a', 'subject' => 'A', 'location' => '', 'starts_at' => '2030-01-01 10:00:00', 'remind_at' => '2030-01-01 09:45:00'],
        ['item_id' => 'b', 'subject' => 'B', 'location' => '', 'starts_at' => '2030-01-02 10:00:00', 'remind_at' => '2030-01-02 09:45:00'],
    ]);
    Assert::same(2, count($repository->dueReminders('u1', '2031-01-01 00:00:00')));

    $due = $repository->dueReminders('u1', '2030-01-01 09:50:00');
    Assert::same(1, count($due));
    $repository->markDelivered('u1', [(int) $due[0]['id']], '2030-01-01 09:50:00');

    // A verschoben → wieder pending; B entfernt.
    $repository->syncReminders('u1', [
        ['item_id' => 'a', 'subject' => 'A neu', 'location' => 'R2', 'starts_at' => '2030-01-01 12:00:00', 'remind_at' => '2030-01-01 11:45:00'],
    ]);
    $pending = $repository->dueReminders('u1', '2031-01-01 00:00:00');
    Assert::same(1, count($pending));
    Assert::same('pending', (string) $pending[0]['state']);
    Assert::same('A neu', (string) $pending[0]['subject']);
    Assert::same([], $repository->activeReminders('u1'), 'Ausgelieferte wurde durch Verschiebung zurueckgesetzt.');
    Assert::same([], $repository->dueReminders('u2', '2031-01-01 00:00:00'));
});

Runner::test('Orvanta: relative Zeitangabe', function (): void {
    Assert::same('jetzt', OrvantaNotificationService::relative(10));
    Assert::same('in 5 Min.', OrvantaNotificationService::relative(5 * 60));
    Assert::contains('seit 3 Min', OrvantaNotificationService::relative(-3 * 60));
});

// ----------------------------------------------------------------------
// Anhaenge, Zwischenspeicher und Quota
// ----------------------------------------------------------------------

Runner::test('Orvanta: Oeffnungsart je Dateityp', function (): void {
    Assert::same('office', OrvantaAttachmentService::openMode('Bericht.DOCX'));
    Assert::same('office', OrvantaAttachmentService::openMode('zahlen.xlsx'));
    Assert::same('office', OrvantaAttachmentService::openMode('folien.pptx'));
    Assert::same('office', OrvantaAttachmentService::openMode('scan.pdf'));
    Assert::same('browser', OrvantaAttachmentService::openMode('bild.png'));
    Assert::same('download', OrvantaAttachmentService::openMode('archiv.zip'));
    Assert::same('cell', OrvantaAttachmentService::documentType('a.csv'));
    Assert::same('slide', OrvantaAttachmentService::documentType('a.odp'));
});

Runner::test('Orvanta: signierte Anhang-Links laufen ab und sind an den Zweck gebunden', function (): void {
    $service = orvantaAttachments()['attachments'];
    $now = time();
    $token = $service->token('u1', 'anna@example.local', 'ATT-1', 'Bericht.docx', $now);
    $claims = $service->verify($token, $now + 10);
    Assert::true($claims !== null);
    Assert::same('u1', $claims['uid']);
    Assert::same('ATT-1', $claims['attachment_id']);
    Assert::same('Bericht.docx', $claims['name']);
    Assert::same('anna@example.local', $claims['impersonate']);
    Assert::null($service->verify($token, $now + OrvantaAttachmentService::TOKEN_LIFETIME + 1), 'Abgelaufen.');
    Assert::null($service->verify($token . 'x', $now), 'Manipuliert.');
    Assert::null(orvantaAttachments()['attachments']->verify($token, $now), 'Anderes Secret.');
});

Runner::test('Orvanta: Zwischenspeicher haelt das Quota ein und verdraengt die aeltesten', function (): void {
    $parts = orvantaAttachments(['cache_quota_mb' => '1']);
    $service = $parts['attachments'];
    $kb600 = str_repeat('a', 600 * 1024);

    Assert::true($service->cache('u1', sha1('one'), ['name' => 'eins.pdf', 'content_type' => 'application/pdf', 'content' => $kb600, 'size' => strlen($kb600)]));
    Assert::true($service->cache('u1', sha1('one'), ['name' => 'eins.pdf', 'content_type' => 'application/pdf', 'content' => $kb600, 'size' => strlen($kb600)]), 'Doppelt ist idempotent.');
    Assert::same(1, $service->usage('u1')['items']);
    Assert::same(1, count(array_filter($parts['probe']->requests, static fn (array $r): bool => $r['method'] === 'POST')));

    Assert::true($service->cache('u1', sha1('two'), ['name' => 'zwei.pdf', 'content_type' => 'application/pdf', 'content' => $kb600, 'size' => strlen($kb600)]));
    $usage = $service->usage('u1');
    Assert::same(1, $usage['items'], 'Aeltester Eintrag wurde verdraengt.');
    Assert::same(strlen($kb600), $usage['used']);
    Assert::true($usage['used'] <= $usage['quota']);
    Assert::same(1, count(array_filter($parts['probe']->requests, static fn (array $r): bool => $r['method'] === 'DELETE')));
    Assert::true($parts['repository']->findCacheItem('u1', sha1('two')) !== null);
    Assert::null($parts['repository']->findCacheItem('u1', sha1('one')));

    // Zu grosser Inhalt wird nicht zwischengespeichert.
    $big = str_repeat('b', 1024 * 1024 + 1);
    Assert::false($service->cache('u1', sha1('big'), ['name' => 'gross.bin', 'content_type' => 'application/octet-stream', 'content' => $big, 'size' => strlen($big)]));

    Assert::same(1, $service->clear('u1'));
    Assert::same(0, $service->usage('u1')['used']);
});

Runner::test('Orvanta: Quota 0 deaktiviert den Zwischenspeicher, Nextcloud-Fehler werden toleriert', function (): void {
    $off = orvantaAttachments(['cache_quota_mb' => '0'])['attachments'];
    Assert::false($off->cache('u1', sha1('x'), ['name' => 'a.txt', 'content_type' => 'text/plain', 'content' => 'abc', 'size' => 3]));

    $broken = orvantaAttachments([], false)['attachments'];
    Assert::false($broken->cache('u1', sha1('x'), ['name' => 'a.txt', 'content_type' => 'text/plain', 'content' => 'abc', 'size' => 3]));
    Assert::same(0, $broken->usage('u1')['items']);
});

Runner::test('Orvanta: Anhang wird von Exchange geladen und zwischengespeichert', function (): void {
    $parts = orvantaAttachments();
    $service = $parts['attachments'];
    $first = $service->load('u1', 'demo@demo.local', 'demo-att-1');
    Assert::true($first['size'] > 0);
    Assert::false($first['cached']);
    Assert::same(1, $service->usage('u1')['items']);

    $parts['probe']->responses['GET http://nextcloud/office/index.php/apps/intranet_integration/api/files'] = [
        'status' => 200, 'body' => $first['content'], 'error' => null,
    ];
    $second = $service->load('u1', 'demo@demo.local', 'demo-att-1');
    Assert::true($second['cached'], 'Zweiter Zugriff kommt aus dem Zwischenspeicher.');
    Assert::same($first['name'], $second['name']);
});

Runner::test('Orvanta: Ablage in Nextcloud legt die Datei im Orvanta-Ordner ab', function (): void {
    $parts = orvantaAttachments();
    $result = $parts['attachments']->saveToNextcloud('u1', 'demo@demo.local', 'demo-att-1');
    Assert::true($result['ok'], $result['message']);
    Assert::contains('/Orvanta/', $result['path']);
    $posts = array_values(array_filter($parts['probe']->requests, static fn (array $r): bool => $r['method'] === 'POST'));
    Assert::true(count($posts) >= 1);
    Assert::true(str_starts_with((string) ($posts[0]['headers']['Authorization'] ?? ''), 'Bearer '));
});

Runner::test('Orvanta: Viewer-Konfiguration fuer Euro-Office', function (): void {
    $parts = orvantaAttachments();
    $viewer = $parts['attachments']->viewerConfig(
        ['uid' => 'u1', 'impersonate' => 'anna@example.local', 'attachment_id' => 'ATT-1', 'name' => 'Bericht.docx'],
        ['username' => 'anna', 'display_name' => 'Anna Muster'],
        'tok'
    );
    Assert::same('/eurooffice/web-apps/apps/api/documents/api.js', $viewer['api_url']);
    $config = $viewer['config'];
    Assert::same('word', $config['documentType']);
    Assert::same('view', $config['editorConfig']['mode']);
    Assert::contains('token=tok', $config['document']['url']);
    Assert::contains('http://app/', $config['document']['url']);
    Assert::same('Bericht.docx', $config['document']['title']);
    Assert::true(isset($config['token']) && $config['token'] !== '', 'JWT fuer den DocumentServer.');
});

// ----------------------------------------------------------------------
// Empfaenger-Vorschlaege (Telefonliste + Verlauf in Nextcloud)
// ----------------------------------------------------------------------

/**
 * @return array{service:\App\Services\Orvanta\OrvantaRecipientService,probe:FakeOfficeProbe,session:array<string,mixed>}
 */
function orvantaRecipients(bool $nextcloudOk = true): array
{
    $pdo = orvantaPdo();
    $pdo->exec('CREATE TABLE phonebook (
        id INTEGER PRIMARY KEY AUTOINCREMENT, identity_source_id INTEGER NOT NULL DEFAULT 0, external_id TEXT NOT NULL DEFAULT \'\',
        samaccount_name TEXT NULL, display_name TEXT NOT NULL, first_name TEXT NULL, last_name TEXT NULL, title TEXT NULL,
        phone TEXT NULL, phone_digits TEXT NULL, mobile TEXT NULL, email TEXT NULL, department TEXT NULL, ad_modified TEXT NULL,
        synced_at TEXT NULL, active INTEGER NOT NULL DEFAULT 1, visible INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec("INSERT INTO phonebook (display_name, first_name, last_name, email, department) VALUES
        ('Anna Muster', 'Anna', 'Muster', 'anna.muster@firma.de', 'Einkauf'),
        ('Anton Meier', 'Anton', 'Meier', 'anton.meier@firma.de', 'IT'),
        ('Ohne Mail', 'Ohne', 'Mail', NULL, 'Lager')");
    $repository = new OrvantaRepository($pdo);
    $repository->saveSettings(['exchange_enabled' => '1', 'exchange_host' => 'demo']);
    $probe = new FakeOfficeProbe();
    $files = 'http://nextcloud/office/index.php/apps/intranet_integration/api/files';
    $probe->responses['POST ' . $files] = $nextcloudOk
        ? ['status' => 200, 'body' => json_encode(['ok' => true]), 'error' => null]
        : ['status' => 500, 'body' => json_encode(['ok' => false, 'message' => 'kaputt']), 'error' => null];
    $probe->responses['GET ' . $files] = ['status' => 404, 'body' => json_encode(['ok' => false, 'message' => 'fehlt']), 'error' => null];
    $office = officeConfig();
    $session = [];
    $service = new \App\Services\Orvanta\OrvantaRecipientService(
        new OrvantaConfigService($repository, orvantaSecrets()),
        new NextcloudFilesService($office, $probe),
        new \App\Repositories\PhonebookRepository($pdo),
        static function (string $key) use (&$session): mixed { return $session[$key] ?? null; },
        static function (string $key, mixed $value) use (&$session): void { $session[$key] = $value; }
    );

    return ['service' => $service, 'probe' => $probe, 'session' => &$session];
}

Runner::test('Orvanta: Empfaenger-Vorschlaege aus Telefonliste, Verlauf zuerst und ohne Dubletten', function (): void {
    $parts = orvantaRecipients();
    $service = $parts['service'];

    $items = $service->suggest('u1', 'an');
    Assert::same(['anton.meier@firma.de', 'anna.muster@firma.de'], array_column($items, 'email'), 'Telefonliste sortiert nach Nachname.');
    Assert::same('Telefonliste', $items[0]['source']);
    Assert::same([], $service->suggest('u1', ''));

    // Versand an eine fremde Adresse und an Anna (mit Anzeigename aus dem Frontend).
    Assert::true($service->remember('u1', [['name' => 'Extern Partner', 'email' => 'partner@extern.example'], ['name' => '', 'email' => 'Anna.Muster@firma.de'], 'ungueltig', ['email' => 'partner@extern.example']], 1000));
    $post = array_values(array_filter($parts['probe']->requests, static fn (array $r): bool => $r['method'] === 'POST'))[0];
    $claims = json_decode(base64_decode(strtr(explode('.', substr((string) $post['headers']['Authorization'], 7))[1], '-_', '+/')), true);
    Assert::same('.empfaenger.json', $claims['name'], 'Versteckte Datei im Orvanta-Ordner.');
    Assert::same('Orvanta', $claims['folder']);
    $stored = json_decode((string) $post['body'], true);
    Assert::same(2, count($stored['items']), 'Dubletten innerhalb eines Versands zaehlen einmal.');

    $items = $service->suggest('u1', 'an');
    Assert::same('anna.muster@firma.de', $items[0]['email'], 'Gross-/Kleinschreibung der Adresse: Telefonlisten-Eintrag wird nicht doppelt gelistet.');
    Assert::true($items[0]['recent']);
    Assert::same('Zuletzt verwendet', $items[0]['source']);
    Assert::same('Anna Muster', $items[0]['display_name'], 'Name der Telefonliste bleibt, wenn der Verlauf keinen Namen hat.');
    Assert::same(['anna.muster@firma.de', 'anton.meier@firma.de'], array_column($items, 'email'));

    $items = $service->suggest('u1', 'ext');
    Assert::same([['display_name' => 'Extern Partner', 'email' => 'partner@extern.example', 'department' => '', 'source' => 'Zuletzt verwendet', 'recent' => true]], $items);
    Assert::same(1, count($service->suggest('u1', 'partner@ex')), 'Praefix auf die Adresse.');
    Assert::same(1, count($service->suggest('u1', 'partner extern')), 'Mehrere Suchwoerter.');
    Assert::same([], $service->suggest('u1', 'xyz'));

    // Erneuter Versand erhoeht den Zaehler und sortiert nach Verwendung.
    Assert::true($service->remember('u1', [['name' => 'Partner, Extern', 'email' => 'partner@extern.example']], 2000));
    $recent = $service->recent('u1');
    Assert::same('partner@extern.example', $recent[0]['email']);
    Assert::same(2, $recent[0]['count']);
    Assert::same('Partner, Extern', $recent[0]['name']);
    Assert::same(1, count(array_filter($parts['probe']->requests, static fn (array $r): bool => $r['method'] === 'GET')), 'Nextcloud wird nur einmal gelesen, danach greift der Sitzungs-Cache.');
});

Runner::test('Orvanta: Empfaenger-Verlauf wird aus Nextcloud gelesen, Fehler werden toleriert', function (): void {
    $parts = orvantaRecipients();
    $files = 'http://nextcloud/office/index.php/apps/intranet_integration/api/files';
    $parts['probe']->responses['GET ' . $files] = ['status' => 200, 'error' => null, 'body' => json_encode([
        'format' => 'lanpa-orvanta-empfaenger', 'version' => 1,
        'items' => [['name' => 'Bob Alt', 'email' => 'bob@alt.example', 'count' => 3, 'last_used' => 5], ['email' => 'kaputt'], 'murks'],
    ])];
    $items = $parts['service']->suggest('u2', 'bob');
    Assert::same([['display_name' => 'Bob Alt', 'email' => 'bob@alt.example', 'department' => '', 'source' => 'Zuletzt verwendet', 'recent' => true]], $items);

    $broken = orvantaRecipients(false)['service'];
    Assert::false($broken->remember('u3', [['email' => 'x@y.example']]));
    Assert::same([], $broken->recent('u3'));
    Assert::false($broken->remember('u3', []));

    // Versteckte Dateinamen sind nur als Datei, nicht als Ordner zulaessig.
    Assert::true(NextcloudFilesService::isSafeFileName('.empfaenger.json'));
    Assert::false(NextcloudFilesService::isSafeSegment('.empfaenger.json'));
    Assert::false(NextcloudFilesService::isSafeFileName('.'));
    Assert::false(NextcloudFilesService::isSafeFileName('..'));
    Assert::false(NextcloudFilesService::isSafeFileName('.a/b'));
    require_once BASE_PATH . '/docker/nextcloud/apps/intranet_integration/lib/Service/FileTarget.php';
    Assert::true(\OCA\IntranetIntegration\Service\FileTarget::isSafeFileName('.empfaenger.json'));
    Assert::false(\OCA\IntranetIntegration\Service\FileTarget::isSafeFileName('..'));
    Assert::same(null, \OCA\IntranetIntegration\Service\FileTarget::folder('.hidden'));
});

// ----------------------------------------------------------------------
// Exchange-DAG (Lastverteilung, Ausfallsicherung)
// ----------------------------------------------------------------------

Runner::test('Orvanta DAG: Hostliste wird geprueft (Hostname, Adresse, Dubletten)', function (): void {
    $parsed = OrvantaExchangePool::parseHostList("mail02.firma.local\nmail03.firma.local https://mail03.firma.local/EWS/Exchange.asmx\n\n");
    Assert::same([], $parsed['errors']);
    Assert::same(['mail02.firma.local', 'mail03.firma.local'], array_column($parsed['hosts'], 'host'));
    Assert::same('https://mail03.firma.local/EWS/Exchange.asmx', $parsed['hosts'][1]['url']);
    Assert::same('', $parsed['hosts'][0]['url'], 'Ohne eigene Adresse wird der Standardpfad verwendet.');

    $parsed = OrvantaExchangePool::parseHostList('MAIL02.firma.local, mail02.firma.local; mail02.firma.local');
    Assert::same(['mail02.firma.local'], array_column($parsed['hosts'], 'host'), 'Gross-/Kleinschreibung: derselbe Host zaehlt einmal.');
    Assert::same(1, count($parsed['errors']));
    Assert::contains('mehrfach', $parsed['errors'][0]);

    $parsed = OrvantaExchangePool::parseHostList("https://mail02.firma.local/EWS/Exchange.asmx\nmail03.firma.local ftp://mail03.firma.local\nkein host");
    Assert::same([], $parsed['hosts']);
    Assert::same(3, count($parsed['errors']), 'Adresse statt Hostname, falsches Schema, Leerzeichen im Namen.');

    $many = OrvantaExchangePool::parseHostList(implode("\n", array_map(static fn (int $i): string => 'mail' . $i . '.firma.local', range(1, 20))));
    Assert::same(OrvantaExchangePool::MAX_HOSTS, count($many['hosts']));
    Assert::same(1, count($many['errors']));
});

Runner::test('Orvanta DAG: Prioritaet 1 verteilt nach Fair-use (aelteste Zuweisung zuerst)', function (): void {
    $parts = orvantaPool();
    $pool = $parts['pool'];

    $hosts = $pool->hosts();
    Assert::same(['mail01.example.local', 'mail02.example.local', 'mail03.example.local'], array_column($hosts, 'host'));
    Assert::same(1, (int) $hosts[0]['is_primary']);

    // Fehlt der konfigurierte Server in der Hostliste, wird er als primaerer
    // Host nachgetragen (die Einstellungen sind die Quelle der Wahrheit).
    $leer = orvantaPool([], []);
    $leer['pdo']->exec("DELETE FROM orvanta_exchange_hosts WHERE host = 'mail01.example.local'");
    Assert::same([], array_column($leer['hosts']->hosts(), 'host'), 'Ohne Eintrag ist die Hostliste leer.');
    Assert::same(['mail01.example.local'], array_column($leer['pool']->hosts(), 'host'));
    Assert::same(1, (int) $leer['pool']->hosts()[0]['is_primary']);

    // Drei neue Sitzungen verteilen sich auf die drei Hosts.
    Assert::same('mail01.example.local', $pool->session('session-a')['host'], 'Die erste Sitzung erhaelt den primaeren Host.');
    $parts['setSession']('session-b');
    Assert::same('mail02.example.local', $pool->session('session-b')['host'], 'Noch ohne Sitzung: der naechste Host der Reihe.');
    $parts['setSession']('session-c');
    Assert::same('mail03.example.local', $pool->session('session-c')['host'], 'Der letzte Host ohne Sitzung.');

    // Vierte Sitzung: alle Hosts haben gleich viele Sitzungen, also entscheidet
    // wieder die aelteste Zuweisung (Fair-use).
    $parts['setSession']('session-d');
    Assert::same('mail01.example.local', $pool->session('session-d')['host'], 'Gleichstand: die aelteste Zuweisung ist wieder an der Reihe.');

    // Fair-use schlaegt die Sitzungszahl: mail02 hat zwar die wenigsten
    // Sitzungen, wurde aber zuletzt bedient. Die Hostliste wird je Anfrage
    // neu gelesen, deshalb entscheidet eine frische Instanz.
    $parts['pdo']->exec("UPDATE orvanta_exchange_hosts SET last_session_at = '2024-01-01 08:00:00' WHERE host = 'mail01.example.local'");
    $parts['pdo']->exec("UPDATE orvanta_exchange_hosts SET last_session_at = '2030-01-01 08:00:00' WHERE host <> 'mail01.example.local'");
    $parts['setSession']('session-e');
    $frisch = new OrvantaExchangePool($parts['hosts'], $parts['config'], static fn (): string => 'session-e');
    Assert::same('mail01.example.local', $frisch->session('session-e')['host'], 'Fair-use hat Vorrang vor der Sitzungszahl.');
});

Runner::test('Orvanta DAG: Prioritaet 2 verteilt auf den Host mit den wenigsten Sitzungen', function (): void {
    $parts = orvantaPool();
    $pool = $parts['pool'];
    // Ohne Fair-use-Unterschied (alle gleich lange ohne Sitzung) entscheidet die Anzahl.
    $parts['pdo']->exec('UPDATE orvanta_exchange_hosts SET last_session_at = NULL');

    foreach (['s1', 's2', 's3', 's4'] as $index => $key) {
        $parts['setSession']($key);
        $pool->session($key, 'anna@example.local');
        $parts['pdo']->exec('UPDATE orvanta_exchange_hosts SET last_session_at = NULL');
    }
    $counts = $parts['hosts']->sessionCounts('2000-01-01 00:00:00');
    Assert::same(2, $counts['mail01.example.local'], 'Vier Sitzungen auf drei Hosts: der erste Host erhaelt zwei.');
    Assert::same(1, $counts['mail02.example.local']);
    Assert::same(1, $counts['mail03.example.local']);

    // mail02 hat die wenigsten Sitzungen und wird daher bevorzugt.
    $parts['pdo']->exec("UPDATE orvanta_exchange_sessions SET host = 'mail03.example.local' WHERE host = 'mail02.example.local'");
    $parts['setSession']('s5');
    Assert::same('mail02.example.local', $pool->session('s5')['host']);
});

Runner::test('Orvanta DAG: Sitzungs-SQL nutzt jeden benannten Platzhalter nur einmal (MySQL ohne Emulation)', function (): void {
    // SQLite toleriert doppelte Platzhalter, MySQL mit ATTR_EMULATE_PREPARES=false
    // nicht (HY093): die Sitzungszeile entstand nie, Zaehler und Liste blieben leer.
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Repositories/OrvantaExchangeHostRepository.php');
    preg_match_all("/'((?:SELECT|INSERT|UPDATE|DELETE)[^']*)'/i", $source, $matches);
    Assert::true(count($matches[1]) > 5, 'SQL-Anweisungen gefunden.');
    foreach ($matches[1] as $sql) {
        preg_match_all('/(?<![:\w]):([a-z_]\w*)/i', $sql, $names);
        Assert::same(count($names[1]), count(array_unique($names[1])), 'Doppelter Platzhalter in: ' . $sql);
    }
});

Runner::test('Orvanta DAG: Pruefpostfach fuer den Verbindungstest ist einstellbar', function (): void {
    $parts = orvantaConfig();
    $config = $parts['config'];
    Assert::same('', $config->testMailbox(), 'Standard: Posteingang des Dienstkontos.');
    $config->saveTestMailbox(' pruefung@example.local ');
    Assert::same('pruefung@example.local', $config->testMailbox());
    $rejected = false;
    try {
        $config->saveTestMailbox('keine-adresse');
    } catch (\App\Exceptions\ValidationException $exception) {
        $rejected = isset($exception->errors()['exchange_test_mailbox']);
    }
    Assert::true($rejected, 'Ungueltige Adresse wird abgelehnt.');
    Assert::same('pruefung@example.local', $config->testMailbox(), 'Ungueltige Eingabe aendert nichts.');
    $config->saveTestMailbox('');
    Assert::same('', $config->testMailbox(), 'Leer setzt auf das Dienstkonto zurueck.');
});

Runner::test('Orvanta DAG: Prioritaet 3 entscheidet nach mittlerer Antwortzeit', function (): void {
    $parts = orvantaPool();
    // Die Hostliste wird je Anfrage neu gelesen: jede Stufe prueft mit einer
    // frischen Instanz, wie es zwei getrennte Aufrufe auch taeten.
    $pool = static function (string $key) use ($parts): OrvantaExchangePool {
        return new OrvantaExchangePool($parts['hosts'], $parts['config'], static fn (): string => $key);
    };
    $gleichstand = static function () use ($parts): void {
        $parts['pdo']->exec('DELETE FROM orvanta_exchange_sessions');
        $parts['pdo']->exec('UPDATE orvanta_exchange_hosts SET last_session_at = NULL');
    };

    $parts['pdo']->exec("UPDATE orvanta_exchange_hosts SET latency_ms = 400, latency_samples = 5 WHERE host = 'mail01.example.local'");
    $parts['pdo']->exec("UPDATE orvanta_exchange_hosts SET latency_ms = 250, latency_samples = 5 WHERE host = 'mail02.example.local'");
    $parts['pdo']->exec("UPDATE orvanta_exchange_hosts SET latency_ms = 90, latency_samples = 5 WHERE host = 'mail03.example.local'");
    $gleichstand();
    Assert::same('mail03.example.local', $pool('s1')->session('s1')['host'], 'Der schnellste Host erhaelt die neue Sitzung.');

    // Ein noch nicht gemessener Host gilt als bester Wert und wird geprueft.
    $parts['pdo']->exec("UPDATE orvanta_exchange_hosts SET latency_ms = 0, latency_samples = 0 WHERE host = 'mail03.example.local'");
    $gleichstand();
    Assert::same('mail03.example.local', $pool('s2')->session('s2')['host'], 'Ohne Messung ist ein Host zuerst an der Reihe.');

    // Eine frische Stoerung macht den Host kurz nachrangig.
    $parts['pdo']->exec("UPDATE orvanta_exchange_hosts SET latency_ms = 90, latency_samples = 5, last_ok = 0, last_error = 'Timeout', last_check_at = " . $parts['pdo']->quote(date('Y-m-d H:i:s')) . " WHERE host = 'mail03.example.local'");
    $gleichstand();
    Assert::same('mail02.example.local', $pool('s3')->session('s3')['host'], 'Ein frisch gestoerter Host wird gemieden.');

    // Nach der Wartezeit wird der gestoerte Host wieder geprueft (Selbstheilung).
    $parts['pdo']->exec("UPDATE orvanta_exchange_hosts SET last_check_at = '2024-01-01 00:00:00' WHERE host = 'mail03.example.local'");
    $gleichstand();
    Assert::same('mail03.example.local', $pool('s4')->session('s4')['host'], 'Nach der Wartezeit wird der Host wieder geprueft.');
});

Runner::test('Orvanta DAG: Sitzung bleibt auf ihrem Host, Wartung leitet um', function (): void {
    $parts = orvantaPool();
    $pool = static function (string $key) use ($parts): OrvantaExchangePool {
        return new OrvantaExchangePool($parts['hosts'], $parts['config'], static fn (): string => $key);
    };
    $id = (int) $parts['pdo']->query("SELECT id FROM orvanta_exchange_hosts WHERE host = 'mail01.example.local'")->fetchColumn();

    Assert::same('mail01.example.local', $pool('session-a')->session('session-a')['host'], 'Neue Sitzung auf dem primaeren Host.');
    Assert::same('mail01.example.local', $pool('session-a')->session('session-a')['host'], 'Sitzungsaffinitaet: die Sitzung bleibt auf ihrem Host.');

    // Der Host wird in Wartung genommen: die Sitzung wechselt sofort.
    $parts['hosts']->setActive($id, false);
    Assert::same('mail02.example.local', $pool('session-a')->session('session-a')['host']);
    $row = $parts['hosts']->findSession(sha1('orvanta-dag:session-a'));
    Assert::same('mail02.example.local', (string) $row['host']);
    Assert::same(1, (int) $row['failovers'], 'Die Umleitung wird gezaehlt.');

    // Ein entfernter Host wird ebenfalls verlassen (der in den Einstellungen
    // eingetragene Server bleibt immer Mitglied der DAG).
    $mail02 = (int) $parts['pdo']->query("SELECT id FROM orvanta_exchange_hosts WHERE host = 'mail02.example.local'")->fetchColumn();
    $parts['hosts']->deleteHost($mail02, 'mail02.example.local');
    Assert::same('mail03.example.local', $pool('session-b')->session('session-b')['host'], 'Ein entfernter Host wird verlassen.');
    Assert::same(['mail01.example.local', 'mail03.example.local'], array_column($parts['hosts']->hosts(), 'host'));
});

Runner::test('Orvanta DAG: currentHost nennt den Host der Sitzung (Tooltipp im Fussbereich)', function (): void {
    $parts = orvantaPool();
    $pool = static function (string $key) use ($parts): OrvantaExchangePool {
        return new OrvantaExchangePool($parts['hosts'], $parts['config'], static fn (): string => $key);
    };

    $host = $pool('session-a')->currentHost();
    Assert::same('mail01.example.local', (string) $host['host'], 'Die neue Sitzung liegt auf dem primaeren Host.');
    Assert::same('mail01.example.local', (string) $pool('session-a')->currentHost()['host'], 'Der Host bleibt ueber Aufrufe hinweg derselbe.');

    // Nach einer Umleitung (hier: Wartung) zeigt der Tooltipp den neuen Host.
    $id = (int) $parts['pdo']->query("SELECT id FROM orvanta_exchange_hosts WHERE host = 'mail01.example.local'")->fetchColumn();
    $parts['hosts']->setActive($id, false);
    Assert::same('mail02.example.local', (string) $pool('session-a')->currentHost()['host']);

    // Ohne Sitzung (CLI, Archivierungs-Worker) gilt der zuerst gewaehlte Host.
    Assert::same('mail02.example.local', (string) $pool('')->currentHost()['host'], 'Ohne Sitzung wird nichts gespeichert, aber ein Host genannt.');
    Assert::null($parts['hosts']->findSession(sha1('orvanta-dag:')));
});

Runner::test('Orvanta DAG: Tooltipp an der Verbindungsanzeige nennt den aktuellen Host', function (): void {
    $render = static function (array $overrides): string {
        $orvanta = [
            'user' => ['name' => 'Erika Muster', 'email' => 'erika.muster@firma.local', 'username' => 'emuster'],
            'backend' => 'exchange',
            'capabilities' => [],
            'defaultModule' => 'inbox',
            'pollInterval' => 30,
            'reminderLead' => 15,
            'officeAvailable' => false,
            'nextcloudAvailable' => false,
            'cacheFolder' => 'Orvanta',
            'cacheQuota' => 0,
            'demo' => false,
            'owaUrl' => '',
            'aiAvailable' => false,
            'spellcheckAvailable' => false,
            'signature' => '',
            'exchangeHost' => 'mail02.example.local',
        ];

        return View::render('orvanta.index', [
            'orvanta' => array_replace($orvanta, $overrides),
            'csrfToken' => 'token',
            'assetVersion' => '1',
        ]);
    };

    $html = $render([]);
    Assert::contains('data-ov-status-conn title="Aktueller Exchange-Host: mail02.example.local"', $html, 'Der Tooltipp sitzt an der Verbindungsanzeige.');
    Assert::contains('erika.muster@firma.local</span>', $html, 'Die Mail-Adresse im Fussbereich bleibt ohne Tooltipp.');

    Assert::false(str_contains($render(['exchangeHost' => '']), 'Aktueller Exchange-Host'), 'Ohne Exchange-Host gibt es keinen Tooltipp.');
    Assert::false(str_contains($render(['backend' => 'proxy', 'exchangeHost' => '']), 'Aktueller Exchange-Host'), 'Am SMTP-/IMAP-Proxy gibt es keinen Tooltipp.');
});

Runner::test('Orvanta DAG: Ausfall eines Hosts leitet die Sitzung um (Failover)', function (): void {
    $parts = orvantaPool();
    Assert::same('mail01.example.local', $parts['pool']->session('session-a')['host']);

    // Der primaere Host antwortet mit einem Serverfehler.
    $parts['transport']->byUrl['https://mail01.example.local/EWS/Exchange.asmx'] = ['status' => 503, 'body' => '', 'error' => null];
    $folders = $parts['exchange']->folders('anna@example.local');
    Assert::true(count($folders) >= 5, 'Die Anfrage gelingt auf dem naechsten Host.');
    $urls = array_column($parts['transport']->requests, 'url');
    Assert::same('https://mail01.example.local/EWS/Exchange.asmx', $urls[0], 'Der erste Aufruf geht an den primaeren Host.');
    Assert::same(
        ['https://mail02.example.local/EWS/Exchange.asmx'],
        array_values(array_unique(array_slice($urls, 1))),
        'Nach dem Ausfall laufen alle weiteren Aufrufe ueber den naechsten Host.'
    );

    $row = $parts['hosts']->findSession(sha1('orvanta-dag:session-a'));
    Assert::same('mail02.example.local', (string) $row['host'], 'Die Zuordnung zeigt dauerhaft auf den neuen Host.');
    Assert::same(1, (int) $row['failovers']);
    $failed = $parts['hosts']->find((int) $parts['pdo']->query("SELECT id FROM orvanta_exchange_hosts WHERE host = 'mail01.example.local'")->fetchColumn());
    Assert::same(0, (int) $failed['last_ok']);
    Assert::same('HTTP 503', (string) $failed['last_error']);
    Assert::same(1, (int) $failed['failures']);

    // Auch der naechste Aufruf laeuft ueber den neuen Host.
    $parts['transport']->requests = [];
    $parts['exchange']->folders('anna@example.local');
    Assert::same(
        ['https://mail02.example.local/EWS/Exchange.asmx'],
        array_values(array_unique(array_column($parts['transport']->requests, 'url')))
    );
    Assert::same(0, (int) $parts['hosts']->find((int) $parts['pdo']->query("SELECT id FROM orvanta_exchange_hosts WHERE host = 'mail02.example.local'")->fetchColumn())['failures'], 'Erfolg setzt die Fehlerzaehlung zurueck.');
});

Runner::test('Orvanta DAG: abgelehnte Anmeldung ist kein Host-Ausfall', function (): void {
    $parts = orvantaPool();
    $parts['transport']->byUrl['https://mail01.example.local/EWS/Exchange.asmx'] = ['status' => 401, 'body' => '', 'error' => null, 'auth_offered' => ['NTLM']];
    try {
        $parts['exchange']->folders('anna@example.local');
        Assert::true(false, 'OrvantaException erwartet.');
    } catch (OrvantaException $exception) {
        Assert::true($exception->getMessage() !== '');
    }
    Assert::same(1, count($parts['transport']->requests), '401 betrifft alle Hosts der DAG: keine Umleitung.');
    $session = $parts['hosts']->findSession(sha1('orvanta-dag:session-a'));
    Assert::same('mail01.example.local', (string) $session['host']);
});

Runner::test('Orvanta DAG: Verbindungstest eines Hosts misst die Antwortzeit', function (): void {
    $parts = orvantaPool();
    $host = $parts['pool']->hosts()[1];
    Assert::same('mail02.example.local', $host['host']);
    $result = $parts['exchange']->testHost($host, 'anna@example.local');
    Assert::true($result['ok']);
    Assert::contains('mail02.example.local', $result['message']);
    Assert::true($result['latency_ms'] >= 0);
    Assert::same('https://mail02.example.local/EWS/Exchange.asmx', $parts['transport']->requests[0]['url'], 'Der Test geht an den geprueften Host.');
    Assert::contains('<t:ExchangeImpersonation>', $parts['transport']->last());

    $parts['transport']->byUrl['https://mail02.example.local/EWS/Exchange.asmx'] = ['status' => 0, 'body' => '', 'error' => 'Verbindung fehlgeschlagen'];
    try {
        $parts['exchange']->testHost($host);
        Assert::true(false, 'OrvantaException erwartet.');
    } catch (OrvantaException $exception) {
        Assert::contains('mail02.example.local', $exception->getMessage());
        Assert::contains('Verbindung fehlgeschlagen', $exception->getMessage());
    }
});

Runner::test('Orvanta DAG: gestoerter Host mit veraltetem Status wird automatisch nachgeprueft', function (): void {
    $parts = orvantaPool();
    $id = (int) $parts['pdo']->query("SELECT id FROM orvanta_exchange_hosts WHERE host = 'mail02.example.local'")->fetchColumn();
    // Ausfall (z. B. VM-Snapshot): der Host gilt als gestoert. Danach liegt der
    // Orvanta-Verkehr auf anderen Hosts der DAG (Sitzungsaffinitaet), der Host
    // erhaelt keine Antwort mehr und bliebe dauerhaft „Gestoert“ – bis ihn
    // bisher ein Administrator mit „Verbindung testen“ zuruecksetzte.
    $parts['hosts']->recordFailure($id, 'Timeout', '2024-01-01 08:00:00');
    $pool = new OrvantaExchangePool($parts['hosts'], $parts['config'], static fn (): string => 'session-a');
    $health = new OrvantaHostHealthService($pool, $parts['exchange'], $parts['config']);
    Assert::same('offline', $pool->overview()['hosts'][1]['status'], 'Der Host gilt zunaechst als gestoert.');

    $results = $health->refresh();
    Assert::same(1, count($results), 'Nur der gestoerte Host wird geprueft.');
    Assert::same('mail02.example.local', $results[0]['host']);
    Assert::true($results[0]['ok'], 'Der wieder erreichbare Host antwortet.');
    Assert::same(1, count($parts['transport']->requests), 'Nur ein Aufruf an Exchange.');
    Assert::same('https://mail02.example.local/EWS/Exchange.asmx', $parts['transport']->requests[0]['url'], 'Geprueft wird der gestoerte Host.');
    Assert::same('online', $pool->overview()['hosts'][1]['status'], 'Der Status ist ohne Zutun des Administrators wieder aktuell.');
    Assert::same(1, (int) $parts['pdo']->query("SELECT last_ok FROM orvanta_exchange_hosts WHERE host = 'mail02.example.local'")->fetchColumn(), 'Der neue Zustand ist gespeichert.');
    Assert::same([], $health->refresh(), 'Der Zustand ist jetzt aktuell: kein weiterer Aufruf.');
    Assert::same(1, count($parts['transport']->requests), 'Der Aufruf wird nicht wiederholt.');
});

Runner::test('Orvanta DAG: ein weiterhin nicht erreichbarer Host bleibt gestoert', function (): void {
    $parts = orvantaPool();
    $id = (int) $parts['pdo']->query("SELECT id FROM orvanta_exchange_hosts WHERE host = 'mail02.example.local'")->fetchColumn();
    $parts['hosts']->recordFailure($id, 'Timeout', '2024-01-01 08:00:00');
    $parts['transport']->byUrl['https://mail02.example.local/EWS/Exchange.asmx'] = ['status' => 0, 'body' => '', 'error' => 'Verbindung fehlgeschlagen'];
    $pool = new OrvantaExchangePool($parts['hosts'], $parts['config'], static fn (): string => 'session-a');
    $health = new OrvantaHostHealthService($pool, $parts['exchange'], $parts['config']);

    $results = $health->refresh();
    Assert::same(1, count($results), 'Auch eine erfolglose Pruefung wird gemeldet.');
    Assert::false($results[0]['ok'], 'Der Host antwortet weiterhin nicht.');
    Assert::contains('Verbindung fehlgeschlagen', $results[0]['message']);
    Assert::same('offline', $pool->overview()['hosts'][1]['status'], 'Der Status bleibt gestoert.');
    $row = $parts['pdo']->query("SELECT last_ok, failures, last_error FROM orvanta_exchange_hosts WHERE host = 'mail02.example.local'")->fetch();
    Assert::same(0, (int) $row['last_ok']);
    Assert::same(2, (int) $row['failures'], 'Die erneute Stoerung wird gezaehlt.');
    Assert::contains('Verbindung fehlgeschlagen', (string) $row['last_error'], 'Der aktuelle Fehler steht in der Statusfuehrung.');
});

Runner::test('Orvanta DAG: frische Stoerungen werden nicht sofort nachgeprueft', function (): void {
    $parts = orvantaPool();
    $pool = new OrvantaExchangePool($parts['hosts'], $parts['config'], static fn (): string => 'session-a');
    $pool->recordFailure($pool->hosts()[1], 'Timeout');
    $health = new OrvantaHostHealthService($pool, $parts['exchange'], $parts['config']);

    Assert::same([], $health->refresh(), 'Ein gerade geprueftes Ergebnis bleibt stehen.');
    Assert::same('offline', $pool->overview()['hosts'][1]['status']);
    Assert::same(0, count($parts['transport']->requests), 'Kein Aufruf an Exchange.');
});

Runner::test('Orvanta DAG: ungepruefte und in Wartung genommene Hosts werden nicht automatisch geprueft', function (): void {
    $parts = orvantaPool();
    $pool = new OrvantaExchangePool($parts['hosts'], $parts['config'], static fn (): string => 'session-a');
    $health = new OrvantaHostHealthService($pool, $parts['exchange'], $parts['config']);
    // „Ungeprueft“ ist keine veraltete Stoerung: diesen Zustand setzt allein der
    // manuelle Verbindungstest (sonst markierte eine fehlende Pruefpostfach-
    // Konfiguration jeden Host als gestoert).
    Assert::same([], $health->refresh(), 'Ungepruefte Hosts bleiben dem manuellen Test vorbehalten.');

    $id = (int) $parts['pdo']->query("SELECT id FROM orvanta_exchange_hosts WHERE host = 'mail02.example.local'")->fetchColumn();
    $parts['hosts']->setActive($id, false);
    $parts['hosts']->recordFailure($id, 'Timeout', '2024-01-01 08:00:00');
    $wartung = new OrvantaExchangePool($parts['hosts'], $parts['config'], static fn (): string => 'session-a');
    Assert::same([], (new OrvantaHostHealthService($wartung, $parts['exchange'], $parts['config']))->refresh(), 'Ein Host in Wartung wird nicht geprueft.');
    Assert::same(0, count($parts['transport']->requests), 'Kein Aufruf an Exchange.');
});

Runner::test('Orvanta DAG: ein Durchgang prueft nur die aeltesten Stoerungen (MAX_CHECKS)', function (): void {
    $parts = orvantaPool([], ['mail02.example.local', 'mail03.example.local', 'mail04.example.local', 'mail05.example.local']);
    foreach ([
        'mail01.example.local' => '2024-05-01 08:00:00',
        'mail02.example.local' => '2024-04-01 08:00:00',
        'mail03.example.local' => '2024-03-01 08:00:00',
        'mail04.example.local' => '2024-02-01 08:00:00',
        'mail05.example.local' => '2024-01-01 08:00:00',
    ] as $host => $checkedAt) {
        $id = (int) $parts['pdo']->query('SELECT id FROM orvanta_exchange_hosts WHERE host = ' . $parts['pdo']->quote($host))->fetchColumn();
        $parts['hosts']->recordFailure($id, 'Timeout', $checkedAt);
    }
    $pool = new OrvantaExchangePool($parts['hosts'], $parts['config'], static fn (): string => 'session-a');
    $results = (new OrvantaHostHealthService($pool, $parts['exchange'], $parts['config']))->refresh();

    Assert::same(OrvantaHostHealthService::MAX_CHECKS, count($results), 'Hoechstens MAX_CHECKS Hosts je Durchgang.');
    Assert::same(
        ['mail05.example.local', 'mail04.example.local', 'mail03.example.local', 'mail02.example.local'],
        array_column($results, 'host'),
        'Die aeltesten Pruefungen zuerst, damit kein Host liegen bleibt.'
    );
});

Runner::test('Orvanta DAG: Kennzahlen werden gemittelt und Uebersicht aufgebaut', function (): void {
    $parts = orvantaPool();
    $host = $parts['pool']->hosts()[0];
    Assert::same('mail01.example.local', $host['host']);

    $parts['pool']->recordSuccess($host, 100);
    $parts['pool']->recordSuccess($parts['hosts']->find((int) $host['id']), 300);
    $stored = $parts['hosts']->find((int) $host['id']);
    Assert::same(200, (int) $stored['latency_ms'], 'Gleitendes Mittel der Antwortzeiten.');
    Assert::same(2, (int) $stored['latency_samples']);
    Assert::same(300, (int) $stored['last_latency_ms']);
    Assert::same(1, (int) $stored['last_ok']);

    $parts['pool']->recordFailure($parts['hosts']->find((int) $host['id']), 'Zeitueberschreitung');
    Assert::same(1, (int) $parts['hosts']->find((int) $host['id'])['failures']);
    $parts['pool']->recordSuccess($parts['hosts']->find((int) $host['id']), 50);
    $stored = $parts['hosts']->find((int) $host['id']);
    Assert::same(0, (int) $stored['failures'], 'Eine erfolgreiche Messung loescht den Fehler.');
    Assert::same('', (string) $stored['last_error']);
    Assert::same(150, (int) $stored['latency_ms'], 'Jede Messung zaehlt fuer das Mittel.');

    $parts['setSession']('session-a');
    $parts['pool']->session('session-a', 'anna@example.local');
    $overview = $parts['pool']->overview();
    Assert::same(3, $overview['totals']['hosts']);
    Assert::same(3, $overview['totals']['active']);
    Assert::same(1, $overview['totals']['online'], 'Nur der gepruefte Host gilt als online.');
    Assert::same(1, $overview['totals']['sessions']);
    Assert::same(1, count($overview['sessions']));
    Assert::same('anna@example.local', $overview['sessions'][0]['user']);
    Assert::same('mail02.example.local', $overview['sessions'][0]['host'], 'Die Sitzung geht an den Host ohne Messung.');
    Assert::same('10.20.30.40', $overview['sessions'][0]['client_ip'], 'Die IP des Clients steht in der Sitzungsliste.');
    Assert::same('pc-anna.example.local', $overview['sessions'][0]['client_host']);
    Assert::same('Ø 150 ms', $overview['hosts'][0]['latency_label']);
    Assert::same('50 ms', $overview['hosts'][0]['last_latency_label']);
    Assert::same('online', $overview['hosts'][0]['status']);
    Assert::same('unknown', $overview['hosts'][1]['status'], 'Ein nie gepruefter Host bleibt offen.');
    Assert::same('–', $overview['hosts'][1]['latency_label'], 'Ohne Messung bleibt die Latenz offen.');
    Assert::same('https://mail01.example.local/EWS/Exchange.asmx', $overview['hosts'][0]['url']);

    // Alte Sitzungen werden aufgeraeumt.
    $parts['pdo']->exec("UPDATE orvanta_exchange_sessions SET last_seen_at = '2020-01-01 00:00:00'");
    Assert::same(1, $parts['pool']->purge());
    Assert::same(0, $parts['pool']->purge());
});

Runner::test('Orvanta DAG: Clientangaben entstehen beim Sitzungsbeginn und ueberdauern Umleitungen', function (): void {
    $parts = orvantaPool();
    $make = static function (string $key, string $ip = '', string $host = '') use ($parts): OrvantaExchangePool {
        return new OrvantaExchangePool($parts['hosts'], $parts['config'], static fn (): string => $key, static fn (): array => ['ip' => $ip, 'host' => $host]);
    };
    $hash = sha1('orvanta-dag:session-a');
    $id = (int) $parts['pdo']->query("SELECT id FROM orvanta_exchange_hosts WHERE host = 'mail01.example.local'")->fetchColumn();

    Assert::same('mail01.example.local', $make('session-a', '10.20.30.40', 'pc-anna.example.local')->session('session-a', 'anna@example.local')['host']);
    $row = $parts['hosts']->findSession($hash);
    Assert::same('10.20.30.40', (string) $row['client_ip'], 'Die IP des Clients wird beim Sitzungsbeginn vermerkt.');
    Assert::same('pc-anna.example.local', (string) $row['client_host'], 'Der Hostname des Clients wird beim Sitzungsbeginn vermerkt.');

    // Weitere Aufrufe derselben Sitzung schreiben die Clientangaben nicht neu.
    $make('session-a', '192.168.7.9', 'pc-bernd.example.local')->session('session-a', 'anna@example.local');
    $row = $parts['hosts']->findSession($hash);
    Assert::same('10.20.30.40', (string) $row['client_ip'], 'Der Client bleibt der des Sitzungsbeginns.');
    Assert::same('pc-anna.example.local', (string) $row['client_host']);

    // Eine Umleitung (Wartung, Ausfall) laesst die Clientangaben stehen.
    $parts['hosts']->setActive($id, false);
    Assert::same('mail02.example.local', $make('session-a', '192.168.7.9', 'pc-bernd.example.local')->session('session-a', 'anna@example.local')['host']);
    $row = $parts['hosts']->findSession($hash);
    Assert::same(1, (int) $row['failovers'], 'Die Sitzung wurde umgeleitet.');
    Assert::same('10.20.30.40', (string) $row['client_ip']);
    Assert::same('pc-anna.example.local', (string) $row['client_host']);

    // Eine neue Sitzung uebernimmt den Client ihres eigenen Aufrufs.
    $make('session-b', '192.168.7.9', 'pc-bernd.example.local')->session('session-b', 'bernd@example.local');
    $row = $parts['hosts']->findSession(sha1('orvanta-dag:session-b'));
    Assert::same('192.168.7.9', (string) $row['client_ip']);
    Assert::same('pc-bernd.example.local', (string) $row['client_host']);

    // Ohne Client (CLI, Archivierungs-Worker) bleibt die Zeile ohne Angaben.
    $make('session-c')->session('session-c', 'carla@example.local');
    $row = $parts['hosts']->findSession(sha1('orvanta-dag:session-c'));
    Assert::same('', (string) $row['client_ip']);
    Assert::same('', (string) $row['client_host']);
});

Runner::test('Orvanta DAG: Sitzungskennung gilt je Benutzer und Client, nicht je PHP-Sitzung', function (): void {
    $anna = ['username' => 'amuster', 'source_key' => 'FIRMA', 'office_uid' => 'amuster'];
    // Eine neue PHP-Sitzungs-ID (Windows-/Admin-Anmeldung regeneriert sie,
    // zweiter Browser-Tab) aendert die Kennung nicht: dieselbe Zuordnung,
    // derselbe Host – das Postfach wird nie auf zwei DAG-Hosts verteilt.
    $key = OrvantaExchangePool::affinityKey($anna, '10.20.30.40', 'php-sess-1');
    Assert::same('user:amuster|client:10.20.30.40', $key);
    Assert::same($key, OrvantaExchangePool::affinityKey($anna, '10.20.30.40', 'php-sess-2'), 'Neue PHP-Sitzung, gleiche Kennung.');
    Assert::same($key, OrvantaExchangePool::affinityKey(['username' => 'AMuster', 'source_key' => 'firma', 'office_uid' => 'AMUSTER'], '10.20.30.40', 'x'), 'Gross-/Kleinschreibung spielt keine Rolle.');

    // Ein anderer Client desselben Benutzers ist eine eigene Sitzung.
    Assert::same('user:amuster|client:192.168.7.9', OrvantaExchangePool::affinityKey($anna, '192.168.7.9', 'php-sess-1'));

    // Ohne Office-Kennung: Benutzername und Quelle.
    Assert::same('user:bernd@zweig|client:10.0.0.1', OrvantaExchangePool::affinityKey(['username' => 'bernd', 'source_key' => 'ZWEIG'], '10.0.0.1', 's'));
    Assert::same('user:bernd|client:10.0.0.1', OrvantaExchangePool::affinityKey(['username' => 'bernd'], '10.0.0.1', 's'));

    // Ohne erkannten Benutzer gilt die PHP-Sitzung, ohne diese (CLI) nichts.
    Assert::same('php-sess-1', OrvantaExchangePool::affinityKey(null, '10.0.0.1', 'php-sess-1'));
    Assert::same('php-sess-1', OrvantaExchangePool::affinityKey(['username' => ''], '10.0.0.1', 'php-sess-1'));
    Assert::same('', OrvantaExchangePool::affinityKey($anna, '10.0.0.1', ''));

    // Im Pool: zwei PHP-Sitzungen desselben Benutzers am selben Client
    // teilen sich eine Zuordnung – auch wenn Fair-use einen anderen Host
    // bevorzugen wuerde.
    $parts = orvantaPool();
    $make = static function (string $sessionId) use ($parts, $anna): OrvantaExchangePool {
        $key = OrvantaExchangePool::affinityKey($anna, '10.20.30.40', $sessionId);

        return new OrvantaExchangePool($parts['hosts'], $parts['config'], static fn (): string => $key);
    };
    $first = $make('php-sess-1');
    Assert::same('mail01.example.local', $first->session($first->sessionKey(), 'anna@example.local')['host']);
    $second = $make('php-sess-2');
    Assert::same('mail01.example.local', $second->session($second->sessionKey(), 'anna@example.local')['host'], 'Neue PHP-Sitzung bleibt auf dem Host der bestehenden Zuordnung.');
    $counts = $parts['hosts']->sessionCounts('2000-01-01 00:00:00');
    Assert::same(['mail01.example.local' => 1], $counts, 'Genau eine Sitzung je Benutzer und Client.');
});

Runner::test('Orvanta DAG: ohne Benutzer begonnene Sitzung erhaelt den Benutzer nach (kein „unbekannt“)', function (): void {
    $parts = orvantaPool();
    $pool = static function (string $key) use ($parts): OrvantaExchangePool {
        return new OrvantaExchangePool($parts['hosts'], $parts['config'], static fn (): string => $key);
    };
    $hash = sha1('orvanta-dag:session-a');

    // Hostanzeige im Fussbereich vor dem ersten EWS-Aufruf: mit Adresse.
    Assert::same('mail01.example.local', (string) $pool('session-a')->currentHost('anna@example.local')['host']);
    Assert::same('anna@example.local', (string) $parts['hosts']->findSession($hash)['user_uid'], 'currentHost() vermerkt den Benutzer.');

    // Ohne Adresse begonnen (z. B. Keep-alive): der erste Aufruf mit Adresse traegt sie nach.
    $pool('session-b')->currentHost();
    Assert::same('', (string) $parts['hosts']->findSession(sha1('orvanta-dag:session-b'))['user_uid']);
    $pool('session-b')->session('session-b', 'bernd@example.local');
    Assert::same('bernd@example.local', (string) $parts['hosts']->findSession(sha1('orvanta-dag:session-b'))['user_uid']);

    // Ein einmal vermerkter Benutzer wird nicht ueberschrieben (Zusatzpostfach).
    $pool('session-b')->session('session-b', 'team@example.local');
    Assert::same('bernd@example.local', (string) $parts['hosts']->findSession(sha1('orvanta-dag:session-b'))['user_uid']);
});

Runner::test('Orvanta DAG: ohne Clientspalten (Migration 044) bleibt die Sitzungsliste nutzbar', function (): void {
    $parts = orvantaConfig(['exchange_host' => 'mail01.example.local']);
    $pdo = $parts['pdo'];
    $pdo->exec('DROP TABLE orvanta_exchange_sessions');
    $pdo->exec('CREATE TABLE orvanta_exchange_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_hash CHAR(40) NOT NULL UNIQUE,
        user_uid VARCHAR(190) NOT NULL DEFAULT \'\',
        host VARCHAR(190) NOT NULL,
        failovers INTEGER NOT NULL DEFAULT 0,
        requests INTEGER NOT NULL DEFAULT 0,
        started_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL
    )');
    $hosts = new OrvantaExchangeHostRepository($pdo);
    $hosts->insert('mail01.example.local', '', true, 0);
    $pool = new OrvantaExchangePool(
        $hosts,
        $parts['config'],
        static fn (): string => 'session-a',
        static fn (): array => ['ip' => '10.20.30.40', 'host' => 'pc-anna.example.local']
    );

    Assert::same('mail01.example.local', $pool->session('session-a', 'anna@example.local')['host'], 'Die Zuordnung der Sitzung greift auch ohne Clientspalten.');
    $overview = $pool->overview();
    Assert::same(1, count($overview['sessions']), 'Die Sitzungsliste bleibt gefuellt.');
    Assert::same('anna@example.local', $overview['sessions'][0]['user']);
    Assert::same('', $overview['sessions'][0]['client_ip']);
    Assert::same('', $overview['sessions'][0]['client_host']);
    Assert::same(1, $overview['totals']['sessions'], 'Die Sitzung zaehlt weiter als verbunden.');
});

Runner::test('Orvanta DAG: ohne Hosttabelle greift nur der konfigurierte Server', function (): void {
    $parts = orvantaConfig(['exchange_host' => 'mail.example.local']);
    $hosts = new OrvantaExchangeHostRepository(new PDO('sqlite::memory:'));
    $pool = new OrvantaExchangePool($hosts, $parts['config'], static fn (): string => 'session-a');
    $hosts_ = $pool->hosts();
    Assert::same(1, count($hosts_));
    Assert::same('mail.example.local', $hosts_[0]['host']);
    Assert::same(0, (int) $hosts_[0]['id'], 'Ohne Tabelle wird nichts gespeichert.');
    Assert::same('mail.example.local', $pool->session('session-a')['host']);
    Assert::same(1, $pool->overview()['totals']['hosts']);

    // Kennzahlen ohne Tabelle duerfen keinen Fehler ausloesen.
    $pool->recordSuccess($hosts_[0], 120);
    $pool->recordFailure($hosts_[0], 'kaputt');
    Assert::same(0, $pool->purge());
});

Runner::test('Orvanta DAG: der konfigurierte Server bleibt der primaere Host', function (): void {
    $parts = orvantaConfig(['exchange_host' => 'mail01.example.local']);
    $hosts = new OrvantaExchangeHostRepository($parts['pdo']);
    $config = new OrvantaConfigService($parts['repository'], orvantaSecrets(), $hosts);
    $config->save(dagSettings('mail01.example.local'));
    Assert::same(['mail01.example.local'], array_column($hosts->hosts(), 'host'));
    Assert::same(1, (int) $hosts->hosts()[0]['is_primary']);

    $hosts->insert('mail02.example.local', '', false, 1);
    $hosts->insert('mail03.example.local', '', false, 2);
    $config->save(dagSettings('mail02.example.local'));
    $rows = $hosts->hosts();
    Assert::same(['mail02.example.local', 'mail03.example.local'], array_column($rows, 'host'), 'Ein umbenannter Server ersetzt die Primaerzeile.');
    Assert::same(1, (int) $rows[0]['is_primary']);
    Assert::same(0, (int) $rows[1]['is_primary']);
    Assert::same('', (string) $rows[0]['ews_url'], 'Ohne eigene Adresse wird nichts erfunden.');

    // Der Standardpfad wird erst bei der Verwendung abgeleitet.
    $pool = new OrvantaExchangePool($hosts, $config, static fn (): string => '');
    Assert::same('https://mail02.example.local/EWS/Exchange.asmx', $pool->overview()['hosts'][0]['url']);
});

Runner::test('Orvanta DAG: Sitzungsaffinitaet haelt die Sitzung aktiv (letzte Aktivitaet, Aufrufe)', function (): void {
    $parts = orvantaPool();
    $hash = sha1('orvanta-dag:session-a');
    Assert::same('mail01.example.local', $parts['pool']->session('session-a', 'anna@example.local')['host']);
    $parts['pdo']->exec("UPDATE orvanta_exchange_sessions SET last_seen_at = '2020-01-01 00:00:00'");

    Assert::same('mail01.example.local', $parts['pool']->session('session-a', 'anna@example.local')['host']);
    $row = $parts['hosts']->findSession($hash);
    Assert::true((string) $row['last_seen_at'] > '2020-01-01 00:00:00', 'Jeder Aufruf vermerkt die Aktivitaet.');
    Assert::same(2, (int) $row['requests']);
    Assert::same(1, $parts['pool']->overview()['totals']['sessions'], 'Die laufende Sitzung zaehlt weiter als verbunden.');

    // Die Anzeige (Tooltipp, Keep-alive) vermerkt die Aktivitaet, zaehlt aber keinen Exchange-Aufruf.
    $parts['pdo']->exec("UPDATE orvanta_exchange_sessions SET last_seen_at = '2020-01-01 00:00:00'");
    Assert::same('mail01.example.local', (string) $parts['pool']->currentHost()['host']);
    $row = $parts['hosts']->findSession($hash);
    Assert::true((string) $row['last_seen_at'] > '2020-01-01 00:00:00');
    Assert::same(2, (int) $row['requests']);
    Assert::same(0, $parts['pool']->purge(), 'Eine aktive Sitzung wird nicht aufgeraeumt.');
});

Runner::test('Orvanta DAG: fachlicher SOAP-Fehler (HTTP 500) ist kein Host-Ausfall', function (): void {
    $parts = orvantaPool();
    $fault = static fn (string $code): array => [
        'status' => 500,
        'body' => '<?xml version="1.0" encoding="utf-8"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault>'
            . '<faultcode>a:' . $code . '</faultcode><faultstring xml:lang="de-DE">Fehler</faultstring>'
            . '<detail><e:ResponseCode xmlns:e="http://schemas.microsoft.com/exchange/services/2006/errors">' . $code . '</e:ResponseCode></detail>'
            . '</s:Fault></s:Body></s:Envelope>',
        'error' => null,
    ];
    $mail01 = 'https://mail01.example.local/EWS/Exchange.asmx';
    $parts['transport']->byUrl[$mail01] = $fault('ErrorNonExistentMailbox');
    try {
        $parts['exchange']->folders('fehlt@example.local');
        Assert::true(false, 'OrvantaException erwartet.');
    } catch (OrvantaException) {
    }
    Assert::same([$mail01], array_values(array_unique(array_column($parts['transport']->requests, 'url'))), 'Kein Wechsel des Hosts.');
    $id = (int) $parts['pdo']->query("SELECT id FROM orvanta_exchange_hosts WHERE host = 'mail01.example.local'")->fetchColumn();
    Assert::same(0, (int) $parts['hosts']->find($id)['failures'], 'Der Host gilt nicht als gestoert.');
    Assert::same('mail01.example.local', (string) $parts['hosts']->findSession(sha1('orvanta-dag:session-a'))['host']);

    // Ein ueberlasteter Host dagegen wird verlassen.
    $parts['transport']->requests = [];
    $parts['transport']->byUrl[$mail01] = $fault('ErrorServerBusy');
    $parts['exchange']->folders('anna@example.local');
    Assert::same(1, (int) $parts['hosts']->find($id)['failures']);
    Assert::same('mail02.example.local', (string) $parts['hosts']->findSession(sha1('orvanta-dag:session-a'))['host']);
});

Runner::test('Orvanta DAG: aendernde Anfragen werden nach Zustellung nicht wiederholt (kein doppelter Versand)', function (): void {
    $mail = ['to' => ['max@example.local'], 'cc' => [], 'bcc' => [], 'subject' => 'Einmal', 'body' => '<p>Hallo</p>', 'html' => true, 'attachments' => []];
    $mail01 = 'https://mail01.example.local/EWS/Exchange.asmx';
    $creates = static fn (array $parts): array => array_values(array_filter(
        $parts['transport']->requests,
        static fn (array $request): bool => str_contains($request['xml'], '<m:CreateItem')
    ));

    // Zeitueberschreitung nach dem Senden: die Mail koennte bereits verschickt sein.
    $parts = orvantaPool();
    $parts['transport']->byUrl[$mail01] = ['status' => 0, 'body' => '', 'error' => 'Operation timed out', 'request_sent' => true];
    try {
        $parts['exchange']->send('anna@example.local', $mail);
        Assert::true(false, 'OrvantaException erwartet.');
    } catch (OrvantaException $exception) {
        Assert::contains('Operation timed out', $exception->getMessage());
    }
    Assert::same(1, count($creates($parts)), 'Kein zweiter Versand ueber einen anderen Host.');
    Assert::same('mail02.example.local', (string) $parts['hosts']->findSession(sha1('orvanta-dag:session-a'))['host'], 'Der naechste Aufruf nutzt trotzdem den neuen Host.');

    // Verbindung gar nicht erst zustande gekommen: gefahrlos auf dem naechsten Host wiederholen.
    $parts = orvantaPool();
    $parts['transport']->byUrl[$mail01] = ['status' => 0, 'body' => '', 'error' => 'Could not connect', 'request_sent' => false];
    $parts['exchange']->send('anna@example.local', $mail);
    $sent = $creates($parts);
    Assert::same(2, count($sent));
    Assert::same('https://mail02.example.local/EWS/Exchange.asmx', $sent[1]['url']);

    // Serverfehler ohne EWS-Antwort bei aendernder Anfrage: ebenfalls keine Wiederholung.
    $parts = orvantaPool();
    $parts['transport']->byUrl[$mail01] = ['status' => 503, 'body' => '', 'error' => null];
    try {
        $parts['exchange']->send('anna@example.local', $mail);
        Assert::true(false, 'OrvantaException erwartet.');
    } catch (OrvantaException) {
    }
    Assert::same(1, count($creates($parts)));
});

Runner::test('Orvanta DAG: nur EWS-Endpunkt ohne Hostnamen bleibt benutzbar', function (): void {
    $ews = 'https://ews.example.local/EWS/Exchange.asmx';
    $parts = orvantaConfig(['exchange_host' => '', 'exchange_ews_url' => $ews]);
    $hosts = new OrvantaExchangeHostRepository($parts['pdo']);
    $config = new OrvantaConfigService($parts['repository'], orvantaSecrets(), $hosts);
    Assert::same('ews.example.local', $config->primaryHost());
    $config->save(['exchange_host' => '', 'exchange_ews_url' => $ews] + dagSettings(''));
    Assert::same(['ews.example.local'], array_column($hosts->hosts(), 'host'), 'Der Host aus dem EWS-Endpunkt wird primaerer Host.');
    Assert::same($ews, (string) $hosts->hosts()[0]['ews_url']);

    $pool = new OrvantaExchangePool($hosts, $config, static fn (): string => 'session-a');
    $transport = new RecordingExchangeTransport();
    $exchange = new OrvantaExchangeService($transport, $config, null, $pool);
    Assert::true(count($exchange->folders('anna@example.local')) >= 5);
    Assert::same([$ews], array_values(array_unique(array_column($transport->requests, 'url'))));
});
