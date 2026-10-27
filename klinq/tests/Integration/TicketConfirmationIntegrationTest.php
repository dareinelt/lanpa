<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\EmployeeRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\OutboundMailSettingsService;
use App\Services\Helpdesk\ReplyTemplateSettingsService;
use App\Services\Helpdesk\TicketConfirmationService;
use App\Services\Helpdesk\TicketService;
use Tests\Support\DatabaseTestCase;

/**
 * Eingangsbestätigung: Meldungen aus dem Störungsformular und dem Störungspostfach erhalten sie
 * immer (bei Sammelkonten inklusive des zusätzlichen Benutzers), Tickets von Agenten nur mit
 * gesetzter Checkbox im Assistenten. Der Text stammt aus den Vorlageneinstellungen und wird mit
 * Platzhaltern gefüllt.
 *
 * Der Versand läuft gegen den lokalen SMTP-Stub (tests/Support/smtp_stub_server.php).
 */
final class TicketConfirmationIntegrationTest extends DatabaseTestCase
{
    private const TEMPLATE = "Guten Tag {name},\n\nIhre Meldung {ticketnummer} zum Thema {betreff} ist eingegangen.";

    private static ?string $stubLog = null;

    /** @var resource|null */
    private static $stubProcess = null;

    private static int $stubPort = 0;

    private int $requesterEmployeeId;

