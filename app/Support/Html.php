<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ausgabe-Helfer. Alle Ausgaben in Templates laufen ueber diese Klasse.
 */
final class Html
{
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Escaping fuer Werte, die in ein <script>-JSON eingebettet werden.
     */
    public static function json(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return $json === false ? 'null' : $json;
    }

    /**
     * Attributwert fuer href: verhindert javascript:/data:-URLs.
     */
    public static function url(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '#';
        }

        if (!Validator::isSafeUrl($url)) {
            return '#';
        }

        return self::e($url);
    }
}
