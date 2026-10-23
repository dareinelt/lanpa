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
}
