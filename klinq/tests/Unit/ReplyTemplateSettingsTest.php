<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Helpdesk\ReplyTemplateSettingsService;
use Tests\Support\TestCase;

/**
 * Vorlageneinstellungen: Der Präfix-Text wird beim Senden maskiert und in Absätze
 * umgesetzt, damit er nie als HTML aus der Administration in die Mail gelangt.
 */
final class ReplyTemplateSettingsTest extends TestCase
{
    public function testEmptyPrefixProducesNoHtml(): void
    {
        $this->assertSame('', ReplyTemplateSettingsService::toHtml(''));
        $this->assertSame('', ReplyTemplateSettingsService::toHtml("  \n\n \t "));
    }

    public function testPrefixTextIsEscaped(): void
    {
        $html = ReplyTemplateSettingsService::toHtml('<b>Hallo</b> & "Tschüss"');

        $this->assertSame('<p>&lt;b&gt;Hallo&lt;/b&gt; &amp; &quot;Tschüss&quot;</p>', $html);
        $this->assertFalse(str_contains($html, '<b>'), 'Aus der Administration darf kein HTML durchkommen.');
    }

    public function testBlankLinesSeparateParagraphsAndSingleBreaksStay(): void
    {
        $this->assertSame('<p>Absatz eins</p><p>Absatz zwei</p>', ReplyTemplateSettingsService::toHtml("Absatz eins\n\nAbsatz zwei"));
        $this->assertSame('<p>Zeile eins<br>Zeile zwei</p>', ReplyTemplateSettingsService::toHtml("Zeile eins\nZeile zwei"));
    }

    public function testWindowsLineEndingsAreNormalized(): void
    {
        $this->assertSame('<p>A</p><p>B</p>', ReplyTemplateSettingsService::toHtml("A\r\n\r\nB"));
    }
}
