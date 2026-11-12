<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Helpdesk\MentionService;
use Tests\Support\TestCase;

/** Erkennung von Agenten-Erwähnungen („@Name“) in internen Kommentaren ohne Datenbank. */
final class HelpdeskMentionTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function agents(): array
    {
        return [
            ['id' => 1, 'username' => 'mmuster', 'display_name' => 'Max Muster'],
            ['id' => 2, 'username' => 'max', 'display_name' => 'Max'],
            ['id' => 3, 'username' => 'eschmidt', 'display_name' => 'Erika Schmidt'],
        ];
    }

    public function testRecognizesDisplayAndUserNamesCaseInsensitive(): void
    {
        $this->assertSame([3], MentionService::parse('Bitte prüfen, @erika schmidt.', $this->agents()));
        $this->assertSame([3, 1], MentionService::parse("@eschmidt und\n(@MMUSTER)", $this->agents()));
    }

    public function testLongestNameWins(): void
    {
        $this->assertSame([1], MentionService::parse('@Max Muster bitte übernehmen', $this->agents()));
        $this->assertSame([2], MentionService::parse('@Max bitte übernehmen', $this->agents()));
    }

    public function testIgnoresEmailsPartialWordsAndDuplicates(): void
    {
        $this->assertSame([], MentionService::parse('Mail an max@firma.de', $this->agents()));
        $this->assertSame([], MentionService::parse('@Maximilian hat angerufen', $this->agents()));
        $this->assertSame([3], MentionService::parse('@eschmidt @Erika Schmidt @eschmidt', $this->agents()));
    }

    public function testExcerptStripsImageDataAndShortens(): void
    {
        $body = "Siehe Bild:\n![Screenshot](data:image/png;base64,iVBORw0KGgo=) @Max";
        $this->assertSame('Siehe Bild: [Bild] @Max', MentionService::excerpt($body));
        $long = MentionService::excerpt(str_repeat('a', 500));
        $this->assertSame(MentionService::EXCERPT_CHARS, mb_strlen($long));
        $this->assertTrue(str_ends_with($long, '…'));
    }
}
