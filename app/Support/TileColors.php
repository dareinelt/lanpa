<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Textfarben fuer Kacheln mit eigener Hintergrundfarbe.
 *
 * Kacheltexte muessen auch dann lesbar bleiben, wenn eine Kachelfarbe von der
 * Design-Oberflaeche abweicht – etwa eine helle Kachelfarbe im dunklen Modus
 * oder eine dunkle Kachelfarbe im hellen Modus. Geprueft wird immer der
 * sichtbare Farbton: die Kachelfarbe mit ihrer Deckkraft ueber dem
 * Seitenhintergrund.
 */
final class TileColors
{
    /** Abstand der Hover-Flaeche vom sichtbaren Kachelhintergrund. */
    private const HOVER_OFFSET = 0.12;

    /**
     * Berechnet die Kachel-Variablen fuer einen Modus.
     *
     * Die Detailflaeche liegt auf der Design-Oberflaeche (nicht auf der
     * Kachelfarbe) und erhaelt deshalb eine eigene, gegen diese Flaeche
     * gepruefte Textfarbe.
     *
     * @param array{background:string,text:string,muted:string,accent:string,surface:string,surfaceHover:string} $base Designfarben des Modus
     * @return array{tileText:string,tileMuted:string,tileAccent:string,tileHoverBg:string,tileDetailsText:string}
     */
    public static function variables(array $base, string $tileBackground, int $opacity): array
    {
        $tile = $tileBackground !== '' ? $tileBackground : $base['surface'];
        $effective = Color::blend($base['background'], $tile, $opacity / 100);

        // Die Hover-Flaeche entfernt sich vom Textpol: Der Kontrast wird beim
        // Ueberfahren groesser, niemals kleiner.
        $pole = Color::bestTextColor($effective);
        $hover = Color::blend($effective, $pole === '#ffffff' ? '#000000' : '#ffffff', self::HOVER_OFFSET);

        return [
            'tileText' => Color::ensureContrast($effective, $base['text']),
            'tileMuted' => Color::ensureContrast($effective, $base['muted']),
            'tileAccent' => Color::ensureContrast($effective, $base['accent']),
            'tileHoverBg' => $hover,
            'tileDetailsText' => Color::ensureContrast($base['surfaceHover'], $base['text']),
        ];
    }
}
