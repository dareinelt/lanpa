<?php

declare(strict_types=1);

use App\Exceptions\ValidationException;
use App\Repositories\AuthMetricsRepository;
use App\Services\Auth\AuthMetricsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Spiegelt database/migrations/050_auth_metrics.sql (manuell, SQLite).
 */
function authMetricsPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE auth_metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recorded_at TEXT NOT NULL,
            cpu_percent NUMERIC NOT NULL DEFAULT 0,
            cpu_limit NUMERIC NOT NULL DEFAULT 1,
            tcp_open INTEGER NOT NULL DEFAULT 0,
            sources TEXT NULL
        )'
    );

    return $pdo;
}

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function authMetricsSample(array $overrides = []): array
{
    return $overrides + [
        'cpu_percent' => '42.5',
        'cpu_limit' => '2.00',
        'tcp_open' => '12',
        'sources' => '{"192.168.200.0/24":5,"10.20.0.0/24":7}',
    ];
}

/**
 * @param list<array<string,mixed>> $rows
 */
function authMetricsSeed(PDO $pdo, array $rows): void
{
    $statement = $pdo->prepare(
        'INSERT INTO auth_metrics (recorded_at, cpu_percent, cpu_limit, tcp_open, sources)'
        . ' VALUES (:at, :cpu, :limit, :tcp, :sources)'
    );
    foreach ($rows as $row) {
        $statement->execute([
            'at' => $row['recorded_at'],
            'cpu' => $row['cpu_percent'] ?? 0,
            'limit' => $row['cpu_limit'] ?? 1,
            'tcp' => $row['tcp_open'] ?? 0,
            'sources' => $row['sources'] ?? null,
        ]);
    }
}

Runner::test('AuthMetrics: Probe wird geprueft und normalisiert', static function (): void {
    $sample = AuthMetricsService::validate(authMetricsSample());

    Assert::same(42.5, $sample['cpu_percent'], 'CPU-Auslastung');
    Assert::same(2.0, $sample['cpu_limit'], 'CPU-Limit');
    Assert::same(12, $sample['tcp_open'], 'Verbindungen');
    Assert::same(['10.20.0.0/24' => 7, '192.168.200.0/24' => 5], $sample['sources'], 'Quellnetze');

    $empty = AuthMetricsService::validate(authMetricsSample(['tcp_open' => 0, 'sources' => '']));
    Assert::same([], $empty['sources'], 'Leere Quellnetze sind erlaubt');
});

Runner::test('AuthMetrics: ungueltige Proben werden abgelehnt', static function (): void {
    $cases = [
        'cpu_percent' => authMetricsSample(['cpu_percent' => '101']),
        'cpu_limit' => authMetricsSample(['cpu_limit' => '0']),
        'tcp_open' => authMetricsSample(['tcp_open' => '-1']),
        'sources' => authMetricsSample(['sources' => '{kaputt']),
    ];

    foreach ($cases as $field => $payload) {
        try {
            AuthMetricsService::validate($payload);
            Assert::true(false, 'Probe mit ungueltigem ' . $field . ' wurde angenommen.');
        } catch (ValidationException $exception) {
            Assert::true(isset($exception->errors()[$field]), 'Fehler zu ' . $field . ' fehlt.');
        }
    }

    $many = [];
    for ($index = 0; $index <= AuthMetricsService::MAX_SOURCES; $index++) {
        $many['10.0.' . $index . '.0/24'] = 1;
    }
    try {
        AuthMetricsService::validate(authMetricsSample(['sources' => json_encode($many)]));
        Assert::true(false, 'Zu viele Quellnetze wurden angenommen.');
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['sources']), 'Fehler zu sources fehlt.');
    }

    $long = str_repeat('a', AuthMetricsService::MAX_SOURCE_LENGTH + 1);
    try {
        AuthMetricsService::validate(authMetricsSample(['sources' => json_encode([$long => 1])]));
        Assert::true(false, 'Zu langer Quellnetz-Name wurde angenommen.');
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['sources']), 'Fehler zu sources fehlt.');
    }
});

Runner::test('AuthMetrics: fehlende Werte werden beanstandet', static function (): void {
    try {
        AuthMetricsService::validate([]);
        Assert::true(false, 'Leere Probe wurde angenommen.');
    } catch (ValidationException $exception) {
        Assert::same(
            ['cpu_percent', 'cpu_limit', 'tcp_open'],
            array_keys($exception->errors()),
            'Alle Pflichtfelder'
        );
    }
});

