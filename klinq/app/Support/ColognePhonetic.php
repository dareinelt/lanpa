<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Kölner Phonetik (Postel 1969) – phonetischer Schlüssel für deutsche Wörter,
 * eignet sich auch für Firmennamen. Dient der Dublettenerkennung bei Herstellern.
 */
final class ColognePhonetic
{
    /** Kodiert einen kompletten Text (mehrere Wörter → Schlüssel durch Leerzeichen getrennt). */
    public static function encode(string $text): string
    {
        $words = preg_split('/[^a-z]+/', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $codes = array_filter(array_map([self::class, 'encodeWord'], $words), static fn (string $c): bool => $c !== '');

        return implode(' ', $codes);
    }

    /** Normalisiert Umlaute/Sonderzeichen und schreibt klein (für Vergleiche). */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['ä', 'ö', 'ü', 'ß', 'é', 'è', 'ê', 'á', 'à', 'ç'], ['ae', 'oe', 'ue', 'ss', 'e', 'e', 'e', 'a', 'a', 'c'], $text);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($ascii) && $ascii !== '') {
            $text = strtolower($ascii);
        }

        return (string) preg_replace('/[^a-z0-9 ]+/', ' ', $text);
    }

    /** Vergleichsname ohne Rechtsformen, Bindestriche, Mehrfachleerzeichen. */
    public static function normalizedName(string $text): string
    {
        $text = self::normalize($text);
        $text = (string) preg_replace('/\b(gmbh|ag|inc|incorporated|corp|corporation|ltd|limited|co|kg|ohg|se|llc|bv|nv|sa|srl|s ?p ?a|plc|company|technologies|technology|electronics|deutschland|germany|europe|international)\b/', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /** Vergleichsname für Artikel: normalisiert, ohne Sonderzeichen und Mehrfachleerzeichen (Rechtsformen bleiben erhalten). */
    public static function normalizedArticleName(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', self::normalize($text)));
    }

    public static function encodeWord(string $word): string
    {
        $word = preg_replace('/[^a-z]/', '', strtolower($word)) ?? '';
        $length = strlen($word);
        if ($length === 0) {
            return '';
        }

        $codes = [];
        for ($i = 0; $i < $length; $i++) {
            $c = $word[$i];
            $prev = $i > 0 ? $word[$i - 1] : '';
            $next = $i + 1 < $length ? $word[$i + 1] : '';

            $code = match (true) {
                in_array($c, ['a', 'e', 'i', 'j', 'o', 'u', 'y'], true) => '0',
                $c === 'h' => '-',
                $c === 'b' => '1',
                $c === 'p' => $next === 'h' ? '3' : '1',
                in_array($c, ['d', 't'], true) => in_array($next, ['c', 's', 'z'], true) ? '8' : '2',
                in_array($c, ['f', 'v', 'w'], true) => '3',
                in_array($c, ['g', 'k', 'q'], true) => '4',
                $c === 'c' => self::encodeC($i, $prev, $next),
                $c === 'x' => in_array($prev, ['c', 'k', 'q'], true) ? '8' : '48',
                $c === 'l' => '5',
                in_array($c, ['m', 'n'], true) => '6',
                $c === 'r' => '7',
                in_array($c, ['s', 'z'], true) => '8',
                default => '',
            };
            $codes[] = $code;
        }

        // Regel: Mehrfachcodes zusammenfassen, dann Nullen (außer am Anfang) und 'h' entfernen
        $raw = implode('', $codes);
        $raw = str_replace('-', '', $raw);
        $collapsed = (string) preg_replace('/(.)\1+/', '$1', $raw);
        $first = $collapsed[0] ?? '';
        $rest = str_replace('0', '', substr($collapsed, 1));

        return $first . $rest;
    }

    private static function encodeC(int $index, string $prev, string $next): string
    {
        if ($index === 0) {
            return in_array($next, ['a', 'h', 'k', 'l', 'o', 'q', 'r', 'u', 'x'], true) ? '4' : '8';
        }
        if (in_array($prev, ['s', 'z'], true)) {
            return '8';
        }

        return in_array($next, ['a', 'h', 'k', 'o', 'q', 'u', 'x'], true) ? '4' : '8';
    }
}
