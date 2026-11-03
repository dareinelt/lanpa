<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use Tests\Support\TestCase;

/**
 * Aufbereitung des HTML aus dem E-Mail-Editor: erlaubte Auszeichnung bleibt,
 * Skripte, Ereignisattribute und fremde Verweise fallen weg.
 */
final class HtmlSanitizerTest extends TestCase
{
    public function testFormattingIsPreserved(): void
    {
        $html = HtmlSanitizer::clean('<p>Hallo <strong>Max</strong>, <em>bitte</em> prüfen.</p>');

        $this->assertStringContains('<p>', $html);
        $this->assertStringContains('<strong>Max</strong>', $html);
        $this->assertStringContains('<em>bitte</em>', $html);
    }

    public function testScriptsAndStylesAreRemovedWithTheirContent(): void
    {
        $html = HtmlSanitizer::clean('<p>Text</p><script>alert(1)</script><style>p{color:red}</style><iframe src="https://example.test"></iframe>');

        $this->assertSame('<p>Text</p>', $html);
    }

    public function testCharsetMetaTagsDoNotGarbleUmlauts(): void
    {
        $html = HtmlSanitizer::clean('<html><head><meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1"><title>Betreff</title></head><body><p>Grüße aus Köln</p></body></html>');

        $this->assertSame('<p>Grüße aus Köln</p>', $html, 'Kopfbereich und Titel fallen weg, Umlaute bleiben erhalten');
    }

    public function testContentAfterTheBodyIsKept(): void
    {
        $html = HtmlSanitizer::clean('<html><body><p>Text</p></body></html>' . "\n<p>— Eingegangen per E-Mail</p>");

        $this->assertStringContains('<p>Text</p>', $html);
        $this->assertStringContains('Eingegangen per E-Mail', $html);
    }

    public function testContentAfterAStrayClosingTagIsKept(): void
    {
        $html = HtmlSanitizer::clean('<p>Erster Teil</p></div><p>Zweiter Teil</p>');

        $this->assertStringContains('Erster Teil', $html);
        $this->assertStringContains('Zweiter Teil', $html);
    }

    public function testClassesCanBeDroppedForExternalMail(): void
    {
        $this->assertSame('<p class="hint">Text</p>', HtmlSanitizer::clean('<p class="hint">Text</p>'));
        $this->assertSame('<p>Text</p>', HtmlSanitizer::clean('<p class="modal-overlay">Text</p>', 0, false));
    }

    public function testEventAttributesAreRemoved(): void
    {
        $html = HtmlSanitizer::clean('<p onclick="alert(1)" onmouseover="x()">Text</p>');

        $this->assertSame('<p>Text</p>', $html);
    }

    public function testScriptUrlsInLinksAreRemoved(): void
    {
        $html = HtmlSanitizer::clean('<a href="javascript:alert(1)">Klick</a>');

        $this->assertSame('<a>Klick</a>', $html);
    }

    public function testExternalLinksOpenInANewWindow(): void
    {
        $html = HtmlSanitizer::clean('<a href="https://example.test/info">Info</a>');

        $this->assertStringContains('href="https://example.test/info"', $html);
        $this->assertStringContains('target="_blank"', $html);
        $this->assertStringContains('rel="noopener noreferrer"', $html);
    }

    public function testUnknownElementsKeepTheirText(): void
    {
        $html = HtmlSanitizer::clean('<custom-tag>wichtig</custom-tag>');

        $this->assertSame('wichtig', $html);
    }

    public function testImagesAreLimitedToWebAndDataSources(): void
    {
        $this->assertStringContains(
            'data:image/png;base64,',
            HtmlSanitizer::clean('<img src="data:image/png;base64,iVBORw0KGgo=" alt="Bild">')
        );
        $this->assertSame('', HtmlSanitizer::clean('<img src="data:text/html;base64,PHNjcmlwdD4=">'));
        $this->assertSame('', HtmlSanitizer::clean('<img alt="ohne Quelle">'));
    }

