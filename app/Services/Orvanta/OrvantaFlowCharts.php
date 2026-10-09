<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

/**
 * Verlaufsgrafik des Nachrichtenfluss-Dashboards: die Nutzerzahlen der
 * Zeitraeume 365/180/90/30/14 Tage als Overlay.
 *
 * Die Grafik ist serverseitiges SVG mit viewBox und CSS-Groesse; dadurch ist
 * sie auf jeder Bildschirmdichte scharf (retina). Es werden ausschliesslich
 * Praesentationsattribute verwendet (CSP: keine style-Attribute). Jeder
 * Zeitraum spannt die volle Breite, der rechte Rand ist "heute"; so sind Form
 * und Niveau der Zeitraeume direkt vergleichbar. Die Tage eines Zeitraums
 * liegen als gleichmaessige Punkte vor, Luecken (Tage ohne Probe) werden als
 * Unterbrechung gezeichnet, nicht als Nullwert.
 */
final class OrvantaFlowCharts
{
    public const WIDTH = 960;
    public const HEIGHT = 340;
    private const PAD_LEFT = 52;
    private const PAD_RIGHT = 16;
    private const PAD_TOP = 22;
    private const PAD_BOTTOM = 38;

    /**
     * Farbe und Bezeichnung je Zeitraum. Die Reihenfolge der Zeichenreihenfolge
     * ist umgekehrt zur Laenge, damit der kurze, wichtigere Zeitraum oben liegt.
     *
     * @var array<int,array{color:string,label:string}>
     */
    private const SERIES = [
        365 => ['color' => '#94a3b8', 'label' => '365 Tage'],
        180 => ['color' => '#0ea5e9', 'label' => '180 Tage'],
        90 => ['color' => '#16a34a', 'label' => '90 Tage'],
        30 => ['color' => '#d97706', 'label' => '30 Tage'],
        14 => ['color' => '#dc2626', 'label' => '14 Tage'],
    ];

    /**
     * Farbe und Bezeichnung je Zeitraum (fuer Legende und Grafik).
     *
     * @return array<int,array{color:string,label:string}>
     */
    public static function series(): array
    {
        return self::SERIES;
    }

    /**
     * Farbe eines Zeitraums; unbekannte Zeitraeume erhalten einen neutralen Ton.
     */
    public static function color(int $days): string
    {
        return self::SERIES[$days]['color'] ?? '#64748b';
    }

    /**
     * Overlay-Grafik aller Zeitraeume.
     *
     * @param array{series?:array<int,array<string,mixed>>,max?:int} $history Verlauf aus OrvantaPresenceService::history()
     */
    public function overlay(array $history): string
    {
        $series = is_array($history['series'] ?? null) ? $history['series'] : [];
        if ($series === []) {
            return $this->empty('Keine Verlaufsdaten vorhanden.');
        }
        $max = max(1, (int) ($history['max'] ?? 0));
        $plotW = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $plotH = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;

        $svg = $this->open($max);
        $svg .= $this->grid($max, $plotW, $plotH);
        foreach ($this->longestFirst($series) as $days => $data) {
            $svg .= $this->line($days, $data, $max, $plotW, $plotH);
        }
        $svg .= $this->xLabels($plotW);

        return $svg . '</svg>';
    }

