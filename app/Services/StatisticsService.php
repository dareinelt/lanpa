<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ClickRepository;
use App\Repositories\NavigationRepository;
use DateTimeImmutable;

final class StatisticsService
{
    public const PERIODS = [3, 7, 14, 30, 90, 365];

    public function __construct(
        private readonly ClickRepository $clicks,
        private readonly NavigationRepository $navigation
    ) {
    }

    public static function normalizePeriod(int $days): int
    {
        return in_array($days, self::PERIODS, true) ? $days : 7;
    }

    /**
     * @return array{days:int,labels:list<string>,series:list<array{id:int,title:string,values:list<int>,total:int}>,total:int}
     */
    public function report(int $days, ?DateTimeImmutable $today = null): array
    {
        $days = self::normalizePeriod($days);
        $today ??= new DateTimeImmutable('today');
        $from = $today->modify('-' . ($days - 1) . ' days');

        $rows = $this->clicks->aggregateByDay($from->format('Y-m-d'));
        $items = $this->navigation->all();

        return self::buildReport($rows, $items, $days, $today);
    }

    /**
     * Reine Aggregationslogik – ohne Datenbank, damit testbar.
     *
     * @param list<array{day:string,navigation_id:int,clicks:int}> $rows
     * @param list<array<string,mixed>>                            $items
     *
     * @return array{days:int,labels:list<string>,series:list<array{id:int,title:string,values:list<int>,total:int}>,total:int}
     */
    public static function buildReport(array $rows, array $items, int $days, DateTimeImmutable $today): array
    {
        $days = self::normalizePeriod($days);

        $labels = [];
        $index = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $today->modify('-' . $i . ' days')->format('Y-m-d');
            $index[$date] = count($labels);
            $labels[] = $date;
        }

        $titles = [];
        foreach ($items as $item) {
            $titles[(int) $item['id']] = (string) $item['title'];
        }

        /** @var array<int,list<int>> $values */
        $values = [];
        $total = 0;

        foreach ($rows as $row) {
            $day = substr((string) $row['day'], 0, 10);
            if (!isset($index[$day])) {
                continue;
            }

            $navigationId = (int) $row['navigation_id'];
            if (!isset($values[$navigationId])) {
                $values[$navigationId] = array_fill(0, $days, 0);
            }

            $values[$navigationId][$index[$day]] += (int) $row['clicks'];
            $total += (int) $row['clicks'];
        }

        $series = [];
        foreach ($titles as $id => $title) {
            $data = $values[$id] ?? array_fill(0, $days, 0);
            $series[] = [
                'id' => $id,
                'title' => $title,
                'values' => array_values($data),
                'total' => array_sum($data),
            ];
        }

        // Geloeschte Navigationselemente koennen weiterhin Klicks besitzen.
        foreach ($values as $id => $data) {
            if (!isset($titles[$id])) {
                $series[] = [
                    'id' => $id,
                    'title' => 'Entferntes Element #' . $id,
                    'values' => array_values($data),
                    'total' => array_sum($data),
                ];
            }
        }

        usort($series, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'days' => $days,
            'labels' => $labels,
            'series' => $series,
            'total' => $total,
        ];
    }

    public function record(int $navigationId): bool
    {
        if (!$this->navigation->exists($navigationId)) {
            return false;
        }

        $this->clicks->record($navigationId);

        return true;
    }

    public function totalClicks(): int
    {
        return $this->clicks->countTotal();
    }

    public function clicksLastDays(int $days): int
    {
        $days = self::normalizePeriod($days);
        $from = (new DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days');

        return $this->clicks->countSince($from->format('Y-m-d'));
    }
}
