<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

/**
 * Gross-/Kleinschreibungsregeln der Rechtschreibpruefung (Hunspell "Casing").
 *
 * Die Umsetzung folgt der deutschen Variante (GermanCasing): das scharfe S wird
 * in Grossschreibung zu "SS", beim Kleinschreiben sind daher beide Varianten
 * ("strasse" und "strasse" mit scharfem S) zu pruefen. Damit Compiler und
 * Laufzeit identisch entscheiden, liegt die Logik in dieser gemeinsamen Klasse.
 */
final class OrvantaSpellcheckCasing
{
    /** Durchgaengig klein geschrieben ("haus"). */
    public const NO = 1;

    /** Nur der erste Buchstabe gross ("Haus"). */
    public const INIT = 2;

    /** Durchgaengig gross geschrieben ("HAUS"). */
    public const ALL = 3;

    /** Erster Buchstabe gross, sonst gemischt ("McDonalds"). */
    public const HUHINIT = 4;

    /** Gemischte Schreibweise ("iPhone"). */
    public const HUH = 5;

    /** Obergrenze fuer die Kombinationen des scharfen S (2^n waere unbegrenzt). */
    private const MAX_SHARP_S_VARIANTS = 64;

    /**
     * Bestimmt die Schreibweise eines Wortes.
     *
     * "ß" gilt als Kleinbuchstabe; ein durchgaengig grossgeschriebenes Wort mit
     * "ß" (etwa "STRAßE") wird wie "SS" behandelt.
     */
    public static function guess(string $word): int
    {
        if (self::isLowerWord($word)) {
            return self::NO;
        }
        if (self::isUpperWord($word)) {
            return self::ALL;
        }
        if (str_contains($word, 'ß') && self::isUpperWord(str_replace('ß', '', $word))) {
            return self::ALL;
        }
        if (self::isUpperWord(self::first($word))) {
            return self::isLowerWord(self::rest($word)) ? self::INIT : self::HUHINIT;
        }

        return self::HUH;
    }

    /**
     * Schreibweisen, unter denen ein Wort im Woerterbuch stehen kann.
     *
     * @return list<string>
     */
    public static function variants(string $word): array
    {
        return match (self::guess($word)) {
            self::INIT => self::unique(array_merge([$word], self::lower($word))),
            self::HUHINIT => self::unique(array_merge([$word], self::lowerFirst($word))),
            self::ALL => self::unique(array_merge([$word], self::lower($word), self::capitalize($word))),
            default => [$word],
        };
    }

    /**
     * Kleinschreibung samt Varianten mit scharfem S.
     *
     * @return list<string>
     */
    public static function lower(string $word): array
    {
        if ($word === '' || str_starts_with($word, 'İ')) {
            return [];
        }
        $lowered = mb_strtolower($word, 'UTF-8');
        if (str_contains($word, 'SS')) {
            return array_merge(self::sharpSVariants($lowered), [$lowered]);
        }

        return [$lowered];
    }

    /**
     * Schreibweisen mit kleingeschriebenem erstem Buchstaben.
     *
     * @return list<string>
     */
    public static function lowerFirst(string $word): array
    {
        $rest = self::rest($word);
        $result = [];
        foreach (self::lower(self::first($word)) as $first) {
            $result[] = $first . $rest;
        }

        return $result;
    }

    /**
     * Schreibweisen mit grossgeschriebenem erstem Buchstaben.
     *
     * @return list<string>
     */
    public static function capitalize(string $word): array
    {
        if (mb_strlen($word, 'UTF-8') === 1) {
            $letters = preg_split('//u', mb_strtoupper($word, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);

            return $letters === false ? [] : $letters;
        }
        $first = mb_strtoupper(self::first($word), 'UTF-8');
        $result = [];
        foreach (self::lower(self::rest($word)) as $rest) {
            $result[] = $first . $rest;
        }

        return $result;
    }

    /**
     * Alle Kombinationen, in denen "ss" durch "ß" ersetzt wird.
     *
     * @return list<string>
     */
    public static function sharpSVariants(string $text): array
    {
        $result = [];
        self::collectSharpS($text, 0, $result);

        return $result;
    }

    /**
     * Erster Buchstabe (Mehrbyte-fest).
     */
    public static function first(string $word): string
    {
        return mb_substr($word, 0, 1, 'UTF-8');
    }

    /**
     * Alles ab dem zweiten Buchstaben (Mehrbyte-fest).
     */
    public static function rest(string $word): string
    {
        return mb_substr($word, 1, null, 'UTF-8');
    }

    /**
     * Besteht das Wort ausschliesslich aus Kleinbuchstaben?
     */
    public static function isLowerWord(string $word): bool
    {
        return $word !== ''
            && preg_match('/\p{Ll}/u', $word) === 1
            && preg_match('/\p{Lu}|\p{Lt}/u', $word) !== 1;
    }

    /**
     * Besteht das Wort ausschliesslich aus Grossbuchstaben?
     */
    public static function isUpperWord(string $word): bool
    {
        return $word !== ''
            && preg_match('/\p{Lu}/u', $word) === 1
            && preg_match('/\p{Ll}|\p{Lt}/u', $word) !== 1;
    }

    /**
     * @param list<string> $result
     */
    private static function collectSharpS(string $text, int $start, array &$result): void
    {
        if (count($result) >= self::MAX_SHARP_S_VARIANTS) {
            return;
        }
        $position = mb_strpos($text, 'ss', $start);
        if ($position === false) {
            return;
        }
        $replaced = mb_substr($text, 0, $position, 'UTF-8') . 'ß' . mb_substr($text, $position + 2, null, 'UTF-8');
        $result[] = $replaced;
        self::collectSharpS($replaced, $position + 1, $result);
        self::collectSharpS($text, $position + 2, $result);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        return array_values(array_unique($values));
    }
}