    /**
     * Wertetabelle des kuerzesten Zeitraums als Textalternative zur Grafik.
     *
     * @param array{series?:array<int,array<string,mixed>>} $history
     * @return list<array{day:string,value:int|null}>
     */
    public function tableRows(array $history): array
    {
        $series = is_array($history['series'] ?? null) ? $history['series'] : [];
        $days = $this->shortest($series);
        if ($days === 0) {
            return [];
        }
        $rows = [];
        foreach ((array) ($series[$days]['points'] ?? []) as $point) {
            if (!is_array($point)) {
                continue;
            }
            $rows[] = [
                'day' => (string) ($point['day'] ?? ''),
                'value' => $point['value'] === null ? null : (int) $point['value'],
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------

    /**
     * Zeitraeume absteigend nach Laenge (langer Zeitraum zuerst zeichnen).
     *
     * @param array<int,array<string,mixed>> $series
     * @return array<int,array<string,mixed>>
     */
    private function longestFirst(array $series): array
    {
        $ordered = [];
        $keys = array_map('intval', array_keys($series));
        rsort($keys);
        foreach ($keys as $days) {
            $ordered[$days] = $series[$days];
        }

        return $ordered;
    }

    /**
     * @param array<int,array<string,mixed>> $series
     */
    private function shortest(array $series): int
    {
        $keys = array_map('intval', array_keys($series));

        return $keys === [] ? 0 : min($keys);
    }

    /**
     * Eine Linie je Zeitraum. Tage ohne Probe unterbrechen die Linie.
     *
     * @param array<string,mixed> $data
     */
    private function line(int $days, array $data, int $max, float $plotW, float $plotH): string
    {
        $points = [];
        foreach ((array) ($data['points'] ?? []) as $point) {
            if (is_array($point)) {
                $points[] = $point;
            }
        }
        if ($points === []) {
            return '';
        }
        $step = count($points) > 1 ? $plotW / (count($points) - 1) : 0.0;
        $color = self::color($days);
        $segments = [];
        $current = [];
        $marker = null;
        foreach ($points as $i => $point) {
            if ($point['value'] === null) {
                if (count($current) > 1) {
                    $segments[] = $current;
                }
                $current = [];
                continue;
            }
            $x = self::PAD_LEFT + $step * $i;
            $y = self::PAD_TOP + $plotH - $plotH * (int) $point['value'] / $max;
            $current[] = sprintf('%.1f,%.1f', $x, $y);
            $marker = ['x' => $x, 'y' => $y, 'value' => (int) $point['value'], 'day' => (string) ($point['day'] ?? '')];
        }
        if (count($current) > 1) {
            $segments[] = $current;
        }
        if ($segments === [] && $marker !== null) {
            $segments[] = [sprintf('%.1f,%.1f', $marker['x'], $marker['y'])];
        }

        $svg = '';
        foreach ($segments as $segment) {
            $svg .= sprintf(
                '<polyline points="%s" fill="none" stroke="%s" stroke-width="%.1f" stroke-linejoin="round" stroke-linecap="round" class="ov-flow-line ov-flow-line--%d"/>',
                implode(' ', $segment),
                $color,
                $days <= 30 ? 2.0 : 1.4,
                $days
            );
        }
        if ($marker !== null) {
            $svg .= sprintf(
                '<circle cx="%.1f" cy="%.1f" r="2.6" fill="%s" class="ov-flow-point ov-flow-point--%d"><title>%s: %d aktive Nutzer (%s)</title></circle>',
                $marker['x'],
                $marker['y'],
                $color,
                $days,
                $this->esc($this->dateLabel($marker['day'])),
                $marker['value'],
                $this->esc(self::SERIES[$days]['label'] ?? $days . ' Tage')
            );
        }

        return $svg;
    }

    /**
     * Hilfslinien und Werte der gemeinsamen y-Achse.
     */
    private function grid(int $max, float $plotW, float $plotH): string
    {
        $svg = '';
        $steps = 4;
        for ($s = 0; $s <= $steps; $s++) {
            $y = self::PAD_TOP + $plotH - $plotH * $s / $steps;
            $svg .= sprintf(
                '<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="#e2e8f0" stroke-width="1" class="ov-flow-grid"/>',
                self::PAD_LEFT,
                $y,
                (int) (self::WIDTH - self::PAD_RIGHT),
                $y
            );
            $svg .= sprintf(
                '<text x="%d" y="%.1f" font-size="10" text-anchor="end" fill="#64748b">%d</text>',
                self::PAD_LEFT - 6,
                $y + 3,
                (int) round($max * $s / $steps)
            );
        }

        return $svg;
    }

    /**
     * Beschriftung der x-Achse. Alle Zeitraeume spannen die volle Breite, die
     * Achse ist deshalb relativ beschriftet (Anfang, Mitte, heute); die
     * konkreten Daten stehen in der Legende.
     */
    private function xLabels(float $plotW): string
    {
        $labels = [[0.0, 'Anfang des Zeitraums'], [0.5, 'Mitte'], [1.0, 'heute']];
        $svg = '';
        foreach ($labels as [$fraction, $label]) {
            $svg .= sprintf(
                '<text x="%.1f" y="%d" font-size="10" text-anchor="%s" fill="#475569">%s</text>',
                self::PAD_LEFT + $plotW * $fraction,
                self::HEIGHT - self::PAD_BOTTOM + 16,
                $fraction === 0.0 ? 'start' : ($fraction === 1.0 ? 'end' : 'middle'),
                $this->esc($label)
            );
        }

        return $svg;
    }

    private function open(int $max): string
    {
        $title = 'Aktive Orvanta-Nutzer je Tag, 365/180/90/30/14 Tage im Vergleich';
        $desc = sprintf(
            'Liniengrafik der Tageshöchstwerte aktiver Orvanta-Nutzer über fünf Zeiträume. '
            . 'Jeder Zeitraum spannt die volle Breite, der rechte Rand ist heute. '
            . 'Gemeinsame y-Achse von 0 bis %d. Die genauen Werte stehen in der Wertetabelle unter der Grafik.',
            $max
        );

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" role="img" aria-label="%s" class="ov-flow-chart" font-family="system-ui, sans-serif"><title>%s</title><desc>%s</desc>',
            self::WIDTH,
            self::HEIGHT,
            $this->esc($title),
            $this->esc($title),
            $this->esc($desc)
        );
    }

    private function empty(string $message): string
    {
        return $this->open(0) . sprintf(
            '<text x="%d" y="%d" font-size="13" text-anchor="middle" fill="#64748b">%s</text></svg>',
            (int) (self::WIDTH / 2),
            (int) (self::HEIGHT / 2),
            $this->esc($message)
        );
    }

    private function dateLabel(string $day): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) === 1
            ? $m[3] . '.' . $m[2] . '.' . $m[1]
            : $day;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
