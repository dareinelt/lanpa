<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Helpdesk\Mail\InboundMail;
use App\Services\Helpdesk\Mail\MimeMessageParser;
use App\Services\Helpdesk\TicketMailIngestionService;
use App\Services\Helpdesk\TicketNotificationService;
use Tests\Support\TestCase;

/** MIME-Parser, Zitat-Erkennung und Ticket-Zuordnung des E-Mail-Eingangs (ohne Datenbank). */
final class HelpdeskMailParserTest extends TestCase
{
    private function parser(): MimeMessageParser
    {
        return new MimeMessageParser();
    }

    // ------------------------------------------------------------------ Einfache Nachrichten

    public function testParsesPlainTextMessage(): void
    {
        $raw = "From: Max Mustermann <max@example.test>\r\n"
            . "To: helpdesk@example.test\r\n"
            . "Subject: Drucker im 2. OG druckt nicht\r\n"
            . "Date: Mon, 02 Mar 2026 09:15:00 +0100\r\n"
            . "Message-ID: <abc123@mail.example.test>\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "Hallo Team,\r\nder Drucker zeigt Papierstau, obwohl keins drin ist.\r\n";

        $mail = $this->parser()->parse($raw);
        $this->assertSame('<abc123@mail.example.test>', $mail->messageId);
        $this->assertSame('max@example.test', $mail->fromAddress);
        $this->assertSame('Max Mustermann', $mail->fromName);
        $this->assertSame('Drucker im 2. OG druckt nicht', $mail->subject);
        $this->assertStringContains('Papierstau', $mail->text);
        $this->assertNotNull($mail->date);
        $this->assertSame('2026-03-02 08:15', $mail->date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
        $this->assertNull($mail->inReplyTo);
        $this->assertCount(0, $mail->attachments);
    }

    public function testDecodesRfc2047HeadersAndFoldedLines(): void
    {
        $raw = "From: =?UTF-8?Q?J=C3=BCrgen_M=C3=BCller?= <juergen@example.test>\n"
            . "Subject: =?utf-8?B?VW1sYXV0ZTogxJbDpMO8IMOfIFRlc3Q=?=\n"
            . " =?utf-8?Q?_Fortsetzung?=\n"
            . "Message-ID: <fold@example.test>\n"
            . "\n"
            . "Text\n";

        $mail = $this->parser()->parse($raw);
        $this->assertSame('Jürgen Müller', $mail->fromName);
        $this->assertSame('juergen@example.test', $mail->fromAddress);
        $this->assertStringContains('Umlaute:', $mail->subject);
        $this->assertStringContains('äü ß Test', $mail->subject);
        $this->assertStringContains('Fortsetzung', $mail->subject);
    }

    public function testConvertsLegacyCharsetAndQuotedPrintable(): void
    {
        $body = quoted_printable_encode(mb_convert_encoding('Größe: 5 € Übergabe', 'ISO-8859-15', 'UTF-8'));
        $raw = "From: a@example.test\nSubject: Test\nMessage-ID: <qp@example.test>\n"
            . "Content-Type: text/plain; charset=iso-8859-15\nContent-Transfer-Encoding: quoted-printable\n\n"
            . $body . "\n";

        $mail = $this->parser()->parse($raw);
        $this->assertSame('Größe: 5 € Übergabe', $mail->text);
    }

    public function testFallsBackToGeneratedMessageIdWhenMissing(): void
    {
        $raw = "From: a@example.test\nSubject: Ohne ID\nDate: Mon, 02 Mar 2026 09:15:00 +0100\n\nInhalt\n";
        $first = $this->parser()->parse($raw);
        $second = $this->parser()->parse($raw);
        $this->assertMatches('/^<[a-f0-9]{64}@generated\.local>$/', $first->messageId);
        $this->assertSame($first->messageId, $second->messageId);
    }

    // ------------------------------------------------------------------ Multipart

    public function testMultipartAlternativePrefersPlainTextAndCollectsAttachments(): void
    {
        $pdf = "%PDF-1.4 fake";
        $raw = "From: Rita <rita@example.test>\n"
            . "Subject: Re: [HD-2026-000042] Monitor flackert\n"
            . "Message-ID: <reply-1@example.test>\n"
            . "In-Reply-To: <ticket-42.created.deadbeef@assets.example.test>\n"
            . "References: <ticket-42@assets.example.test> <ticket-42.created.deadbeef@assets.example.test>\n"
            . "Content-Type: multipart/mixed; boundary=\"outer\"\n"
            . "\n"
            . "--outer\n"
            . "Content-Type: multipart/alternative; boundary=\"inner\"\n"
            . "\n"
            . "--inner\n"
            . "Content-Type: text/plain; charset=utf-8\n"
            . "Content-Transfer-Encoding: base64\n"
            . "\n"
            . chunk_split(base64_encode("Danke, das Problem besteht weiterhin.\n\nAm 01.03.2026 schrieb Helpdesk <helpdesk@example.test>:\n> Bitte Kabel prüfen.\n"))
            . "--inner\n"
            . "Content-Type: text/html; charset=utf-8\n"
            . "\n"
            . "<html><body><p>Danke, das Problem besteht <b>weiterhin</b>.</p></body></html>\n"
            . "--inner--\n"
            . "--outer\n"
            . "Content-Type: application/pdf; name=\"screenshot.pdf\"\n"
            . "Content-Disposition: attachment; filename=\"screenshot.pdf\"\n"
            . "Content-Transfer-Encoding: base64\n"
            . "\n"
            . chunk_split(base64_encode($pdf))
            . "--outer--\n";

        $mail = $this->parser()->parse($raw);
        $this->assertSame('Danke, das Problem besteht weiterhin.', $mail->text);
        $this->assertSame('<ticket-42.created.deadbeef@assets.example.test>', $mail->inReplyTo);
        $this->assertCount(2, $mail->references);
        $this->assertSame('<ticket-42@assets.example.test>', $mail->references[0]);
        $this->assertCount(1, $mail->attachments);
        $this->assertSame('screenshot.pdf', $mail->attachments[0]['filename']);
        $this->assertSame('application/pdf', $mail->attachments[0]['mime_type']);
        $this->assertSame($pdf, $mail->attachments[0]['content']);

        // In-Reply-To zuerst, dann References von neu nach alt – ohne Duplikate
        $ids = $mail->referencedIds();
        $this->assertSame('<ticket-42.created.deadbeef@assets.example.test>', $ids[0]);
        $this->assertSame('<ticket-42@assets.example.test>', $ids[1]);
        $this->assertCount(2, $ids);
    }

    public function testHtmlOnlyMessageIsConvertedToText(): void
    {
        $raw = "From: a@example.test\nSubject: HTML\nMessage-ID: <html@example.test>\n"
            . "Content-Type: text/html; charset=utf-8\n\n"
            . "<html><head><style>p{color:red}</style></head><body><p>Erste Zeile</p><ul><li>Punkt&nbsp;1</li><li>Punkt 2</li></ul><script>alert(1)</script></body></html>";

        $mail = $this->parser()->parse($raw);
        $this->assertStringContains("Erste Zeile\n", $mail->text);
        $this->assertStringContains('- Punkt 1', $mail->text);
        $this->assertStringContains('- Punkt 2', $mail->text);
        $this->assertFalse(str_contains($mail->text, 'alert'));
        $this->assertFalse(str_contains($mail->text, 'color'));
    }

    public function testRfc2231FilenameIsDecoded(): void
    {
        $raw = "From: a@example.test\nSubject: Anhang\nMessage-ID: <2231@example.test>\n"
            . "Content-Type: multipart/mixed; boundary=b\n\n"
            . "--b\nContent-Type: text/plain\n\nText\n"
            . "--b\nContent-Type: application/octet-stream\n"
            . "Content-Disposition: attachment; filename*=UTF-8''Ma%C3%9Fnahmen%20%C3%9Cbersicht.txt\n\n"
            . "Inhalt\n--b--\n";

        $mail = $this->parser()->parse($raw);
        $this->assertCount(1, $mail->attachments);
        $this->assertSame('Maßnahmen Übersicht.txt', $mail->attachments[0]['filename']);
    }

    // ------------------------------------------------------------------ Zitat-Erkennung

    public function testStripQuotedReplyRemovesOutlookAndGmailQuotes(): void
    {
        $outlook = "Erledigt, danke!\n\nVon: Helpdesk <hd@example.test>\nGesendet: Montag, 2. März 2026 09:00\nAn: Max\nBetreff: [HD-2026-000001] Test\n\nBitte prüfen.";
        $this->assertSame('Erledigt, danke!', MimeMessageParser::stripQuotedReply($outlook));

        $gmail = "Funktioniert wieder.\n\nAm Mo., 2. März 2026 um 09:00 Uhr schrieb Helpdesk <hd@example.test>:\n> Bitte prüfen.\n> Danke";
        $this->assertSame('Funktioniert wieder.', MimeMessageParser::stripQuotedReply($gmail));

        $original = "Noch offen.\n\n-----Ursprüngliche Nachricht-----\nVon: x\nAlt";
        $this->assertSame('Noch offen.', MimeMessageParser::stripQuotedReply($original));

        $trailingQuotes = "Antwort oben.\n\n> alte Zeile 1\n> alte Zeile 2\n";
        $this->assertSame('Antwort oben.', MimeMessageParser::stripQuotedReply($trailingQuotes));
    }

    public function testStripQuotedReplyKeepsInlineAnswers(): void
    {
        // Bei Inline-Antworten bleibt der Kontext (Zitat + Antwort) erhalten, da die Antwort allein sinnlos wäre
        $inline = "> Welche Fehlermeldung?\nCode 0x80070005\n> Seit wann?\nSeit gestern";
        $result = MimeMessageParser::stripQuotedReply($inline);
        $this->assertStringContains('Code 0x80070005', $result);
        $this->assertStringContains('Seit gestern', $result);
        $this->assertStringContains('Welche Fehlermeldung', $result);
    }

    public function testStripQuotedReplyNeverReturnsEmptyForNonEmptyInput(): void
    {
        $this->assertSame('> nur Zitat', MimeMessageParser::stripQuotedReply('> nur Zitat'));
    }

    // ------------------------------------------------------------------ Adress-/ID-Hilfen

    public function testParseAddressVariants(): void
    {
        $this->assertSame(['max@example.test', 'Max Mustermann'], MimeMessageParser::parseAddress('"Max Mustermann" <Max@Example.test>'));
        $this->assertSame(['max@example.test', ''], MimeMessageParser::parseAddress('max@example.test'));
        $this->assertSame(['max@example.test', 'Max'], MimeMessageParser::parseAddress('Max <max@example.test>, other@example.test'));
        $this->assertSame(['', ''], MimeMessageParser::parseAddress(''));
    }

    public function testNormalizeIdAndIdList(): void
    {
        $this->assertSame('<abc@x.test>', MimeMessageParser::normalizeId('  abc@x.test '));
        $this->assertSame('<abc@x.test>', MimeMessageParser::normalizeId('<abc@x.test>'));
        $this->assertSame('', MimeMessageParser::normalizeId(''));
        $list = MimeMessageParser::parseIdList("<a@x.test>\n <b@x.test> <c@x.test>");
        $this->assertSame(['<a@x.test>', '<b@x.test>', '<c@x.test>'], $list);
    }

    // ------------------------------------------------------------------ Threading

    public function testTicketIdFromMessageIdRecognisesOwnFormat(): void
    {
        $this->assertSame(42, TicketNotificationService::ticketIdFromMessageId('<ticket-42@assets.example.test>'));
        $this->assertSame(42, TicketNotificationService::ticketIdFromMessageId('<ticket-42.created.0badf00d@assets.example.test>'));
        $this->assertSame(7, TicketNotificationService::ticketIdFromMessageId(' <TICKET-7.comment.ab@x> '));
        $this->assertNull(TicketNotificationService::ticketIdFromMessageId('<ticket-abc@x>'));
        // Fremde IDs, die „ticket-“ nur enthalten, dürfen nicht zugeordnet werden
        $this->assertNull(TicketNotificationService::ticketIdFromMessageId('<CAF+ticket-42@mail.gmail.com>'));
        $this->assertNull(TicketNotificationService::ticketIdFromMessageId('<random@example.test>'));
    }

    public function testExtractTicketNumberFromSubject(): void
    {
        $this->assertSame('HD-2026-000012', TicketMailIngestionService::extractTicketNumber('Re: [HD-2026-000012] Drucker'));
        $this->assertSame('HD-2026-000012', TicketMailIngestionService::extractTicketNumber('AW: hd-2026-000012 Drucker'));
        $this->assertSame('IT-2025-1234567', TicketMailIngestionService::extractTicketNumber('WG: Ticket IT-2025-1234567 erneut'));
        $this->assertNull(TicketMailIngestionService::extractTicketNumber('Drucker kaputt'));
        $this->assertNull(TicketMailIngestionService::extractTicketNumber('Rechnung 2026-000012'));
        $this->assertNull(TicketMailIngestionService::extractTicketNumber('HD-2026-123'));
    }

    // ------------------------------------------------------------------ Auto-Reply-Erkennung

    public function testIsAutomaticDetectsAutoRepliesBouncesAndLists(): void
    {
        $this->assertTrue($this->mail(['auto-submitted' => 'auto-replied'])->isAutomatic());
        $this->assertFalse($this->mail(['auto-submitted' => 'no'])->isAutomatic());
        $this->assertTrue($this->mail(['x-auto-response-suppress' => 'All'])->isAutomatic());
        $this->assertTrue($this->mail(['precedence' => 'bulk'])->isAutomatic());
        $this->assertTrue($this->mail(['list-unsubscribe' => '<mailto:x>'])->isAutomatic());
        $this->assertTrue($this->mail(['content-type' => 'multipart/report; report-type=delivery-status'])->isAutomatic());
        $this->assertTrue($this->mail([], 'MAILER-DAEMON@example.test')->isAutomatic());
        $this->assertTrue($this->mail([], 'noreply@example.test')->isAutomatic());
        $this->assertTrue($this->mail([], '')->isAutomatic());
        $this->assertFalse($this->mail([], 'max@example.test')->isAutomatic());
    }

    /** @param array<string,string> $headers */
    private function mail(array $headers, string $from = 'max@example.test'): InboundMail
    {
        return new InboundMail('<m@x>', $from, 'Max', 'Betreff', 'Text', null, null, [], [], $headers);
    }
}
