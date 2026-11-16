<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Helpdesk\Spellcheck\SpellcheckCompiler;
use App\Services\Helpdesk\Spellcheck\SpellcheckDictionary;
use App\Services\Helpdesk\Spellcheck\SpellcheckService;
use Tests\Support\TestCase;

/**
 * Rechtschreibprüfung im Help Desk: das aufbereitete Wörterbuch erkennt Wörter,
 * schlägt Korrekturen vor und nimmt persönliche Wörter auf. Ohne Wörterbuch oder
 * bei abgeschalteter Prüfung gilt jedes Wort als korrekt.
 */
final class HelpdeskSpellcheckTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    public function testCompiledDictionaryChecksWords(): void
    {
        $service = $this->engine(['Haus', 'Ticket', 'Helpdesk', 'Krankenhaus']);

        $this->assertTrue($service->isAvailable());
        $this->assertTrue($service->isUsable());
        $this->assertTrue($service->check('Haus'));
        $this->assertTrue($service->check('Krankenhaus'));
        $this->assertFalse($service->check('Hauss'));
        // Leere und zu lange Wörter gelten als korrekt (Grenze des Endpunkts).
        $this->assertTrue($service->check(str_repeat('a', SpellcheckService::MAX_WORD_LENGTH + 1)));
        $this->assertTrue($service->check(''));
    }

    public function testSuggestionsForMisspelledWord(): void
    {
        $service = $this->engine(['Haus', 'Ticket', 'Helpdesk']);

        $this->assertContains('Haus', $service->suggest('Hauss'));
        $this->assertSame([], $service->suggest('Haus'));
        $this->assertSame([], $service->suggest(str_repeat('x', 65)));
    }

    public function testTrailingDotIsCheckedAsAbbreviation(): void
    {
        $service = $this->engine(['Haus', 'usw.']);

        // "Ende." bleibt korrekt, wenn das Wort ohne Punkt im Wörterbuch steht;
        // Abkürzungen wie "usw." stehen mit Punkt in der Liste.
        $this->assertTrue($service->check('Haus.'));
        $this->assertTrue($service->check('usw.'));
        $this->assertTrue($service->check('Haus'));
        $this->assertFalse($service->check('Hauss.'));
    }

    public function testPersonalWordsCountAsCorrect(): void
    {
        $base = $this->engine(['Haus', 'Ticket']);
        $service = $base->withUserWords(['Zammad', 'Buchhaltung']);

        $this->assertTrue($service->check('Zammad'));
        $this->assertTrue($service->check('Buchhaltung'));
        $this->assertSame([], $service->suggest('Buchhaltung'));
        // Die Kopie teilt keine Zwischenspeicher mit der Ausgangsprüfung.
        $this->assertFalse($base->check('Zammad'));
    }

    public function testDisabledCheckAcceptsEveryWord(): void
    {
        $service = $this->engine(['Haus'], false);

        $this->assertFalse($service->isAvailable());
        $this->assertFalse($service->isUsable());
        $this->assertTrue($service->check('Hauss'));
        $this->assertSame([], $service->suggest('Hauss'));
    }

    public function testMissingDictionaryAcceptsEveryWord(): void
    {
        $dir = $this->tempDir('leer');
        $service = new SpellcheckService(new SpellcheckDictionary($dir . '/fehlt'));

        $this->assertFalse($service->isAvailable());
        $this->assertFalse($service->isUsable());
        $this->assertTrue($service->check('Hauss'));
        $this->assertSame([], $service->suggest('Hauss'));
    }

    /** @param list<string> $words */
    private function engine(array $words, bool $enabled = true): SpellcheckService
    {
        $dir = $this->tempDir('woerterbuch');
        $aff = $dir . '/de_DE.aff';
        $dic = $dir . '/de_DE.dic';
        file_put_contents($aff, "SET UTF-8\nTRY esianrtolcdugmphbyfvkwz\n");
        file_put_contents($dic, count($words) . "\n" . implode("\n", $words) . "\n");

        $target = $dir . '/aufbereitet';
        $stats = (new SpellcheckCompiler())->compile($aff, $dic, $target);
        $this->assertSame(count($words), $stats['words']);

        return new SpellcheckService(new SpellcheckDictionary($target), $enabled);
    }

    private function tempDir(string $name): string
    {
        $dir = sys_get_temp_dir() . '/klinq-spellcheck-' . bin2hex(random_bytes(4)) . '/' . $name;
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            $this->markSkipped('Temporäres Verzeichnis nicht anlegbar: ' . $dir);
        }
        $this->tempDirs[] = dirname($dir);

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}
