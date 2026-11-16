<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Logger;
use App\Repositories\HelpdeskSpellcheckWordRepository;
use App\Services\Helpdesk\HelpdeskSpellcheckService;
use App\Services\Helpdesk\Spellcheck\SpellcheckCompiler;
use App\Services\Helpdesk\Spellcheck\SpellcheckDictionary;
use App\Services\Helpdesk\Spellcheck\SpellcheckProofreader;
use App\Services\Helpdesk\Spellcheck\SpellcheckService;
use App\Services\Helpdesk\Spellcheck\SpellcheckUserWords;
use PDO;
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

    public function testOfflineBasicProofreaderWorksWithoutDictionaryAndProtectsTokens(): void
    {
        $proofreader = new SpellcheckProofreader();
        $input = "hallo Herr Müller ,  ich habe die die Meldung HD-2026-0042 zu https://intra.local/Pfad_x und [Bild 1] sowie {name} geprüft .\nviele grüße";

        $result = $proofreader->proofread($input, null);

        $this->assertTrue($result['changed']);
        $this->assertTrue($result['replacements'] >= 4);
        $this->assertSame(
            "Hallo Herr Müller, ich habe die Meldung HD-2026-0042 zu https://intra.local/Pfad_x und [Bild 1] sowie {name} geprüft.\nViele Grüße",
            $result['text']
        );
    }

    public function testOfflineBasicProofreaderFixesCuratedTyposAndUniqueDistanceOneMatches(): void
    {
        $engine = $this->engine(['Haus', 'Drucker', 'Ticket', 'prüfen', 'geprüft']);
        $proofreader = new SpellcheckProofreader();

        $result = $proofreader->proofread(
            'vielen dank fuer die Rückmeldung. der Hauss und das passwort wurden geprüft.',
            $engine
        );

        $this->assertTrue($result['changed']);
        $this->assertSame(
            'Vielen Dank für die Rückmeldung. Der Haus und das Passwort wurden geprüft.',
            $result['text']
        );

        // Relativsatz nach Komma ("..., die die ...") und Eigennamen mit Binnen-Großbuchstaben bleiben erhalten;
        // Satzanfang nach [Bild 1]. wird ebenfalls großgeschrieben.
        $edge = $proofreader->proofread(
            'iPhone und macOS wurden geprüft siehe [Bild 1]. die Kollegen, die die Meldung geprüft haben, danken.',
            $engine
        );
        $this->assertSame(
            'iPhone und macOS wurden geprüft siehe [Bild 1]. Die Kollegen, die die Meldung geprüft haben, danken.',
            $edge['text']
        );

        // ASCII-Umlaute ("geprueft", "geraet"), kleingeschriebene Nomen ("frau", "netzteil"),
        // Kontextverwechslungen ("seid gestern", "wider einwandfrei") und Grußformeln ("guten tag", "mfg").
        $asciiEngine = $this->engine([
            'Guten', 'Tag', 'Frau', 'Schmidt', 'wir', 'haben', 'das', 'Netzteil', 'geprüft',
            'und', 'können', 'Gerät', 'gerät', 'seit', 'gestern', 'wieder', 'einwandfrei', 'übergeben',
        ]);
        $asciiResult = $proofreader->proofread(
            "guten tag frau schmidt ,\n\nwir haben das das netzteil geprueft und koennen das geraet seid gestern wider einwandfrei uebergeben . mfg",
            $asciiEngine
        );
        $this->assertSame(
            "Guten Tag Frau Schmidt,\n\nWir haben das Netzteil geprüft und können das Gerät seit gestern wieder einwandfrei übergeben. Mit freundlichen Grüßen",
            $asciiResult['text']
        );
    }

    public function testHelpdeskSpellcheckServiceInvalidatesCacheAndTracksPersonalWords(): void
    {
        $dir = $this->tempDir('dienst');
        $aff = $dir . '/de_DE.aff';
        $dic = $dir . '/de_DE.dic';
        file_put_contents($aff, "SET UTF-8\nTRY esianrtolcdugmphbyfvkwz\n");
        file_put_contents($dic, "2\nHaus\nTicket\n");
        $compiled = $dir . '/compiled';
        (new SpellcheckCompiler())->compile($aff, $dic, $compiled);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE helpdesk_spellcheck_words (user_id INTEGER NOT NULL, word TEXT NOT NULL, PRIMARY KEY (user_id, word))');

        $config = Config::fromArray([
            'helpdesk' => [
                'spellcheck' => [
                    'enabled' => true,
                    'dir' => $compiled,
                ],
            ],
        ]);
        $logger = new Logger($dir . '/test.log', 'error');
        $userWords = new SpellcheckUserWords(new HelpdeskSpellcheckWordRepository($pdo), $logger);
        $service = new HelpdeskSpellcheckService($config, $userWords);

        $before = $service->suggestions(7, 'Orvanta');
        $this->assertTrue($before['available']);
        $this->assertFalse($before['known']);
        $this->assertFalse($before['personal']);

        $service->addWord(7, 'Orvanta');

        // Nach addWord() muss derselbe Service-Zwischenspeicher das neue Wort sofort kennen.
        $afterAdd = $service->suggestions(7, 'Orvanta.');
        $this->assertTrue($afterAdd['known']);
        $this->assertTrue($afterAdd['personal']);
        $this->assertSame('Orvanta', $afterAdd['personal_word']);

        $batch = $service->checkWords(7, ['Haus', 'Orvanta', 'Hauss']);
        $this->assertTrue($batch['available']);
        $this->assertSame(['Haus' => true, 'Orvanta' => true, 'Hauss' => false], $batch['results']);
        $this->assertSame(['Orvanta' => 'Orvanta'], $batch['personal']);

        $service->removeWord(7, 'Orvanta');
        $afterRemove = $service->suggestions(7, 'Orvanta');
        $this->assertFalse($afterRemove['known']);
        $this->assertFalse($afterRemove['personal']);
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
