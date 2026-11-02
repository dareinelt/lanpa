<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Formatiert Freitext aus Tickets und Kommentaren für die Anzeige.
 *
 * Bilder aus der Zwischenablage liegen als Base64-Daten-URI direkt im Text
 * (Markdown-Schreibweise `![Alt](data:image/png;base64,…)`) und werden dadurch
 * gemeinsam mit dem Text in der Datenbank gespeichert. Beim Rendern entstehen
 * daraus <img>-Elemente; für E-Mails werden sie durch einen kurzen Hinweis ersetzt.
 * Erlaubt sind ausschließlich Rasterbilder als Daten-URI – keine fremden URLs und
 * kein SVG (aktive Inhalte).
 *
 * Robustheit: Neben der regulären Marke werden auch Daten-URIs ohne Marke und rohes
 * Base64 mit bekanntem Bildkopf erkannt. Solche Texte entstehen, wenn die Zwischenablage
 * den Screenshot nur als Text liefert und das Einfügen im Browser nicht abgefangen wurde.
 * Zusätzlich sind weitere Media-Type-Parameter (`;charset=…`) sowie Zeilenumbrüche und
 * Leerzeichen innerhalb der Base64-Daten erlaubt.
 */
final class RichText
{
    /** Media-Typen, die als Daten-URI erlaubt sind (kein SVG – aktive Inhalte). */
    private const IMAGE_TYPES = 'png|jpe?g|gif|webp';

    /** Zusätzliche Media-Type-Parameter zwischen Typ und `;base64,` (z. B. `;charset=utf-8`). */
    private const IMAGE_PARAMS = '(?:;[a-z0-9!#$&^_.+\-]+=[^,;()\s]*)*';

    /** Hinweis darauf, dass der Text überhaupt eingebettete Bilddaten enthält. */
    private const IMAGE_HINT = '/(?:data:image\/|iVBORw0KGgo|\/9j\/|R0lGOD|UklGR)/i';

    /**
     * Bild im Text. Erkannt werden drei Schreibweisen:
     *  1. Markdown-Marke `![Alt](data:image/<typ>;base64,<daten>)` – der Regelfall,
     *  2. eine Daten-URI ohne (vollständige) Marke – z. B. direkt eingefügter Base64-Text,
     *  3. rohes Base64 mit bekanntem Bildkopf – Altbestand ohne `data:`-Präfix.
     */
    public const IMAGE_PATTERN = '/'
        . '!\[([^\]\r\n]{0,150})\]\(\s*data:image\/(' . self::IMAGE_TYPES . ')' . self::IMAGE_PARAMS . ';base64,([A-Za-z0-9+\/=\s]{16,})\s*\)'
        . '|(?:!\[([^\]\r\n]{0,150})\]\(\s*)?data:image\/(' . self::IMAGE_TYPES . ')' . self::IMAGE_PARAMS . ';base64,([A-Za-z0-9+\/=]{64,})'
        . '|((?:iVBORw0KGgo|\/9j\/|R0lGOD|UklGR)[A-Za-z0-9+\/=]{160,})'
        . '/i';

    /** Platzhalter, der beim Maskieren die Stelle eines Bildes markiert. */
    private const PLACEHOLDER = '/\x02(\d+)\x03/';

    /** Freitext als HTML: maskiert, mit Zeilenumbrüchen und eingebetteten Bildern. */
    public static function toHtml(?string $text): string
    {
        $text = self::normalizeNewlines((string) ($text ?? ''));

        // Bilder vor dem Maskieren durch Platzhalter ersetzen: Nur so bleiben die erzeugten
        // <img>-Elemente unversehrt und Alternativtexte werden korrekt maskiert.
        $images = [];
        if (preg_match(self::IMAGE_HINT, $text) === 1) {
            $text = (string) preg_replace_callback(
                self::IMAGE_PATTERN,
                static function (array $match) use (&$images): string {
                    $images[] = self::imageTag($match);

                    return "\x02" . (count($images) - 1) . "\x03";
                },
                $text
            );
        }

        $html = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        if ($images === []) {
            return $html;
        }

        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            static fn (array $match): string => $images[(int) $match[1]] ?? '',
            $html
        );
    }

    /** Freitext ohne eingebettete Bilder (für E-Mail-Texte, Protokolle, Zusammenfassungen). */
    public static function toPlain(?string $text, string $placeholder = '[Bild]'): string
    {
        return (string) preg_replace(self::IMAGE_PATTERN, $placeholder, (string) ($text ?? ''));
    }

    /**
     * Baut aus einem Treffer des IMAGE_PATTERN ein <img>-Element.
     *
     * @param array<int,string> $match
     */
    private static function imageTag(array $match): string
    {
        $alt = '';
        $type = '';
        $data = '';
        if (($match[3] ?? '') !== '') {
            $alt = (string) ($match[1] ?? '');
            $type = strtolower($match[2]);
            $data = (string) preg_replace('/\s+/', '', $match[3]);
        } elseif (($match[6] ?? '') !== '') {
            $alt = (string) ($match[4] ?? '');
            $type = strtolower($match[5]);
            $data = $match[6];
        } else {
            [$type, $data] = self::rawImage((string) ($match[7] ?? ''));
        }
        $alt = trim($alt) !== '' ? $alt : 'Bild';

        return '<img class="text-image" src="data:image/' . $type . ';base64,' . $data
            . '" alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" loading="lazy">';
    }

    /**
     * Ermittelt Typ und Daten eines rohen Base64-Blocks anhand seines Bildkopfs.
     *
     * @return array{0:string,1:string}
     */
    private static function rawImage(string $raw): array
    {
        if (str_starts_with($raw, 'iVBORw0KGgo')) {
            return ['png', $raw];
        }
        if (str_starts_with($raw, '/9j/')) {
            return ['jpeg', $raw];
        }
        if (str_starts_with($raw, 'R0lGOD')) {
            return ['gif', $raw];
        }

        return ['webp', $raw];
    }

    /**
     * Vereinheitlicht Zeilenumbrüche und begrenzt Leerzeilen-Blöcke auf eine Leerzeile.
     * E-Mail-Texte bringen oft mehrere Leerzeilen zwischen Absätzen mit, die im Browser
     * als massiver Zeilenabstand erscheinen; mehr als eine Leerzeile ist nie gewollt.
     */
    private static function normalizeNewlines(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    }
}
