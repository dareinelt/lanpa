<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * Verlaufsgrafiken der Karte "Reverse-Proxy" (Overlay auf dem Dashboard).
 *
 * Die Grafiken sind serverseitiges SVG mit viewBox und CSS-Groesse; dadurch
 * sind sie auf jeder Bildschirmdichte scharf. Es werden ausschliesslich
 * Praesentationsattribute verwendet (CSP: keine style-Attribute). Farben und
 * Schriftgroessen, die sich am Erscheinungsbild orientieren (Raster, Achsen),
 * sind zusaetzlich per CSS-Klasse ueberschreibbar; die inhaltlich bedeutsamen
 * Farben (Schwellen, Linien je Quellnetz) sind fest.
 *
 * Der Verlauf kommt aus AuthMetricsService::buildHistory(): gleichmaessige
 * Zeitabschnitte, ein Punkt je Abschnitt, Luecken (Abschnitte ohne Probe)
 * unterbrechen die Linie statt als Nullwert zu gelten.
 */
final class AuthMetricsCharts
{
    public const WIDTH = 960;
    public const HEIGHT = 280;

    /** Hoechstzahl der Linien in der Quellnetz-Grafik. */
    public const MAX_SOURCES = 5;

    private const PAD_LEFT = 54;
    private const PAD_RIGHT = 20;
    private const PAD_TOP = 16;
    private const PAD_BOTTOM = 34;

    /** Farbe der CPU-Linie. */
    private const CPU_COLOR = '#2563eb';

    /** Farbe der Verbindungslinie. */
    private const TCP_COLOR = '#0f766e';

    /** Farbe je Quellnetz, Reihenfolge wie in AuthMetricsService::buildHistory(). */
    private const SOURCE_COLORS = ['#0ea5e9', '#16a34a', '#d97706', '#7c3aed', '#c8102e'];

    /** Stufen der y-Achse; die erste, bei der vier Abschnitte genuegen, gewinnt. */
    private const AXIS_STEPS = [1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000, 10000, 25000, 50000, 100000];

    /**
     * Farbe einer Quellnetz-Linie.
     */
    public static function sourceColor(int $index): string
    {
        return self::SOURCE_COLORS[$index % count(self::SOURCE_COLORS)];
    }

