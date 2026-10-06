<?php

declare(strict_types=1);

use App\Contracts\MailProxyTransportInterface;
use App\Contracts\OrvantaMailBackendInterface;
use App\Controllers\Admin\MailProxyController;
use App\Core\Logger;
use App\Core\Request;
use App\Exceptions\HttpException;
use App\Repositories\MailProxyRepository;
use App\Security\Csrf;
use App\Security\SecretBox;
use App\Services\MailProxy\HttpMailProxyTransport;
use App\Services\MailProxy\MailProxyAccount;
use App\Services\MailProxy\MailProxyCache;
use App\Services\MailProxy\MailProxyResolver;
use App\Services\MailProxy\MailProxyRoute;
use App\Services\MailProxy\MailProxyService;
use App\Services\MailProxy\OrvantaMailRouter;
use App\Services\MailProxy\ProxyMailBackend;
use App\Services\Orvanta\OrvantaException;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Simulierter Proxy-Dienst: zeichnet Operationen auf und liefert
 * vorbereitete Antworten (kein Netzwerk).
 */
final class FakeMailProxyTransport implements MailProxyTransportInterface
{
    /** @var list<array{operation:string,payload:array<string,mixed>}> */
    public array $requests = [];

    /** @var array<string,array<string,mixed>|OrvantaException> */
    public array $responses = [];

    public function request(string $operation, array $payload): array
    {
        $this->requests[] = ['operation' => $operation, 'payload' => $payload];
        $response = $this->responses[$operation] ?? [];
        if ($response instanceof OrvantaException) {
            throw $response;
        }

        return $response;
    }

    public function health(): array
    {
        return ['ok' => true, 'message' => 'Proxy-Dienst erreichbar.', 'details' => ['status' => 'ok']];
    }

    /**
     * @return list<string>
     */
    public function operations(): array
    {
        return array_column($this->requests, 'operation');
    }
}

function mailProxyPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Spiegelt database/migrations/039_mail_proxy.sql (manuell, SQLite).
    $pdo->exec(
        "CREATE TABLE phonebook (
            id INTEGER PRIMARY KEY AUTOINCREMENT, identity_source_id INTEGER NOT NULL DEFAULT 0,
            samaccount_name TEXT NULL, display_name TEXT NULL, email TEXT NULL, department TEXT NULL,
            active INTEGER NOT NULL DEFAULT 1
        )"
    );
    $pdo->exec(
        "CREATE TABLE identity_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT, source_key TEXT NOT NULL, label TEXT NOT NULL,
            base_dn TEXT NOT NULL DEFAULT '', sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1
        )"
    );
    $pdo->exec(
        "CREATE TABLE mail_proxy_servers (
            id INTEGER PRIMARY KEY AUTOINCREMENT, identity_source_id INTEGER NOT NULL DEFAULT 0 UNIQUE, name TEXT NOT NULL,
            smtp_host TEXT NOT NULL, smtp_port INTEGER NOT NULL DEFAULT 587, smtp_security TEXT NOT NULL DEFAULT 'starttls',
            smtp_auth INTEGER NOT NULL DEFAULT 1, imap_host TEXT NOT NULL, imap_port INTEGER NOT NULL DEFAULT 993,
            imap_security TEXT NOT NULL DEFAULT 'tls', verify_tls INTEGER NOT NULL DEFAULT 1,
            timeout_seconds INTEGER NOT NULL DEFAULT 20, active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec(
        "CREATE TABLE mail_proxy_mailboxes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, server_id INTEGER NOT NULL, username TEXT NOT NULL,
            email_address TEXT NOT NULL, display_name TEXT NOT NULL DEFAULT '', quota_mb INTEGER NOT NULL DEFAULT 0, password_encrypted TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (server_id, email_address)
        )"
    );
    $pdo->exec(
        "CREATE TABLE mail_proxy_mappings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, identity_source_id INTEGER NOT NULL DEFAULT 0,
            phonebook_id INTEGER NOT NULL UNIQUE, mailbox_id INTEGER NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec(
        "CREATE TABLE mail_proxy_state (
            id INTEGER PRIMARY KEY, generation INTEGER NOT NULL DEFAULT 1, last_success_at TEXT NULL,
            last_error_at TEXT NULL, last_error TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec('INSERT INTO mail_proxy_state (id, generation) VALUES (1, 1)');
    $pdo->exec("INSERT INTO identity_sources (id, source_key, label, base_dn, active) VALUES (5, 'HAMBURG', 'Zweigstelle Hamburg', 'DC=hh,DC=example,DC=local', 1)");
    $pdo->exec("INSERT INTO identity_sources (id, source_key, label, base_dn, active) VALUES (6, 'ALT', 'Stillgelegt', 'DC=alt,DC=local', 0)");
    $insert = $pdo->prepare('INSERT INTO phonebook (id, identity_source_id, samaccount_name, display_name, email, department, active) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([1, 0, 'mueller', 'Anna Müller', 'anna@zentrale.example', 'IT', 1]);
    $insert->execute([2, 5, 'mueller', 'Jan Müller', 'jan@hh.example', 'Vertrieb', 1]);
    $insert->execute([3, 5, 'schmidt', 'Eva Schmidt', 'eva@hh.example', 'Vertrieb', 1]);
    $insert->execute([4, 5, 'alt', 'Ausgeschieden', 'alt@hh.example', '', 0]);

    return $pdo;
}

function mailProxyTempDir(): string
{
    $dir = sys_get_temp_dir() . '/mail-proxy-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o700, true);

    return $dir;
}

/**
 * @return array{pdo:PDO,repository:MailProxyRepository,cache:MailProxyCache,secrets:SecretBox,transport:FakeMailProxyTransport,resolver:MailProxyResolver,service:MailProxyService,dir:string}
 */
function mailProxyEnv(?Closure $clock = null): array
{
    $pdo = mailProxyPdo();
    $dir = mailProxyTempDir();
    $repository = new MailProxyRepository($pdo);
    $cache = new MailProxyCache($dir . '/cache', 300, $clock);
    $secrets = new SecretBox($dir . '/keys/secrets.key');
    $logger = new Logger($dir . '/mail-proxy.log', 'debug');
    $transport = new FakeMailProxyTransport();
    $resolver = new MailProxyResolver($repository, $cache, $secrets, $logger);
    $service = new MailProxyService(
        $repository,
        $cache,
        $secrets,
        $transport,
        $resolver,
        $logger,
        static fn (): array => ['label' => 'Zentrale', 'base_dn' => 'DC=zentrale,DC=example,DC=local']
    );

    return compact('pdo', 'repository', 'cache', 'secrets', 'transport', 'resolver', 'service', 'dir');
}

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function mailProxyServerInput(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Mailserver Hamburg',
        'smtp_host' => 'smtp.hh.example.net',
        'smtp_port' => '587',
        'smtp_security' => 'starttls',
        'smtp_auth' => '1',
        'imap_host' => 'imap.hh.example.net',
        'imap_port' => '993',
        'imap_security' => 'tls',
        'verify_tls' => '1',
        'timeout_seconds' => '20',
        'active' => '1',
    ];
}

/**
 * Server (Quelle 5), Postfach und Zuordnung fuer Jan Mueller (phonebook 2).
 *
 * @param array<string,mixed> $env
 * @return array{server:int,mailbox:int,mapping:int}
 */
function mailProxySeed(array $env): array
{
    $server = $env['service']->saveServer(mailProxyServerInput(['identity_source_id' => '5']));
    $mailbox = $env['service']->saveMailbox($server, ['username' => 'jan', 'email_address' => 'Jan@HH.example', 'display_name' => 'Jan Müller', 'active' => '1'], 'geheim-123');
    $mapping = $env['service']->saveMapping(5, 2, $mailbox);

    return ['server' => $server, 'mailbox' => $mailbox, 'mapping' => $mapping];
}

function mailProxyRejected(callable $callback): string
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage();
    }
    throw new RuntimeException('Erwartete Validierungsfehler blieb aus.');
}