Runner::test('AuthMetrics: Schwellen der CPU-Anzeige', static function (): void {
    Assert::same('ok', AuthMetricsService::level(0.0), 'ohne Last');
    Assert::same('ok', AuthMetricsService::level(74.9), 'knapp unter gelb');
    Assert::same('warn', AuthMetricsService::level(75.0), 'gelb ab 75 %');
    Assert::same('warn', AuthMetricsService::level(89.9), 'knapp unter rot');
    Assert::same('crit', AuthMetricsService::level(90.0), 'rot ab 90 %');
    Assert::same('crit', AuthMetricsService::level(100.0), 'Volllast');
});

Runner::test('AuthMetrics: Proben werden gespeichert und alte geraeumt', static function (): void {
    $pdo = authMetricsPdo();
    $now = time();
    authMetricsSeed($pdo, [
        ['recorded_at' => date('Y-m-d H:i:s', $now - (AuthMetricsService::RETENTION_HOURS + 1) * 3600), 'cpu_percent' => 10],
    ]);

    $service = new AuthMetricsService(new AuthMetricsRepository($pdo), static fn (): int => $now);
    $service->record(authMetricsSample());

    $rows = $pdo->query('SELECT recorded_at, cpu_percent, cpu_limit, tcp_open, sources FROM auth_metrics')
        ->fetchAll(PDO::FETCH_ASSOC);
    Assert::same(1, count($rows), 'Alte Probe wurde entfernt');
    Assert::same(date('Y-m-d H:i:s', $now), (string) $rows[0]['recorded_at'], 'Zeitpunkt der Probe');
    Assert::same(42.5, (float) $rows[0]['cpu_percent'], 'CPU-Auslastung');
    Assert::same(2.0, (float) $rows[0]['cpu_limit'], 'CPU-Limit');
    Assert::same(12, (int) $rows[0]['tcp_open'], 'Verbindungen');
    Assert::same(
        '{"10.20.0.0/24":7,"192.168.200.0/24":5}',
        (string) $rows[0]['sources'],
        'Quellnetze als JSON'
    );
});

Runner::test('AuthMetrics: Anzeige ohne Proben', static function (): void {
    $card = AuthMetricsService::buildCard([], time());

    Assert::same(false, $card['available'], 'Keine Messwerte');
    Assert::same(0, $card['samples'], 'Keine Messungen');
    Assert::null($card['cpu'], 'Keine CPU-Werte');
    Assert::null($card['tcp'], 'Keine Verbindungswerte');
    Assert::same([], $card['sources'], 'Keine Quellnetze');
});

Runner::test('AuthMetrics: Kennzahlen aus den Proben', static function (): void {
    $now = time();
    $rows = [
        ['recorded_at' => date('Y-m-d H:i:s', $now - 3600), 'cpu_percent' => 10.0, 'cpu_limit' => 2.0, 'tcp_open' => 10, 'sources' => '{"10.20.0.0/24":10}'],
        ['recorded_at' => date('Y-m-d H:i:s', $now - 1800), 'cpu_percent' => 90.0, 'cpu_limit' => 2.0, 'tcp_open' => 30, 'sources' => null],
        ['recorded_at' => date('Y-m-d H:i:s', $now), 'cpu_percent' => 50.0, 'cpu_limit' => 2.0, 'tcp_open' => 20, 'sources' => '{"10.20.0.0/24":5,"192.168.200.0/24":15}'],
    ];
    $card = AuthMetricsService::buildCard($rows, $now);

    Assert::same(true, $card['available'], 'Messwerte vorhanden');
    Assert::same(3, $card['samples'], 'Anzahl der Messungen');
    Assert::same(false, $card['stale'], 'Messwerte sind aktuell');
    Assert::same(50.0, $card['cpu']['current'], 'CPU aktuell');
    Assert::same(90.0, $card['cpu']['peak'], 'CPU-Spitze');
    Assert::same(date('d.m.Y H:i', $now - 1800), $card['cpu']['peak_at'], 'Zeitpunkt der Spitze');
    Assert::same(50.0, $card['cpu']['avg'], 'CPU-Mittel');
    Assert::same(2.0, $card['cpu']['limit'], 'CPU-Bezug');
    Assert::same('ok', $card['cpu']['level'], 'CPU-Zustand');
    Assert::same(12, $card['cpu']['window'], 'CPU-Fenster');

    Assert::same(20, $card['tcp']['open'], 'Verbindungen offen');
    Assert::same(30, $card['tcp']['peak'], 'Verbindungen Spitze');
    Assert::same(20.0, $card['tcp']['avg'], 'Verbindungen Mittel');
    Assert::same(24, $card['tcp']['window'], 'Verbindungs-Fenster');

    Assert::same(
        [
            ['network' => '192.168.200.0/24', 'count' => 15, 'share' => 75.0],
            ['network' => '10.20.0.0/24', 'count' => 5, 'share' => 25.0],
        ],
        $card['sources'],
        'Quellnetze der letzten Messung'
    );
    Assert::same(20, $card['source_total'], 'Verbindungen in der Verteilung');
});

