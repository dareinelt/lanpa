<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ValidationException;
use App\Repositories\EmployeeRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\OutboundMailSettingsService;
use App\Services\Helpdesk\ReplyTemplateSettingsService;
use App\Services\Helpdesk\TaskService;
use App\Services\Helpdesk\TicketService;
use Tests\Support\DatabaseTestCase;

/**
 * Schließungsbenachrichtigung: Beim Schließen entscheidet der Agent, ob der Melder eine E-Mail
 * mit dem Schließungsgrund erhält. Der Text stammt aus den Vorlageneinstellungen
 * (`close_text`) und wird mit den Schließungs-Platzhaltern gefüllt.
 *
 * Der Versand läuft gegen den lokalen SMTP-Stub (tests/Support/smtp_stub_server.php).
 */
final class TicketCloseNotificationIntegrationTest extends DatabaseTestCase
{
    private const TEMPLATE = "Guten Tag {name},\n\nIhr Ticket {ticketnummer} „{betreff}“ wurde am {geschlossen_am} geschlossen.\n\nGrund: {schliessungsgrund}\nLösung: {loesung}";

    private static ?string $stubLog = null;

    /** @var resource|null */
    private static $stubProcess = null;

    private static int $stubPort = 0;

    private int $adminId;

    private int $requesterEmployeeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startSmtpStub();
        $this->requesterEmployeeId = $this->c->get(EmployeeRepository::class)->create([
            'first_name' => 'Max', 'last_name' => 'Melder', 'display_name' => 'Max Melder',
            'email' => 'max@example.test', 'source' => 'manual', 'is_active' => 1,
        ]);
        $this->adminId = $this->createUser('hd-admin', 'admin');
        $this->c->get(CurrentUser::class)->login([
            'id' => $this->adminId,
            'username' => 'hd-admin',
            'display_name' => 'Helpdesk Admin',
            'role' => 'admin',
            'groups' => [],
        ]);
        $this->configureMail();
    }

    public function testClosingWithNotificationSendsTemplateWithReasonAndResolution(): void
    {
        $this->templates()->save(['close_text' => self::TEMPLATE]);
        $ticket = $this->closedTicket(true, 'Ersatzgerät geliefert');
        $this->assertSame('BIOS zurückgesetzt, Gerät startet wieder.', (string) $ticket['resolution'], 'Die Lösung bleibt beim Schließen erhalten.');

        $message = $this->stubMessage();
        $this->assertStringContains('To: <max@example.test>', $message);
        $this->assertStringContains('[' . $ticket['number'] . '] Ticket geschlossen', $message);
        $this->assertStringContains('Guten Tag Max Melder', $message);
        $this->assertStringContains('Notebook startet nicht', $message);
        $this->assertStringContains('Grund: Ersatzgerät geliefert', $message);
        $this->assertStringContains('Lösung: BIOS zurückgesetzt, Gerät startet wieder.', $message);
        // Die allgemeine Statusmeldung entfällt, sobald der Schließungstext greift.
        $this->assertFalse(str_contains($message, 'Ihr Ticket wurde geschlossen.'));
    }

    public function testClosingWithoutNotificationSendsNoMail(): void
    {
        $this->templates()->save(['close_text' => self::TEMPLATE]);
        $this->closedTicket(false, 'Ohne Benachrichtigung');

        $message = $this->stubMessage();
        $this->assertFalse(str_contains($message, 'Ticket geschlossen'), 'Ohne Häkchen geht keine Schließungsmail raus.');
        $this->assertFalse(str_contains($message, 'Ohne Benachrichtigung'));
    }

    public function testEmptyTemplateFallsBackToGenericStatusMail(): void
    {
        $this->templates()->save(['close_text' => '']);
        $this->closedTicket(true, 'Kein eigener Text');

        $message = $this->stubMessage();
        $this->assertStringContains('Ihr Ticket wurde geschlossen.', $message);
    }

    public function testClosingWithoutTemplateKeepsGenericMail(): void
    {
        $this->closedTicket(true, 'Standardverhalten');

        $this->assertStringContains('Ihr Ticket wurde geschlossen.', $this->stubMessage());
    }

    public function testClosingWithErrorOriginPersistsValueAndFillsPlaceholder(): void
    {
        $this->templates()->save(['close_text' => self::TEMPLATE . "\nFehlerursprung: {fehlerursprung}"]);
        $ticket = $this->closedTicket(true, 'Festplatte defekt', 'Hardware-Fehler');

        $this->assertSame('Hardware-Fehler', (string) $ticket['error_origin'], 'Der Fehlerursprung wird am Ticket gespeichert.');
        $this->assertStringContains('Fehlerursprung: Hardware-Fehler', $this->stubMessage());
    }

    public function testClosingWithUnknownErrorOriginIsRejected(): void
    {
        $ticket = $this->createTicket();
        $this->service()->changeStatus((int) $ticket['id'], 'in_progress');

        $this->assertThrows(
            ValidationException::class,
            fn (): array => $this->service()->changeStatus((int) $ticket['id'], 'closed', null, null, 'Grund', null, false, null, true, 'Nicht im Katalog'),
            'Fehlerursprung'
        );
        $this->assertSame('in_progress', (string) $this->service()->get((int) $ticket['id'])['status_code'], 'Das Ticket bleibt offen.');
    }

    public function testConvertingTicketToTaskUsesDefaultTaskTextWithDetails(): void
    {
        $ticket = $this->createTicket();
        $this->c->get(TaskService::class)->convert((int) $ticket['id'], []);

        $message = $this->stubMessage();
        $this->assertStringContains('Ticket in eine Aufgabe umgewandelt', $message);
        $this->assertStringContains('Ihr Ticket wurde in eine Aufgabe umgewandelt, da es sich um keine Störung handelt.', $message);
        $this->assertStringContains('<strong>Ticket</strong></td><td>' . $ticket['number'], $message);
        $this->assertStringContains('<strong>Betreff</strong></td><td>Notebook startet nicht', $message);
        $this->assertStringContains('<strong>Status</strong></td><td>Geschlossen', $message);
        $this->assertStringContains('<strong>Priorität</strong>', $message);
        $this->assertFalse(str_contains($message, 'Ihr Ticket wurde geschlossen.'), 'Die allgemeine Schließungsmeldung entfällt.');
    }

    public function testTaskTextIsEditableAndSupportsPlaceholders(): void
    {
        $this->templates()->save(['task_text' => 'Guten Tag {name}, aus {ticketnummer} wird eine Aufgabe.']);
        $ticket = $this->createTicket();
        $this->c->get(TaskService::class)->convert((int) $ticket['id'], []);

        $message = $this->stubMessage();
        $this->assertStringContains('Guten Tag Max Melder, aus ' . $ticket['number'] . ' wird eine Aufgabe.', $message);
        $this->assertFalse(str_contains($message, 'da es sich um keine Störung handelt'));
    }

    public function testCloseTemplateDoesNotApplyToConversion(): void
    {
        $this->templates()->save(['close_text' => self::TEMPLATE]);
        $ticket = $this->createTicket();
        $this->c->get(TaskService::class)->convert((int) $ticket['id'], []);

        $message = $this->stubMessage();
        $this->assertStringContains('da es sich um keine Störung handelt.', $message);
        $this->assertFalse(str_contains($message, 'Grund: '), 'Der Schließungstext gilt nicht für die Umwandlung.');
    }

    // ------------------------------------------------------------------ Helfer

    /**
     * Legt ein Ticket an, löst es und schließt es mit dem gewünschten Benachrichtigungsverhalten.
     *
     * @return array<string,mixed>
     */
    private function closedTicket(bool $notifyRequester, string $reason, ?string $errorOrigin = null): array
    {
        $ticket = $this->createTicket();
        $id = (int) $ticket['id'];
        $this->service()->changeStatus($id, 'in_progress');
        $this->service()->changeStatus($id, 'resolved', null, 'BIOS zurückgesetzt, Gerät startet wieder.');

        return $this->service()->changeStatus($id, 'closed', null, null, $reason, null, false, null, $notifyRequester, $errorOrigin);
    }

    private function templates(): ReplyTemplateSettingsService
    {
        return $this->c->get(ReplyTemplateSettingsService::class);
    }

    private function service(): TicketService
    {
        return $this->c->get(TicketService::class);
    }

    /** @return array<string,mixed> */
    private function createTicket(): array
    {
        $type = $this->c->get(TicketMasterDataRepository::class)->typeByCode('incident');

        return $this->service()->create([
            'subject' => 'Notebook startet nicht',
            'description' => 'Nach dem Update bleibt der Bildschirm schwarz.',
            'ticket_type_id' => $type['id'],
            'requester_employee_id' => $this->requesterEmployeeId,
            'impact' => 1,
            'urgency' => 2,
        ], 'web');
    }

    private function stubMessage(): string
    {
        $raw = (string) file_get_contents((string) self::$stubLog);
        // MIME-kodierte Betreffzeilen und die Base64-Blöcke (Text- und HTML-Teil) entschlüsseln,
        // damit die Prüfungen den lesbaren Inhalt vergleichen können. Die Blöcke werden als
        // Ganzes dekodiert, weil der Zeilenumbruch sonst mitten im Text läge.
        $decoded = preg_replace_callback(
            '/=\?UTF-8\?B\?([^?]+)\?=/',
            static fn (array $m): string => (string) base64_decode($m[1], true),
            $raw
        ) ?? $raw;

        return preg_replace_callback(
            '/(?:^[A-Za-z0-9+\/=]{20,}\r?\n)+/m',
            static function (array $m): string {
                $block = preg_replace('/\s+/', '', $m[0]) ?? '';

                return (string) base64_decode($block, true);
            },
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
        $stmt->execute([$username, 'Helpdesk Agent', $username . '@example.test', $role]);

        return (int) $this->pdo->lastInsertId();
    }

    private function startSmtpStub(): void
    {
        if (self::$stubProcess !== null) {
            if (self::$stubLog !== null) {
                file_put_contents(self::$stubLog, '');
            }

            return;
        }
        self::$stubLog = sys_get_temp_dir() . '/klinq-close-stub-' . getmypid() . '.log';
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