function mailProxyStatus(callable $callback): int
{
    try {
        $callback();
    } catch (OrvantaException $exception) {
        return $exception->status();
    }
    throw new RuntimeException('Erwartete OrvantaException blieb aus.');
}

/**
 * HTTP-Status einer erwarteten HttpException (z. B. 403 oder 419).
 */
function mailProxyHttpStatus(callable $callback): int
{
    try {
        $callback();
    } catch (HttpException $exception) {
        return $exception->statusCode();
    }
    throw new RuntimeException('Erwartete HttpException blieb aus.');
}

/**
 * Rumpf einer Middleware-Gruppe aus public/index.php (klammerrichtig,
 * Zeichenketten werden nicht als Klammern gewertet).
 */
function mailProxyGroupBody(string $source, string $header): string
{
    $start = strpos($source, $header);
    if ($start === false) {
        throw new RuntimeException('Gruppe nicht gefunden: ' . $header);
    }
    $depth = 0;
    $quote = '';
    for ($i = (int) strpos($source, '{', $start), $length = strlen($source); $i < $length; $i++) {
        $char = $source[$i];
        if ($quote !== '') {
            if ($char === '\\') {
                $i++;
            } elseif ($char === $quote) {
                $quote = '';
            }
            continue;
        }
        if ($char === "'" || $char === '"') {
            $quote = $char;
            continue;
        }
        if ($char === '{') {
            $depth++;
            continue;
        }
        if ($char === '}' && --$depth === 0) {
            return substr($source, $start, $i - $start + 1);
        }
    }
    throw new RuntimeException('Gruppe nicht geschlossen: ' . $header);
}

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function mailProxyResolutionRow(array $overrides = []): array
{
    return $overrides + [
        'phonebook_id' => 2, 'mapping_source_id' => 5, 'user_source_id' => 5, 'server_source_id' => 5,
        'user_active' => 1, 'source_row_id' => 5, 'source_active' => 1, 'server_active' => 1,
        'mailbox_active' => 1, 'mailbox_id' => 7, 'server_id' => 3, 'email_address' => 'jan@hh.example',
    ];
}

function mailProxyBackend(FakeMailProxyTransport $transport, int $mailboxId = 7, int $quotaMb = 0): ProxyMailBackend
{
    $route = MailProxyRoute::proxy($mailboxId, 3, 5, 'jan@hh.example');
    $account = new MailProxyAccount($mailboxId, 3, 5, 'jan', 'jan@hh.example', 'Jan', 'geheim-123', [
        'smtp_host' => 'smtp.hh.example.net', 'smtp_port' => 587, 'smtp_security' => 'starttls', 'smtp_auth' => true,
        'imap_host' => 'imap.hh.example.net', 'imap_port' => 993, 'imap_security' => 'tls', 'verify_tls' => true, 'timeout' => 20,
    ], 1, $quotaMb);

    return new ProxyMailBackend($route, static fn (): MailProxyAccount => $account, $transport);
}

Runner::test('Mail-Proxy: Hostprüfung sperrt Loopback, Link-Local und einteilige Namen', static function (): void {
    foreach (['mail.example.com', 'imap.hh.example.net', '10.0.0.25', '192.168.1.10', '2001:db8::25', 'fd00::25'] as $host) {
        Assert::true(MailProxyService::isAllowedHost($host), 'Host sollte erlaubt sein: ' . $host);
    }
    foreach (['', 'localhost', 'mail-proxy', 'db', 'app.localhost', '127.0.0.1', '0.0.0.0', '169.254.169.254', '224.0.0.1', '::1', '::', 'fe80::1', 'ff02::1', 'mail example.com'] as $host) {
        Assert::false(MailProxyService::isAllowedHost($host), 'Host sollte gesperrt sein: ' . $host);
    }
});

Runner::test('Mail-Proxy: Servervalidierung prüft Ports, TLS-Modus und Zeitlimit', static function (): void {
    $env = mailProxyEnv();
    $values = $env['service']->validateServer(mailProxyServerInput(['smtp_host' => ' SMTP.HH.Example.NET ']));
    Assert::same('smtp.hh.example.net', $values['smtp_host']);
    Assert::true($values['verify_tls']);
    Assert::true($env['service']->validateServer(array_diff_key(mailProxyServerInput(), ['verify_tls' => true]))['verify_tls'], 'TLS-Prüfung ist standardmäßig an.');
    Assert::false($env['service']->validateServer(mailProxyServerInput(['verify_tls' => '0']))['verify_tls']);

    Assert::contains('SMTP-Port', mailProxyRejected(fn () => $env['service']->validateServer(mailProxyServerInput(['smtp_port' => '22']))));
    Assert::contains('IMAP-Port', mailProxyRejected(fn () => $env['service']->validateServer(mailProxyServerInput(['imap_port' => '3306']))));
    Assert::contains('IMAP erfordert', mailProxyRejected(fn () => $env['service']->validateServer(mailProxyServerInput(['imap_security' => 'none']))));
    Assert::contains('SMTP-Anmeldung', mailProxyRejected(fn () => $env['service']->validateServer(mailProxyServerInput(['smtp_security' => 'none']))));
    Assert::contains('Zeitlimit', mailProxyRejected(fn () => $env['service']->validateServer(mailProxyServerInput(['timeout_seconds' => '120']))));
    Assert::contains('SMTP-Host', mailProxyRejected(fn () => $env['service']->validateServer(mailProxyServerInput(['smtp_host' => '127.0.0.1']))));
    Assert::contains('IMAP-Host', mailProxyRejected(fn () => $env['service']->validateServer(mailProxyServerInput(['imap_host' => 'db']))));
    Assert::same('hh.example.local', MailProxyService::domainFromDn('OU=Benutzer,DC=HH,DC=example,DC=local'));
});

Runner::test('Mail-Proxy: ein Mailserver je Identitätsquelle, nur vorhandene Quellen', static function (): void {
    $env = mailProxyEnv();
    $id = $env['service']->saveServer(mailProxyServerInput(['identity_source_id' => '5']));
    Assert::true($id > 0);
    Assert::contains('bereits ein Mailserver', mailProxyRejected(fn () => $env['service']->saveServer(mailProxyServerInput(['identity_source_id' => '5']))));
    Assert::contains('Identitätsquelle', mailProxyRejected(fn () => $env['service']->saveServer(mailProxyServerInput(['identity_source_id' => '99']))));
    Assert::true($env['service']->saveServer(mailProxyServerInput(['identity_source_id' => '0'])) > 0, 'Hauptquelle (0) ist zulässig.');
});

