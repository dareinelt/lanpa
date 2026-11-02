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
}
