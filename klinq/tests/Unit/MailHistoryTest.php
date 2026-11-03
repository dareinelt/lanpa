<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use App\Support\MailHistory;
use App\Support\RichText;
use Tests\Support\TestCase;

/** Zitierter E-Mail-Verlauf: bleibt im Ticket erhalten und wird aufklappbar angezeigt. */
final class MailHistoryTest extends TestCase
{
    public function testTextRoundTrip(): void
    {
        $text = MailHistory::appendText("Hallo\n\n— Eingegangen per E-Mail von x", "-----Original Message-----\nAlt");
        [$main, $history] = MailHistory::splitText($text);
        $this->assertSame("Hallo\n\n— Eingegangen per E-Mail von x", $main);
        $this->assertSame("-----Original Message-----\nAlt", $history);

        $this->assertSame('Ohne', MailHistory::appendText('Ohne', '  '));
        $this->assertSame(['Ohne', null], MailHistory::splitText('Ohne'));
    }

    public function testRichTextRendersCollapsibleHistory(): void
    {
        $html = RichText::toHtml(MailHistory::appendText('Neu <b>', "Alt\n> <script>"));
        $this->assertStringContains('Neu &lt;b&gt;', $html);
        $this->assertStringContains('<details class="mail-history"><summary>Bisheriger E-Mail-Verlauf</summary>', $html);
        $this->assertStringContains('&gt; &lt;script&gt;', $html);
        $this->assertFalse(str_contains($html, '<script>'));
        $this->assertTrue(strpos($html, 'Neu') < strpos($html, '<details'));
    }

    public function testOutlookDesktopHeaderBlockIsCollapsedWithFollowingContent(): void
    {
        $html = '<html><head><style>p{margin:0}</style></head><body><div class="WordSection1">'
            . '<p>Bitte erledigen.</p><p>Gruß</p>'
            . '<div><div style="border:none;border-top:solid #E1E1E1 1.0pt;padding:3.0pt 0cm 0cm 0cm">'
            . '<p><b>Von:</b> a@example.test<br><b>Gesendet:</b> Montag<br><b>Betreff:</b> X</p></div></div>'
            . '<p>Alter Inhalt</p></div></body></html>';
        $out = MailHistory::collapseHtml($html);

        $this->assertStringContains('<style>p{margin:0}</style>', $out);
        $details = strpos($out, '<details class="mail-history">');
        $this->assertTrue($details !== false);
        $this->assertTrue(strpos($out, 'Gruß') < $details);
        $this->assertTrue(strpos($out, 'Von:') > $details);
        $this->assertTrue(strpos($out, 'Alter Inhalt') > $details);

        // Übersteht die Bereinigung für den Anzeigerahmen
        $clean = HtmlSanitizer::mailDocument($out);
        $this->assertStringContains('<details class="mail-history"><summary>Bisheriger E-Mail-Verlauf</summary>', $clean);
        $this->assertStringContains('Alter Inhalt', $clean);
    }

    public function testGmailQuoteAndOutlookWebAreDetected(): void
    {
        $gmail = MailHistory::collapseHtml('<div dir="ltr">Antwort</div><div class="gmail_quote"><div class="gmail_attr">Am Mo. schrieb x:</div><blockquote>Alt</blockquote></div>');
        $this->assertStringContains('<details class="mail-history"><summary>Bisheriger E-Mail-Verlauf</summary><div class="gmail_quote">', $gmail);

        $owa = MailHistory::collapseHtml('<div>Antwort</div><hr style="display:inline-block;width:98%"><div id="divRplyFwdMsg"><b>From:</b> x</div><div>Alt</div>');
        $this->assertStringContains('<summary>Bisheriger E-Mail-Verlauf</summary><hr', $owa);
        $this->assertTrue(strpos($owa, '<div>Alt</div>') > strpos($owa, '<details'));
    }

    public function testNothingIsCollapsedWithoutOwnTextOrHistory(): void
    {
        $plain = '<p>Nur eine Nachricht.</p>';
        $this->assertSame($plain, MailHistory::collapseHtml($plain));

        // Reine Weiterleitung ohne eigenen Text: Der Inhalt bleibt sichtbar
        $forward = '<p>-----Original Message-----<br>From: x</p><p>Inhalt</p>';
        $this->assertSame($forward, MailHistory::collapseHtml($forward));
    }

    public function testUmlautsSurvive(): void
    {
        $out = MailHistory::collapseHtml('<p>Grüße</p><p>-----Ursprüngliche Nachricht-----</p><p>Größe</p>');
        $clean = HtmlSanitizer::mailDocument($out);
        $this->assertStringContains('Grüße', html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringContains('Größe', html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringContains('<details', $clean);
    }
}