    public function testStyleAttributeIsCheckedForActiveContent(): void
    {
        $this->assertStringContains('color:#222', HtmlSanitizer::clean('<span style="color:#222">Text</span>'));
        $this->assertSame('<span>Text</span>', HtmlSanitizer::clean('<span style="background:url(https://example.test/x.png)">Text</span>'));
        $this->assertSame('<span>Text</span>', HtmlSanitizer::clean('<span style="position:fixed;top:0">Text</span>'));
    }

    public function testCommentsAreRemoved(): void
    {
        $this->assertSame('<p>Text</p>', HtmlSanitizer::clean('<p>Text</p><!-- geheim -->'));
    }

    public function testInputIsTruncatedAtTheLimit(): void
    {
        $html = HtmlSanitizer::clean('<p>' . str_repeat('a', 500) . '</p>', 20);

        $this->assertTrue(mb_strlen($html) < 100, 'Die Eingabe wird vor dem Parsen gekürzt.');
    }

    public function testEmptyInputStaysEmpty(): void
    {
        $this->assertSame('', HtmlSanitizer::clean('   '));
        $this->assertSame('', HtmlSanitizer::plain(''));
    }

    public function testPlainTextKeepsParagraphAndListStructure(): void
    {
        $text = HtmlSanitizer::plain('<p>Erster Absatz</p><ul><li>Punkt A</li><li>Punkt B</li></ul>');

        $this->assertSame("Erster Absatz\n– Punkt A\n– Punkt B", $text);
    }

    public function testPlainTextDecodesEntitiesAndNormalisesWhitespace(): void
    {
        $text = HtmlSanitizer::plain('<p>M&uuml;ller &amp; S&ouml;hne<br>Zeile&nbsp;zwei</p>');

        $this->assertSame("Müller & Söhne\nZeile zwei", $text);
    }

    public function testMailDocumentKeepsFilteredStyleSheetsAndClasses(): void
    {
        $html = HtmlSanitizer::mailDocument(
            '<html><head><style>.crumb{display:inline}@import url(https://evil.test/x.css);'
            . 'td{background:url(https://evil.test/t.png) no-repeat;width:expression(alert(1))}</style></head>'
            . '<body><table bgcolor="#eeeeee"><tr><td class="crumb"><font color="red" face="Arial">KHWF</font></td></tr></table></body></html>'
        );

        $this->assertStringContains('<style>', $html);
        $this->assertStringContains('.crumb{display:inline}', $html);
        $this->assertStringContains('class="crumb"', $html);
        $this->assertStringContains('bgcolor="#eeeeee"', $html);
        $this->assertStringContains('<font color="red" face="Arial">KHWF</font>', $html);
        $this->assertFalse(str_contains($html, '@import'), '@import');
        $this->assertFalse(str_contains($html, 'evil.test'), 'evil.test');
        $this->assertFalse(str_contains($html, 'expression'), 'expression');
    }

    public function testMailDocumentStyleSheetCannotCloseItsBlock(): void
    {
        $html = HtmlSanitizer::mailDocument('<style>p{color:red}</style ><p>Text</p><style>a{}</style><script>alert(1)</script>');
        $this->assertFalse(str_contains($html, '<script'), '<script');

        $html = HtmlSanitizer::mailDocument('<style>p{color:red}<\/style><img src=x onerror=alert(1)></style><p>Text</p>');
        $this->assertFalse(str_contains($html, '<img'), 'Kein Element aus dem Stylesheet');
        $this->assertSame(1, substr_count($html, '</style>'));
    }

    public function testMailDocumentIsIdempotent(): void
    {
        $once = HtmlSanitizer::mailDocument('<head><style>p{margin:0}</style></head><body><p class="x">Text</p></body>');

        $this->assertSame($once, HtmlSanitizer::mailDocument($once));
    }

    public function testCleanStillDropsStyleSheetsAndPresentationalMailMarkup(): void
    {
        $html = HtmlSanitizer::clean('<style>p{color:red}</style><font color="red">Text</font><table><tr><td bgcolor="red">Zelle</td></tr></table>', 0, false);

        $this->assertSame('Text<table><tr><td>Zelle</td></tr></table>', $html);
    }
}
