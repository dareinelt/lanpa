<?php

declare(strict_types=1);

namespace App\Services\Helpdesk\Spellcheck;

use App\Core\Logger;
use App\Exceptions\ValidationException;
use App\Repositories\HelpdeskSpellcheckWordRepository;
use PDOException;

/**
 * Persönliches Wörterbuch der Rechtschreibprüfung: Wörter, die ein Benutzer als
 * korrekt bestätigt hat. Sie gelten serverseitig bei Prüfung und Vorschlägen
 * ({@see SpellcheckService::withUserWords()}) und damit auf allen Geräten.
 */
final class SpellcheckUserWords
{
    /** Höchstzahl der Wörter je Benutzer. */
    public const MAX_WORDS = 1000;

    // Gleiche Wortgrenzen wie der Tokenizer in public/js/helpdesk-ai.js, dazu
    // ein abschließender Punkt für Abkürzungen ("Kundennr.").
    private const WORD_PATTERN = "/^[\\p{L}\\p{M}0-9]+(?:[.\\-'\u{2019}][\\p{L}\\p{M}0-9]+)*\\.?$/u";

    public function __construct(
        private readonly HelpdeskSpellcheckWordRepository $repository,
        private readonly Logger $logger
    ) {}

    /** @return list<string> */
    public function words(int $userId): array
    {
        return $this->repository->words($userId);
    }

    /**
     * Wörter für die Prüfung. Ist die Tabelle (noch) nicht lesbar, etwa vor der
     * Migration, läuft die Prüfung ohne persönliche Wörter weiter.
     *
     * @return list<string>
     */
    public function wordsForCheck(int $userId): array
    {
        try {
            return $this->words($userId);
        } catch (PDOException $exception) {
            $this->logger->warning('Help Desk: Benutzerwörterbuch nicht lesbar.', ['error' => $exception->getMessage()]);

            return [];
        }
    }

    /** @return list<string> Wörter nach dem Hinzufügen */
    public function add(int $userId, string $word): array
    {
        $word = $this->normalize($word);
        if (!$this->repository->exists($userId, $word)) {
            if ($this->repository->count($userId) >= self::MAX_WORDS) {
                throw new ValidationException(['word' => 'Ihr Wörterbuch ist voll (höchstens ' . self::MAX_WORDS . ' Wörter). Bitte entfernen Sie zuerst Wörter über das Kontextmenü.']);
            }
            $this->repository->add($userId, $word);
        }

        return $this->repository->words($userId);
    }

    /** @return list<string> Wörter nach dem Entfernen */
    public function remove(int $userId, string $word): array
    {
        $this->repository->remove($userId, trim($word));

        return $this->repository->words($userId);
    }

    private function normalize(string $word): string
    {
        $word = trim($word);
        $length = mb_strlen($word, 'UTF-8');
        if ($length < 2 || $length > SpellcheckService::MAX_WORD_LENGTH) {
            throw new ValidationException(['word' => 'Das Wort muss 2 bis ' . SpellcheckService::MAX_WORD_LENGTH . ' Zeichen lang sein.']);
        }
        if (preg_match(self::WORD_PATTERN, $word) !== 1 || preg_match('/\p{L}/u', $word) !== 1) {
            throw new ValidationException(['word' => 'Nur einzelne Wörter (Buchstaben, Ziffern, Binde- und Schlusszeichen) können aufgenommen werden.']);
        }

        return $word;
    }
}
