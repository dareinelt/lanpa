<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RichText;
use Tests\Support\TestCase;

/** Darstellung von Freitext (Tickets, Kommentare, Wissensdatenbank). */
final class RichTextTest extends TestCase
{
    public function testToHtmlKeepsSingleLineBreaks(): void
    {
        $this->assertSame("Zeile 1<br />\nZeile 2", RichText::toHtml("Zeile 1\nZeile 2"));
    }

    public function testToHtmlCollapsesBlankLineRunsToOneBlankLine(): void
    {
        $html = RichText::toHtml("Hallo Zusammen,\n\n\n\n\nnach dem Hinweis.\n\n\n\nGrüße");

        $this->assertSame("Hallo Zusammen,<br />\n<br />\nnach dem Hinweis.<br />\n<br />\nGrüße", $html);
    }

    public function testToHtmlNormalizesWindowsLineEndings(): void
    {
        $this->assertSame("Zeile 1<br />\nZeile 2", RichText::toHtml("Zeile 1\r\nZeile 2"));
        $this->assertSame("Zeile 1<br />\nZeile 2", RichText::toHtml("Zeile 1\rZeile 2"));
    }

    public function testToHtmlRemovesTrailingSpacesOnLines(): void
    {
        $this->assertSame("Zeile 1<br />\n<br />\nZeile 2", RichText::toHtml("Zeile 1   \n \t \nZeile 2"));
    }

    public function testToHtmlEscapesMarkup(): void
    {
        $this->assertStringContains('&lt;script&gt;', RichText::toHtml('<script>alert(1)</script>'));
    }

    public function testToHtmlEmbedsBase64Images(): void
    {
        $data = str_repeat('A', 24);
        $html = RichText::toHtml('Text ![Beleg](data:image/png;base64,' . $data . ')');

        $this->assertStringContains('<img class="text-image" src="data:image/png;base64,' . $data . '"', $html);
        $this->assertSame('Text [Beleg]', RichText::toPlain('Text ![Beleg](data:image/png;base64,' . $data . ')', '[Beleg]'));
    }

    public function testToHtmlAcceptsLineBreaksInsideBase64Data(): void
    {
        $data = str_repeat('A', 20) . "\n" . str_repeat('B', 20);
        $html = RichText::toHtml("Text ![Beleg](data:image/jpeg;base64,{$data})");

        $this->assertSame(
            'Text <img class="text-image" src="data:image/jpeg;base64,' . str_repeat('A', 20) . str_repeat('B', 20)
            . '" alt="Beleg" loading="lazy">',
            $html
        );
    }

    public function testToHtmlAcceptsAdditionalMediaTypeParameters(): void
    {
        $data = str_repeat('C', 24);
        $html = RichText::toHtml('Text ![Beleg](data:image/PNG;charset=utf-8;base64,' . $data . ')');

        $this->assertStringContains('<img class="text-image" src="data:image/png;base64,' . $data . '"', $html);
    }

    public function testToHtmlRendersDataUriWithoutMarkdownMark(): void
    {
        $data = str_repeat('D', 80);
        $html = RichText::toHtml('Vorher data:image/png;base64,' . $data . ' Nachher');

        $this->assertSame(
            'Vorher <img class="text-image" src="data:image/png;base64,' . $data . '" alt="Bild" loading="lazy"> Nachher',
            $html
        );
    }

    public function testToHtmlRendersRawBase64WithImageHeader(): void
    {
        $raw = 'iVBORw0KGgo' . str_repeat('E', 200);
        $html = RichText::toHtml('Screenshot: ' . $raw);

        $this->assertStringContains('<img class="text-image" src="data:image/png;base64,' . $raw . '" alt="Bild"', $html);
    }

    public function testToPlainReplacesImageDataWithoutMarkdownMark(): void
    {
        $data = str_repeat('F', 80);
        $raw = '/9j/' . str_repeat('G', 200);

        $this->assertSame('[Bild]', RichText::toPlain('data:image/png;base64,' . $data));
        $this->assertSame('Foto [Bild]', RichText::toPlain('Foto ' . $raw));
    }

    public function testToHtmlEscapesAlternativeText(): void
    {
        $data = str_repeat('H', 24);
        $html = RichText::toHtml('![<b>"x"</b>](data:image/png;base64,' . $data . ')');

        $this->assertStringContains('alt="&lt;b&gt;&quot;x&quot;&lt;/b&gt;"', $html);
    }

    public function testToHtmlLeavesShortBase64LikeTextAlone(): void
    {
        $this->assertSame('Kein Bild: data:image/png;base64,AAAA', RichText::toHtml('Kein Bild: data:image/png;base64,AAAA'));
    }
}
