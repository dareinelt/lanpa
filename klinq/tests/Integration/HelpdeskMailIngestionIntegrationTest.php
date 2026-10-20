<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\EmployeeRepository;
use App\Repositories\TicketCommentRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketRuleRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\TicketMailIngestionService;
use App\Services\Helpdesk\TicketMergeService;
use App\Services\Helpdesk\TicketNotificationService;
use App\Services\Helpdesk\TicketService;
use Tests\Support\DatabaseTestCase;

/** E-Mail-Eingang: Ticketerstellung, Threading über Message-ID/Ticketnummer, Duplikate, Auto-Replies, Anhänge. */
final class HelpdeskMailIngestionIntegrationTest extends DatabaseTestCase
{
    private int $adminId;
    private int $employeeId;
    private TicketMailIngestionService $ingestion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->employeeId = $this->c->get(EmployeeRepository::class)->create(['first_name' => 'Rita', 'last_name' => 'Requester', 'display_name' => 'Rita Requester', 'email' => 'rita@example.test', 'source' => 'manual', 'is_active' => 1]);
        $stmt = $this->pdo->prepare("INSERT INTO users (username, display_name, email, role_id, auth_source, is_active) SELECT 'hd-mail-admin', 'HD Admin', 'hd-admin@example.test', id, 'local', 1 FROM roles WHERE name = 'helpdesk_admin'");
        $stmt->execute();
        $this->adminId = (int) $this->pdo->lastInsertId();
        $this->c->get(CurrentUser::class)->login(['id' => $this->adminId, 'username' => 'hd-mail-admin', 'display_name' => 'HD Admin', 'role' => 'helpdesk_admin']);
        $this->ingestion = $this->c->get(TicketMailIngestionService::class);
    }

    /** @param array<string,string> $headers */
    private function raw(string $from, string $subject, string $body, array $headers = []): string
    {
        $lines = [
            'From: ' . $from,
            'To: helpdesk@example.test',
            'Subject: ' . $subject,
            'Date: ' . gmdate('D, d M Y H:i:s O'),
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@sender.example.test>',
            'Content-Type: text/plain; charset=utf-8',
        ];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . $body . "\r\n";
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function createTicket(array $overrides = []): array
    {
        $type = $this->c->get(TicketMasterDataRepository::class)->typeByCode('incident');

        return $this->c->get(TicketService::class)->create(array_merge([
            'subject' => 'Monitor flackert',
            'description' => 'Der Monitor am Arbeitsplatz flackert seit heute.',
            'ticket_type_id' => $type['id'],
            'requester_employee_id' => $this->employeeId,
            'impact' => 2,
            'urgency' => 2,
        ], $overrides), 'web');
    }

    /** @return array<int,array<string,mixed>> */
    private function comments(int $ticketId): array
    {
        return $this->c->get(TicketCommentRepository::class)->forTicket($ticketId, true);
    }

    // ------------------------------------------------------------------ Neue Tickets

    public function testUnknownSenderCreatesTicketWithExternalRequester(): void
    {
        $result = $this->ingestion->ingestRaw($this->raw('Gast Nutzer <gast@extern.test>', 'AW: Drucker im 2. OG druckt nicht', "Hallo,\nder Drucker meldet Papierstau.\n\nAm 01.03.2026 schrieb jemand:\n> alt"), '17');

        $this->assertSame(TicketMailIngestionService::ACTION_CREATED, $result['action']);
        $this->assertNotNull($result['ticket_id']);
        $ticket = $this->c->get(TicketRepository::class)->find((int) $result['ticket_id']);
        $this->assertNotNull($ticket);
        $this->assertSame('Drucker im 2. OG druckt nicht', $ticket['subject']);
        $this->assertSame('email', $ticket['source']);
        $this->assertSame('gast@extern.test', $ticket['requester_email']);
        $this->assertNull($ticket['requester_user_id']);
        $this->assertNull($ticket['requester_employee_id']);
        $this->assertStringContains('Papierstau', (string) $ticket['description']);
        $this->assertFalse(str_contains((string) $ticket['description'], '> alt'));
        $this->assertStringContains('Eingegangen per E-Mail von Gast Nutzer <gast@extern.test>', (string) $ticket['description']);
        $this->assertNotNull($ticket['mail_message_id']);
        $this->assertSame('gast@extern.test', $ticket['mail_from_address']);
        $this->assertSame('Gast Nutzer', $ticket['mail_from_name']);

        $log = $this->c->get(TicketRuleRepository::class)->recentInboundMails(5)[0];
        $this->assertSame('created', $log['action']);
        $this->assertSame('none', $log['matched_by']);
        $this->assertSame('17', $log['mailbox_uid']);
        $this->assertSame((int) $ticket['id'], (int) $log['ticket_id']);
    }

    public function testKnownEmployeeBecomesRequester(): void
    {
        $result = $this->ingestion->ingestRaw($this->raw('rita@example.test', 'Tastatur defekt', 'Mehrere Tasten reagieren nicht.'));

        $this->assertSame(TicketMailIngestionService::ACTION_CREATED, $result['action']);
        $ticket = $this->c->get(TicketRepository::class)->find((int) $result['ticket_id']);
        $this->assertSame($this->employeeId, (int) $ticket['requester_employee_id']);
        $this->assertSame('rita@example.test', $ticket['requester_email']);
    }

    public function testEmptySubjectAndBodyStillCreateTicket(): void
    {
        $result = $this->ingestion->ingestRaw($this->raw('x@extern.test', '', ''));
        $this->assertSame(TicketMailIngestionService::ACTION_CREATED, $result['action']);
        $ticket = $this->c->get(TicketRepository::class)->find((int) $result['ticket_id']);
        $this->assertSame('E-Mail ohne Betreff', $ticket['subject']);
    }

    // ------------------------------------------------------------------ Threading

    public function testReplyIsThreadedBySubjectTicketNumber(): void
    {
        $ticket = $this->createTicket();
        $result = $this->ingestion->ingestRaw($this->raw('rita@example.test', 'Re: [' . $ticket['number'] . '] Monitor flackert', 'Es flackert weiterhin.'));

        $this->assertSame(TicketMailIngestionService::ACTION_COMMENT, $result['action']);
        $this->assertSame((int) $ticket['id'], $result['ticket_id']);
        $this->assertSame($ticket['number'], $result['ticket_number']);
        $comments = $this->comments((int) $ticket['id']);
        $this->assertCount(1, $comments);
        $this->assertSame('Es flackert weiterhin.', $comments[0]['body']);
        $this->assertSame('email', $comments[0]['source']);
        $this->assertSame('rita@example.test', $comments[0]['mail_from_address']);
        $this->assertSame(1, (int) $comments[0]['is_requester']);
        $this->assertSame('Rita Requester', $comments[0]['author_name']);
        $this->assertNull($comments[0]['author_user_id']);

        $log = $this->c->get(TicketRuleRepository::class)->recentInboundMails(1)[0];
        $this->assertSame('subject', $log['matched_by']);
    }

    public function testReplyIsThreadedByInReplyToHeader(): void
    {
        $ticket = $this->createTicket();
        $root = $this->c->get(TicketNotificationService::class)->threadRootId((int) $ticket['id']);
        // Betreff ohne Ticketnummer – nur der Header zählt
        $result = $this->ingestion->ingestRaw($this->raw('rita@example.test', 'Re: Monitor', 'Antwort per Header.', ['In-Reply-To' => $root]));

        $this->assertSame(TicketMailIngestionService::ACTION_COMMENT, $result['action']);
        $this->assertSame((int) $ticket['id'], $result['ticket_id']);
        $this->assertSame('reference', $this->c->get(TicketRuleRepository::class)->recentInboundMails(1)[0]['matched_by']);
    }

    public function testReplyIsThreadedByReferencesToLoggedNotification(): void
    {
        $ticket = $this->createTicket();
        $messageId = '<ticket-' . $ticket['id'] . '.assigned.abcdef@assets.example.test>';
        $this->c->get(TicketRuleRepository::class)->logNotification((int) $ticket['id'], 'assigned', 'rita@example.test', 'x', 'sent', null, $messageId);

        $result = $this->ingestion->ingestRaw($this->raw('rita@example.test', 'Re: irgendwas', 'Antwort auf Benachrichtigung.', ['References' => '<unrelated@x.test> ' . $messageId]));
        $this->assertSame(TicketMailIngestionService::ACTION_COMMENT, $result['action']);
        $this->assertSame((int) $ticket['id'], $result['ticket_id']);
    }

    public function testReplyToInboundMailMessageIdIsThreaded(): void
    {
        $created = $this->ingestion->ingestRaw($this->raw('gast@extern.test', 'Neues Problem', 'Erstmeldung.'));
        $ticket = $this->c->get(TicketRepository::class)->find((int) $created['ticket_id']);

        // Der Absender antwortet auf seine eigene Ursprungsmail (z. B. „Nachtrag“) – ohne Ticketnummer im Betreff
        $result = $this->ingestion->ingestRaw($this->raw('gast@extern.test', 'Nachtrag', 'Hier noch ein Screenshot-Hinweis.', ['In-Reply-To' => (string) $ticket['mail_message_id']]));
        $this->assertSame(TicketMailIngestionService::ACTION_COMMENT, $result['action']);
        $this->assertSame((int) $ticket['id'], $result['ticket_id']);
        $comments = $this->comments((int) $ticket['id']);
        $this->assertCount(1, $comments);
        $this->assertSame(1, (int) $comments[0]['is_requester']);

        // Antwort auf den Kommentar (mail_message_id des Kommentars) landet ebenfalls im Ticket
        $again = $this->ingestion->ingestRaw($this->raw('gast@extern.test', 'Nachtrag 2', 'Und noch etwas.', ['In-Reply-To' => (string) $comments[0]['mail_message_id']]));
        $this->assertSame((int) $ticket['id'], $again['ticket_id']);
    }

    public function testReferenceToUnknownTicketFallsBackToNewTicket(): void
    {
        $result = $this->ingestion->ingestRaw($this->raw('gast@extern.test', 'Re: [HD-1999-999999] Uralt', 'Ticket gibt es nicht mehr.', ['In-Reply-To' => '<ticket-999999999@assets.example.test>']));
        $this->assertSame(TicketMailIngestionService::ACTION_CREATED, $result['action']);
    }

    // ------------------------------------------------------------------ Statuslogik

    public function testRequesterReplyEndsWaitingForUser(): void
    {
        $ticket = $this->createTicket();
        $service = $this->c->get(TicketService::class);
        $service->changeStatus((int) $ticket['id'], 'in_progress');
        $service->changeStatus((int) $ticket['id'], 'waiting_user', 'Bitte Kabel prüfen.');
        $this->assertSame('waiting_user', $service->get((int) $ticket['id'])['status_code']);

        $result = $this->ingestion->ingestRaw($this->raw('rita@example.test', 'Re: [' . $ticket['number'] . '] Monitor', 'Kabel geprüft, Problem bleibt.'));
        $this->assertSame(TicketMailIngestionService::ACTION_COMMENT, $result['action']);
        $fresh = $service->get((int) $ticket['id']);
        $this->assertSame('in_progress', $fresh['status_code']);
        $this->assertNull($fresh['sla_paused_at']);
    }

    public function testRequesterReplyReopensResolvedTicket(): void
    {
        $ticket = $this->createTicket();
        $service = $this->c->get(TicketService::class);
        $service->changeStatus((int) $ticket['id'], 'in_progress');
        $service->changeStatus((int) $ticket['id'], 'resolved', null, 'Kabel getauscht.');

        $this->ingestion->ingestRaw($this->raw('rita@example.test', 'Re: [' . $ticket['number'] . '] Monitor', 'Leider flackert es schon wieder.'));
        $fresh = $service->get((int) $ticket['id']);
        $this->assertSame('open', $fresh['status_code']);
        $this->assertNull($fresh['resolved_at']);
    }

    public function testAgentReplyCountsAsFirstResponse(): void
    {
        $ticket = $this->createTicket();
        $this->assertNull($ticket['first_response_at']);

        $result = $this->ingestion->ingestRaw($this->raw('HD Admin <hd-admin@example.test>', 'Re: [' . $ticket['number'] . '] Monitor', 'Wir kümmern uns darum.'));
        $this->assertSame(TicketMailIngestionService::ACTION_COMMENT, $result['action']);
        $fresh = $this->c->get(TicketService::class)->get((int) $ticket['id']);
        $this->assertNotNull($fresh['first_response_at']);
        $this->assertNotNull($fresh['last_agent_comment_at']);
        $this->assertSame('new', $fresh['status_code']);
        $comment = $this->comments((int) $ticket['id'])[0];
        $this->assertSame(0, (int) $comment['is_requester']);
        $this->assertSame($this->adminId, (int) $comment['author_user_id']);
    }

    public function testReplyToMergedTicketLandsInTarget(): void
    {
        $source = $this->createTicket(['subject' => 'Monitor flackert (Duplikat)']);
        $target = $this->createTicket();
        $this->c->get(TicketMergeService::class)->merge((int) $source['id'], (string) $target['number']);

        $result = $this->ingestion->ingestRaw($this->raw('rita@example.test', 'Re: [' . $source['number'] . '] Monitor', 'Antwort auf das alte Ticket.'));
        $this->assertSame(TicketMailIngestionService::ACTION_COMMENT, $result['action']);
        $this->assertSame((int) $target['id'], $result['ticket_id']);
        $this->assertSame($target['number'], $result['ticket_number']);
        $this->assertCount(1, array_filter($this->comments((int) $target['id']), static fn (array $c): bool => $c['body'] === 'Antwort auf das alte Ticket.'));
    }

    // ------------------------------------------------------------------ Schutzmechanismen

    public function testDuplicateMessageIdIsIgnored(): void
    {
        $raw = $this->raw('gast@extern.test', 'Doppelt', 'Einmal reicht.');
        $first = $this->ingestion->ingestRaw($raw);
        $second = $this->ingestion->ingestRaw($raw);

        $this->assertSame(TicketMailIngestionService::ACTION_CREATED, $first['action']);
        $this->assertSame(TicketMailIngestionService::ACTION_IGNORED, $second['action']);
        $this->assertStringContains('Duplikat', (string) $second['detail']);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM tickets WHERE subject = 'Doppelt'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testAutoRepliesAndBouncesAreIgnored(): void
    {
        $ticket = $this->createTicket();
        $before = count($this->comments((int) $ticket['id']));

        $auto = $this->ingestion->ingestRaw($this->raw('rita@example.test', 'Automatische Antwort: [' . $ticket['number'] . ']', 'Ich bin im Urlaub.', ['Auto-Submitted' => 'auto-replied']));
        $bounce = $this->ingestion->ingestRaw($this->raw('MAILER-DAEMON@mail.test', 'Undelivered Mail Returned to Sender', 'Bounce', []));
        $list = $this->ingestion->ingestRaw($this->raw('news@vendor.test', 'Newsletter März', 'Angebote', ['List-Unsubscribe' => '<mailto:leave@vendor.test>', 'Precedence' => 'bulk']));

        $this->assertSame(TicketMailIngestionService::ACTION_IGNORED, $auto['action']);
        $this->assertSame(TicketMailIngestionService::ACTION_IGNORED, $bounce['action']);
        $this->assertSame(TicketMailIngestionService::ACTION_IGNORED, $list['action']);
        $this->assertCount($before, $this->comments((int) $ticket['id']));

        $logs = $this->c->get(TicketRuleRepository::class)->recentInboundMails(3);
        foreach ($logs as $log) {
            $this->assertSame('ignored', $log['action']);
        }
    }

    public function testUnparsableMessageIsLoggedAsFailed(): void
    {
        $result = $this->ingestion->ingestRaw('', 'u1');
        $this->assertContains($result['action'], [TicketMailIngestionService::ACTION_FAILED, TicketMailIngestionService::ACTION_IGNORED]);
        $this->assertNull($result['ticket_id']);
    }

    // ------------------------------------------------------------------ Anhänge

    public function testAttachmentsAreStoredAndLinked(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        $raw = "From: rita@example.test\r\nSubject: Screenshot\r\nMessage-ID: <att-" . bin2hex(random_bytes(4)) . "@x.test>\r\n"
            . "Content-Type: multipart/mixed; boundary=\"bnd\"\r\n\r\n"
            . "--bnd\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nSiehe Anhang.\r\n"
            . "--bnd\r\nContent-Type: image/png; name=\"fehler.png\"\r\nContent-Disposition: attachment; filename=\"fehler.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($png))
            . "--bnd\r\nContent-Type: application/x-msdownload; name=\"virus.exe\"\r\nContent-Disposition: attachment; filename=\"virus.exe\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode("\x00\x01\x02\xff\xfe" . random_bytes(64)))
            . "--bnd--\r\n";

        $result = $this->ingestion->ingestRaw($raw);
        $this->assertSame(TicketMailIngestionService::ACTION_CREATED, $result['action']);
        $ticket = $this->c->get(TicketService::class)->get((int) $result['ticket_id']);
        $attachments = $this->c->get(TicketService::class)->attachments($ticket);
        $names = array_map(static fn (array $a): string => (string) ($a['original_name'] ?? $a['name'] ?? $a['filename'] ?? ''), $attachments);
        $this->assertCount(1, $attachments);
        $this->assertContains('fehler.png', $names);

        $events = array_map(static fn (array $e): string => (string) $e['type'], $this->c->get(TicketService::class)->timeline((int) $ticket['id']));
        $this->assertContains('attachment_added', $events);
    }
}
