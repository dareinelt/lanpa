<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Color;
use App\Support\TileColors;
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
        $light = $this->withTileContrast($this->lightVariables());
        $dark = $this->withTileContrast($this->darkVariables());

        $tileDefaults = '';
        $tileColor = $this->settings->tileBackgroundColor();
        if ($tileColor !== '') {
            $tileDefaults .= '--tile-bg-default:' . $tileColor . ';';
        }
        $tileDefaults .= '--tile-bg-opacity-default:' . $this->settings->tileBackgroundOpacity() . '%;';

        return ':root{' . $this->toDeclarations($light) . '--nav-opacity:' . $this->settings->navOpacity() . '%;' . $tileDefaults . '}'
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

    /**
     * Designfarben des hellen Modus.
     *
     * @return array<string,string>
     */
    private function lightVariables(): array
    {
        $theme = $this->settings->theme();

        return [
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
    }

    /**
     * Designfarben des dunklen Modus.
     *
     * @return array<string,string>
     */
    private function darkVariables(): array
    {
        $theme = $this->settings->theme();

        return [
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
    }

    /**
     * Kacheltexte muessen auch auf einer eigenen Kachel-Hintergrundfarbe lesbar
     * bleiben. Reicht der Kontrast der Designfarben dort nicht aus, wird die
     * Textfarbe automatisch angepasst (siehe App\Support\TileColors).
     *
     * @param array<string,string> $variables
     * @return array<string,string>
     */
    private function withTileContrast(array $variables): array
    {
        $tileBackground = $this->settings->tileBackgroundColor();
        $tile = TileColors::variables(
            $this->contrastBase($variables),
            $tileBackground,
            $this->settings->tileBackgroundOpacity()
        );

        $variables['--tile-text'] = $tile['tileText'];
        $variables['--tile-muted'] = $tile['tileMuted'];
        $variables['--tile-accent'] = $tile['tileAccent'];
        // Die Detailflaeche liegt auf der Design-Oberflaeche, nicht auf der
        // Kachelfarbe, und wird deshalb unabhaengig davon geprueft.
        $variables['--tile-details-text'] = $tile['tileDetailsText'];

        if ($tileBackground !== '') {
            // Eigene Kachelfarbe: Die Hover-Flaeche wird aus der Kachelfarbe
            // abgeleitet, damit die angepassten Textfarben auch beim
            // Ueberfahren gelten. Ohne eigene Kachelfarbe bleibt es beim
            // Design-Hover der Oberflaeche.
            $variables['--tile-hover-bg'] = $tile['tileHoverBg'];
        }

        return $variables;
    }

    /**
     * Basisfarben je Modus, mit denen Kacheln mit eigener Hintergrundfarbe ihre
     * Textfarben kontrastsicher berechnen (siehe views/partials/tiles.php).
     *
     * @return array{tileBackground:string,tileOpacity:int,light:array<string,string>,dark:array<string,string>}
     */
    public function tileContrastBase(): array
    {
        return [
            'tileBackground' => $this->settings->tileBackgroundColor(),
            'tileOpacity' => $this->settings->tileBackgroundOpacity(),
            'light' => $this->contrastBase($this->lightVariables()),
            'dark' => $this->contrastBase($this->darkVariables()),
        ];
    }

    /**
     * @param array<string,string> $variables
     * @return array{background:string,text:string,muted:string,accent:string,surface:string,surfaceHover:string}
     */
    private function contrastBase(array $variables): array
    {
        return [
            'background' => $variables['--color-background'],
            'text' => $variables['--color-text'],
            'muted' => $variables['--color-muted'],
            'accent' => $variables['--color-primary'],
            'surface' => $variables['--color-surface'],
            'surfaceHover' => $variables['--color-surface-hover'],
        ];
    }

    public function mix(string $colorA, string $colorB, float $weight): string
    {
        return Color::blend($colorA, $colorB, $weight);
    }

    public function lighten(string $color, float $amount): string
    {
        return Color::blend($color, '#ffffff', $amount);
    }

    /**
     * Waehlt Schwarz oder Weiss anhand der relativen Leuchtdichte (WCAG).
     */
    public function readableTextColor(string $color): string
    {
        return Color::relativeLuminance($color) > 0.45 ? '#000000' : '#ffffff';
    }
}
