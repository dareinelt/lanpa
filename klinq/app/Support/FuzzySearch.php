<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ähnlichkeitssuche über mehrere Textfelder eines Datensatzes (fachlogikfrei).
 *
 * Ein Suchbegriff wird in Wörter zerlegt; ein Datensatz passt, wenn **jedes** Wort in mindestens
 * einem Feld vorkommt. Verglichen wird normalisiert (Kleinschreibung, Umlaute ausgeschrieben,
 * Satzzeichen als Leerzeichen, siehe {@see ColognePhonetic::normalize()}).
 *
 * Ergebnis {@see match()}:
 * - EXACT: jedes Wort ist als Teilzeichenfolge enthalten (wie eine LIKE-Suche),
 * - SIMILAR: kein exakter Treffer, aber ähnlich – Leerzeichen werden ignoriert („rp doc“ → „RPDoc“),
 *   kleine Tippfehler toleriert (Levenshtein, „rpdok“ → „RPDoc“) und gleich klingende Wörter erkannt
 *   (Kölner Phonetik, „Maier“ → „Meyer“). Ziffernfolgen (Rufnummern) werden nur exakt bzw. ohne
 *   Leer-/Trennzeichen verglichen, nie unscharf.
 */
final class FuzzySearch
{
    public const EXACT = 'exact';
    public const SIMILAR = 'similar';

    /** Mindestlänge eines Worts für Tippfehler-Toleranz und Phonetik. */
    private const MIN_FUZZY_LENGTH = 4;

    /**
     * @param list<string|null> $fields Durchsuchbare Felder des Datensatzes
     * @return self::EXACT|self::SIMILAR|null null = kein Treffer
     */
    public static function match(string $query, array $fields): ?string
    {
        $terms = self::words(self::normalize($query));
        if ($terms === []) {
            return self::EXACT;
        }
        $normalized = [];
        foreach ($fields as $field) {
            $value = self::normalize((string) $field);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }
        if ($normalized === []) {
            return null;
        }

        $exact = true;
        foreach ($terms as $term) {
            if (!self::containsAny($normalized, $term)) {
                $exact = false;
                break;
            }
        }
        if ($exact) {
            return self::EXACT;
        }

        $compactFields = array_map([self::class, 'compact'], $normalized);
        $words = [];
        foreach ($normalized as $value) {
            foreach (self::words($value) as $word) {
                $words[$word] = true;
            }
        }
        $words = array_map('strval', array_keys($words));

        // Ganze Eingabe ohne Leerzeichen („rp doc“ → „rpdoc“, „rp dok“ → „rpdok“)
        $compactQuery = implode('', $terms);
        if (count($terms) > 1 && self::termMatches($compactQuery, $compactFields, $words)) {
            return self::SIMILAR;
        }
        foreach ($terms as $term) {
            if (!self::termMatches($term, $compactFields, $words)) {
                return null;
            }
        }

        return self::SIMILAR;
    }

    /** Normalisierte Form (klein, ASCII, Satzzeichen → Leerzeichen, einfache Leerzeichen). */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', ColognePhonetic::normalize($text)));
    }

    /** Erlaubte Tippfehler (Levenshtein-Abstand) je Wortlänge. */
    public static function tolerance(int $length): int
    {
        if ($length < self::MIN_FUZZY_LENGTH) {
            return 0;
        }

        return $length < 8 ? 1 : 2;
    }

    /**
     * @param list<string> $compactFields Felder ohne Leerzeichen
     * @param list<string> $words Einzelwörter aller Felder
     */
    private static function termMatches(string $term, array $compactFields, array $words): bool
    {
        if (self::containsAny($compactFields, $term)) {
            return true;
        }
        // Rufnummern und andere Ziffernfolgen nie unscharf vergleichen
        if (preg_match('/\d/', $term) === 1) {
            return false;
        }
        $length = strlen($term);
        $tolerance = self::tolerance($length);
        if ($tolerance === 0) {
            return false;
        }
        $phonetic = ColognePhonetic::encodeWord($term);
        foreach (array_merge($words, $compactFields) as $candidate) {
            if (preg_match('/\d/', $candidate) === 1 && preg_match('/[a-z]/', $candidate) !== 1) {
                continue;
            }
            // Ganzes Wort oder gleich langer Wortanfang (Eingabe während des Tippens)
            if (levenshtein($term, $candidate) <= $tolerance
                || (strlen($candidate) > $length && levenshtein($term, substr($candidate, 0, $length)) <= $tolerance)) {
                return true;
            }
            if ($phonetic !== '' && strlen($phonetic) >= 2 && ColognePhonetic::encodeWord($candidate) === $phonetic) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $haystacks */
    private static function containsAny(array $haystacks, string $needle): bool
    {
        foreach ($haystacks as $haystack) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function compact(string $value): string
    {
        return str_replace(' ', '', $value);
    }

    /** @return list<string> */
    private static function words(string $normalized): array
    {
        return preg_split('/ +/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
