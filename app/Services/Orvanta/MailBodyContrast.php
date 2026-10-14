<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Support\Color;
use App\Support\Validator;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Macht Textfarben aus Nachrichten im dunklen Modus lesbar.
 *
 * Absender schreiben Farben fest in das HTML (z. B. ein dunkles Grau aus
 * Outlook). Auf der dunklen Design-Oberflaeche haben solche Farben zu wenig
 * Kontrast. Wie bei den Navigationskacheln wird deshalb je Modus eine
 * kontrastsichere Ersatzfarbe berechnet (siehe App\Support\TileColors und
 * App\Support\Color) und dem Element als eigene CSS-Variable mitgegeben;
 * orvanta.css faerbt den Text im jeweiligen Modus damit ein.
 *
 * Die Originalfarbe bleibt im style-Attribut stehen: Sie gilt weiterhin im
 * anderen Modus, beim Zitieren einer Nachricht und fuer den Empfaenger.
 */
final class MailBodyContrast
{
    public const ATTR_DARK = 'data-ov-dark-color';
    public const ATTR_LIGHT = 'data-ov-light-color';

    private const PROPERTY_DARK = '--ov-dark-color';
    private const PROPERTY_LIGHT = '--ov-light-color';

    /** Grundfarben (HTML/CSS), die in Nachrichten als Farbname vorkommen. */
    private const NAMED_COLORS = [
        'white' => '#ffffff', 'black' => '#000000', 'silver' => '#c0c0c0',
        'gray' => '#808080', 'grey' => '#808080', 'red' => '#ff0000',
        'maroon' => '#800000', 'yellow' => '#ffff00', 'olive' => '#808000',
        'lime' => '#00ff00', 'green' => '#008000', 'aqua' => '#00ffff',
        'cyan' => '#00ffff', 'teal' => '#008080', 'blue' => '#0000ff',
        'navy' => '#000080', 'fuchsia' => '#ff00ff', 'magenta' => '#ff00ff',
        'purple' => '#800080', 'orange' => '#ffa500',
    ];

