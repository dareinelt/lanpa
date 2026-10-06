<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Exceptions\ValidationException;
use App\Repositories\OrvantaSpellcheckWordRepository;
use PDOException;

/**
 * Persoenliches Woerterbuch der Rechtschreibpruefung: Woerter, die ein
 * Benutzer als korrekt bestaetigt hat. Sie gelten serverseitig bei Pruefung
 * und Vorschlaegen ({@see OrvantaSpellcheckService::withUserWords()}) und
 * damit auf allen Geraeten.
 */
final class OrvantaSpellcheckUserWords
{
    /** Hoechstzahl der Woerter je Benutzer. */
    public const MAX_WORDS = 1000;

    // Gleiche Wortgrenzen wie der Tokenizer in orvanta.js, dazu ein
    // abschliessender Punkt fuer Abkuerzungen ("Kundennr.").
    private const WORD_PATTERN = "/^[\\p{L}\\p{M}0-9]+(?:[.\\-'\u{2019}][\\p{L}\\p{M}0-9]+)*\\.?$/u";

    public function __construct(private readonly OrvantaSpellcheckWordRepository $repository)
    {
    }

    /**
     * @return list<string>
     */
    public function words(string $uid): array
    {
        return $this->repository->words($this->uid($uid));
    }

    /**
     * Woerter fuer die Pruefung. Ist die Tabelle (noch) nicht lesbar, etwa
     * vor der Migration, laeuft die Pruefung ohne persoenliche Woerter weiter.
     *
     * @return list<string>
     */
    public function wordsForCheck(string $uid): array
    {
        try {
            return $this->words($uid);
        } catch (PDOException $exception) {
            app_logger()->warning('Orvanta: Benutzerwoerterbuch nicht lesbar.', ['error' => $exception->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<string> Woerter nach dem Hinzufuegen
     */
    public function add(string $uid, string $word): array
    {
        $uid = $this->uid($uid);
        $word = $this->normalize($word);
        if (!$this->repository->exists($uid, $word)) {
            if ($this->repository->count($uid) >= self::MAX_WORDS) {
                throw new ValidationException(['word' => 'Ihr Wörterbuch ist voll (höchstens ' . self::MAX_WORDS . ' Wörter). Bitte entfernen Sie zuerst Wörter in den Einstellungen.']);
            }
            $this->repository->add($uid, $word);
        }

        return $this->repository->words($uid);
    }

    /**
     * @return list<string> Woerter nach dem Entfernen
     */
    public function remove(string $uid, string $word): array
    {
        $uid = $this->uid($uid);
        $this->repository->remove($uid, trim($word));

        return $this->repository->words($uid);
    }

    private function normalize(string $word): string
    {
        $word = trim($word);
        $length = mb_strlen($word, 'UTF-8');
        if ($length < 2 || $length > OrvantaSpellcheckService::MAX_WORD_LENGTH) {
            throw new ValidationException(['word' => 'Das Wort muss 2 bis ' . OrvantaSpellcheckService::MAX_WORD_LENGTH . ' Zeichen lang sein.']);
        }
        if (preg_match(self::WORD_PATTERN, $word) !== 1 || preg_match('/\p{L}/u', $word) !== 1) {
            throw new ValidationException(['word' => 'Nur einzelne Wörter (Buchstaben, Ziffern, Binde- und Schlusszeichen) können aufgenommen werden.']);
        }

        return $word;
    }

    private function uid(string $uid): string
    {
        return mb_strtolower(trim($uid), 'UTF-8');
    }
}
