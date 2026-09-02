<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Validator;

/**
 * Erzeugt aus den administrierbaren Farben serverseitig validierte CSS-Variablen.
 * Es werden ausschliesslich geprüfte Hex-Werte ausgegeben – niemals rohe Eingaben.
 */
final class ThemeService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function css(): string
    {
        $theme = $this->settings->theme();

        $light = [
            '--color-primary' => $theme['color_primary'],
            '--color-secondary' => $theme['color_secondary'],
            '--color-accent' => $theme['color_accent'],
            '--color-background' => $theme['color_background'],
            '--color-text' => $theme['color_text'],
            '--color-surface' => $this->mix($theme['color_background'], '#ffffff', 0.6),
            '--color-surface-hover' => $this->mix($theme['color_background'], '#ffffff', 0.85),
            '--color-border' => $this->mix($theme['color_text'], $theme['color_background'], 0.75),
            '--color-muted' => $this->mix($theme['color_text'], $theme['color_background'], 0.35),
            '--color-on-primary' => $this->readableTextColor($theme['color_primary']),
            '--color-on-accent' => $this->readableTextColor($theme['color_accent']),
        ];

        $dark = [
            '--color-primary' => $this->lighten($theme['color_primary'], 0.35),
            '--color-secondary' => $this->lighten($theme['color_secondary'], 0.3),
            '--color-accent' => $this->lighten($theme['color_accent'], 0.25),
            '--color-background' => $theme['color_background_dark'],
            '--color-text' => $theme['color_text_dark'],
            '--color-surface' => $this->lighten($theme['color_background_dark'], 0.08),
            '--color-surface-hover' => $this->lighten($theme['color_background_dark'], 0.16),
            '--color-border' => $this->lighten($theme['color_background_dark'], 0.3),
            '--color-muted' => $this->mix($theme['color_text_dark'], $theme['color_background_dark'], 0.45),
            '--color-on-primary' => $this->readableTextColor($this->lighten($theme['color_primary'], 0.35)),
            '--color-on-accent' => $this->readableTextColor($this->lighten($theme['color_accent'], 0.25)),
        ];

        return ':root{' . $this->toDeclarations($light) . '}'
            . '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){' . $this->toDeclarations($dark) . '}}'
            . ':root[data-theme="dark"]{' . $this->toDeclarations($dark) . '}'
            . ':root[data-theme="light"]{' . $this->toDeclarations($light) . '}';
    }

    /**
     * @param array<string,string> $declarations
     */
    private function toDeclarations(array $declarations): string
    {
        $css = '';
        foreach ($declarations as $property => $value) {
            $color = Validator::normalizeHexColor($value);
            if ($color === null) {
                continue;
            }
            $css .= $property . ':' . $color . ';';
        }

        return $css;
    }

    public function mix(string $colorA, string $colorB, float $weight): string
    {
        $a = $this->toRgb($colorA);
        $b = $this->toRgb($colorB);
        $weight = max(0.0, min(1.0, $weight));

        $mixed = [];
        for ($i = 0; $i < 3; $i++) {
            $mixed[$i] = (int) round($a[$i] * (1 - $weight) + $b[$i] * $weight);
        }

        return sprintf('#%02x%02x%02x', $mixed[0], $mixed[1], $mixed[2]);
    }

    public function lighten(string $color, float $amount): string
    {
        return $this->mix($color, '#ffffff', $amount);
    }

    /**
     * Waehlt Schwarz oder Weiss anhand der relativen Leuchtdichte (WCAG).
     */
    public function readableTextColor(string $color): string
    {
        [$r, $g, $b] = $this->toRgb($color);

        $channel = static function (float $value): float {
            $value /= 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        $luminance = 0.2126 * $channel((float) $r) + 0.7152 * $channel((float) $g) + 0.0722 * $channel((float) $b);

        return $luminance > 0.45 ? '#000000' : '#ffffff';
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function toRgb(string $color): array
    {
        $normalized = Validator::normalizeHexColor($color) ?? '#000000';

        return [
            (int) hexdec(substr($normalized, 1, 2)),
            (int) hexdec(substr($normalized, 3, 2)),
            (int) hexdec(substr($normalized, 5, 2)),
        ];
    }
}