Runner::test('Mail-Proxy: Postfach-Passwort nur verschlüsselt, Listen ohne Zugangsdaten', static function (): void {
    $env = mailProxyEnv();
    $ids = mailProxySeed($env);
    $stored = $env['repository']->mailboxSecret($ids['mailbox']);
    Assert::true(SecretBox::isEncrypted((string) $stored));
    Assert::false(str_contains((string) $stored, 'geheim-123'));
    Assert::same('geheim-123', $env['secrets']->decrypt($stored));

    $overview = $env['service']->overview(5);
    $json = (string) json_encode($overview);
    Assert::false(str_contains($json, 'password_encrypted'));
    Assert::false(str_contains($json, 'enc:v1:'));
    Assert::same('jan@hh.example', $overview['mailboxes'][0]['email_address'], 'E-Mail-Adresse wird kleingeschrieben gespeichert.');
    Assert::true($overview['mailboxes'][0]['password_set']);

    // Leeres Passwort beim Bearbeiten laesst das Chiffrat unveraendert.
    $env['service']->saveMailbox($ids['server'], ['username' => 'jan2', 'email_address' => 'jan@hh.example', 'active' => '1'], '', $ids['mailbox']);
    Assert::same($stored, $env['repository']->mailboxSecret($ids['mailbox']));
    Assert::contains('Passwort', mailProxyRejected(fn () => $env['service']->saveMailbox($ids['server'], ['username' => 'x', 'email_address' => 'x@hh.example'], '')));
    Assert::contains('Passwort ist ungültig', mailProxyRejected(fn () => $env['service']->saveMailbox($ids['server'], ['username' => 'x', 'email_address' => 'x@hh.example'], "a\r\nb")));
    Assert::contains('bereits angelegt', mailProxyRejected(fn () => $env['service']->saveMailbox($ids['server'], ['username' => 'x', 'email_address' => 'JAN@hh.example'], 'pw')));
    Assert::contains('noch Postfächer', mailProxyRejected(fn () => $env['service']->deleteServer($ids['server'])));
});

Runner::test('Mail-Proxy: feste Postfachgröße je Postfach wird gespeichert und geprüft', static function (): void {
    $env = mailProxyEnv();
    $ids = mailProxySeed($env);
    Assert::same(0, $env['repository']->findMailbox($ids['mailbox'])['quota_mb'], 'Standard: ohne Grenze.');

    $env['service']->saveMailbox($ids['server'], ['username' => 'jan', 'email_address' => 'jan@hh.example', 'quota_mb' => ' 2048 ', 'active' => '1'], '', $ids['mailbox']);
    Assert::same(2048, $env['repository']->findMailbox($ids['mailbox'])['quota_mb']);
    Assert::same(2048, $env['service']->overview(5)['mailboxes'][0]['quota_mb']);
    Assert::same(2048, (int) $env['repository']->connectionRow($ids['mailbox'])['quota_mb']);
    Assert::same(2048 * 1024 * 1024, $env['resolver']->accountForTest($ids['mailbox'])->quotaBytes());

    foreach (['-1', '10485761', 'abc', '1.5'] as $invalid) {
        Assert::contains('Postfachgröße', mailProxyRejected(fn () => $env['service']->saveMailbox($ids['server'], ['username' => 'jan', 'email_address' => 'jan@hh.example', 'quota_mb' => $invalid, 'active' => '1'], '', $ids['mailbox'])), 'Ungültig: ' . $invalid);
    }
    $env['service']->saveMailbox($ids['server'], ['username' => 'jan', 'email_address' => 'jan@hh.example', 'quota_mb' => '', 'active' => '1'], '', $ids['mailbox']);
    Assert::same(0, $env['repository']->findMailbox($ids['mailbox'])['quota_mb'], 'Leer = ohne Grenze.');
});

Runner::test('Mail-Proxy: Postfachbelegung nutzt die feste Postfachgröße, nie AD-Grenzen', static function (): void {
    $transport = new FakeMailProxyTransport();
    $transport->responses['imap.quota'] = ['used' => 512 * 1024 * 1024, 'limit' => 4096 * 1024 * 1024];
    $directory = ['warning' => 1, 'send' => 2, 'receive' => 3];

    // Feste Groesse hat Vorrang vor IMAP-QUOTA und Verzeichnis.
    $usage = mailProxyBackend($transport, 7, 1024)->mailboxUsage('jan@hh.example', $directory);
    Assert::same(512 * 1024 * 1024, $usage['used']);
    Assert::same(1024 * 1024 * 1024, $usage['limit']);
    Assert::same(50, $usage['percent']);
    Assert::same('setting', $usage['source']);
    Assert::same(['imap.quota'], $transport->operations());

    // Ohne feste Groesse: IMAP-Grenze; AD-Grenzen werden nie verwendet.
    $usage = mailProxyBackend($transport)->mailboxUsage('jan@hh.example', $directory);
    Assert::same(4096 * 1024 * 1024, $usage['limit']);
    Assert::same('imap', $usage['source']);
    $transport->responses['imap.quota'] = ['used' => 10, 'limit' => 0];
    $usage = mailProxyBackend($transport)->mailboxUsage('jan@hh.example', $directory);
    Assert::same(0, $usage['limit']);
    Assert::same(0, $usage['percent']);
    Assert::same('', $usage['source']);
});

Runner::test('Mail-Proxy: Zuordnung nur innerhalb einer Identitätsquelle und eindeutig', static function (): void {
    $env = mailProxyEnv();
    $ids = mailProxySeed($env);
    $second = $env['service']->saveMailbox($ids['server'], ['username' => 'eva', 'email_address' => 'eva@hh.example', 'active' => '1'], 'pw-eva');

    Assert::contains('aktiven AD-Benutzer', mailProxyRejected(fn () => $env['service']->saveMapping(5, 1, $second)), 'Benutzer der Hauptquelle');
    Assert::contains('aktiven AD-Benutzer', mailProxyRejected(fn () => $env['service']->saveMapping(5, 4, $second)), 'inaktiver Benutzer');
    Assert::contains('bereits einem Postfach', mailProxyRejected(fn () => $env['service']->saveMapping(5, 2, $second)));
    Assert::contains('anderen Benutzer', mailProxyRejected(fn () => $env['service']->saveMapping(5, 3, $ids['mailbox'])));
    Assert::contains('Postfach dieser Identitätsquelle', mailProxyRejected(fn () => $env['service']->saveMapping(0, 1, $second)));
    Assert::contains('Identitätsquelle wurde nicht gefunden', mailProxyRejected(fn () => $env['service']->saveMapping(42, 2, $second)));

    Assert::true($env['service']->saveMapping(5, 3, $second) > 0);
    // Bestehende Zuordnung darf ihr eigenes Postfach behalten.
    Assert::same($ids['mapping'], $env['service']->saveMapping(5, 2, $ids['mailbox'], $ids['mapping']));
});

Runner::test('Mail-Proxy: Vorschläge filtern nach Quelle und enthalten keine Zugangsdaten', static function (): void {
    $env = mailProxyEnv();
    $ids = mailProxySeed($env);
    $env['service']->saveMailbox($ids['server'], ['username' => 'frei', 'email_address' => 'frei@hh.example', 'active' => '1'], 'pw');

    $users = $env['service']->suggestUsers(5, 'MÜLL');
    Assert::same([2], array_column($users, 'id'), 'Nur Benutzer der Quelle 5, keine inaktiven.');
    Assert::true($users[0]['mapped']);
    Assert::same('hh.example.local', $users[0]['source']);
    Assert::same([1], array_column($env['service']->suggestUsers(0, 'müller'), 'id'));
    Assert::same([], $env['service']->suggestUsers(42, ''));
    Assert::same([], $env['service']->suggestUsers(5, '%'), 'LIKE-Platzhalter werden maskiert.');

    $mailboxes = $env['service']->suggestMailboxes(5, '');
    Assert::same(['frei@hh.example'], array_column($mailboxes, 'label'), 'Zugeordnete Postfächer werden ausgeblendet.');
    Assert::same(['frei@hh.example', 'jan@hh.example'], array_column($env['service']->suggestMailboxes(5, '', $ids['mapping']), 'label'));
    Assert::same([], $env['service']->suggestMailboxes(0, ''));
    Assert::false(str_contains((string) json_encode($mailboxes), 'enc:v1:'));
});