Runner::test('AuthMetrics: Fenster von CPU und Verbindungen sind getrennt', static function (): void {
    $now = time();
    $rows = [
        ['recorded_at' => date('Y-m-d H:i:s', $now - 13 * 3600), 'cpu_percent' => 99.0, 'cpu_limit' => 1.0, 'tcp_open' => 99, 'sources' => null],
        ['recorded_at' => date('Y-m-d H:i:s', $now - 3600), 'cpu_percent' => 80.0, 'cpu_limit' => 1.0, 'tcp_open' => 40, 'sources' => null],
    ];
    $card = AuthMetricsService::buildCard($rows, $now);

    Assert::same(80.0, $card['cpu']['peak'], 'Alte Probe zaehlt nicht zur CPU-Spitze');
    Assert::same(80.0, $card['cpu']['avg'], 'Alte Probe zaehlt nicht zum CPU-Mittel');
    Assert::same('warn', $card['cpu']['level'], 'CPU-Zustand gelb');
    Assert::same(99, $card['tcp']['peak'], 'Alte Probe zaehlt zur Verbindungsspitze');
    Assert::same(69.5, $card['tcp']['avg'], 'Verbindungsmittel ueber 24 h');
});

Runner::test('AuthMetrics: veraltete Messwerte werden erkannt', static function (): void {
    $now = time();
    $rows = [
        ['recorded_at' => date('Y-m-d H:i:s', $now - (AuthMetricsService::STALE_SECONDS + 60)), 'cpu_percent' => 95.0, 'cpu_limit' => 1.0, 'tcp_open' => 1, 'sources' => null],
    ];
    $card = AuthMetricsService::buildCard($rows, $now);

    Assert::same(true, $card['stale'], 'Messwerte gelten als veraltet');
    Assert::same('crit', $card['cpu']['level'], 'CPU-Zustand rot');
});

Runner::test('AuthMetrics: Karte entsteht aus den gespeicherten Proben', static function (): void {
    $pdo = authMetricsPdo();
    $now = time();
    authMetricsSeed($pdo, [
        ['recorded_at' => date('Y-m-d H:i:s', $now - 120), 'cpu_percent' => 91.0, 'cpu_limit' => 1.0, 'tcp_open' => 4, 'sources' => '{"lokal":4}'],
        ['recorded_at' => date('Y-m-d H:i:s', $now - (AuthMetricsService::TCP_WINDOW_HOURS + 1) * 3600), 'cpu_percent' => 5.0, 'tcp_open' => 500],
    ]);

    $service = new AuthMetricsService(new AuthMetricsRepository($pdo), static fn (): int => $now);
    $card = $service->card();

    Assert::same(1, $card['samples'], 'Nur Proben des Fensters');
    Assert::same(91.0, $card['cpu']['current'], 'CPU aktuell');
    Assert::same('crit', $card['cpu']['level'], 'CPU-Zustand rot');
    Assert::same(4, $card['tcp']['open'], 'Verbindungen offen');
    Assert::same(4, $card['tcp']['peak'], 'Verbindungen Spitze im Fenster');
    Assert::same([['network' => 'lokal', 'count' => 4, 'share' => 100.0]], $card['sources'], 'Quellnetze');
    Assert::same(date('d.m.Y H:i', $now - 120), $card['recorded_at'], 'Stand der Messwerte');
});
