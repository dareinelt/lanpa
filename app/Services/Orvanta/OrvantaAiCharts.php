<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Repositories\OrvantaRepository;

/**
 * Anonymisierter Nutzungsbericht der KI-Textunterstuetzung fuer den
 * Adminbereich. Die Diagramme werden serverseitig als SVG erzeugt - ohne
 * JavaScript und ohne style-Attribute (CSP), nur mit Praesentationsattributen.
 * Benutzer erscheinen ausschliesslich als "Benutzer 1..n"; die Zuordnung
 * wird nirgends gespeichert und aendert sich mit jedem Zeitraum.
 */
final class OrvantaAiCharts
{
    public const PERIODS = [7, 30, 90];
    public const DEFAULT_PERIOD = 30;

    private const WIDTH = 640;
    private const HEIGHT = 220;
    private const PAD_LEFT = 56;
    private const PAD_RIGHT = 16;
    private const PAD_TOP = 16;
    private const PAD_BOTTOM = 36;

    public function __construct(private readonly OrvantaRepository $repository)
    {
    }

    /**
     * Zeitraum aus der Anfrage normalisieren (nur erlaubte Werte).
     */
    public static function period(mixed $value): int
    {
        $days = is_numeric($value) ? (int) $value : self::DEFAULT_PERIOD;

        return in_array($days, self::PERIODS, true) ? $days : self::DEFAULT_PERIOD;
    }

    /**
     * Daten und fertige SVGs fuer die Ansicht.
     *
     * @return array{period:int,from:string,to:string,totals:array<string,int>,users:list<array<string,mixed>>,days:list<array<string,mixed>>,svg:array<string,string>}
     */
    public function report(int $days, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');
        $to = $now->format('Y-m-d 23:59:59');
        $from = $now->modify('-' . ($days - 1) . ' days')->format('Y-m-d 00:00:00');

        $users = $this->repository->aiUsagePerUser($from, $to);
        $days_ = $this->fillDays($this->repository->aiUsagePerDay($from, $to), $now, $days);
        $totals = $this->repository->aiTokenTotals($from, $to);

        return [
            'period' => $days,
            'from' => substr($from, 0, 10),
            'to' => substr($to, 0, 10),
            'totals' => $totals,
            'users' => $users,
            'days' => $days_,
            'svg' => [
                'users' => $this->usersChart($users),
                'requests' => $this->daysChart($days_, 'requests', 'Anfragen je Tag', '#2563eb'),
                'tokens' => $this->tokensChart($days_),
            ],
        ];
    }

    /**
     * Balkendiagramm: Anfragen je (pseudonymem) Benutzer.
     *
     * @param list<array<string,mixed>> $users
     */
    public function usersChart(array $users): string
    {
        $users = array_slice($users, 0, 20);
        if ($users === []) {
            return $this->empty('Keine Anfragen im Zeitraum.');
        }
        $max = max(1, (int) max(array_map(static fn (array $u): int => (int) $u['requests'], $users)));
        $plotW = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $plotH = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;
        $slot = $plotW / count($users);
        $barW = max(6, min(40, $slot * 0.6));

        $svg = $this->open('Anfragen je Benutzer (anonymisiert)');
        $svg .= $this->axes($max, $plotH);
        foreach ($users as $i => $user) {
            $value = (int) $user['requests'];
            $h = $plotH * $value / $max;
            $x = self::PAD_LEFT + $slot * $i + ($slot - $barW) / 2;
            $y = self::PAD_TOP + $plotH - $h;
            $svg .= sprintf(
                '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="#60a5fa" rx="2"><title>%s: %d Anfragen, %d Eingabe-/%d Ausgabe-Token</title></rect>',
                $x,
                $y,
                $barW,
                $h,
                $this->esc((string) $user['label']),
                $value,
                (int) $user['input_tokens'],
                (int) $user['output_tokens']
            );
            $svg .= sprintf(
                '<text x="%.1f" y="%d" font-size="10" text-anchor="middle" fill="#475569">%s</text>',
                $x + $barW / 2,
                self::HEIGHT - self::PAD_BOTTOM + 14,
                $this->esc(count($users) > 10 ? (string) ($i + 1) : (string) $user['label'])
            );
        }

        return $svg . '</svg>';
    }

    /**
     * Balkendiagramm ueber die Tage des Zeitraums.
     *
     * @param list<array<string,mixed>> $days
     */
    public function daysChart(array $days, string $key, string $title, string $color): string
    {
        if ($days === [] || array_sum(array_column($days, $key)) === 0) {
            return $this->empty('Keine Anfragen im Zeitraum.');
        }
        $max = max(1, (int) max(array_column($days, $key)));
        $plotW = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $plotH = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;
        $slot = $plotW / count($days);
        $barW = max(2, $slot * 0.7);

        $svg = $this->open($title);
        $svg .= $this->axes($max, $plotH);
        foreach ($days as $i => $day) {
            $value = (int) $day[$key];
            $h = $plotH * $value / $max;
            $x = self::PAD_LEFT + $slot * $i + ($slot - $barW) / 2;
            $svg .= sprintf(
                '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s" rx="1"><title>%s: %d</title></rect>',
                $x,
                self::PAD_TOP + $plotH - $h,
                $barW,
                $h,
                $color,
                $this->esc($this->dateLabel((string) $day['day'])),
                $value
            );
        }
        $svg .= $this->dayLabels($days, $slot);

        return $svg . '</svg>';
    }

