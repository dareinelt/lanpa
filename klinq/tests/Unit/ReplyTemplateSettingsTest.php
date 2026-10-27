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

    public function testConfirmationPlaceholdersAreReplaced(): void
    {
        $html = ReplyTemplateSettingsService::render(
            "Guten Tag {name},\n\nIhre Meldung {ticketnummer} ist eingegangen.",
            ['{name}' => 'Erika Mustermann', '{ticketnummer}' => 'HD-2026-000123']
        );

        $this->assertSame('<p>Guten Tag Erika Mustermann,</p><p>Ihre Meldung HD-2026-000123 ist eingegangen.</p>', $html);
    }

    public function testPlaceholderValuesAreEscaped(): void
    {
        $html = ReplyTemplateSettingsService::render('Hallo {name}', ['{name}' => '<script>x</script>']);

        $this->assertSame('<p>Hallo &lt;script&gt;x&lt;/script&gt;</p>', $html);
        $this->assertFalse(str_contains($html, '<script>'));
    }

    public function testEmptyPlaceholderLeavesNoDoubleSpace(): void
    {
        $this->assertSame('<p>Guten Tag,</p>', ReplyTemplateSettingsService::render('Guten Tag {name},', ['{name}' => '']));
        $this->assertSame('<p>Hallo</p>', ReplyTemplateSettingsService::render('Hallo {anrede} {name}', ['{anrede}' => '', '{name}' => '']));
    }

    public function testUnknownPlaceholdersStayUntouched(): void
    {
        $html = ReplyTemplateSettingsService::render('Hallo {unbekannt}', ['{name}' => 'X']);

        $this->assertSame('<p>Hallo {unbekannt}</p>', $html);
    }

    public function testFooterPlaceholderIsFilledWithCompanyName(): void
    {
        $html = ReplyTemplateSettingsService::render(
            ReplyTemplateSettingsService::DEFAULT_FOOTER_TEXT,
            ['{firma}' => 'Muster GmbH']
        );

        $this->assertSame(
            '<p>Diese E-Mail wurde automatisch vom IT Help Desk (Muster GmbH) erzeugt. Antworten auf diese E-Mail werden nicht verarbeitet.</p>',
            $html
        );
    }
}