    private int $affectedEmployeeId;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startSmtpStub();
        $employees = $this->c->get(EmployeeRepository::class);
        $this->requesterEmployeeId = $employees->create([
            'first_name' => 'Max', 'last_name' => 'Melder', 'display_name' => 'Max Melder',
            'email' => 'max@example.test', 'source' => 'manual', 'is_active' => 1,
        ]);
        $this->affectedEmployeeId = $employees->create([
            'first_name' => 'Olga', 'last_name' => 'Other', 'display_name' => 'Olga Other',
            'email' => 'olga@example.test', 'source' => 'manual', 'is_active' => 1,
        ]);
        $this->adminId = $this->createUser('hd-admin', 'admin');
        $this->login($this->adminId, 'admin');
        $this->configureMail();
    }

    // ------------------------------------------------------------------ Störungsformular

    public function testQuickReportSendsConfirmationToRequester(): void
    {
        $this->templates()->save(['confirmation_text' => self::TEMPLATE]);

        $ticket = $this->createTicket('form');

        $message = $this->stubMessage();
        $this->assertStringContains('To: <max@example.test>', $message);
        $this->assertStringContains('[' . $ticket['number'] . '] Eingangsbestätigung', $message);
        $this->assertStringContains('Guten Tag Max Melder', $message);
        $this->assertStringContains((string) $ticket['number'], $message);
        $this->assertStringContains('Notebook startet nicht', $message);
        // Die allgemeine Info-Mail an den Melder entfällt, die Team-Info bleibt.
        $this->assertFalse(str_contains($message, 'Ihr Ticket wurde erfolgreich angelegt'));
        // Keine automatischen Ticket-Links im Fuß der Mail.
        $this->assertFalse(str_contains($message, 'Ticket im Help Desk öffnen'));
        $this->assertFalse(str_contains($message, 'Im Self-Service-Portal ansehen'));
    }

    public function testSharedAccountAlsoNotifiesAdditionalUser(): void
    {
        $this->templates()->save(['confirmation_text' => self::TEMPLATE]);

        $this->createTicket('form', ['affected_employee_id' => $this->affectedEmployeeId]);

        $message = $this->stubMessage();
        $this->assertStringContains('To: <max@example.test>', $message);
        $this->assertStringContains('To: <olga@example.test>', $message);
        $this->assertStringContains('Guten Tag Olga Other', $message);
    }

    public function testMailIntakeSendsConfirmationToExternalSender(): void
    {
        $this->templates()->save(['confirmation_text' => self::TEMPLATE]);

        $type = $this->c->get(TicketMasterDataRepository::class)->typeByCode('incident');
        $this->service()->create([
            'subject' => 'Drucker defekt',
            'description' => 'Der Drucker im Flur meldet Papierstau.',
            'ticket_type_id' => $type['id'],
            'requester_email' => 'extern@example.test',
            'mail_from_name' => 'Erika Extern',
            'mail_from_address' => 'extern@example.test',
            'external_requester' => true,
        ], 'email');

        $message = $this->stubMessage();
        $this->assertStringContains('To: <extern@example.test>', $message);
        $this->assertStringContains('Guten Tag Erika Extern', $message);
    }

    // ------------------------------------------------------------------ Agenten-Ticket

    public function testAgentTicketWithoutOptInSendsNoConfirmation(): void
    {
        $this->templates()->save(['confirmation_text' => self::TEMPLATE]);

        $this->createTicket('web');

        $message = $this->stubMessage();
        $this->assertStringContains('Ihr Ticket wurde erfolgreich angelegt', $message);
        $this->assertFalse(str_contains($message, 'Eingangsbestätigung'), 'Ohne Checkbox keine Bestätigung.');
    }

    public function testAgentTicketWithOptInSendsConfirmation(): void
    {
        $this->templates()->save(['confirmation_text' => self::TEMPLATE]);

        $this->createTicket('web', ['confirm_send' => '1']);

        $message = $this->stubMessage();
        $this->assertStringContains('Eingangsbestätigung', $message);
        $this->assertStringContains('Guten Tag Max Melder', $message);
        $this->assertFalse(str_contains($message, 'Ihr Ticket wurde erfolgreich angelegt'));
    }

    public function testEmptyTemplateSendsNoConfirmation(): void
    {
        $this->templates()->save(['confirmation_text' => '']);

        $this->createTicket('form');

        $message = $this->stubMessage();
        $this->assertFalse(str_contains($message, 'Eingangsbestätigung'));
        $this->assertStringContains('Ihr Ticket wurde erfolgreich angelegt', $message);
    }

    public function testConfirmationIsSentOnlyOncePerRecipient(): void
    {
        $this->templates()->save(['confirmation_text' => self::TEMPLATE]);

        $ticket = $this->createTicket('form');
        $before = substr_count($this->stubMessage(), 'To: <max@example.test>');

        $this->c->get(TicketConfirmationService::class)->send($ticket);

        $this->assertSame($before, substr_count($this->stubMessage(), 'To: <max@example.test>'));
    }

    // ------------------------------------------------------------------ Helfer

    private function templates(): ReplyTemplateSettingsService
    {
        return $this->c->get(ReplyTemplateSettingsService::class);
    }

    private function service(): TicketService
    {
        return $this->c->get(TicketService::class);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function createTicket(string $source, array $overrides = []): array
    {
        $type = $this->c->get(TicketMasterDataRepository::class)->typeByCode('incident');
        $input = array_merge([
            'subject' => 'Notebook startet nicht',
            'description' => 'Nach dem Update bleibt der Bildschirm schwarz.',
            'ticket_type_id' => $type['id'],
            'requester_employee_id' => $this->requesterEmployeeId,
            'impact' => 1,
            'urgency' => 2,
        ], $overrides);
        if ($source === 'form') {
            $input['reporter'] = [
                'employee_id' => $this->requesterEmployeeId,
                'display_name' => 'Max Melder',
                'username' => 'mmelder',
                'host' => 'PC-01',
            ];
        }

        return $this->service()->create($input, $source);
    }

    private function stubMessage(): string
    {
        $raw = (string) file_get_contents((string) self::$stubLog);
        // MIME-kodierte Betreffzeilen und den Base64-Textkörper entschlüsseln, damit die
        // Prüfungen den lesbaren Inhalt vergleichen können.
        $decoded = preg_replace_callback(
            '/=\?UTF-8\?B\?([^?]+)\?=/',
            static fn (array $m): string => (string) base64_decode($m[1], true),
            $raw
        ) ?? $raw;

        return preg_replace_callback(
            '/^([A-Za-z0-9+\/=]{20,})$/m',
            static fn (array $m): string => (string) base64_decode($m[1], true),
            $decoded
        ) ?? $decoded;
    }

    private function configureMail(): void
    {
        $this->c->get(OutboundMailSettingsService::class)->save([
            'enabled' => '1',
            'host' => '127.0.0.1',
            'port' => (string) self::$stubPort,
            'encryption' => 'none',
            'username' => '',
            'from_address' => 'helpdesk@example.test',
            'from_name' => 'Help Desk',
            'reply_to' => '',
            'timeout' => '10',
            'verify_peer' => '1',
            'password' => '',
        ]);
    }

    private function createUser(string $username, string $role): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, display_name, email, role_id, auth_source, is_active)
             SELECT ?, ?, ?, id, 'local', 1 FROM roles WHERE name = ?"
        );
        $stmt->execute([$username, 'Helpdesk Admin', $username . '@example.test', $role]);

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
        self::$stubLog = sys_get_temp_dir() . '/klinq-confirmation-stub-' . getmypid() . '.log';
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
