<?php

declare(strict_types=1);

namespace App\Services\Klinq;

use App\Services\ThemeService;
use App\Support\Color;

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
    /** Statusfarben, wie KLINQ sie ohne Anbindung verwendet (siehe tokens.css). */
    private const STATUS_COLORS = [
        'success' => '#107c41',
        'warning' => '#9a6a00',
        'danger' => '#b3261e',
        'neutral' => '#5b6b7b',
    ];

    /** Zugehoerige Flaechenfarben des hellen Modus. */
    private const STATUS_SOFT_COLORS = [
        'success' => '#e5f3ec',
        'warning' => '#fdf3d7',
        'danger' => '#fbeae9',
        'neutral' => '#eceff2',
    ];

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

        $light = $this->declarations($this->variables($modes['light'] ?? [], false));
        $dark = $this->declarations($this->variables($modes['dark'] ?? [], true));

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
     * Farbvariablen eines Modus.
     *
     * Statusfarben dienen in KLINQ zugleich als Schriftfarbe auf ihrer
     * gedaempften Flaeche (Badges, Hinweise, interne Notizen) und als
     * Hintergrund von Schaltflaechen und Meldungen. Im dunklen Modus werden
     * daher die Flaechen dunkel eingefaerbt und die Schriftfarbe so weit
     * aufgehellt, bis sie den Mindestkontrast erreicht; die Textfarbe auf der
     * Vollflaeche liefert die passende Gegenfarbe. Im hellen Modus bleiben die
     * Werte unveraendert.
     *
     * @param array<string,string> $base
     * @return array<string,string>
     */
    private function variables(array $base, bool $dark): array
    {
        $primary = (string) ($base['accent'] ?? '#1f4e79');
        $background = (string) ($base['background'] ?? '#ffffff');
        $text = (string) ($base['text'] ?? '#1f2933');
        $surface = (string) ($base['surface'] ?? $background);

        $variables = [
            // Akzent dezent abgeschwaecht, damit KLINQ eigenstaendig bleibt.
            '--klinq-lanpa-accent' => $this->theme->mix($primary, $background, 0.15),
            '--klinq-lanpa-accent-strong' => $primary,
            // Dunklerer Akzent fuer gedrueckte Zustaende (:active).
            '--klinq-lanpa-accent-dark' => $this->theme->mix($primary, '#000000', 0.22),
            // Sehr heller Akzentton fuer Flaechen (Hover, Badges, Hinweise).
            '--klinq-lanpa-accent-soft' => $this->theme->mix($primary, $background, 0.88),
            '--klinq-lanpa-on-accent' => $this->theme->readableTextColor($primary),
            '--klinq-lanpa-background' => $background,
            '--klinq-lanpa-surface' => $surface,
            '--klinq-lanpa-surface-hover' => (string) ($base['surfaceHover'] ?? $background),
            '--klinq-lanpa-border' => $this->theme->mix($text, $background, 0.82),
            '--klinq-lanpa-border-strong' => $this->theme->mix($text, $background, 0.68),
            '--klinq-lanpa-text' => $text,
            '--klinq-lanpa-muted' => (string) ($base['muted'] ?? $text),
            // Steuert die Darstellung der Bedienelemente des Browsers
            // (Dateiauswahl, Auswahllisten, Bildlaufleisten).
            '--klinq-lanpa-color-scheme' => $dark ? 'dark' : 'light',
        ];

        foreach (self::STATUS_COLORS as $name => $color) {
            if ($dark) {
                $soft = $this->theme->mix($color, $background, 0.82);
                // Die Statusfarbe dient im dunklen Modus als Schriftfarbe auf
                // ihrer eigenen gedaempften Flaeche; dort muss sie lesbar sein.
                $status = Color::ensureContrast($soft, $color);
            } else {
                $soft = self::STATUS_SOFT_COLORS[$name];
                $status = $color;
            }

            $variables['--klinq-lanpa-' . $name] = $status;
            $variables['--klinq-lanpa-' . $name . '-soft'] = $soft;
            // Schriftfarbe auf der Vollflaeche: Schwarz oder Weiss, je nach
            // tatsaechlichem Kontrast (readableTextColor neigt bei mittleren
            // Helligkeiten zu Weiss und unterschreitet dann den Mindestwert).
            $variables['--klinq-lanpa-on-' . $name] = Color::bestTextColor($status);
        }

        // Textfarbe auf der neutralen Vollflaeche (Standard-Toast).
        $variables['--klinq-lanpa-on-text'] = Color::bestTextColor($text);

        return $variables;
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
