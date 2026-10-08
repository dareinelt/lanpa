<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Farbmathematik fuer kontrastsichere Textfarben nach WCAG 2.1.
 *
 * Wird u. a. fuer Kacheltexte verwendet: Weicht eine Kachel-Hintergrundfarbe
 * von der Design-Oberflaeche ab, wird die Textfarbe automatisch so weit
 * angepasst, dass der Mindestkontrast erreicht wird.
 */
final class Color
{
    /** Mindestkontrast fuer normalen Text nach WCAG 2.1 AA (1.4.3). */
    public const MIN_CONTRAST = 4.5;

    /** Anzahl der Zwischenschritte bei der automatischen Anpassung. */
    private const ADJUST_STEPS = 20;

    /**
     * Mischt zwei Farben; Gewicht 0 liefert die erste, 1 die zweite Farbe.
     */
    public static function blend(string $colorA, string $colorB, float $weight): string
    {
        $a = self::toRgb($colorA);
        $b = self::toRgb($colorB);
        $weight = max(0.0, min(1.0, $weight));

        $mixed = [];
        for ($i = 0; $i < 3; $i++) {
            $mixed[$i] = (int) round($a[$i] * (1 - $weight) + $b[$i] * $weight);
        }

        return sprintf('#%02x%02x%02x', $mixed[0], $mixed[1], $mixed[2]);
    }

    /**
     * Relative Leuchtdichte nach WCAG 2.1.
     */
    public static function relativeLuminance(string $color): float
    {
        [$r, $g, $b] = self::toRgb($color);

        $channel = static function (int $value): float {
            $value /= 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /**
     * Kontrastverhaeltnis zweier Farben (1.0 bis 21.0).
     */
    public static function contrastRatio(string $colorA, string $colorB): float
    {
        $luminanceA = self::relativeLuminance($colorA);
        $luminanceB = self::relativeLuminance($colorB);

        $lighter = max($luminanceA, $luminanceB);
        $darker = min($luminanceA, $luminanceB);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Schwarz oder Weiss mit dem jeweils hoeheren Kontrast zum Hintergrund.
     */
    public static function bestTextColor(string $background): string
    {
        return self::contrastRatio($background, '#000000') >= self::contrastRatio($background, '#ffffff')
            ? '#000000'
            : '#ffffff';
    }

    /**
     * Gibt die Farbe zurueck, sofern ihr Kontrast zum Hintergrund ausreicht;
     * andernfalls eine moeglichst aehnliche, ausreichend kontrastreiche Farbe.
     */
    public static function ensureContrast(string $background, string $color, float $minRatio = self::MIN_CONTRAST): string
    {
        $normalized = Validator::normalizeHexColor($color);
        if ($normalized === null) {
            return self::bestTextColor($background);
        }

        if (self::contrastRatio($background, $normalized) >= $minRatio) {
            return $normalized;
        }

        $target = self::bestTextColor($background);
        for ($step = 1; $step <= self::ADJUST_STEPS; $step++) {
            $candidate = self::blend($normalized, $target, $step / self::ADJUST_STEPS);
            if (self::contrastRatio($background, $candidate) >= $minRatio) {
                return $candidate;
            }
        }

        return $target;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function toRgb(string $color): array
    {
        $normalized = Validator::normalizeHexColor($color) ?? '#000000';

        return [
            (int) hexdec(substr($normalized, 1, 2)),
            (int) hexdec(substr($normalized, 3, 2)),
            (int) hexdec(substr($normalized, 5, 2)),
        ];
    }
}