    /**
     * Kleines Linienzeichen zur Farbe eines Quellnetzes (Legende).
     */
    public function sourceSwatch(int $index): string
    {
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 12 12" width="12" height="12" aria-hidden="true" class="auth-chart__swatch"><line x1="1" y1="6" x2="11" y2="6" stroke="%s" stroke-width="2.5" stroke-linecap="round"/></svg>',
            self::sourceColor($index)
        );
    }

    /**
     * CPU-Auslastung mit den Schwellen fuer gelb und rot.
     *
     * @param array<string,mixed> $history Verlauf aus AuthMetricsService::history()
     */
    public function cpu(array $history): string
    {
        $values = self::values($history['cpu']['values'] ?? null);
        if ($values === []) {
            return $this->empty('Keine Verlaufsdaten der CPU-Auslastung vorhanden.');
        }

        return $this->render(
            $history,
            'CPU-Auslastung im Reverse-Proxy',
            'Verlauf der CPU-Auslastung im Container als Mittel je Zeitabschnitt. '
            . 'Die gestrichelten Linien markieren die Schwellen 75 und 90 Prozent.',
            [['values' => $values, 'color' => self::CPU_COLOR, 'label' => 'CPU-Auslastung']],
            ['max' => 100.0, 'step' => 25.0],
            [
                ['value' => AuthMetricsService::WARN_PERCENT, 'color' => '#bf8700', 'label' => '75 %', 'level' => 'warn'],
                ['value' => AuthMetricsService::CRIT_PERCENT, 'color' => '#c8102e', 'label' => '90 %', 'level' => 'crit'],
            ],
            'percent'
        );
    }

    /**
     * Verbindungen je Messung (Summe des Messfensters).
     *
     * @param array<string,mixed> $history
     */
    public function tcp(array $history): string
    {
        $values = self::values($history['tcp']['values'] ?? null);
        if ($values === []) {
            return $this->empty('Keine Verlaufsdaten der Verbindungen vorhanden.');
        }

        return $this->render(
            $history,
            'Verbindungen im Reverse-Proxy je Messung',
            'Verlauf der im Messfenster aufgebauten eingehenden TCP-Verbindungen als Mittel je Zeitabschnitt.',
            [['values' => $values, 'color' => self::TCP_COLOR, 'label' => 'Verbindungen']],
            self::axis(self::peak($values)),
            [],
            'count'
        );
    }

    /**
     * Anfragen je Quellnetz (eine Linie je Netz).
     *
     * @param array<string,mixed> $history
     */
    public function sources(array $history): string
    {
        $series = [];
        $peak = 0.0;
        foreach ((array) ($history['sources'] ?? []) as $index => $source) {
            $values = self::values(is_array($source) ? ($source['values'] ?? null) : null);
            if ($values === []) {
                continue;
            }

            $peak = max($peak, self::peak($values));
            $series[] = [
                'values' => $values,
                'color' => self::sourceColor((int) $index),
                'label' => is_array($source) ? (string) ($source['network'] ?? '') : '',
            ];
        }

        if ($series === []) {
            return $this->empty('Keine Verlaufsdaten der Quellnetze vorhanden.');
        }

        return $this->render(
            $history,
            'Anfragen je Quellnetz',
            'Verlauf der Anfragen je Quellnetz als Mittel je Zeitabschnitt.',
            $series,
            self::axis($peak),
            [],
            'count'
        );
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $history
     * @param list<array{values:list<float|null>,color:string,label?:string}> $series
     * @param array{max:float,step:float} $axis
     * @param list<array{value:float,color:string,label:string,level:string}> $thresholds
     * @param string $unit Einheit der Werte fuer die Anzeige beim Ueberfahren: "percent" oder "count"
     */
    private function render(array $history, string $title, string $desc, array $series, array $axis, array $thresholds, string $unit): string
    {
        $buckets = max(1, (int) ($history['buckets'] ?? 0));
        $plotW = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $plotH = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;

        $svg = $this->open($title, $desc, self::payload($history, $series, $buckets, $unit, $axis['max']));
        $svg .= $this->grid($axis, $plotH);
        foreach ($thresholds as $threshold) {
            $svg .= $this->threshold($threshold, $axis, $plotH);
        }
        foreach ($series as $line) {
            $svg .= $this->line($line, $axis['max'], $buckets, $plotW, $plotH);
        }
        $svg .= $this->xLabels($history, $buckets, $plotW);

        return $svg . '</svg>';
    }

    /**
     * Werte der Grafik fuer die Anzeige beim Ueberfahren: Zeitpunkt und Wert
     * je Abschnitt und Linie, damit das Overlay ohne eigene Rechnung dieselben
     * Zahlen zeigt wie die gezeichneten Punkte. Abschnitte ohne Probe bleiben
     * null (Luecke) und werden nicht angezeigt.
     *
     * @param array<string,mixed> $history
     * @param list<array{values:list<float|null>,color:string,label?:string}> $series
     * @param float $axisMax Obergrenze der y-Achse (Position der Punkte)
     * @return array<string,mixed>
     */
    private static function payload(array $history, array $series, int $buckets, string $unit, float $axisMax): array
    {
        $start = strtotime((string) ($history['start'] ?? ''));

        $lines = [];
        foreach ($series as $line) {
            $values = [];
            foreach ($line['values'] as $value) {
                $values[] = $value === null ? null : round($value, 1);
            }

            $lines[] = [
                'label' => (string) ($line['label'] ?? ''),
                'color' => $line['color'],
                'values' => $values,
            ];
        }

        return [
            'unit' => $unit,
            'minutes' => max(1, (int) ($history['bucket_minutes'] ?? AuthMetricsService::HISTORY_BUCKET_MINUTES)),
            'start' => ($start === false ? 0 : $start) * 1000,
            'buckets' => $buckets,
            'axis' => $axisMax,
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'padLeft' => self::PAD_LEFT,
            'padRight' => self::PAD_RIGHT,
            'padTop' => self::PAD_TOP,
            'padBottom' => self::PAD_BOTTOM,
            'series' => $lines,
        ];
    }

    /**
     * Hilfslinien und Werte der y-Achse.
     *
     * @param array{max:float,step:float} $axis
     */
    private function grid(array $axis, float $plotH): string
    {
        $steps = max(1, (int) round($axis['max'] / $axis['step']));
        $svg = '';
        for ($index = 0; $index <= $steps; $index++) {
            $value = $axis['step'] * $index;
            $y = self::PAD_TOP + $plotH - $plotH * $value / $axis['max'];
            $svg .= sprintf(
                '<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="#e2e8f0" stroke-width="1" class="auth-chart__grid"/>',
                self::PAD_LEFT,
                $y,
                (int) (self::WIDTH - self::PAD_RIGHT),
                $y
            );
            $svg .= sprintf(
                '<text x="%d" y="%.1f" font-size="10" text-anchor="end" fill="#64748b" class="auth-chart__axis">%s</text>',
                self::PAD_LEFT - 8,
                $y + 3,
                $this->esc(self::format($value))
            );
        }

        return $svg;
    }

    /**
     * Gestrichelte Schwelle mit Beschriftung.
     *
     * @param array{value:float,color:string,label:string,level:string} $threshold
     * @param array{max:float,step:float} $axis
     */
    private function threshold(array $threshold, array $axis, float $plotH): string
    {
        $value = min($axis['max'], $threshold['value']);
        $y = self::PAD_TOP + $plotH - $plotH * $value / $axis['max'];

        return sprintf(
            '<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="%s" stroke-width="1" stroke-dasharray="4 3" class="auth-chart__threshold auth-chart__threshold--%s"/>'
            . '<text x="%d" y="%.1f" font-size="9" fill="%s" class="auth-chart__threshold-label auth-chart__threshold-label--%s">%s</text>',
            self::PAD_LEFT,
            $y,
            (int) (self::WIDTH - self::PAD_RIGHT),
            $y,
            $threshold['color'],
            $threshold['level'],
            self::PAD_LEFT + 4,
            $y - 3,
            $threshold['color'],
            $threshold['level'],
            $this->esc($threshold['label'])
        );
    }

    /**
     * Eine Linie; Abschnitte ohne Probe unterbrechen sie.
     *
     * @param array{values:list<float|null>,color:string} $line
     */
    private function line(array $line, float $max, int $buckets, float $plotW, float $plotH): string
    {
        $step = $buckets > 1 ? $plotW / ($buckets - 1) : 0.0;
        $segments = [];
        $current = [];
        foreach ($line['values'] as $index => $value) {
            if ($value === null) {
                if ($current !== []) {
                    $segments[] = $current;
                }
                $current = [];
                continue;
            }

            $current[] = sprintf(
                '%.1f,%.1f',
                self::PAD_LEFT + $step * $index,
                self::PAD_TOP + $plotH - $plotH * min($max, $value) / $max
            );
        }
        if ($current !== []) {
            $segments[] = $current;
        }

        $svg = '';
        foreach ($segments as $segment) {
            if (count($segment) === 1) {
                [$x, $y] = explode(',', $segment[0]);
                $svg .= sprintf(
                    '<circle cx="%s" cy="%s" r="2" fill="%s" class="auth-chart__point"/>',
                    $x,
                    $y,
                    $line['color']
                );
                continue;
            }

            $svg .= sprintf(
                '<polyline points="%s" fill="none" stroke="%s" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" class="auth-chart__line"/>',
                implode(' ', $segment),
                $line['color']
            );
        }

        return $svg;
    }

    /**
     * Beschriftung der x-Achse (Uhrzeiten des Zeitraums).
     *
     * @param array<string,mixed> $history
     */
    private function xLabels(array $history, int $buckets, float $plotW): string
    {
        $start = strtotime((string) ($history['start'] ?? ''));
        if ($start === false) {
            return '';
        }
        $bucket = max(1, (int) ($history['bucket_minutes'] ?? AuthMetricsService::HISTORY_BUCKET_MINUTES)) * 60;

        $svg = '';
        foreach ([0.0, 0.25, 0.5, 0.75, 1.0] as $fraction) {
            $index = (int) round(($buckets - 1) * $fraction);
            $at = $start + $index * $bucket;
            $svg .= sprintf(
                '<text x="%.1f" y="%d" font-size="10" text-anchor="%s" fill="#475569" class="auth-chart__axis">%s</text>',
                self::PAD_LEFT + $plotW * $fraction,
                self::HEIGHT - self::PAD_BOTTOM + 16,
                $fraction === 0.0 ? 'start' : ($fraction === 1.0 ? 'end' : 'middle'),
                $this->esc($fraction === 0.0 || $fraction === 1.0 ? date('d.m. H:i', $at) : date('H:i', $at))
            );
        }

        return $svg;
    }

    /**
     * @param array<string,mixed> $payload Werte fuer die Anzeige beim Ueberfahren (data-chart)
     */
    private function open(string $title, string $desc, array $payload = []): string
    {
        $data = '';
        if ($payload !== []) {
            $data = sprintf(
                ' data-chart="%s"',
                $this->esc((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" role="img" aria-label="%s" class="auth-chart" font-family="system-ui, sans-serif"%s><title>%s</title><desc>%s</desc>',
            self::WIDTH,
            self::HEIGHT,
            $this->esc($title),
            $data,
            $this->esc($title),
            $this->esc($desc)
        );
    }

    private function empty(string $message): string
    {
        return $this->open('Verlaufsgrafik', $message) . sprintf(
            '<text x="%d" y="%d" font-size="13" text-anchor="middle" fill="#64748b" class="auth-chart__axis">%s</text></svg>',
            (int) (self::WIDTH / 2),
            (int) (self::HEIGHT / 2),
            $this->esc($message)
        );
    }

    /**
     * Werte eines Verlaufs; ohne einen einzigen Wert bleibt die Liste leer.
     *
     * @return list<float|null>
     */
    private static function values(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            $result[] = $value === null ? null : (float) $value;
        }

        return array_filter($result, static fn (?float $value): bool => $value !== null) === [] ? [] : $result;
    }

    /**
     * @param list<float|null> $values
     */
    private static function peak(array $values): float
    {
        $peak = 0.0;
        foreach ($values as $value) {
            if ($value !== null) {
                $peak = max($peak, $value);
            }
        }

        return $peak;
    }

    /**
     * y-Achse mit ganzzahligen Hilfslinien: moeglichst wenige Abschnitte
     * (hoechstens vier) und ein runder Schritt.
     *
     * @return array{max:float,step:float}
     */
    private static function axis(float $peak): array
    {
        $peak = max(1.0, ceil($peak));
        foreach (self::AXIS_STEPS as $step) {
            if ($peak / $step <= 4.0) {
                return ['max' => (float) ($step * ceil($peak / $step)), 'step' => (float) $step];
            }
        }

        return ['max' => $peak, 'step' => ceil($peak / 4)];
    }

    private static function format(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