Runner::test('Mail-Proxy: Entscheidungsregeln für Exchange, Proxy und gesperrte Postfächer', static function (): void {
    $resolver = mailProxyEnv()['resolver'];
    Assert::same(MailProxyRoute::EXCHANGE, $resolver->decide(5, 2, null)->state);
    Assert::same(MailProxyRoute::EXCHANGE, $resolver->decide(5, 2, mailProxyResolutionRow(['server_source_id' => 0]))->state, 'Quellen passen nicht');
    Assert::same(MailProxyRoute::EXCHANGE, $resolver->decide(5, 2, mailProxyResolutionRow(['user_source_id' => 0]))->state);
    Assert::same(MailProxyRoute::EXCHANGE, $resolver->decide(5, 2, mailProxyResolutionRow(['phonebook_id' => 3]))->state);
    Assert::same(MailProxyRoute::EXCHANGE, $resolver->decide(5, 2, mailProxyResolutionRow(['user_active' => 0]))->state);
    Assert::same(MailProxyRoute::EXCHANGE, $resolver->decide(5, 2, mailProxyResolutionRow(['source_row_id' => null]))->state, 'Quelle gelöscht');
    Assert::same(MailProxyRoute::EXCHANGE, $resolver->decide(5, 2, mailProxyResolutionRow(['source_active' => 0]))->state, 'Quelle deaktiviert');
    Assert::same(MailProxyRoute::EXCHANGE, $resolver->decide(5, 2, mailProxyResolutionRow(['server_active' => 0, 'mailbox_active' => 0]))->state);
    Assert::same(MailProxyRoute::PROXY, $resolver->decide(0, 2, mailProxyResolutionRow(['mapping_source_id' => 0, 'user_source_id' => 0, 'server_source_id' => 0, 'source_row_id' => null]))->state, 'Hauptquelle ohne identity_sources-Zeile');

    $blocked = $resolver->decide(5, 2, mailProxyResolutionRow(['mailbox_active' => 0]));
    Assert::true($blocked->isBlocked());
    Assert::same(7, $blocked->mailboxId);

    $proxy = $resolver->decide(5, 2, mailProxyResolutionRow());
    Assert::true($proxy->isProxy());
    Assert::same(['state' => 'proxy', 'mailbox_id' => 7, 'server_id' => 3, 'source_id' => 5, 'email' => 'jan@hh.example', 'reason' => ''], $proxy->toArray());
    Assert::same($proxy->toArray(), MailProxyRoute::fromArray($proxy->toArray())?->toArray());
    Assert::null(MailProxyRoute::fromArray(['state' => 'unbekannt']));
});

Runner::test('Mail-Proxy: Auflösung wird gecacht und durch Admin-Änderungen invalidiert', static function (): void {
    $env = mailProxyEnv();
    $ids = mailProxySeed($env);
    $user = ['id' => 2, 'source_id' => 5, 'source_key' => 'HAMBURG'];

    Assert::same(MailProxyRoute::EXCHANGE, $env['resolver']->resolve(['id' => 0, 'source_id' => 5])->state);
    Assert::same(MailProxyRoute::EXCHANGE, $env['resolver']->resolve(['id' => 1, 'source_id' => 0])->state, 'Benutzer der Hauptquelle ohne Zuordnung');
    Assert::same(MailProxyRoute::EXCHANGE, $env['resolver']->resolve(['id' => 2, 'source_id' => 0])->state, 'Gleiche phonebook-ID, andere Quelle');
    $route = $env['resolver']->resolve($user);
    Assert::true($route->isProxy());
    Assert::same($ids['mailbox'], $route->mailboxId);

    // Direkte DB-Aenderung ohne Invalidierung: Cache liefert weiter den alten Stand.
    $env['pdo']->exec('UPDATE mail_proxy_mailboxes SET active = 0');
    Assert::true($env['resolver']->resolve($user)->isProxy(), 'Cache-Treffer erwartet.');
    $generation = $env['repository']->generation();
    $env['service']->invalidate('test');
    Assert::same($generation + 1, $env['repository']->generation());
    Assert::true($env['resolver']->resolve($user)->isBlocked(), 'Nach Invalidierung frisch aufgelöst.');

    $env['pdo']->exec('UPDATE mail_proxy_mailboxes SET active = 1');
    $env['service']->deleteMapping($ids['mapping']);
    Assert::same(MailProxyRoute::EXCHANGE, $env['resolver']->resolve($user)->state);
    Assert::true(in_array('cache.invalidate', $env['transport']->operations(), true), 'Proxy-Pool wird geleert.');

    $env['service']->saveMapping(5, 2, $ids['mailbox']);
    $env['pdo']->exec('UPDATE identity_sources SET active = 0 WHERE id = 5');
    $env['service']->invalidate('source deactivated');
    Assert::same(MailProxyRoute::EXCHANGE, $env['resolver']->resolve($user)->state, 'Deaktivierte Identitätsquelle fällt auf Exchange zurück.');
});

Runner::test('Mail-Proxy: Cache beachtet TTL und Generation', static function (): void {
    $now = 1000;
    $cache = new MailProxyCache(mailProxyTempDir(), 60, static function () use (&$now): int {
        return $now;
    });
    $cache->put('route:5:2', 3, ['state' => 'proxy']);
    Assert::same(['state' => 'proxy'], $cache->get('route:5:2', 3));
    Assert::null($cache->get('route:5:2', 4), 'Andere Generation');
    $cache->put('route:5:2', 3, ['state' => 'proxy']);
    $now += 61;
    Assert::null($cache->get('route:5:2', 3), 'TTL abgelaufen');
    $cache->put('route:5:2', 3, ['state' => 'proxy']);
    Assert::true($cache->clear() >= 1);
    Assert::null($cache->get('route:5:2', 3));
    $disabled = new MailProxyCache(mailProxyTempDir(), 0);
    $disabled->put('k', 1, ['x' => 1]);
    Assert::null($disabled->get('k', 1), 'TTL 0 schaltet den Cache ab.');
});

Runner::test('Mail-Proxy: Zugangsdaten werden frisch gelesen und geprüft', static function (): void {
    $env = mailProxyEnv();
    $ids = mailProxySeed($env);
    $route = $env['resolver']->resolve(['id' => 2, 'source_id' => 5]);
    $account = $env['resolver']->account($route);
    $payload = $account->payload();
    Assert::same('geheim-123', $payload['imap']['password']);
    Assert::same('geheim-123', $payload['smtp']['password']);
    Assert::same('imap.hh.example.net', $payload['imap']['host']);
    Assert::true($payload['verify_tls']);

    // Passwort taucht weder in Debug- noch JSON-Ausgaben auf.
    Assert::false(str_contains(print_r($account, true), 'geheim-123'));
    Assert::false(str_contains(var_export($account->jsonSerialize(), true), 'geheim-123'));
    Assert::false(str_contains((string) json_encode($account), 'geheim-123'));
    $serialized = false;
    try {
        serialize($account);
    } catch (LogicException) {
        $serialized = true;
    }
    Assert::true($serialized, 'serialize() muss verweigert werden.');

    $env['pdo']->exec('UPDATE mail_proxy_mailboxes SET active = 0');
    Assert::same(403, mailProxyStatus(fn () => $env['resolver']->account($route)), 'Deaktiviert trotz Cache-Route');
    Assert::same('geheim-123', $env['resolver']->accountForTest($ids['mailbox'])->payload()['imap']['password'], 'Verbindungstest auch für deaktivierte Postfächer.');
    $env['pdo']->exec("UPDATE mail_proxy_mailboxes SET active = 1, email_address = 'neu@hh.example'");
    Assert::same(409, mailProxyStatus(fn () => $env['resolver']->account($route)), 'Geänderte Postfachadresse');
    $env['pdo']->exec("UPDATE mail_proxy_mailboxes SET email_address = 'jan@hh.example', password_encrypted = 'enc:v1:kaputt'");
    Assert::same(503, mailProxyStatus(fn () => $env['resolver']->account($route)));
    Assert::same(403, mailProxyStatus(fn () => $env['resolver']->account(MailProxyRoute::exchange())));
    Assert::same(404, mailProxyStatus(fn () => $env['resolver']->accountForTest(999)));
});

