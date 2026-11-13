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
use App\Services\SettingsService;
use App\Support\MailHistory;
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

    /**
     * Nachricht mit Text- und HTML-Teil sowie eingebettetem Bild (cid:), damit die 1:1-Anzeige
     * des HTML-Inhalts geprüft werden kann.
     */
    private function htmlMail(string $from, string $subject, string $text, string $html): string
    {
        $boundary = 'rel' . bin2hex(random_bytes(4));
        $alternative = 'alt' . bin2hex(random_bytes(4));
        $lines = [
            'From: ' . $from,
            'To: helpdesk@example.test',
            'Subject: ' . $subject,
            'Date: ' . gmdate('D, d M Y H:i:s O'),
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@sender.example.test>',
            'Content-Type: multipart/related; boundary="' . $boundary . '"',
        ];
        $body = [
            '--' . $boundary,
            'Content-Type: multipart/alternative; boundary="' . $alternative . '"',
            '',
            '--' . $alternative,
            'Content-Type: text/plain; charset=utf-8',
            '',
            $text,
            '--' . $alternative,
            'Content-Type: text/html; charset=utf-8',
            '',
            $html,
            '--' . $alternative . '--',
            '--' . $boundary,
            'Content-Type: image/png',
            'Content-Transfer-Encoding: base64',
            'Content-ID: <logo@example.test>',
            'Content-Disposition: inline; filename="logo.png"',
            '',
            chunk_split(base64_encode('PNGDATA'), 76, "\r\n"),
            '--' . $boundary . '--',
            '',
        ];

        return implode("\r\n", $lines) . "\r\n\r\n" . implode("\r\n", $body);
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
        // Der zitierte Verlauf bleibt erhalten, steht aber hinter der Markierung (eingeklappt angezeigt)
        [$main, $history] = MailHistory::splitText((string) $ticket['description']);
        $this->assertFalse(str_contains($main, '> alt'));
        $this->assertStringContains('Eingegangen per E-Mail von Gast Nutzer <gast@extern.test>', $main);
        $this->assertSame("Am 01.03.2026 schrieb jemand:\n> alt", $history);
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

    public function testUnknownSenderAppearsAsRequesterInTicketList(): void
    {
        $result = $this->ingestion->ingestRaw($this->raw('Gast Nutzer <gast@extern.test>', 'Beamer defekt', 'Der Beamer startet nicht.'));

        $this->assertSame(TicketMailIngestionService::ACTION_CREATED, $result['action']);
        $ticket = $this->c->get(TicketRepository::class)->find((int) $result['ticket_id']);
        $this->assertNotNull($ticket);
        // Auch ohne Mitarbeiter-/Benutzerdatensatz steht der erkannte Absender als Melder in der Liste.
        $this->assertSame('Gast Nutzer', $ticket['requester_name']);
        $this->assertSame('gast@extern.test', $ticket['requester_email']);

        $rows = $this->c->get(TicketRepository::class)->search(['view' => 'all', 'q' => 'Gast Nutzer'], 'updated_at', 'desc', 10, 0);
        $this->assertCount(1, $rows);
        $this->assertSame('Gast Nutzer', $rows[0]['requester_name']);
    }

    public function testKnownEmployeeBecomesRequester(): void
    {
        $result = $this->ingestion->ingestRaw($this->raw('rita@example.test', 'Tastatur defekt', 'Mehrere Tasten reagieren nicht.'));

        $this->assertSame(TicketMailIngestionService::ACTION_CREATED, $result['action']);
        $ticket = $this->c->get(TicketRepository::class)->find((int) $result['ticket_id']);
        $this->assertSame($this->employeeId, (int) $ticket['requester_employee_id']);
        $this->assertSame('rita@example.test', $ticket['requester_email']);
    }

    public function testKnownUserWithoutEmployeeBecomesRequester(): void
    {
        // Benutzerkonto ohne Mitarbeiterdatensatz (z. B. per Intranet-SSO angelegt)
        $stmt = $this->pdo->prepare("INSERT INTO users (username, display_name, email, role_id, auth_source, is_active) SELECT 'julia.portal', 'Julia Portal', 'julia@example.test', id, 'local', 1 FROM roles WHERE name = 'portal'");
        $stmt->execute();
        $userId = (int) $this->pdo->lastInsertId();

        $result = $this->ingestion->ingestRaw($this->raw('Julia Portal <julia@example.test>', 'Scanner defekt', 'Der Scanner zieht kein Papier ein.'));

        $this->assertSame(TicketMailIngestionService::ACTION_CREATED, $result['action']);
        $ticket = $this->c->get(TicketRepository::class)->find((int) $result['ticket_id']);
        $this->assertNotNull($ticket);
        // Der Absender selbst ist Melder – nicht das Systemkonto des E-Mail-Eingangs.
        $this->assertSame($userId, (int) $ticket['requester_user_id']);
        $this->assertNull($ticket['requester_employee_id']);
        $this->assertSame('Julia Portal', $ticket['requester_name']);
    }

    public function testEmptySubjectAndBodyStillCreateTicket(): void
    {
        $result = $this->ingestion->ingestRaw($this->raw('x@extern.test', '', ''));
        $this->assertSame(TicketMailIngestionService::ACTION_CREATED, $result['action']);
        $ticket = $this->c->get(TicketRepository::class)->find((int) $result['ticket_id']);
        $this->assertSame('E-Mail ohne Betreff', $ticket['subject']);
    }

    // ------------------------------------------------------------------ HTML-Inhalt

    public function testHtmlMailIsAdoptedForTicketDescription(): void
    {
        $html = '<html><head><style>p{color:red}</style></head><body>'
            . '<p>Bitte <b>prüfen</b>:</p><img src="cid:logo@example.test" alt="Logo">'
            . '<script>alert(1)</script></body></html>';
        $result = $this->ingestion->ingestRaw($this->htmlMail('Gast Nutzer <gast@extern.test>', 'Drucker streikt', 'Bitte prüfen:', $html), '31');

        $this->assertSame(TicketMailIngestionService::ACTION_CREATED, $result['action']);
        $ticket = $this->c->get(TicketRepository::class)->find((int) $result['ticket_id']);
        $this->assertNotNull($ticket);
        // Klartext bleibt maßgeblich für Suche, Benachrichtigungen, API und Export
        $this->assertStringContains('Bitte prüfen:', (string) $ticket['description']);
        $this->assertStringContains('Eingegangen per E-Mail von Gast Nutzer <gast@extern.test>', (string) $ticket['description']);
        // Die HTML-Fassung wird unverändert übernommen, aber bereinigt gespeichert
        $descriptionHtml = (string) $ticket['description_html'];
        $this->assertStringContains('<p>Bitte <b>prüfen</b>:</p>', $descriptionHtml);
        $this->assertStringContains('data:image/png;base64,', $descriptionHtml);
        $this->assertStringContains('Eingegangen per E-Mail von Gast Nutzer &lt;gast@extern.test&gt;', $descriptionHtml);
        $this->assertFalse(str_contains($descriptionHtml, 'alert(1)'));
        // Stylesheets bleiben für die isolierte Anzeige im Sandbox-Rahmen erhalten
        $this->assertStringContains('<style>p{color:red}</style>', $descriptionHtml);
        $this->assertFalse(str_contains($descriptionHtml, 'cid:logo'));
    }

    public function testForwardedOutlookMailKeepsCollapsedHistory(): void
    {
        $text = "Hallo zusammen,\n\ndie ist bestimmt für Euch\n\n-----Ursprüngliche Nachricht-----\nVon: phoenix@khwf.de <phoenix@khwf.de>\nGesendet: Dienstag, 3. November 2026 09:45\nAn: Buchhaltung\nBetreff: Dell Support-Verträge\n\nFolgende E-Mail konnte nicht automatisch verarbeitet werden.";
        $html = '<html><body><div class="WordSection1"><p>Hallo zusammen,</p><p>die ist bestimmt für Euch</p>'
            . '<p>-----Ursprüngliche Nachricht-----<br><b>Von:</b> phoenix@khwf.de<br><b>Gesendet:</b> Dienstag, 3. November 2026 09:45</p>'
            . '<p>Folgende E-Mail konnte nicht automatisch verarbeitet werden.</p></div></body></html>';
        $result = $this->ingestion->ingestRaw($this->htmlMail('Buchhaltung <buchhaltung@extern.test>', 'WG: Dell Support-Verträge', $text, $html), '41');

        $this->assertSame(TicketMailIngestionService::ACTION_CREATED, $result['action']);
        $ticket = $this->c->get(TicketRepository::class)->find((int) $result['ticket_id']);
        [$main, $history] = MailHistory::splitText((string) $ticket['description']);
        $this->assertStringContains('die ist bestimmt für Euch', $main);
        $this->assertFalse(str_contains($main, 'Ursprüngliche Nachricht'));
        $this->assertStringContains('-----Ursprüngliche Nachricht-----', (string) $history);
        $this->assertStringContains('Folgende E-Mail konnte nicht automatisch verarbeitet werden.', (string) $history);

        $descriptionHtml = (string) $ticket['description_html'];
        $this->assertStringContains('<details class="mail-history"><summary>Bisheriger E-Mail-Verlauf</summary>', $descriptionHtml);
        $this->assertStringContains('Folgende E-Mail konnte nicht automatisch verarbeitet werden.', $descriptionHtml);
        $this->assertTrue(strpos($descriptionHtml, 'die ist bestimmt') < strpos($descriptionHtml, '<details'));
    }

    public function testHtmlReplyIsAdoptedForCommentBody(): void
    {
        $ticket = $this->createTicket();
        $result = $this->ingestion->ingestRaw($this->htmlMail('rita@example.test', 'Re: [' . $ticket['number'] . '] Monitor flackert', 'Danke, behoben.', '<p>Danke, <i>behoben</i>.</p>'), '32');

        $this->assertSame(TicketMailIngestionService::ACTION_COMMENT, $result['action']);
        $comments = $this->comments((int) $ticket['id']);
        $this->assertCount(1, $comments);
        $this->assertSame('Danke, behoben.', $comments[0]['body']);
        $this->assertStringContains('<p>Danke, <i>behoben</i>.</p>', (string) $comments[0]['body_html']);
    }

    public function testPlainTextMailKeepsHtmlColumnEmpty(): void
    {
        $result = $this->ingestion->ingestRaw($this->raw('gast@extern.test', 'Nur Text', 'Reiner Text ohne Auszeichnung.'));
        $ticket = $this->c->get(TicketRepository::class)->find((int) $result['ticket_id']);
        $this->assertNull($ticket['description_html']);
    }

    /**
     * Ein Bearbeiten ohne echte Textänderung darf die HTML-Fassung nicht verwerfen:
     * Browser-Textareas senden CRLF, der eingegangene Klartext enthält LF.
     */
    public function testSavingUnchangedDescriptionKeepsHtml(): void
    {
        $result = $this->ingestion->ingestRaw($this->htmlMail(
            'Gast Nutzer <gast@extern.test>',
            'Drucker streikt',
            "Zeile eins\nZeile zwei",
            '<p>Zeile eins<br>Zeile zwei</p>'
        ), '33');
        $ticket = $this->c->get(TicketRepository::class)->find((int) $result['ticket_id']);
        $this->assertNotNull($ticket);

        $submitted = str_replace("\n", "\r\n", (string) $ticket['description']);
        $updated = $this->c->get(TicketService::class)->update((int) $ticket['id'], [
            'subject' => 'Drucker streikt weiterhin',
            'description' => $submitted,
            'ticket_type_id' => (int) $ticket['ticket_type_id'],
        ], (int) $ticket['version']);

        $this->assertSame('Drucker streikt weiterhin', $updated['subject']);
        $this->assertNotNull($updated['description_html']);
        $this->assertStringContains('<p>Zeile eins<br>Zeile zwei</p>', (string) $updated['description_html']);
        $this->assertFalse(str_contains((string) $updated['description'], "\r"));
    }

    /** Eine inhaltlich geänderte Beschreibung ersetzt weiterhin die HTML-Fassung. */
    public function testChangingDescriptionDropsHtml(): void
    {
        $result = $this->ingestion->ingestRaw($this->htmlMail(
            'Gast Nutzer <gast@extern.test>',
            'Drucker streikt',
            'Bitte prüfen.',
            '<p>Bitte <b>prüfen</b>.</p>'
        ), '34');
        $ticket = $this->c->get(TicketRepository::class)->find((int) $result['ticket_id']);
        $this->assertNotNull($ticket);

        $updated = $this->c->get(TicketService::class)->update((int) $ticket['id'], [
            'subject' => (string) $ticket['subject'],
            'description' => 'Bitte dringend prüfen.',
            'ticket_type_id' => (int) $ticket['ticket_type_id'],
        ], (int) $ticket['version']);

        $this->assertNull($updated['description_html']);
        $this->assertSame('Bitte dringend prüfen.', $updated['description']);
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
        $service->changeStatus((int) $ticket['id'], 'waiting_feedback', 'Bitte Kabel prüfen.', null, null, null, false, date('Y-m-d H:i', strtotime('+2 days')));
        $this->assertSame('waiting_feedback', $service->get((int) $ticket['id'])['status_code']);

        $result = $this->ingestion->ingestRaw($this->raw('rita@example.test', 'Re: [' . $ticket['number'] . '] Monitor', 'Kabel geprüft, Problem bleibt.'));
        $this->assertSame(TicketMailIngestionService::ACTION_COMMENT, $result['action']);
        $fresh = $service->get((int) $ticket['id']);
        $this->assertSame('in_progress', $fresh['status_code']);
        $this->assertNull($fresh['sla_paused_at']);
        $this->assertNull($fresh['paused_until']);
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

    // ------------------------------------------------------------------ Abruf

    public function testPullKeepsLoggedInUserWhileWorkingAsSystemUser(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'klinq-mail-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        file_put_contents($dir . DIRECTORY_SEPARATOR . '001.eml', $this->raw('gast@extern.test', 'Drucker streikt', 'Bitte prüfen.'));

        // Systembenutzer ohne settings.manage: Ein login() würde die Sitzung des Administrators
        // ersetzen, sodass der Redirect nach /admin/helpdesk-mail mit 403 endet.
        $stmt = $this->pdo->prepare("INSERT INTO users (username, display_name, email, role_id, auth_source, is_active) SELECT 'hd-mail-system', 'HD System', 'hd-system@example.test', id, 'local', 1 FROM roles WHERE name = 'helpdesk_agent'");
        $stmt->execute();
        $systemId = (int) $this->pdo->lastInsertId();

        $this->c->get(SettingsService::class)->setMany([
            'helpdesk.mail.enabled' => '1',
            'helpdesk.mail.driver' => 'file',
            'helpdesk.mail.file_path' => $dir,
            'helpdesk.mail.system_user' => 'hd-mail-system',
        ], $this->adminId);

        try {
            $result = $this->ingestion->pull(5);

            $this->assertSame(1, $result['fetched']);
            $this->assertSame(1, $result['created']);
            $this->assertSame(0, $result['failed']);
            $createdBy = (int) $this->pdo->query("SELECT created_by FROM tickets WHERE subject = 'Drucker streikt'")->fetchColumn();
            $this->assertSame($systemId, $createdBy, 'Ticket wird im Kontext des Systembenutzers angelegt');

            $current = $this->c->get(CurrentUser::class);
            $this->assertSame($this->adminId, $current->id(), 'Sitzung bleibt beim angemeldeten Administrator');
            $this->assertSame('helpdesk_admin', $current->role());
        } finally {
            $this->removeDirectory($dir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
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
