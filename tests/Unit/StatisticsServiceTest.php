<?php

declare(strict_types=1);

use App\Services\StatisticsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

Runner::test('Zeiträume werden auf erlaubte Werte begrenzt', static function (): void {
    Assert::same(30, StatisticsService::normalizePeriod(30));
    Assert::same(7, StatisticsService::normalizePeriod(999));
    Assert::same(7, StatisticsService::normalizePeriod(0));
});

Runner::test('Bericht füllt fehlende Tage mit Nullwerten', static function (): void {
    $today = new DateTimeImmutable('2026-03-10');
    $rows = [
        ['day' => '2026-03-10', 'navigation_id' => 1, 'clicks' => 5],
        ['day' => '2026-03-08', 'navigation_id' => 1, 'clicks' => 2],
    ];
    $items = [['id' => 1, 'title' => 'DMS'], ['id' => 2, 'title' => 'Flip']];

    $report = StatisticsService::buildReport($rows, $items, 3, $today);

    Assert::same(3, $report['days']);
    Assert::same(['2026-03-08', '2026-03-09', '2026-03-10'], $report['labels']);
    Assert::same(7, $report['total']);
    Assert::same('DMS', $report['series'][0]['title']);
    Assert::same([2, 0, 5], $report['series'][0]['values']);
    Assert::same([0, 0, 0], $report['series'][1]['values']);
});

Runner::test('Bericht ignoriert Tage außerhalb des Zeitraums', static function (): void {
    $today = new DateTimeImmutable('2026-03-10');
    $rows = [['day' => '2026-01-01', 'navigation_id' => 1, 'clicks' => 99]];
    $report = StatisticsService::buildReport($rows, [['id' => 1, 'title' => 'DMS']], 3, $today);

    Assert::same(0, $report['total']);
    Assert::same([0, 0, 0], $report['series'][0]['values']);
});

Runner::test('Bericht berücksichtigt entfernte Navigationselemente', static function (): void {
    $today = new DateTimeImmutable('2026-03-10');
    $rows = [['day' => '2026-03-10 08:12:00', 'navigation_id' => 42, 'clicks' => 3]];
    $report = StatisticsService::buildReport($rows, [], 7, $today);

    Assert::same(1, count($report['series']));
    Assert::same('Entferntes Element #42', $report['series'][0]['title']);
    Assert::same(3, $report['series'][0]['total']);
});

Runner::test('Serien werden absteigend nach Summe sortiert', static function (): void {
    $today = new DateTimeImmutable('2026-03-10');
    $rows = [
        ['day' => '2026-03-10', 'navigation_id' => 1, 'clicks' => 1],
        ['day' => '2026-03-10', 'navigation_id' => 2, 'clicks' => 9],
    ];
    $items = [['id' => 1, 'title' => 'DMS'], ['id' => 2, 'title' => 'Flip']];

    $report = StatisticsService::buildReport($rows, $items, 3, $today);

    Assert::same('Flip', $report['series'][0]['title']);
    Assert::same('DMS', $report['series'][1]['title']);
});