Runner::test('Mail-Proxy: Router wählt Exchange, Proxy oder sperrt ohne Rückfall', static function (): void {
    $env = mailProxyEnv();
    $ids = mailProxySeed($env);
    $exchangeCalls = 0;
    $exchange = new class () implements OrvantaMailBackendInterface {
        public function backendName(): string { return 'exchange'; }
        public function capabilities(): array { return [self::CAPABILITY_MAIL => true, self::CAPABILITY_CALENDAR => true]; }
        public function folders(string $user): array { return []; }
        public function createFolder(string $user, string $parent, string $name): array { return []; }
        public function markFolderRead(string $user, string $folder): void {}
        public function folderProperties(string $user, string $folder): array { return []; }
        public function messages(string $user, string $folder, int $offset = 0, int $limit = 50, string $search = ''): array { return []; }
        public function message(string $user, string $id): array { return []; }
        public function messageHeaders(string $user, string $id): array { return []; }
        public function send(string $user, array $mail, string $draftId = '', string $changeKey = ''): array { return []; }
        public function saveDraft(string $user, array $mail, string $draftId = '', string $changeKey = ''): array { return []; }
        public function respond(string $user, string $id, string $mode, array $mail): array { return []; }
        public function markRead(string $user, array $ids, bool $read): void {}
        public function flag(string $user, array $ids, bool $flagged): void {}
        public function move(string $user, array $ids, string $folder): void {}
        public function delete(string $user, array $ids, bool $permanent = false): void {}
        public function attachment(string $user, string $attachmentId): array { return []; }
        public function mailboxUsage(string $user, ?array $directory = null): array { return []; }
    };
    $router = new OrvantaMailRouter($env['resolver'], $env['transport'], static function () use ($exchange, &$exchangeCalls): OrvantaMailBackendInterface {
        $exchangeCalls++;

        return $exchange;
    }, $env['repository']);

    Assert::same('exchange', $router->backendFor(['id' => 1, 'source_id' => 0])->backendName());
    Assert::same(1, $exchangeCalls);
    $backend = $router->backendFor(['id' => 2, 'source_id' => 5]);
    Assert::true($backend instanceof ProxyMailBackend);
    Assert::same(1, $exchangeCalls, 'Exchange wird für Proxy-Benutzer nicht angefasst.');
    $capabilities = $backend->capabilities();
    Assert::true($capabilities[OrvantaMailBackendInterface::CAPABILITY_MAIL]);
    foreach ([OrvantaMailBackendInterface::CAPABILITY_CALENDAR, OrvantaMailBackendInterface::CAPABILITY_CONTACTS, OrvantaMailBackendInterface::CAPABILITY_TASKS,
        OrvantaMailBackendInterface::CAPABILITY_NOTES, OrvantaMailBackendInterface::CAPABILITY_REMINDERS, OrvantaMailBackendInterface::CAPABILITY_ARCHIVE] as $capability) {
        Assert::false($capabilities[$capability], 'Nicht verfügbar über den Proxy: ' . $capability);
    }

    // Tokenbasierter Zugriff (Office-Viewer): Kennung mit Quelle, Adresse muss passen.
    Assert::true($router->backendForUid('mueller@hamburg', 'JAN@hh.example') instanceof ProxyMailBackend);
    Assert::same(403, mailProxyStatus(fn () => $router->backendForUid('mueller@hamburg', 'andere@hh.example')));
    Assert::same('exchange', $router->backendForUid('mueller', 'anna@zentrale.example')->backendName());
    Assert::same('exchange', $router->backendForUid('mueller@alt', 'jan@hh.example')->backendName(), 'Deaktivierte Quelle');

    $env['service']->saveMailbox($ids['server'], ['username' => 'jan', 'email_address' => 'jan@hh.example', 'active' => '0'], '', $ids['mailbox']);
    $fresh = new OrvantaMailRouter($env['resolver'], $env['transport'], static function () use ($exchange, &$exchangeCalls): OrvantaMailBackendInterface {
        $exchangeCalls++;

        return $exchange;
    }, $env['repository']);
    $before = $exchangeCalls;
    Assert::same(403, mailProxyStatus(fn () => $fresh->backendFor(['id' => 2, 'source_id' => 5])));
    Assert::same($before, $exchangeCalls, 'Kein stiller Rückfall auf Exchange.');
});

Runner::test('Mail-Proxy: Backend bindet Kennungen und Absender an das zugeordnete Postfach', static function (): void {
    $transport = new FakeMailProxyTransport();
    $transport->responses['imap.messages'] = [
        'uidvalidity' => 42,
        'total' => 3,
        'items' => [['uid' => 9, 'subject' => 'Hallo', 'seen' => true, 'from' => ['name' => 'Eva', 'email' => 'eva@hh.example'], 'importance' => 'Dringend']],
    ];
    $backend = mailProxyBackend($transport);
    $list = $backend->messages('jan@hh.example', 'inbox', 0, 1);
    $id = $list['items'][0]['id'];
    Assert::same('mpx.7.' . rtrim(strtr(base64_encode('inbox'), '+/', '-_'), '=') . '.42.9', $id);
    Assert::true($list['has_more']);
    Assert::same('Normal', $list['items'][0]['importance']);
    Assert::true($backend->ownsId($id));
    Assert::true($backend->ownsId($id . '.2'));
    Assert::false($backend->ownsId(str_replace('mpx.7.', 'mpx.8.', $id)));
    Assert::false($backend->ownsId('AAMkAGI2'), 'EWS-Kennung');
    $request = $transport->requests[0];
    Assert::same('geheim-123', $request['payload']['account']['imap']['password']);

    // Fremdes Postfach, fremder Benutzer, kaputte Kennungen.
    Assert::same(403, mailProxyStatus(fn () => $backend->message('jan@hh.example', str_replace('mpx.7.', 'mpx.8.', $id))));
    Assert::same(403, mailProxyStatus(fn () => $backend->attachment('jan@hh.example', str_replace('mpx.7.', 'mpx.8.', $id) . '.0')));
    Assert::same(403, mailProxyStatus(fn () => $backend->folders('eva@hh.example')));
    Assert::same(404, mailProxyStatus(fn () => $backend->message('jan@hh.example', 'mpx.7.!!.1.1')));
    Assert::same(404, mailProxyStatus(fn () => $backend->messages('jan@hh.example', 'mpx.f.' . rtrim(base64_encode("name:a\nb"), '='))));
    Assert::same(404, mailProxyStatus(fn () => $backend->messages('jan@hh.example', 'calendar')));
    $requests = count($transport->requests);
    Assert::same(403, mailProxyStatus(fn () => $backend->markRead('jan@hh.example', [$id, str_replace('mpx.7.', 'mpx.8.', $id)], true)));
    Assert::same($requests, count($transport->requests), 'Bei fremden Kennungen keine Teilausführung.');

    $backend->markRead('jan@hh.example', [$id, str_replace('.9', '.10', $id)], true);
    $flags = end($transport->requests);
    Assert::same('imap.flags', $flags['operation']);
    Assert::same([9, 10], $flags['payload']['uids']);
    Assert::same(['\\Seen'], $flags['payload']['add']);

    Assert::same(422, mailProxyStatus(fn () => $backend->send('jan@hh.example', ['to' => [], 'subject' => 'x'])));
    $transport->responses['smtp.send'] = ['folder' => 'sentitems', 'uidvalidity' => 5, 'uid' => 77];
    $sent = $backend->send('jan@hh.example', ['to' => ['eva@hh.example'], 'subject' => 'Test', 'body' => '<p>Hallo</p><script>alert(1)</script>', 'html' => true]);
    Assert::true(str_starts_with($sent['id'], 'mpx.7.'));
    $send = end($transport->requests);
    Assert::false(str_contains($send['payload']['message']['body'], '<script'), 'HTML wird wie bei EWS bereinigt.');
    Assert::false(isset($send['payload']['message']['from']), 'Absender bestimmt ausschließlich der Proxy.');
});

