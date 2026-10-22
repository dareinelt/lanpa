<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Prueft hochgeladene SVG-Dateien auf aktive oder externe Inhalte.
 *
 * Die Dateien werden spaeter als Bild ausgeliefert; Skripte, Event-Handler und
 * externe Referenzen sollen trotzdem nicht in den Speicher gelangen.
 */
final class SvgSanitizer
{
    public static function isSafe(string $contents): bool
    {
        $normalized = strtolower($contents);

        $forbidden = ['<script', '<foreignobject', 'javascript:', '<iframe', '<embed', '<object', '<use ', '<handler', '<set ', '<audio', '<video'];
        foreach ($forbidden as $needle) {
            if (str_contains($normalized, $needle)) {
                return false;
            }
        }

        // Event-Handler-Attribute (onload, onclick, ...)
        if (preg_match('/\son[a-z]+\s*=/i', $contents) === 1) {
            return false;
        }

        // Externe Referenzen und Entities
        if (preg_match('/<!entity/i', $contents) === 1 || preg_match('/<!doctype[^>]*\[/i', $contents) === 1) {
            return false;
        }

        return str_contains($normalized, '<svg');
    }
}
