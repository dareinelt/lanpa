<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Logger;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\EmployeeRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\Ai\AiTransportInterface;
use App\Services\Helpdesk\Mail\SmtpTransport;
use App\Services\Helpdesk\OutboundMailSettingsService;
use App\Services\Helpdesk\TicketMailService;
use App\Services\Helpdesk\TicketNotificationService;
use App\Services\Helpdesk\TicketService;
use App\Services\Lanpa\LanpaBridge;
use App\Services\MailClient;
use Tests\Support\DatabaseTestCase;

/**
 * E-Mail eines Agenten aus einem Ticket: Empfänger, feste Intranet-Signatur,
 * Protokollierung im Verlauf und Pausierung im selben Schritt.
 *
 * Der Versanddienst wird durch einen lokalen HTTP-Stub ersetzt, der die
 * Anfragen mitschreibt; das Intranet durch einen festen Transport.
 */
final class TicketMailServiceIntegrationTest extends DatabaseTestCase
{
    private static ?string $stubDir = null;

    /** @var resource|null */
    private static $stubProcess = null;

    private static int $stubPort = 0;

    private int $agentId;
    private int $portalUserId;
    private int $requesterId;
    private int $affectedId;
    private int $withoutMailId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startMailStub();
        self::resetStubLog();

        $employees = $this->c->get(EmployeeRepository::class);
        $this->requesterId = $employees->create(['first_name' => 'Max', 'last_name' => 'Melder', 'display_name' => 'Max Melder', 'email' => 'max@example.test', 'department' => 'Vertrieb', 'source' => 'manual', 'is_active' => 1]);
        $this->affectedId = $employees->create(['first_name' => 'Olga', 'last_name' => 'Betroffen', 'display_name' => 'Olga Betroffen', 'email' => 'olga@example.test', 'department' => 'Vertrieb', 'source' => 'manual', 'is_active' => 1]);
        $this->withoutMailId = $employees->create(['first_name' => 'Ohne', 'last_name' => 'Adresse', 'display_name' => 'Ohne Adresse', 'source' => 'manual', 'is_active' => 1]);
        $this->agentId = $this->createUser('hd-agent', 'helpdesk_agent', null, 'agent@example.test');
        $this->portalUserId = $this->createUser('portal-user', 'lager', $this->requesterId, 'portal@example.test');
        $this->loginAs($this->agentId, 'helpdesk_agent');
    }

    public function testComposeDataOffersRequesterAffectedAndPauses(): void
    {
        $ticket = $this->createTicket(['affected_employee_id' => $this->affectedId]);
        $this->tickets()->changeStatus((int) $ticket['id'], 'in_progress');

        $compose = $this->service()->composeData($this->tickets()->getVisible((int) $ticket['id']));

        $this->assertTrue($compose['available']);
        $this->assertSame('', $compose['reason']);
        $this->assertSame('max@example.test', $compose['requester']['email']);
        $this->assertSame('Max Melder', $compose['requester']['name']);
        $this->assertSame('olga@example.test', $compose['affected']['email']);
        $this->assertSame('[' . $ticket['number'] . '] Notebook startet nicht', $compose['subject']);
        $this->assertTrue($compose['pauses'] !== [], 'Ein laufendes Ticket bietet Pausengründe an.');
        $this->assertMatches('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $compose['pause_default_until']);
        $this->assertSame(TicketMailService::MAX_BODY_CHARS, $compose['max_chars']);
    }

    public function testComposeDataExplainsMissingRequesterMail(): void
    {
        $ticket = $this->createTicket(['requester_employee_id' => $this->withoutMailId]);

        $compose = $this->service()->composeData($this->tickets()->getVisible((int) $ticket['id']));

        $this->assertFalse($compose['available']);
        $this->assertStringContains('keine E-Mail-Adresse', $compose['reason']);
    }

    public function testAffectedPersonIsClearedWhenIdenticalToRequester(): void
    {
        $ticket = $this->createTicket(['affected_employee_id' => $this->requesterId]);

        $compose = $this->service()->composeData($this->tickets()->getVisible((int) $ticket['id']));

        $this->assertSame('', $compose['affected']['email']);
    }

    public function testMailGoesToRequesterAndAffectedPerson(): void
    {
        $ticket = $this->createTicket(['affected_employee_id' => $this->affectedId]);

        $result = $this->service()->send((int) $ticket['id'], [
            'subject' => 'Ihr Ticket ' . $ticket['number'],
            'body_html' => '<p>Guten Tag,</p><p>wir prüfen Ihr Gerät.</p>',
            'to_affected' => '1',
        ]);

        $requests = self::stubRequests();
        $this->assertCount(2, $requests);
        $this->assertSame(['max@example.test', 'olga@example.test'], $result['sent']);
        $this->assertSame(['max@example.test', 'olga@example.test'], array_column($requests, 'to'));
        $this->assertSame('Ihr Ticket ' . $ticket['number'], $requests[0]['subject']);
        $this->assertStringContains('wir prüfen Ihr Gerät.', $requests[0]['html']);
        $this->assertStringContains('wir prüfen Ihr Gerät.', $requests[1]['html']);

        $this->assertSame($requests[0]['headers']['Message-ID'] ?? '', $requests[1]['headers']['Message-ID'] ?? '', 'Beide Empfänger gehören zum selben Nachrichtenkopf.');
        $this->assertMatches('/^<ticket-' . (int) $ticket['id'] . '\.agent-mail\.[0-9a-f]+@[^>]+>$/', $requests[0]['headers']['Message-ID'] ?? '');
    }

    public function testMailCarriesThreadHeadersButNoAutoSubmitted(): void
    {
        $ticket = $this->createTicket();

        $this->service()->send((int) $ticket['id'], [
            'subject' => 'Antwort',
            'body_html' => '<p>Kurze Rückmeldung.</p>',
        ]);

        $headers = self::stubRequests()[0]['headers'];
        $this->assertMatches('/^<ticket-' . (int) $ticket['id'] . '@[^>]+>$/', $headers['In-Reply-To'] ?? '');
        $this->assertSame($headers['In-Reply-To'] ?? '', $headers['References'] ?? '');
        $this->assertSame($ticket['number'], $headers['X-Ticket-Number'] ?? '');
        $this->assertFalse(array_key_exists('Auto-Submitted', $headers), 'Agentenpost darf nicht als automatisch erzeugt markiert werden.');
    }

    public function testMailIsLoggedAsPublicComment(): void
    {
        $ticket = $this->createTicket();

        $result = $this->service()->send((int) $ticket['id'], [
            'subject' => 'Antwort',
            'body_html' => '<p>Bitte senden Sie uns ein Foto.</p>',
        ]);

        $comment = $this->pdo->query('SELECT type, source, body, mail_message_id FROM ticket_comments WHERE ticket_id = ' . (int) $ticket['id'] . ' ORDER BY id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('public', $comment['type'] ?? '');
        $this->assertSame('email', $comment['source'] ?? '');
        $this->assertStringContains('max@example.test', (string) ($comment['body'] ?? ''));
        $this->assertStringContains('Bitte senden Sie uns ein Foto.', (string) ($comment['body'] ?? ''));
        $this->assertSame(self::stubRequests()[0]['headers']['Message-ID'] ?? '', $comment['mail_message_id'] ?? '');

        $fresh = $this->tickets()->getVisible((int) $ticket['id']);
        $this->assertNotNull($fresh['last_public_comment_at']);
        $this->assertSame($result['subject'], 'Antwort');
    }

    public function testSignatureIsAppendedServerSideAndScriptsAreRemoved(): void
    {
        $ticket = $this->createTicket();

        $this->service(static fn (string $username): string => '<div class="ov-signature-block">Max Muster</div>')
            ->send((int) $ticket['id'], [
                'subject' => 'Antwort',
                'body_html' => '<p>Text</p><script>alert(1)</script>',
            ]);

        $html = self::stubRequests()[0]['html'];
        $this->assertStringContains('<div class="ov-signature-block">Max Muster</div>', $html);
        $this->assertFalse(str_contains($html, 'alert(1)'), 'Skripte aus dem Editor werden entfernt.');
    }

    public function testSignatureFromEditorIsReplacedByTheServerSideCopy(): void
    {
        $ticket = $this->createTicket();

        $this->service(static fn (string $username): string => '<div class="ov-signature-block">Max Muster</div>')
            ->send((int) $ticket['id'], [
                'subject' => 'Antwort',
                'body_html' => '<p>Text</p><div class="ov-signature-block" contenteditable="false">Gefaelscht</div>',
            ]);

        $html = self::stubRequests()[0]['html'];
        $this->assertSame(1, substr_count($html, 'ov-signature-block'), 'Die Signatur steht genau einmal in der Mail.');
        $this->assertStringContains('<div class="ov-signature-block">Max Muster</div>', $html);
        $this->assertFalse(str_contains($html, 'Gefaelscht'), 'Die Signatur aus dem Editor wird verworfen.');
    }

    public function testPauseIsSetTogetherWithTheMail(): void
    {
        $ticket = $this->createTicket();
        $this->tickets()->changeStatus((int) $ticket['id'], 'in_progress');
        $pause = $this->service()->composeData($this->tickets()->getVisible((int) $ticket['id']))['pauses'][0];

        $result = $this->service()->send((int) $ticket['id'], [
            'subject' => 'Wir warten auf den Hersteller',
            'body_html' => '<p>Ihr Gerät ist beim Hersteller.</p>',
            'pause_code' => $pause['code'],
            'pause_until' => (new \DateTimeImmutable('+2 days 12:00'))->format('Y-m-d\TH:i'),
        ]);

        $this->assertTrue($result['paused']);
        $this->assertSame($pause['name'], $result['pause_name']);
        $this->assertSame('', $result['pause_warning']);

        $fresh = $this->tickets()->getVisible((int) $ticket['id']);
        $this->assertSame($pause['code'], $fresh['status_code']);
        $this->assertNotNull($fresh['paused_until']);
        $this->assertStringContains('pausiert', self::stubRequests()[0]['html']);
        $this->assertStringContains($pause['name'], self::stubRequests()[0]['html']);
    }

    public function testPauseInThePastIsRejectedBeforeSending(): void
    {
        $ticket = $this->createTicket();
        $this->tickets()->changeStatus((int) $ticket['id'], 'in_progress');
        $pause = $this->service()->composeData($this->tickets()->getVisible((int) $ticket['id']))['pauses'][0];

        $this->assertThrows(ValidationException::class, fn () => $this->service()->send((int) $ticket['id'], [
            'subject' => 'Antwort',
            'body_html' => '<p>Text</p>',
            'pause_code' => $pause['code'],
            'pause_until' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d\TH:i'),
        ]), 'Zukunft');

        $this->assertSame([], self::stubRequests(), 'Ohne gültiges Pausenende geht keine E-Mail raus.');
    }

    public function testUnknownPauseReasonIsRejected(): void
    {
        $ticket = $this->createTicket();

        $this->assertThrows(ValidationException::class, fn () => $this->service()->send((int) $ticket['id'], [
            'subject' => 'Antwort',
            'body_html' => '<p>Text</p>',
            'pause_code' => 'gibt-es-nicht',
            'pause_until' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i'),
        ]));

        $this->assertSame([], self::stubRequests());
    }

    public function testEmptyMessageAndSubjectAreRejected(): void
    {
        $ticket = $this->createTicket();

        $this->assertThrows(ValidationException::class, fn () => $this->service()->send((int) $ticket['id'], [
            'subject' => 'Antwort',
            'body_html' => '<p>   </p>',
        ]), 'Nachrichtentext');

        $this->assertThrows(ValidationException::class, fn () => $this->service()->send((int) $ticket['id'], [
            'subject' => '   ',
            'body_html' => '<p>Text</p>',
        ]), 'Betreff');

        $this->assertSame([], self::stubRequests());
    }

    public function testPortalUserCannotSendMail(): void
    {
        $ticket = $this->createTicket();
        $this->loginAs($this->portalUserId, 'lager');

        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->send((int) $ticket['id'], [
            'subject' => 'Antwort',
            'body_html' => '<p>Text</p>',
        ]));

        $this->assertSame([], self::stubRequests());
    }

    // ------------------------------------------------------------------ Zusammenführen

    public function testMergeComposeDataOffersTemplateAndRequester(): void
    {
        $ticket = $this->createTicket();

        $compose = $this->service()->mergeComposeData($this->tickets()->getVisible((int) $ticket['id']));

        $this->assertTrue($compose['available']);
        $this->assertSame('max@example.test', $compose['requester']['email']);
        $this->assertSame('Max Melder', $compose['requester']['name']);
        $this->assertSame('[{{nummer}}] Ticket zusammengeführt in {{ziel}}', $compose['subject']);
        $this->assertStringContains('{{nummer}}', $compose['body_html']);
        $this->assertStringContains('{{ziel}}', $compose['body_html']);
        $this->assertStringContains('{{grund}}', $compose['body_html']);
    }

    public function testMergeComposeDataExplainsMissingRequesterMail(): void
    {
        $ticket = $this->createTicket(['requester_employee_id' => $this->withoutMailId]);

        $compose = $this->service()->mergeComposeData($this->tickets()->getVisible((int) $ticket['id']));

        $this->assertFalse($compose['available']);
        $this->assertStringContains('keine E-Mail-Adresse', $compose['reason']);
    }

    public function testMergeNoticeGoesToRequesterWithoutSignature(): void
    {
        $source = $this->createTicket(['subject' => 'Doppelt erfasst']);
        $target = $this->createTicket(['subject' => 'Sammelvorgang']);

        $result = $this->service(static fn (string $username): string => '<div class="ov-signature-block">Max Muster</div>')
            ->sendMergeNotice(
                $this->tickets()->getVisible((int) $source['id']),
                $this->tickets()->getVisible((int) $target['id']),
                'Duplikat',
                [
                    'subject' => '',
                    'body_html' => '<p>Ihr Ticket {{nummer}} gehört zu {{ziel}} ({{grund}}).</p><div class="ov-signature-block">Fremde Signatur</div>',
                ]
            );

        $requests = self::stubRequests();
        $this->assertCount(1, $requests, 'Die Info geht nur an die meldende Person.');
        $this->assertSame(['max@example.test'], $result['sent']);
        $this->assertSame('max@example.test', $requests[0]['to']);
        $this->assertSame('[' . $source['number'] . '] Ticket zusammengeführt in ' . $target['number'], $result['subject']);
        $this->assertStringContains('Ihr Ticket ' . $source['number'] . ' gehört zu ' . $target['number'] . ' (Duplikat).', $requests[0]['html']);
        $this->assertFalse(str_contains((string) $requests[0]['html'], 'ov-signature-block'), 'Bei Zusammenführungen wird keine Signatur versendet.');
        $this->assertFalse(str_contains((string) $requests[0]['html'], 'Fremde Signatur'), 'Eine mitgeschickte Signatur wird verworfen.');

        $headers = $requests[0]['headers'];
        $this->assertMatches('/^<ticket-' . (int) $target['id'] . '\.merge-notice\.[0-9a-f]+@[^>]+>$/', $headers['Message-ID'] ?? '');
        $this->assertMatches('/^<ticket-' . (int) $target['id'] . '@[^>]+>$/', $headers['In-Reply-To'] ?? '');
        $this->assertSame($headers['In-Reply-To'] ?? '', $headers['References'] ?? '');
        $this->assertSame($target['number'], $headers['X-Ticket-Number'] ?? '');

        $comment = $this->pdo->query('SELECT ticket_id, type, source FROM ticket_comments WHERE ticket_id = ' . (int) $target['id'] . ' ORDER BY id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame((int) $target['id'], (int) ($comment['ticket_id'] ?? 0), 'Die Info wird am Zielticket protokolliert.');
        $this->assertSame('public', $comment['type'] ?? '');
        $this->assertSame('email', $comment['source'] ?? '');
    }

    public function testMergeNoticeRequiresRequesterMail(): void
    {
        $source = $this->createTicket(['requester_employee_id' => $this->withoutMailId]);
        $target = $this->createTicket();

        $this->assertThrows(ValidationException::class, fn () => $this->service()->sendMergeNotice(
            $this->tickets()->getVisible((int) $source['id']),
            $this->tickets()->getVisible((int) $target['id']),
            'Duplikat',
            ['subject' => 'Zusammenführung', 'body_html' => '<p>Text</p>']
        ));

        $this->assertSame([], self::stubRequests());
    }

    public function testMergeNoticeRejectsEmptyMessage(): void
    {
        $source = $this->createTicket();
        $target = $this->createTicket();

        $this->assertThrows(ValidationException::class, fn () => $this->service()->sendMergeNotice(
            $this->tickets()->getVisible((int) $source['id']),
            $this->tickets()->getVisible((int) $target['id']),
            'Duplikat',
            ['subject' => 'Zusammenführung', 'body_html' => '<p> </p>']
        ));

        $this->assertSame([], self::stubRequests());
    }

    // ------------------------------------------------------------------ Hilfen

    private function service(?callable $signature = null): TicketMailService
    {
        $config = $this->c->get(Config::class)->with(['mail.service_url' => 'http://127.0.0.1:' . self::$stubPort]);
        $bridge = $signature === null
            ? $this->c->get(LanpaBridge::class)
            : new LanpaBridge($config->with(['lanpa' => ['enabled' => true, 'internal_url' => 'http://intranet.test', 'service_token' => 'dienst-token']]), new SignatureTransport($signature));

        return new TicketMailService(
            $this->c->get(TicketService::class),
            $this->c->get(TicketNotificationService::class),
            new MailClient(
                $config,
                $this->c->get(Logger::class),
                $this->c->get(OutboundMailSettingsService::class),
                $this->c->get(SmtpTransport::class)
            ),
            $bridge,
            $this->c->get(CurrentUser::class),
            $config,
            $this->c->get(Logger::class)
        );
    }

    private function tickets(): TicketService
    {
        return $this->c->get(TicketService::class);
    }

    private function createUser(string $username, string $role, ?int $employeeId, string $email): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO users (username, display_name, email, role_id, auth_source, employee_id, is_active) SELECT ?, ?, ?, id, 'local', ?, 1 FROM roles WHERE name = ?");
        $stmt->execute([$username, ucfirst($username), $email, $employeeId, $role]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<int,string> $groups */
    private function loginAs(int $userId, string $role, array $groups = []): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => $userId, 'username' => 'u' . $userId, 'display_name' => 'User ' . $userId, 'role' => $role, 'groups' => $groups]);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function createTicket(array $overrides = []): array
    {
        $type = $this->c->get(TicketMasterDataRepository::class)->typeByCode('incident');

        return $this->c->get(TicketService::class)->create(array_merge([
            'subject' => 'Notebook startet nicht',
            'description' => 'Nach dem Update bleibt der Bildschirm schwarz.',
            'ticket_type_id' => $type['id'],
            'requester_employee_id' => $this->requesterId,
            'impact' => 1,
            'urgency' => 2,
        ], $overrides), 'web');
    }

    /** Startet einmalig einen lokalen E-Mail-Dienst, der die Anfragen mitschreibt. */
    private function startMailStub(): void
    {
        if (self::$stubProcess !== null) {
            return;
        }
        self::$stubDir = sys_get_temp_dir() . '/klinq-mail-stub-' . getmypid();
        if (!is_dir(self::$stubDir)) {
            mkdir(self::$stubDir, 0777, true);
        }
        file_put_contents(self::$stubDir . '/router.php', <<<'PHP'
<?php
file_put_contents(__DIR__ . '/requests.log', file_get_contents('php://input') . "\n", FILE_APPEND);
header('Content-Type: application/json');
echo '{"ok":true}';
PHP);

        self::$stubPort = self::freePort();
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . self::$stubPort, self::$stubDir . '/router.php'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, self::$stubDir);
        if (!is_resource($process)) {
            $this->markSkipped('Der lokale E-Mail-Stub konnte nicht gestartet werden.');
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
        $this->markSkipped('Der lokale E-Mail-Stub nimmt keine Verbindungen an.');
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function resetStubLog(): void
    {
        if (self::$stubDir !== null) {
            @unlink(self::$stubDir . '/requests.log');
        }
    }

    /** @return list<array<string,mixed>> */
    private static function stubRequests(): array
    {
        $file = (string) self::$stubDir . '/requests.log';
        if (!is_file($file)) {
            return [];
        }
        $requests = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($file))) as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                $requests[] = $data;
            }
        }

        return $requests;
    }
}

/** Liefert eine feste Signatur des Intranets. */
final class SignatureTransport implements AiTransportInterface
{
    /** @param callable(string):string $signature */
    public function __construct(private readonly mixed $signature)
    {
    }

    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $username = (string) ($query['username'] ?? '');

        return [
            'status' => 200,
            'body' => (string) json_encode([
                'available' => true,
                'name' => 'Max Muster',
                'html' => (string) ($this->signature)($username),
            ]),
            'error' => null,
        ];
    }
}