    /**
     * Ergänzt im Nachrichtentext die modusabhaengigen Ersatzfarben.
     *
     * @param array{background:string,text:string,muted:string,accent:string,surface:string,surfaceHover:string} $light Designfarben des hellen Modus
     * @param array{background:string,text:string,muted:string,accent:string,surface:string,surfaceHover:string} $dark Designfarben des dunklen Modus
     */
    public static function apply(string $html, array $light, array $dark): string
    {
        if ($html === '' || stripos($html, 'color') === false) {
            return $html;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><div id="orvanta-contrast-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            return $html;
        }

        $root = $dom->getElementById('orvanta-contrast-root');
        if ($root === null) {
            return $html;
        }
        foreach ($root->childNodes as $child) {
            self::walk($child, null, $light, $dark);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /**
     * @param array{color:string,alpha:float}|null $background sichtbarer Hintergrund aus der Umgebung
     * @param array<string,string> $light
     * @param array<string,string> $dark
     */
    private static function walk(DOMNode $node, ?array $background, array $light, array $dark): void
    {
        if (!$node instanceof DOMElement) {
            return;
        }
        // Eine eigene Hintergrundfarbe gilt fuer diesen Teilbaum; unbekannte
        // Werte erben weiter, damit die Textfarbe nicht gegen die falsche
        // Flaeche geprueft wird.
        $background = self::declaredBackground($node) ?? $background;
        self::applyTextColor($node, $background, $light, $dark);

        foreach ($node->childNodes as $child) {
            self::walk($child, $background, $light, $dark);
        }
    }

    /**
     * @param array{color:string,alpha:float}|null $background
     * @param array<string,string> $light
     * @param array<string,string> $dark
     */
    private static function applyTextColor(DOMElement $node, ?array $background, array $light, array $dark): void
    {
        $declared = self::declaredTextColor($node);
        if ($declared === null) {
            return;
        }
        $style = (string) $node->getAttribute('style');

        foreach ([[self::ATTR_LIGHT, self::PROPERTY_LIGHT, $light], [self::ATTR_DARK, self::PROPERTY_DARK, $dark]] as [$attribute, $property, $base]) {
            $effective = $background === null
                ? $base['background']
                : Color::blend($base['background'], $background['color'], $background['alpha']);
            $color = Color::ensureContrast($effective, $declared);
            if ($color === $declared) {
                continue;
            }
            // Anhaengen statt umbauen: Die uebrigen Deklarationen bleiben
            // unveraendert, und bei mehrfachem Lauf gewinnt der letzte Wert.
            $style = ($style === '' ? '' : rtrim($style, "; \t") . ';') . $property . ':' . $color;
            $node->setAttribute('style', $style);
            $node->setAttribute($attribute, '');
        }
    }

    /**
     * Feste Textfarbe des Elements (style-Attribut oder color-Attribut).
     */
    private static function declaredTextColor(DOMElement $node): ?string
    {
        $style = (string) $node->getAttribute('style');
        if ($style !== '' && preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/i', $style, $match) === 1) {
            return self::colorValue($match[1])['color'] ?? null;
        }

        $attribute = trim($node->getAttribute('color'));
        if ($attribute !== '') {
            return self::colorValue($attribute)['color'] ?? null;
        }

        return null;
    }

    /**
     * Sichtbarer Hintergrund aus style-Attribut oder bgcolor-Attribut.
     *
     * @return array{color:string,alpha:float}|null null = unbekannt bzw. durchsichtig
     */
    private static function declaredBackground(DOMElement $node): ?array
    {
        $style = (string) $node->getAttribute('style');
        if ($style !== '' && preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)/i', $style, $match) === 1) {
            // Kurzschreibweise: der Farbwert steht vor weiteren Angaben.
            $parts = preg_split('/\s+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $value = self::colorValue($parts[0] ?? '');
            if ($value !== null) {
                return $value;
            }
        }

        $attribute = trim($node->getAttribute('bgcolor'));

        return $attribute === '' ? null : self::colorValue($attribute);
    }

    /**
     * Farbwert lesen: Hex, rgb()/rgba() oder Grundfarbe.
     *
     * @return array{color:string,alpha:float}|null
     */
    private static function colorValue(string $value): ?array
    {
        $value = strtolower(trim((string) preg_replace('/\s*!important\s*$/i', '', trim($value))));
        if ($value === '' || in_array($value, ['transparent', 'inherit', 'initial', 'none'], true)) {
            return null;
        }
        if (isset(self::NAMED_COLORS[$value])) {
            return ['color' => self::NAMED_COLORS[$value], 'alpha' => 1.0];
        }
        if (preg_match('/^rgba?\(([^)]*)\)$/', $value, $match) === 1) {
            $parts = preg_split('/[\s,\/]+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) < 3) {
                return null;
            }
            $channels = [];
            foreach (array_slice($parts, 0, 3) as $part) {
                $channel = self::channelValue($part);
                if ($channel === null) {
                    return null;
                }
                $channels[] = $channel;
            }
            $alpha = isset($parts[3]) && is_numeric($parts[3]) ? (float) $parts[3] : 1.0;
            if ($alpha <= 0.0) {
                return null;
            }

            return [
                'color' => sprintf('#%02x%02x%02x', $channels[0], $channels[1], $channels[2]),
                'alpha' => min(1.0, $alpha),
            ];
        }
        $hex = Validator::normalizeHexColor($value);

        return $hex === null ? null : ['color' => $hex, 'alpha' => 1.0];
    }

    /**
     * Farbkanal aus einer rgb()-Angabe (Zahl oder Prozent).
     */
    private static function channelValue(string $part): ?int
    {
        $part = trim($part);
        if (str_ends_with($part, '%')) {
            $number = trim(substr($part, 0, -1));
            if (!is_numeric($number)) {
                return null;
            }

            return (int) round(min(100.0, max(0.0, (float) $number)) * 2.55);
        }
        if (!is_numeric($part)) {
            return null;
        }

        return (int) round(min(255.0, max(0.0, (float) $part)));
    }
}
