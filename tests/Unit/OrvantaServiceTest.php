<?php

declare(strict_types=1);

use App\Contracts\ExchangeTransportInterface;
use App\Exceptions\ValidationException;
use App\Repositories\OrvantaRepository;
use App\Security\SecretBox;
use App\Services\LdapClient;
use App\Services\Office\NextcloudFilesService;
use App\Services\Orvanta\DemoExchangeTransport;
use App\Services\Orvanta\EwsXml;
use App\Services\Orvanta\MailHtmlSanitizer;
use App\Services\Orvanta\OrvantaAttachmentService;
use App\Services\Orvanta\OrvantaConfigService;
use App\Services\Orvanta\OrvantaException;
use App\Services\Orvanta\OrvantaExchangeService;
use App\Services\Orvanta\OrvantaNotificationService;
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

    private DemoExchangeTransport $demo;

    public function __construct()
    {
        $this->demo = new DemoExchangeTransport();
    }

    public function post(string $url, string $xml, array $options): array
    {
        $this->requests[] = ['url' => $url, 'xml' => $xml, 'options' => $options];

        return $this->forced ?? $this->demo->post($url, $xml, $options);
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

    return $pdo;
}

function orvantaSecrets(): SecretBox
{
    $dir = sys_get_temp_dir() . '/orvanta-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return new SecretBox($dir . '/secrets.key');
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
    Assert::same('calendar', $config->get('default_folder'));
    Assert::same(45, $config->pollInterval());
    Assert::same('jdoe@example.local', $config->impersonationAddress(['username' => 'jdoe', 'email' => 'john@mail.example']));
    Assert::same('john@mail.example', orvantaConfig()['config']->impersonationAddress(['username' => 'jdoe', 'email' => 'john@mail.example']));
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

    $message = $parts['exchange']->message('demo@demo.local', (string) $first['id']);
    Assert::same((string) $first['id'], (string) $message['id']);
    Assert::true(is_string($message['body_html']));
    Assert::false(str_contains($message['body_html'], '<script'), 'Skripte werden entfernt.');
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