    /**
     * Gestapelte Balken: Eingabe- und Ausgabe-Token je Tag.
     *
     * @param list<array<string,mixed>> $days
     */
    public function tokensChart(array $days): string
    {
        $sum = static fn (array $d): int => (int) $d['input_tokens'] + (int) $d['output_tokens'];
        if ($days === [] || array_sum(array_map($sum, $days)) === 0) {
            return $this->empty('Keine Token im Zeitraum.');
        }
        $max = max(1, (int) max(array_map($sum, $days)));
        $plotW = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $plotH = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;
        $slot = $plotW / count($days);
        $barW = max(2, $slot * 0.7);

        $svg = $this->open('Token je Tag (Eingabe hell, Ausgabe dunkel)');
        $svg .= $this->axes($max, $plotH);
        foreach ($days as $i => $day) {
            $in = (int) $day['input_tokens'];
            $out = (int) $day['output_tokens'];
            $x = self::PAD_LEFT + $slot * $i + ($slot - $barW) / 2;
            $hIn = $plotH * $in / $max;
            $hOut = $plotH * $out / $max;
            $title = sprintf('<title>%s: %d Eingabe, %d Ausgabe</title>', $this->esc($this->dateLabel((string) $day['day'])), $in, $out);
            $svg .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="#93c5fd">%s</rect>', $x, self::PAD_TOP + $plotH - $hIn, $barW, $hIn, $title);
            $svg .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="#1d4ed8">%s</rect>', $x, self::PAD_TOP + $plotH - $hIn - $hOut, $barW, $hOut, $title);
        }
        $svg .= $this->dayLabels($days, $slot);

        return $svg . '</svg>';
    }

    // ------------------------------------------------------------------

    /**
     * Alle Tage des Zeitraums auffuellen (auch ohne Anfragen).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function fillDays(array $rows, \DateTimeImmutable $now, int $days): array
    {
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = $row;
        }
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $now->modify('-' . $i . ' days')->format('Y-m-d');
            $row = $byDay[$day] ?? [];
            $result[] = [
                'day' => $day,
                'requests' => (int) ($row['requests'] ?? 0),
                'input_tokens' => (int) ($row['input_tokens'] ?? 0),
                'output_tokens' => (int) ($row['output_tokens'] ?? 0),
            ];
        }

        return $result;
    }

    private function open(string $title): string
    {
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" role="img" aria-label="%s" class="ov-ai-chart" font-family="system-ui, sans-serif"><title>%s</title>',
            self::WIDTH,
            self::HEIGHT,
            $this->esc($title),
            $this->esc($title)
        );
    }

    private function axes(int $max, float $plotH): string
    {
        $svg = '';
        $steps = 4;
        for ($s = 0; $s <= $steps; $s++) {
            $value = (int) round($max * $s / $steps);
            $y = self::PAD_TOP + $plotH - $plotH * $s / $steps;
            $svg .= sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="#e2e8f0" stroke-width="1"/>', self::PAD_LEFT, $y, self::WIDTH - self::PAD_RIGHT, $y);
            $svg .= sprintf('<text x="%d" y="%.1f" font-size="10" text-anchor="end" fill="#64748b">%s</text>', self::PAD_LEFT - 6, $y + 3, $this->number($value));
        }

        return $svg;
    }

    /**
     * @param list<array<string,mixed>> $days
     */
    private function dayLabels(array $days, float $slot): string
    {
        $count = count($days);
        $every = $count > 45 ? 15 : ($count > 14 ? 5 : 1);
        $svg = '';
        foreach ($days as $i => $day) {
            if ($i % $every !== 0 && $i !== $count - 1) {
                continue;
            }
            $svg .= sprintf(
                '<text x="%.1f" y="%d" font-size="10" text-anchor="middle" fill="#475569">%s</text>',
                self::PAD_LEFT + $slot * $i + $slot / 2,
                self::HEIGHT - self::PAD_BOTTOM + 14,
                $this->esc($this->dateLabel((string) $day['day']))
            );
        }

        return $svg;
    }

    private function empty(string $message): string
    {
        return $this->open($message) . sprintf(
            '<text x="%d" y="%d" font-size="13" text-anchor="middle" fill="#64748b">%s</text></svg>',
            (int) (self::WIDTH / 2),
            (int) (self::HEIGHT / 2),
            $this->esc($message)
        );
    }

    private function dateLabel(string $day): string
    {
        return preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $day, $m) === 1 ? $m[2] . '.' . $m[1] . '.' : $day;
    }

    private function number(int $value): string
    {
        return $value >= 10000 ? round($value / 1000) . 'k' : (string) $value;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
