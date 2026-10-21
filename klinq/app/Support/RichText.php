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
 */
final class RichText
{
    /** Bildmarke im Text: ![Alternativtext](data:image/<typ>;base64,<daten>) */
    public const IMAGE_PATTERN = '/!\[([^\]\r\n]{0,150})\]\(data:image\/(png|jpe?g|gif|webp);base64,([A-Za-z0-9+\/=]{16,})\)/';

    /** Freitext als HTML: maskiert, mit Zeilenumbrüchen und eingebetteten Bildern. */
    public static function toHtml(?string $text): string
    {
        $html = nl2br(htmlspecialchars((string) ($text ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        if (!str_contains($html, 'data:image/')) {
            return $html;
        }

        return (string) preg_replace_callback(
            self::IMAGE_PATTERN,
            static fn (array $match): string => '<img class="text-image" src="data:image/' . $match[2] . ';base64,' . $match[3]
                . '" alt="' . $match[1] . '" loading="lazy">',
            $html
        );
    }

    /** Freitext ohne eingebettete Bilder (für E-Mail-Texte, Protokolle, Zusammenfassungen). */
    public static function toPlain(?string $text, string $placeholder = '[Bild]'): string
    {
        return (string) preg_replace(self::IMAGE_PATTERN, $placeholder, (string) ($text ?? ''));
    }
}
