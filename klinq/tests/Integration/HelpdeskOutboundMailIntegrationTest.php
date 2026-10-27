<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\HelpdeskOutboundMailController;
use App\Core\Request;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Security\CurrentUser;
use App\Services\Helpdesk\Mail\SmtpTransport;
use App\Services\Helpdesk\OutboundMailSettingsService;
use App\Services\MailClient;
use App\Services\SettingsService;
use App\Support\Secret;
use Tests\Support\DatabaseTestCase;

/**
 * Versandkonto für ausgehende Help-Desk-E-Mails: Ablage (inkl. verschlüsseltem Passwort),
 * Prüfungen der Eingaben, „Antwort-an“ und der tatsächliche SMTP-Versand.
 *
 * Der Versand läuft gegen einen lokalen SMTP-Stub (tests/Support/smtp_stub_server.php), der die
 * angenommene Nachricht mitschreibt.
 */
final class HelpdeskOutboundMailIntegrationTest extends DatabaseTestCase
{
    private static ?string $stubLog = null;

    /** @var resource|null */
    private static $stubProcess = null;

    private static int $stubPort = 0;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startSmtpStub();
        $this->adminId = $this->createUser('mail-admin', 'admin');
        $this->login($this->adminId, 'admin');
    }

    // ------------------------------------------------------------------ Ablage

    public function testNothingIsStoredBeforeTheFirstSave(): void
    {
        $this->assertSame([], $this->c->get(SettingsService::class)->withPrefix(OutboundMailSettingsService::PREFIX));
    }

    public function testSaveStoresAccountWithEncryptedPasswordAndReplyTo(): void
    {
        $settings = $this->settings();
        $settings->save($this->form(['password' => 'geheim!123']));

        $this->assertSame('127.0.0.1', $settings->get('host'));
        $this->assertSame('helpdesk@example.test', $settings->fromAddress());
        $this->assertSame('Help Desk', $settings->fromName());
        $this->assertSame('antwort@example.test', $settings->replyTo());
        $this->assertTrue($settings->configured());
        $this->assertTrue($settings->enabled());
        $this->assertTrue((bool) $settings->get('password_set'));
        $this->assertFalse((bool) $settings->get('password_unreadable'));
        $this->assertSame('geheim!123', $settings->password());

        $stored = (string) $this->pdo
            ->query("SELECT value FROM system_settings WHERE `key` = 'helpdesk.outbound_mail.password'")
            ->fetchColumn();
        $this->assertTrue(Secret::isEncrypted($stored));
        $this->assertFalse(str_contains($stored, 'geheim'));

        // Das Passwort darf über die effektive Konfiguration nicht an die Oberfläche gelangen.
        $this->assertFalse(array_key_exists('password', $settings->all()));
    }

    public function testEmptyPasswordFieldKeepsStoredPassword(): void
    {
        $settings = $this->settings();
        $settings->save($this->form(['password' => 'erstes-passwort']));
        $settings->save($this->form(['from_name' => 'Helpdesk Team', 'password' => '']));

        $this->assertSame('Helpdesk Team', $settings->fromName());
        $this->assertSame('erstes-passwort', $settings->password());
    }

    public function testPasswordCanBeRemovedExplicitly(): void
    {
        $settings = $this->settings();
        $settings->save($this->form(['password' => 'alt']));
        $settings->save($this->form(['password' => '', 'remove_password' => '1']));

        $this->assertFalse((bool) $settings->get('password_set'));
        $this->assertSame('', $settings->password());
    }

    public function testSaveIsWrittenToAuditLogWithoutPassword(): void
    {
        $this->settings()->save($this->form(['password' => 'geheim!123']));

        $row = $this->pdo
            ->query("SELECT new_data FROM audit_logs WHERE object_label = 'Help-Desk-Versandkonto' ORDER BY id DESC LIMIT 1")
            ->fetchColumn();

        $this->assertNotNull($row);
        $this->assertStringContains('helpdesk@example.test', (string) $row);
        $this->assertFalse(str_contains((string) $row, 'geheim'));
    }

    // ------------------------------------------------------------------ Prüfungen

    public function testActiveAccountRequiresServerAndSender(): void
    {
        $exception = $this->assertThrows(
            ValidationException::class,
            fn () => $this->settings()->save($this->form(['host' => '', 'from_address' => '']))
        );
        /** @var ValidationException $exception */
        $errors = $exception->errors();
        $this->assertTrue(isset($errors['host']), 'Server ist bei aktivem Konto Pflicht.');
        $this->assertTrue(isset($errors['from_address']), 'Absenderadresse ist bei aktivem Konto Pflicht.');
    }

    public function testInactiveAccountCanBeSavedWithoutServer(): void
    {
        $settings = $this->settings();
        $settings->save($this->form(['enabled' => '0', 'host' => '', 'from_address' => '']));

        $this->assertFalse($settings->enabled());
        $this->assertFalse($settings->configured());
    }

    public function testLoginWithoutTlsIsRejected(): void
    {
        $exception = $this->assertThrows(
            ValidationException::class,
            fn () => $this->settings()->save($this->form([
                'encryption' => 'none',
                'username' => 'helpdesk',
                'password' => 'geheim',
            ]))
        );
        /** @var ValidationException $exception */
        $this->assertTrue(isset($exception->errors()['encryption']));
    }

    public function testLoginRequiresPassword(): void
    {
        $exception = $this->assertThrows(
            ValidationException::class,
            fn () => $this->settings()->save($this->form([
                'encryption' => 'starttls',
                'username' => 'helpdesk',
                'password' => '',
            ]))
        );
        /** @var ValidationException $exception */
        $this->assertTrue(isset($exception->errors()['password']));
    }

    public function testReplyToMustBeAValidAddress(): void
    {
        $exception = $this->assertThrows(
            ValidationException::class,
            fn () => $this->settings()->save($this->form(['reply_to' => 'keine-adresse']))
        );
        /** @var ValidationException $exception */
        $this->assertTrue(isset($exception->errors()['reply_to']));
    }

    public function testEmptyPortUsesTheDefaultOfTheEncryption(): void
    {
        $settings = $this->settings();
        $settings->save($this->form(['encryption' => 'ssl', 'port' => '']));
        $this->assertSame(465, $settings->get('port'));

        $settings->save($this->form(['encryption' => 'starttls', 'port' => '']));
        $this->assertSame(587, $settings->get('port'));
    }

    public function testSaveRequiresSettingsPermission(): void
    {
        $agentId = $this->createUser('hd-agent', 'helpdesk_agent');
        $this->login($agentId, 'helpdesk_agent');

        $this->assertThrows(ForbiddenException::class, fn () => $this->settings()->save($this->form()));
    }

    // ------------------------------------------------------------------ Oberfläche

    public function testFormShowsServerSenderAndReplyTo(): void
    {
        $response = $this->c->get(HelpdeskOutboundMailController::class)->index($this->request('GET'));

        $this->assertSame(200, $response->status());
        $html = $response->content();
        $this->assertStringContains('name="host"', $html);
        $this->assertStringContains('name="port"', $html);
        $this->assertStringContains('name="encryption"', $html);
        $this->assertStringContains('name="from_address"', $html);
        $this->assertStringContains('name="reply_to"', $html);
        $this->assertStringContains('Antwort-an', $html);
    }

    public function testSaveThroughTheControllerStoresTheAccount(): void
    {
        $response = $this->c->get(HelpdeskOutboundMailController::class)->save($this->request('POST', $this->form()));

        $this->assertSame(302, $response->status());
        $this->assertTrue($this->settings()->enabled());
        $this->assertSame('antwort@example.test', $this->settings()->replyTo());
    }

    // ------------------------------------------------------------------ Versand

    public function testConnectionProbeReportsServerBanner(): void
    {
        $settings = $this->settings();
        $settings->save($this->form());

        $banner = $this->c->get(SmtpTransport::class)->probe($settings->mailerOptions());

        $this->assertStringContains('stub.test', $banner);
    }

    public function testMailClientSendsOverAccountWithReplyTo(): void
    {
        $settings = $this->settings();
        $settings->save($this->form());
        $this->assertTrue($this->c->get(MailClient::class)->usesAccount());

        $sent = $this->c->get(MailClient::class)->send(
            'kunde@example.test',
            'Ihr Ticket HD-2026-0001',
            '<p>Guten Tag</p><p>Ihr Anliegen ist in Bearbeitung.</p>',
            [],
            ['X-Ticket-Number' => 'HD-2026-0001', 'Bcc' => 'angriff@example.test']
        );

        $this->assertTrue($sent);
        $message = (string) file_get_contents((string) self::$stubLog);
        $this->assertStringContains('To: <kunde@example.test>', $message);
        $this->assertStringContains('From: "Help Desk" <helpdesk@example.test>', $message);
        $this->assertStringContains('Reply-To: <antwort@example.test>', $message);
        $this->assertStringContains('X-Ticket-Number: HD-2026-0001', $message);
        // Nicht freigegebene Kopfzeilen werden nicht durchgereicht (Schutz vor Header-Injection).
        $this->assertFalse(str_contains($message, 'angriff@example.test'));
        $this->assertStringContains('Content-Type: text/plain', $message);
        $this->assertStringContains($this->base64('<p>Guten Tag</p><p>Ihr Anliegen ist in Bearbeitung.</p>'), $message);
    }

    public function testMailClientOmitsReplyToWhenNoAddressIsConfigured(): void
    {
        $settings = $this->settings();
        $settings->save($this->form(['reply_to' => '']));

        $this->assertTrue($this->c->get(MailClient::class)->send('kunde@example.test', 'Ohne Antwortadresse', '<p>Text</p>'));

        $message = (string) file_get_contents((string) self::$stubLog);
        $this->assertFalse(str_contains($message, 'Reply-To:'));
    }

    public function testInactiveAccountDoesNotClaimTheDispatch(): void
    {
        $settings = $this->settings();
        $settings->save($this->form(['enabled' => '0']));

        $this->assertFalse($this->c->get(MailClient::class)->usesAccount());
    }

    // ------------------------------------------------------------------ Helfer

    private function settings(): OutboundMailSettingsService
    {
        return $this->c->get(OutboundMailSettingsService::class);
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body = []): Request
    {
        return new Request(['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/admin/helpdesk-outgoing-mail'], [], $body, []);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'enabled' => '1',
            'host' => '127.0.0.1',
            'port' => (string) self::$stubPort,
            'encryption' => 'none',
            'username' => '',
            'from_address' => 'helpdesk@example.test',
            'from_name' => 'Help Desk',
            'reply_to' => 'antwort@example.test',
            'timeout' => '10',
            'verify_peer' => '1',
            'password' => '',
        ], $overrides);
    }

    private function base64(string $content): string
    {
        return rtrim(chunk_split(base64_encode($content), 76, "\n"), "\n");
    }

    private function createUser(string $username, string $role): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, display_name, email, role_id, auth_source, is_active)
             SELECT ?, ?, ?, id, 'local', 1 FROM roles WHERE name = ?"
        );
        $stmt->execute([$username, 'Mail Admin', $username . '@example.test', $role]);

        return (int) $this->pdo->lastInsertId();
    }

    private function login(int $userId, string $role): void
    {
        $this->c->get(CurrentUser::class)->login([
            'id' => $userId,
            'username' => 'u' . $userId,
            'display_name' => 'User ' . $userId,
            'role' => $role,
            'groups' => [],
        ]);
    }

    private function startSmtpStub(): void
    {
        if (self::$stubProcess !== null) {
            if (self::$stubLog !== null) {
                file_put_contents(self::$stubLog, '');
            }

            return;
        }
        self::$stubLog = sys_get_temp_dir() . '/klinq-smtp-stub-' . getmypid() . '.log';
        file_put_contents(self::$stubLog, '');

        self::$stubPort = self::freePort();
        $script = dirname(__DIR__) . '/Support/smtp_stub_server.php';
        $command = [PHP_BINARY, $script, self::$stubLog, (string) self::$stubPort];
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $nullDevice, 'a'], 2 => ['file', $nullDevice, 'a']], $pipes);
        if (!is_resource($process)) {
            $this->markSkipped('Der lokale SMTP-Stub konnte nicht gestartet werden.');
        }
        self::$stubProcess = $process;
        register_shutdown_function(static function (): void {
            if (is_resource(self::$stubProcess)) {
                proc_terminate(self::$stubProcess);
            }
        });

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$stubPort, $errno, $error, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }
        $this->markSkipped('Der lokale SMTP-Stub nimmt keine Verbindungen an.');
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
