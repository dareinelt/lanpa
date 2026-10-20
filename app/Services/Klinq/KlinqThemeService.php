<?php

declare(strict_types=1);

namespace App\Services\Klinq;

use App\Services\ThemeService;

/**
 * Designfarben fuer KLINQ.
 *
 * KLINQ bleibt optisch eigenstaendig, uebernimmt aber die Farben des
 * Intranets: Akzentfarbe, Linien und gedaempfte Texte werden dezent
 * angeglichen. Die Werte stehen als CSS-Variablen bereit; die Anpassung
 * selbst erfolgt in KLINQ ueber eine kleine Zusatz-Stylesheet-Datei.
 */
final class KlinqThemeService
{
    public function __construct(private readonly ThemeService $theme)
    {
    }

    /**
     * Stylesheet mit den abgeleiteten Farbvariablen.
     *
     * Bei bekannter Designwahl (light, dark) wird nur dieser Block geliefert,
     * damit KLINQ der Einstellung des Intranets folgt und nicht der
     * Systemeinstellung des Rechners. Unbekannte Angaben gelten wie das
     * fehlende Cookie als helles Standarddesign.
     *
     * @param string $mode Design des Intranets: light, dark oder auto
     */
    public function css(string $mode = 'auto'): string
    {
        $modes = $this->theme->contrastModes();

        $light = $this->declarations($this->variables($modes['light'] ?? []));
        $dark = $this->declarations($this->variables($modes['dark'] ?? []));

        if ($mode === 'dark') {
            return ':root{' . $dark . '}';
        }

        if ($mode === 'auto') {
            return ':root{' . $light . '}'
                . '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){' . $dark . '}}'
                . ':root[data-theme="dark"]{' . $dark . '}'
                . ':root[data-theme="light"]{' . $light . '}';
        }

        return ':root{' . $light . '}';
    }

    /**
     * @param array<string,string> $base
     * @return array<string,string>
     */
    private function variables(array $base): array
    {
        $primary = (string) ($base['accent'] ?? '#1f4e79');
        $background = (string) ($base['background'] ?? '#ffffff');
        $text = (string) ($base['text'] ?? '#1f2933');

        return [
            // Akzent dezent abgeschwaecht, damit KLINQ eigenstaendig bleibt.
            '--klinq-lanpa-accent' => $this->theme->mix($primary, $background, 0.15),
            '--klinq-lanpa-accent-strong' => $primary,
            // Dunklerer Akzent fuer gedrueckte Zustaende (:active).
            '--klinq-lanpa-accent-dark' => $this->theme->mix($primary, '#000000', 0.22),
            // Sehr heller Akzentton fuer Flaechen (Hover, Badges, Hinweise).
            '--klinq-lanpa-accent-soft' => $this->theme->mix($primary, $background, 0.88),
            '--klinq-lanpa-on-accent' => $this->theme->readableTextColor($primary),
            '--klinq-lanpa-background' => $background,
            '--klinq-lanpa-surface' => (string) ($base['surface'] ?? $background),
            '--klinq-lanpa-surface-hover' => (string) ($base['surfaceHover'] ?? $background),
            '--klinq-lanpa-border' => $this->theme->mix($text, $background, 0.82),
            '--klinq-lanpa-text' => $text,
            '--klinq-lanpa-muted' => (string) ($base['muted'] ?? $text),
        ];
    }

    /**
     * @param array<string,string> $declarations
     */
    private function declarations(array $declarations): string
    {
        $css = '';
        foreach ($declarations as $property => $value) {
            $css .= $property . ':' . $value . ';';
        }

        return $css;
    }
}
