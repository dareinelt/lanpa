<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Exceptions\ValidationException;
use App\Services\Helpdesk\Spellcheck\SpellcheckCasing;
use App\Services\Helpdesk\Spellcheck\SpellcheckDictionary;
use App\Services\Helpdesk\Spellcheck\SpellcheckProofreader;
use App\Services\Helpdesk\Spellcheck\SpellcheckService;
use App\Services\Helpdesk\Spellcheck\SpellcheckUserWords;

/**
 * Rechtschreibprüfung für die Help-Desk-Textfelder: bereitet das Wörterbuch aus
 * `helpdesk.spellcheck.dir` auf, ergänzt das persönliche Wörterbuch des Benutzers
 * und liefert Sammelprüfungen sowie Vorschläge für Kontextmenü und Komfortleiste.
 *
 * Die Wörterbuchprüfung läuft ohne KI und ohne Netz: das Wörterbuch wird beim
 * Containerstart aufbereitet (bin/spellcheck-dictionary.php). Fehlt es, meldet
 * die API „nicht verfügbar“, und die Oberfläche blendet den Abschnitt aus.
 */
final class HelpdeskSpellcheckService
{
    private ?SpellcheckService $baseEngine = null;
    private ?SpellcheckService $engine = null;
    private int $engineUser = -1;

    public function __construct(
        private readonly Config $config,
        private readonly SpellcheckUserWords $userWords
    ) {}

    /**
     * Vorschläge für ein Wort im Kontextmenü. Bei leerem Wort dient der Aufruf
     * als Bereitschaftsprüfung: er meldet nur, ob das Wörterbuch nutzbar ist.
     *
     * @return array{available: bool, known: bool, personal: bool, personal_word: ?string, suggestions: list<string>}
     */
    public function suggestions(int $userId, string $word): array
    {
        $word = trim($word);
        $engine = $this->engine($userId);
        $available = $engine->isUsable();
        $empty = ['available' => $available, 'known' => false, 'personal' => false, 'personal_word' => null, 'suggestions' => []];
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
        $personalWord = $known ? $this->matchedPersonalWord($userId, $word) : null;

        return [
            'available' => true,
            'known' => $known,
            'personal' => $personalWord !== null,
            'personal_word' => $personalWord,
            'suggestions' => $known ? [] : array_slice($engine->suggest($word), 0, SpellcheckService::MAX_SUGGESTIONS),
        ];
    }

    /**
     * Prüft eine Wortliste in einem Durchgang (Vorprüfung für Komfortleiste und
     * Mail-Editor). Unbekannte oder zu lange Einträge werden übersprungen.
     *
     * @param list<mixed> $words
     * @return array{available: bool, results: array<string, bool>, personal: array<string, string>}
     */
    public function checkWords(int $userId, array $words): array
    {
        if (count($words) > SpellcheckService::MAX_WORDS_PER_REQUEST) {
            throw new ValidationException([
                'words' => 'Zu viele Wörter in einer Anfrage (höchstens ' . SpellcheckService::MAX_WORDS_PER_REQUEST . ').',
            ]);
        }
        $engine = $this->engine($userId);
        $available = $engine->isUsable();
        if (!$available || $words === []) {
            return ['available' => $available, 'results' => [], 'personal' => []];
        }

        $results = [];
        $personal = [];
        foreach ($words as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $word = trim($raw);
            if ($word === '' || isset($results[$word]) || mb_strlen($word, 'UTF-8') > SpellcheckService::MAX_WORD_LENGTH) {
                continue;
            }
            $known = $engine->check($word);
            $results[$word] = $known;
            if ($known) {
                $matched = $this->matchedPersonalWord($userId, $word);
                if ($matched !== null) {
                    $personal[$word] = $matched;
                }
            }
        }

        return [
            'available' => true,
            'results' => $results,
            'personal' => $personal,
        ];
    }

    /**
     * Lokale Basis-Korrektur ohne KI (Typografie, Satzanfänge, typische Tippfehler
     * und eindeutige Hunspell-Treffer unter Berücksichtigung des persönlichen Wörterbuchs).
     *
     * @return array{available: bool, text: string, changed: bool, replacements: int}
     */
    public function proofread(int $userId, string $text): array
    {
        $engine = $this->engine($userId);
        $result = (new SpellcheckProofreader())->proofread($text, $engine);

        return [
            'available' => $engine->isUsable(),
            'text' => $result['text'],
            'changed' => $result['changed'],
            'replacements' => $result['replacements'],
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
        $words = $this->userWords->add($userId, $word);
        $this->invalidateEngine();

        return $words;
    }

    /** @return list<string> Wörter nach dem Entfernen */
    public function removeWord(int $userId, string $word): array
    {
        $words = $this->userWords->remove($userId, $word);
        $this->invalidateEngine();

        return $words;
    }

    private function invalidateEngine(): void
    {
        $this->engine = null;
        $this->engineUser = -1;
    }

    /**
     * Ermittelt den gespeicherten Eintrag im persönlichen Wörterbuch, falls das
     * Wort darüber akzeptiert wurde (inkl. Satzendepunkt und Großschreibung).
     */
    private function matchedPersonalWord(int $userId, string $word): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        $entries = $this->userWords->wordsForCheck($userId);
        if ($entries === []) {
            return null;
        }
        // Nur als persönliches Wort führen, wenn das Hauptwörterbuch allein es nicht kennt
        // oder das Wort exakt in der persönlichen Liste hinterlegt wurde.
        $exactMap = [];
        $upperMap = [];
        foreach ($entries as $entry) {
            $exactMap[$entry] = $entry;
            $upper = mb_strtoupper($entry, 'UTF-8');
            if (!isset($upperMap[$upper])) {
                $upperMap[$upper] = $entry;
            }
        }

        $candidates = [$word];
        $trimmed = rtrim($word, '.');
        if ($trimmed !== '' && $trimmed !== $word) {
            $candidates[] = $trimmed;
        }

        foreach ($candidates as $candidate) {
            if (isset($exactMap[$candidate])) {
                return $exactMap[$candidate];
            }
            $cap = SpellcheckCasing::guess($candidate);
            if ($cap === SpellcheckCasing::INIT || $cap === SpellcheckCasing::HUHINIT) {
                foreach (SpellcheckCasing::lowerFirst($candidate) as $variant) {
                    if (isset($exactMap[$variant])) {
                        return $exactMap[$variant];
                    }
                }
            }
            if ($cap === SpellcheckCasing::ALL && isset($upperMap[$candidate])) {
                return $upperMap[$candidate];
            }
        }

        return null;
    }

    private function baseEngine(): SpellcheckService
    {
        if ($this->baseEngine !== null) {
            return $this->baseEngine;
        }
        $enabled = (bool) $this->config->get('helpdesk.spellcheck.enabled', true);
        $directory = (string) $this->config->get('helpdesk.spellcheck.dir', dirname(__DIR__, 3) . '/storage/dictionaries/de_DE');

        return $this->baseEngine = new SpellcheckService(new SpellcheckDictionary($directory), $enabled);
    }

    /**
     * Prüfinstanz mit den persönlichen Wörtern des Benutzers. Sie wird je Benutzer
     * zwischengespeichert und nach Änderungen am persönlichen Wörterbuch geleert.
     */
    private function engine(int $userId): SpellcheckService
    {
        if ($this->engine !== null && $this->engineUser === $userId) {
            return $this->engine;
        }
        $base = $this->baseEngine();
        $this->engine = $userId > 0 ? $base->withUserWords($this->userWords->wordsForCheck($userId)) : $base;
        $this->engineUser = $userId;

        return $this->engine;
    }
}