Runner::test('Mail-Proxy: Ordnerkennungen sind umkehrbar und Systemordner bleiben erhalten', static function (): void {
    $id = ProxyMailBackend::folderId('Projekte/Ärger & Co');
    Assert::true(str_starts_with($id, 'mpx.f.'));
    Assert::same('name:Projekte/Ärger & Co', ProxyMailBackend::folderSpec($id));
    Assert::same('drafts', ProxyMailBackend::folderSpec('drafts'));
    Assert::true(ProxyMailBackend::isProxyId($id));
    Assert::false(ProxyMailBackend::isProxyId('AAMkAGI2'));
    $failed = false;
    try {
        new ProxyMailBackend(MailProxyRoute::exchange(), static fn () => null, new FakeMailProxyTransport());
    } catch (InvalidArgumentException) {
        $failed = true;
    }
    Assert::true($failed, 'Backend nur mit Proxy-Route.');
});

Runner::test('Mail-Proxy: Transport-Signatur ist stabil und Basis-URL wird geprüft', static function (): void {
    // Referenzwert mit signature() aus docker/mail-proxy/mail_proxy.py berechnet.
    Assert::same(
        'ef83608e90b603bf5b5b1bdb490072e4ea3df85ad04607e5411d4db3f9a933f6',
        HttpMailProxyTransport::signature('schluessel', '1700000000', 'abc', 'imap.folders', '{"a":1}')
    );
    Assert::same(1, preg_match('/^[0-9a-f]{64}$/', HttpMailProxyTransport::signature('k', '1', 'n', 'smtp.send', '')));
    Assert::true(HttpMailProxyTransport::isValidBaseUrl('http://mail-proxy:8025'));
    Assert::false(HttpMailProxyTransport::isValidBaseUrl('ftp://mail-proxy'));
    Assert::false(HttpMailProxyTransport::isValidBaseUrl('http://user:pw@mail-proxy'));
    Assert::false(HttpMailProxyTransport::isValidBaseUrl('http://mail-proxy/?x=1'));
    Assert::false(HttpMailProxyTransport::isValidBaseUrl(''));

    $env = mailProxyEnv();
    $transport = new HttpMailProxyTransport('ftp://mail-proxy', $env['secrets']);
    Assert::same(503, mailProxyStatus(fn () => $transport->request('imap.folders', [])), 'Ungültige URL: keine Verbindung.');
    Assert::same(500, mailProxyStatus(fn () => $transport->request('../admin', [])));
});

Runner::test('Mail-Proxy: Verbindungstest und Diagnose ohne Geheimnisse', static function (): void {
    $env = mailProxyEnv();
    $ids = mailProxySeed($env);
    $env['transport']->responses['mailbox.test'] = ['checks' => [['name' => 'IMAP', 'ok' => true, 'message' => 'ok'], ['name' => 'SMTP', 'ok' => true, 'message' => 'ok']]];
    $result = $env['service']->testConnection($ids['mailbox']);
    Assert::true($result['ok']);
    Assert::same(['IMAP', 'SMTP'], array_column($result['checks'], 'name'));
    Assert::false(in_array('smtp.send', $env['transport']->operations(), true), 'Verbindungstest versendet keine Mail.');
    Assert::true($env['repository']->state()['last_success_at'] !== '');

    $env['transport']->responses['mailbox.test'] = new OrvantaException('Die Anmeldung am Postfach ist fehlgeschlagen.', 502);
    $failed = $env['service']->testConnection($ids['mailbox']);
    Assert::false($failed['ok']);
    Assert::same('Die Anmeldung am Postfach ist fehlgeschlagen.', $env['repository']->state()['last_error']);

    $diagnostics = $env['service']->diagnostics();
    Assert::true($diagnostics['available']);
    Assert::same(['servers' => 1, 'active_servers' => 1, 'mailboxes' => 1, 'active_mailboxes' => 1, 'mappings' => 1], $diagnostics['counts']);
    Assert::true($diagnostics['service']['ok']);
    Assert::same(300, $diagnostics['cache_ttl']);
    Assert::false(str_contains((string) json_encode($diagnostics), 'geheim-123'));
    Assert::false(str_contains((string) @file_get_contents($env['dir'] . '/mail-proxy.log'), 'geheim-123'), 'Kein Passwort im Log.');

    $env['pdo']->exec('DROP TABLE mail_proxy_state');
    Assert::false($env['service']->diagnostics()['available'], 'Fehlende Migration wird gemeldet.');
    Assert::same(MailProxyRoute::EXCHANGE, $env['resolver']->resolve(['id' => 2, 'source_id' => 5])->state, 'Ohne Tabellen bleibt Exchange.');
});

Runner::test('Mail-Proxy: Benutzer übernimmt geändertes Kennwort nur nach erfolgreicher Anmeldung', static function (): void {
    $env = mailProxyEnv();
    $ids = mailProxySeed($env);
    $route = $env['resolver']->resolve(['id' => 2, 'source_id' => 5]);
    Assert::true($route->isProxy());
    $stored = static fn (): ?string => $env['secrets']->decrypt((string) $env['repository']->mailboxSecret($ids['mailbox']));
    $env['transport']->requests = [];

    Assert::same(422, mailProxyStatus(fn () => $env['service']->updateUserPassword($route, '')));
    Assert::same(422, mailProxyStatus(fn () => $env['service']->updateUserPassword($route, "neu\r\n")));
    Assert::same([], $env['transport']->operations(), 'Ungültige Eingabe erreicht den Mailserver nicht.');

    $env['transport']->responses['mailbox.test'] = ['checks' => [
        ['name' => 'SMTP-Verbindung', 'ok' => true, 'message' => ''],
        ['name' => 'IMAP', 'ok' => false, 'code' => 'auth_failed', 'message' => 'IMAP-Anmeldung abgelehnt.'],
    ]];
    Assert::same(422, mailProxyStatus(fn () => $env['service']->updateUserPassword($route, 'falsch')));
    Assert::same('geheim-123', $stored(), 'Abgelehntes Kennwort wird nicht gespeichert.');
    Assert::same('falsch', $env['transport']->requests[0]['payload']['account']['imap']['password'], 'Geprüft wird das eingegebene Kennwort.');

    $env['transport']->responses['mailbox.test'] = ['checks' => [
        ['name' => 'SMTP-Verbindung', 'ok' => true, 'message' => ''],
        ['name' => 'SMTP', 'ok' => false, 'code' => 'auth_failed', 'message' => 'SMTP-Anmeldung abgelehnt.'],
        ['name' => 'IMAP-Anmeldung', 'ok' => true, 'message' => ''],
    ]];
    Assert::same(422, mailProxyStatus(fn () => $env['service']->updateUserPassword($route, 'nur-imap')), 'Auch SMTP muss das Kennwort annehmen.');

    $env['transport']->responses['mailbox.test'] = ['checks' => [['name' => 'IMAP', 'ok' => false, 'code' => 'unreachable', 'message' => 'Mailserver nicht erreichbar (IMAP).']]];
    Assert::same(502, mailProxyStatus(fn () => $env['service']->updateUserPassword($route, 'neu-456')));
    Assert::same('geheim-123', $stored(), 'Ohne bestätigte Anmeldung bleibt das Kennwort unverändert.');

    $env['transport']->responses['mailbox.test'] = ['checks' => [
        ['name' => 'SMTP-Verbindung', 'ok' => true, 'message' => ''],
        ['name' => 'SMTP-Anmeldung', 'ok' => true, 'message' => ''],
        ['name' => 'IMAP-Verbindung/TLS', 'ok' => true, 'message' => ''],
        ['name' => 'IMAP-Anmeldung', 'ok' => true, 'message' => ''],
    ]];
    $env['service']->updateUserPassword($route, 'neu-456');
    Assert::same('neu-456', $stored(), 'Bestätigtes Kennwort ersetzt den Admin-Wert.');
    Assert::same('neu-456', $env['resolver']->account($route)->payload()['imap']['password']);
    Assert::false(in_array('smtp.send', $env['transport']->operations(), true), 'Die Prüfung versendet keine Mail.');
    Assert::false(str_contains((string) @file_get_contents($env['dir'] . '/mail-proxy.log'), 'neu-456'), 'Kein Kennwort im Log.');

    $env['service']->saveMailbox($ids['server'], ['username' => 'jan', 'email_address' => 'jan@hh.example', 'display_name' => 'Jan Müller', 'active' => '0'], '', $ids['mailbox']);
    Assert::same(403, mailProxyStatus(fn () => $env['service']->updateUserPassword($route, 'neu-789')), 'Deaktiviertes Postfach: keine Änderung.');
});

