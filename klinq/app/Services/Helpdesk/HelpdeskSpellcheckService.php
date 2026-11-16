<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Exceptions\ValidationException;
use App\Services\Helpdesk\Spellcheck\SpellcheckDictionary;
use App\Services\Helpdesk\Spellcheck\SpellcheckService;
use App\Services\Helpdesk\Spellcheck\SpellcheckUserWords;

/**
 * Rechtschreibprüfung für die Help-Desk-Textfelder: bereitet das Wörterbuch aus
 * `helpdesk.spellcheck.dir` auf, ergänzt das persönliche Wörterbuch des Benutzers
 * und liefert die Vorschläge für das Kontextmenü (public/js/helpdesk-ai.js).
 *
 * Die Prüfung läuft ohne KI und ohne Netz: das Wörterbuch wird beim Containerstart
 * aufbereitet (bin/spellcheck-dictionary.php). Fehlt es, meldet die API
 * „nicht verfügbar“, und das Kontextmenü blendet den Abschnitt aus.
 */
final class HelpdeskSpellcheckService
{
    private ?SpellcheckService $engine = null;
    private int $engineUser = 0;

    public function __construct(
        private readonly Config $config,
        private readonly SpellcheckUserWords $userWords
    ) {}

    /**
     * Vorschläge für ein Wort im Kontextmenü. Bei leerem Wort dient der Aufruf
     * als Bereitschaftsprüfung: er meldet nur, ob das Wörterbuch nutzbar ist.
     *
     * @return array{available: bool, known: bool, personal: bool, suggestions: list<string>}
     */
    public function suggestions(int $userId, string $word): array
    {
        $word = trim($word);
        $engine = $this->engine($userId);
        $available = $engine->isUsable();
        $empty = ['available' => $available, 'known' => false, 'personal' => false, 'suggestions' => []];
        if ($word === '') {
            return $empty;
        }
        if (mb_strlen($word, 'UTF-8') > SpellcheckService::MAX_WORD_LENGTH) {
            throw new ValidationException(['word' => 'Das Wort ist zu lang.']);
        }
        if (!$available) {
            return $empty;
        }

        $known = $engine->check($word);
        $personal = in_array($word, $this->userWords->wordsForCheck($userId), true);

        return [
            'available' => true,
            'known' => $known,
            'personal' => $personal,
            'suggestions' => $known ? [] : array_slice($engine->suggest($word), 0, SpellcheckService::MAX_SUGGESTIONS),
        ];
    }

    /** @return list<string> */
    public function words(int $userId): array
    {
        return $this->userWords->words($userId);
    }

    /** @return list<string> Wörter nach dem Hinzufügen */
    public function addWord(int $userId, string $word): array
    {
        return $this->userWords->add($userId, $word);
    }

    /** @return list<string> Wörter nach dem Entfernen */
    public function removeWord(int $userId, string $word): array
    {
        return $this->userWords->remove($userId, $word);
    }

    /**
     * Prüfinstanz mit den persönlichen Wörtern des Benutzers. Sie wird je Anfrage
     * einmal aufgebaut: das Wörterbuch wird erst bei der ersten Prüfung geöffnet.
     */
    private function engine(int $userId): SpellcheckService
    {
        if ($this->engine !== null && $this->engineUser === $userId) {
            return $this->engine;
        }
        $enabled = (bool) $this->config->get('helpdesk.spellcheck.enabled', true);
        $directory = (string) $this->config->get('helpdesk.spellcheck.dir', dirname(__DIR__, 3) . '/storage/dictionaries/de_DE');
        $engine = new SpellcheckService(new SpellcheckDictionary($directory), $enabled);
        $this->engine = $userId > 0 ? $engine->withUserWords($this->userWords->wordsForCheck($userId)) : $engine;
        $this->engineUser = $userId;

        return $this->engine;
    }
}
