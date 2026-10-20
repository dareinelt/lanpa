<?php

declare(strict_types=1);

namespace App\Support;

/** URL-Hilfsfunktionen (Open-Redirect-Schutz). */
final class Url
{
    /**
     * Lässt nur anwendungsinterne, absolute Pfade zu ("/assets/1?x=y#top").
     * Verwirft alles, was Browser als fremden Host interpretieren könnten:
     * Schema/Host, "//host", "/\host" (Backslash gilt in URL-Parsern als Slash), Steuerzeichen.
     * Gibt bei ungültigen Zielen den Fallback zurück.
     */
    public static function safeLocalPath(?string $target, string $fallback = ''): string
    {
        $target = (string) $target;
        if ($target === '' || $target[0] !== '/' || strlen($target) > 2000) {
            return $fallback;
        }
        if (isset($target[1]) && ($target[1] === '/' || $target[1] === '\\')) {
            return $fallback;
        }
        if (preg_match('/[\\\\\x00-\x1F\x7F]/', $target) === 1) {
            return $fallback;
        }
        $parts = parse_url($target);
        if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) {
            return $fallback;
        }

        return $target;
    }
}