Runner::test('Mail-Proxy: abgelehnte Anmeldung liefert den Grund mail_auth an Orvanta', static function (): void {
    $transport = new FakeMailProxyTransport();
    $transport->responses['imap.folders'] = new OrvantaException('Die Anmeldung am Postfach wurde abgelehnt.', 409, null, OrvantaException::MAIL_AUTH);
    $backend = mailProxyBackend($transport);
    try {
        $backend->folders('jan@hh.example');
        throw new RuntimeException('Erwartete OrvantaException blieb aus.');
    } catch (OrvantaException $exception) {
        Assert::same(409, $exception->status());
        Assert::same(OrvantaException::MAIL_AUTH, $exception->reason());
    }
    Assert::same('', (new OrvantaException('x'))->reason());
});
/**
 * Rendert ein Overlay-Partial der Adminseite mit den Variablen, die
 * views/admin/mail-proxy.php bereitstellt.
 *
 * @param array<string,mixed> $dialog
 */
function mailProxyDialogHtml(string $type, array $dialog): string
{
    $base = '/admin/office/mail-proxy';
    $overview = ['sources' => [
        ['id' => 3, 'label' => 'Hamburg', 'domain' => 'hh.example', 'base_dn' => '', 'active' => true],
        ['id' => 4, 'label' => 'Berlin', 'domain' => 'be.example', 'base_dn' => '', 'active' => true],
    ]];
    $sourceName = static fn (?array $source): string => $source === null ? 'Unbekannte Quelle (gelöscht)' : (string) $source['label'];
    $smtpPorts = [25, 465, 587];
    $imapPorts = [143, 993];
    ob_start();
    require dirname(__DIR__, 2) . '/views/admin/mail-proxy/' . $type . '.php';

    return (string) ob_get_clean();
}

Runner::test('Mail-Proxy-Admin: Overlay-Routen registriert, alte Links umgeleitet', static function (): void {
    $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    foreach (['server/neu' => 'createServer', 'server/bearbeiten' => 'editServer', 'postfach/neu' => 'createMailbox', 'postfach/bearbeiten' => 'editMailbox'] as $path => $method) {
        Assert::contains("\$router->get('/admin/office/mail-proxy/" . $path . "', [MailProxyController::class, '" . $method . "']);", $routes);
        Assert::true(method_exists(\App\Controllers\Admin\MailProxyController::class, $method), $method . ' fehlt.');
    }
    $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/MailProxyController.php');
    Assert::contains("queryInt('bearbeiten', 0)", $controller);
    Assert::contains("queryInt('postfach', 0)", $controller);
});

Runner::test('Mail-Proxy-Admin: Konfigurations-Overlay führt zur angezeigten Quelle zurück und escapt Eingaben', static function (): void {
    $html = mailProxyDialogHtml('server', [
        'type' => 'server', 'title' => 'Neue Konfiguration', 'action' => '/admin/office/mail-proxy/server',
        'error' => 'Ungültiger <Host>.', 'editing' => false, 'cancel' => '/admin/office/mail-proxy?quelle=3',
        'freeSources' => [['id' => 4, 'label' => 'Berlin', 'domain' => 'be.example', 'base_dn' => '', 'active' => true]],
        'values' => ['id' => 0, 'identity_source_id' => 4, 'name' => '<script>x</script>', 'smtp_host' => 'smtp.be.example', 'smtp_port' => 465,
            'smtp_security' => 'tls', 'smtp_auth' => false, 'imap_host' => 'imap.be.example', 'imap_port' => 993, 'imap_security' => 'tls',
            'verify_tls' => true, 'timeout_seconds' => 20, 'active' => true],
    ]);
    Assert::contains('data-cancel-url="/admin/office/mail-proxy?quelle=3"', $html);
    Assert::same(2, substr_count($html, 'href="/admin/office/mail-proxy?quelle=3"'), 'Schließen und Abbrechen behalten die Quelle.');
    Assert::contains('role="alert">Ungültiger &lt;Host&gt;.', $html);
    Assert::contains('value="&lt;script&gt;x&lt;/script&gt;"', $html);
    Assert::false(str_contains($html, '<script>'), 'Eingaben werden escapt.');
    Assert::contains('<option value="4" selected>', $html);
    Assert::false(str_contains($html, '<option value="3"'), 'Nur freie Quellen sind wählbar.');
    Assert::contains('<option value="465" selected>', $html);
    Assert::false(str_contains($html, 'name="smtp_auth" value="1" checked'), 'Checkbox-Eingabe bleibt erhalten.');
});

Runner::test('Mail-Proxy-Admin: Postfach-Overlay gibt kein Passwort aus und kehrt zu #postfaecher zurück', static function (): void {
    $html = mailProxyDialogHtml('mailbox', [
        'type' => 'mailbox', 'title' => 'Postfach bearbeiten', 'action' => '/admin/office/mail-proxy/postfach',
        'error' => null, 'editing' => true, 'cancel' => '/admin/office/mail-proxy?quelle=3#postfaecher',
        'server' => ['id' => 9, 'identity_source_id' => 3],
        'values' => ['id' => 12, 'username' => 'jan', 'email_address' => 'jan@hh.example', 'display_name' => 'Jan', 'quota_mb' => '250', 'active' => false, 'password' => 'geheim-123'],
    ]);
    Assert::contains('data-cancel-url="/admin/office/mail-proxy?quelle=3#postfaecher"', $html);
    Assert::contains('href="/admin/office/mail-proxy?quelle=3#postfaecher">Abbrechen</a>', $html);
    Assert::contains('<input type="hidden" name="server_id" value="9">', $html);
    Assert::contains('<input type="hidden" name="quelle" value="3">', $html);
    Assert::contains('<input type="hidden" name="id" value="12">', $html);
    Assert::contains('value="250"', $html);
    Assert::false(str_contains($html, 'geheim-123'), 'Das Passwort wird nie ausgegeben.');
    Assert::false(str_contains($html, 'role="alert"'), 'Ohne Fehler keine Meldung.');
    Assert::false(str_contains($html, 'name="active" value="1" checked'));
});

Runner::test('Mail-Proxy-Admin: schreibende Aktionen prüfen das CSRF-Token als Erstes', static function (): void {
    $actions = [
        'saveServer' => '/admin/office/mail-proxy/server',
        'toggleServer' => '/admin/office/mail-proxy/server/status',
        'deleteServer' => '/admin/office/mail-proxy/server/loeschen',
        'saveMailbox' => '/admin/office/mail-proxy/postfach',
        'deleteMailbox' => '/admin/office/mail-proxy/postfach/loeschen',
        'testMailbox' => '/admin/office/mail-proxy/postfach/test',
        'saveMapping' => '/admin/office/mail-proxy/zuordnung',
        'deleteMapping' => '/admin/office/mail-proxy/zuordnung/loeschen',
    ];
    $session = $_SESSION ?? [];
    try {
        // Ohne Sitzungstoken ist jede Variante ungültig: fehlend, fremd (64 Zeichen) oder zu kurz.
        $_SESSION = [];
        $controller = new MailProxyController();
        foreach ($actions as $action => $path) {
            foreach ([[], ['_token' => str_repeat('a', 64)], ['_token' => 'kurz']] as $post) {
                Assert::same(
                    419,
                    mailProxyHttpStatus(static fn (): mixed => $controller->{$action}(new Request('POST', $path, [], $post))),
                    $action . ': Ohne gültiges CSRF-Token muss die Anfrage abgewiesen werden.'
                );
            }
        }

        // Mit gültigem Token greift die CSRF-Prüfung nicht mehr (danach folgt erst die Validierung).
        $token = Csrf::token();
        $passed = true;
        try {
            $controller->saveServer(new Request('POST', $actions['saveServer'], [], ['_token' => $token]));
        } catch (HttpException $exception) {
            $passed = $exception->statusCode() !== 419;
        } catch (Throwable) {
            $passed = true;
        }
        Assert::true($passed, 'Mit gültigem CSRF-Token darf die CSRF-Prüfung nicht mehr greifen.');
    } finally {
        $_SESSION = $session;
    }
});

Runner::test('Mail-Proxy-Admin: alle Routen liegen in der Admin-Gruppe (Redaktion und KAEP gesperrt)', static function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');

    // Die Wache selbst: nur Administratoren kommen durch, sonst 403.
    $guard = mailProxyGroupBody($source, '$requireAdmin = static function (Request $request): ?Response {');
    Assert::contains('Container::auth()->isAdmin()', $guard);
    Assert::contains('new HttpException(403', $guard);

    // Keine eigene Untergruppe: alle Mail-Proxy-Routen erben die Admin-Wache direkt.
    $admin = mailProxyGroupBody($source, '$router->group([$requireAdmin], static function (Router $router): void {');
    Assert::same(1, substr_count($admin, '$router->group('), 'Der Admin-Block enthält keine weitere Middleware-Gruppe.');

    $routes = [
        'get' => ['/admin/office/mail-proxy', '/admin/office/mail-proxy/server/neu', '/admin/office/mail-proxy/server/bearbeiten',
            '/admin/office/mail-proxy/postfach/neu', '/admin/office/mail-proxy/postfach/bearbeiten',
            '/admin/office/mail-proxy/users', '/admin/office/mail-proxy/mailboxes'],
        'post' => ['/admin/office/mail-proxy/server', '/admin/office/mail-proxy/server/status', '/admin/office/mail-proxy/server/loeschen',
            '/admin/office/mail-proxy/postfach', '/admin/office/mail-proxy/postfach/loeschen', '/admin/office/mail-proxy/postfach/test',
            '/admin/office/mail-proxy/zuordnung', '/admin/office/mail-proxy/zuordnung/loeschen'],
    ];
    foreach ($routes as $method => $paths) {
        foreach ($paths as $path) {
            $call = "\$router->" . $method . "('" . $path . "'";
            Assert::true(str_contains($admin, $call), 'Route fehlt in der Admin-Gruppe: ' . $path);
            Assert::same(1, substr_count($source, $call), 'Route darf nur einmal registriert sein: ' . $path);
        }
    }
});

Runner::test('Mail-Proxy: Postfach- und Proxyänderungen invalidieren den Auflösungs-Cache', static function (): void {
    $env = mailProxyEnv();
    $ids = mailProxySeed($env);
    $user = ['id' => 2, 'source_id' => 5];
    Assert::true($env['resolver']->resolve($user)->isProxy(), 'Ausgangslage: Proxy-Postfach.');

    // Postfach deaktivieren.
    $generation = $env['repository']->generation();
    $env['service']->saveMailbox($ids['server'], ['username' => 'jan', 'email_address' => 'jan@hh.example', 'display_name' => 'Jan Müller'], '', $ids['mailbox']);
    Assert::same($generation + 1, $env['repository']->generation(), 'Postfachänderung hebt die Generation.');
    Assert::true($env['resolver']->resolve($user)->isBlocked(), 'Deaktiviertes Postfach wird frisch aufgelöst.');
    Assert::true(in_array('cache.invalidate', $env['transport']->operations(), true), 'Der Proxy-Pool wird geleert.');

    // Postfach wieder aktivieren.
    $generation = $env['repository']->generation();
    $env['service']->saveMailbox($ids['server'], ['username' => 'jan', 'email_address' => 'jan@hh.example', 'display_name' => 'Jan Müller', 'active' => '1'], '', $ids['mailbox']);
    Assert::same($generation + 1, $env['repository']->generation(), 'Erneutes Speichern hebt die Generation.');
    Assert::true($env['resolver']->resolve($user)->isProxy(), 'Aktiviertes Postfach wird frisch aufgelöst.');

    // Proxy-Konfiguration bearbeiten.
    $generation = $env['repository']->generation();
    $env['service']->saveServer(mailProxyServerInput(['identity_source_id' => '5', 'imap_host' => 'imap2.hh.example.net']), $ids['server']);
    Assert::same($generation + 1, $env['repository']->generation(), 'Proxyänderung hebt die Generation.');
    Assert::true($env['resolver']->resolve($user)->isProxy(), 'Geänderte Konfiguration bleibt aktiv.');

    // Proxy-Konfiguration deaktivieren und wieder aktivieren.
    $env['service']->setServerActive($ids['server'], false);
    Assert::same(MailProxyRoute::EXCHANGE, $env['resolver']->resolve($user)->state, 'Deaktivierte Konfiguration fällt auf Exchange zurück.');
    $env['service']->setServerActive($ids['server'], true);
    Assert::true($env['resolver']->resolve($user)->isProxy(), 'Aktivierte Konfiguration wird frisch aufgelöst.');

    // Postfach löschen.
    $generation = $env['repository']->generation();
    $env['service']->deleteMailbox($ids['mailbox']);
    Assert::same($generation + 1, $env['repository']->generation(), 'Postfachlöschung hebt die Generation.');
    Assert::same(MailProxyRoute::EXCHANGE, $env['resolver']->resolve($user)->state, 'Ohne Postfach kein Proxy-Zugriff.');

    // Konfiguration löschen (zuletzt, weil sie nur ohne Postfächer möglich ist).
    $generation = $env['repository']->generation();
    $env['service']->deleteServer($ids['server']);
    Assert::same($generation + 1, $env['repository']->generation(), 'Löschen der Konfiguration hebt die Generation.');
});
