<?php

declare(strict_types=1);

use App\Exceptions\ValidationException;
use App\Repositories\ContainerMetricsRepository;
use App\Services\Auth\AuthMetricsCharts;
use App\Services\Monitoring\ContainerMetricsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Spiegelt database/migrations/054_container_metrics.sql (manuell, SQLite).
 */
function containerMetricsPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE container_metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service TEXT NOT NULL,
            recorded_at TEXT NOT NULL,
            cpu_percent NUMERIC NOT NULL DEFAULT 0,
            cpu_limit NUMERIC NOT NULL DEFAULT 1,
            cpu_limited INTEGER NOT NULL DEFAULT 0,
            ram_percent NUMERIC NULL,
            ram_used INTEGER NULL,
            ram_total INTEGER NULL
        )'
    );

    return $pdo;
}

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function containerMetricsSample(array $overrides = []): array
{
    return $overrides + [
        'service' => 'app',
        'cpu_percent' => '42.5',
        'cpu_limit' => '2.00',
        'cpu_limited' => '1',
        'ram_percent' => '62.5',
        'ram_used' => '1342177280',
        'ram_total' => '2147483648',
    ];
}

/**
 * @param list<array<string,mixed>> $rows
 */
function containerMetricsSeed(PDO $pdo, array $rows): void
{
    $statement = $pdo->prepare(
        'INSERT INTO container_metrics (service, recorded_at, cpu_percent, cpu_limit, cpu_limited, ram_percent, ram_used, ram_total)'
        . ' VALUES (:service, :at, :cpu, :limit, :limited, :ram, :ram_used, :ram_total)'
    );
    foreach ($rows as $row) {
        $statement->execute([
            'service' => $row['service'] ?? 'app',
            'at' => $row['recorded_at'],
            'cpu' => $row['cpu_percent'] ?? 0,
            'limit' => $row['cpu_limit'] ?? 1,
            'limited' => $row['cpu_limited'] ?? 0,
            'ram' => $row['ram_percent'] ?? null,
            'ram_used' => $row['ram_used'] ?? null,
            'ram_total' => $row['ram_total'] ?? null,
        ]);
    }
}

function containerMetricsService(PDO $pdo, int $now): ContainerMetricsService
{
    return new ContainerMetricsService(new ContainerMetricsRepository($pdo), static fn (): int => $now);
}

/** Abschnitte des Verlaufs ohne Luecken. */
function containerMetricsPresent(array $values): array
{
    return array_values(array_filter($values, static fn ($value): bool => $value !== null));
}

Runner::test('ContainerMetrics: Probe wird geprueft und normalisiert', static function (): void {
    $sample = ContainerMetricsService::validate(containerMetricsSample());

    Assert::same('app', $sample['service'], 'Container');
    Assert::same(42.5, $sample['cpu_percent'], 'CPU-Auslastung');
    Assert::same(2.0, $sample['cpu_limit'], 'CPU-Bezugsgroesse');
    Assert::same(true, $sample['cpu_limited'], 'CPU-Obergrenze gesetzt');
    Assert::same(
        ['percent' => 62.5, 'used' => 1342177280, 'total' => 2147483648],
        $sample['ram'],
        'Arbeitsspeicher'
    );

    $withoutLimit = ContainerMetricsService::validate(containerMetricsSample(['cpu_limited' => '0']));
    Assert::same(false, $withoutLimit['cpu_limited'], 'Ohne Obergrenze gilt der Host');
});

Runner::test('ContainerMetrics: Arbeitsspeicher ist optional', static function (): void {
    $without = ContainerMetricsService::validate(containerMetricsSample([
        'ram_percent' => null,
        'ram_used' => null,
        'ram_total' => null,
    ]));

    Assert::null($without['ram'], 'Ohne Angaben bleibt der Arbeitsspeicher leer');
});

Runner::test('ContainerMetrics: unbekannter Container wird abgelehnt', static function (): void {
    foreach (['', 'monitor', 'APP', 'auth'] as $service) {
        try {
            ContainerMetricsService::validate(containerMetricsSample(['service' => $service]));
            Assert::true(false, 'Unbekannter Container "' . $service . '" wurde angenommen.');
        } catch (ValidationException $exception) {
            Assert::true(isset($exception->errors()['service']), 'Fehler zu service fehlt (' . $service . ').');
        }
    }
});

Runner::test('ContainerMetrics: ungueltige Arbeitsspeicher-Angaben werden abgelehnt', static function (): void {
    $cases = [
        'ram_percent' => containerMetricsSample(['ram_percent' => '101']),
        'ram_negative' => containerMetricsSample(['ram_used' => '-1']),
        'ram_text' => containerMetricsSample(['ram_used' => 'viel']),
        'ram_missing' => containerMetricsSample(['ram_total' => null]),
        'ram_too_large' => containerMetricsSample(['ram_total' => (string) (ContainerMetricsService::MAX_MEMORY_BYTES + 1)]),
    ];

    foreach ($cases as $case => $payload) {
        try {
            ContainerMetricsService::validate($payload);
            Assert::true(false, 'Probe mit ungueltigem Arbeitsspeicher (' . $case . ') wurde angenommen.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            Assert::true(isset($errors['ram_percent']), 'Fehler zu ram_percent fehlt (' . $case . ').');
            Assert::true(isset($errors['ram_used']), 'Fehler zu ram_used fehlt (' . $case . ').');
            Assert::true(isset($errors['ram_total']), 'Fehler zu ram_total fehlt (' . $case . ').');
        }
    }
});

Runner::test('ContainerMetrics: ungueltige Proben werden abgelehnt', static function (): void {
    $cases = [
        'CPU-Auslastung ueber 100' => ['cpu_percent', containerMetricsSample(['cpu_percent' => '101'])],
        'CPU-Auslastung negativ' => ['cpu_percent', containerMetricsSample(['cpu_percent' => '-1'])],
        'CPU-Auslastung ohne Zahl' => ['cpu_percent', containerMetricsSample(['cpu_percent' => 'viel'])],
        'CPU-Bezugsgroesse null' => ['cpu_limit', containerMetricsSample(['cpu_limit' => '0'])],
        'CPU-Bezugsgroesse negativ' => ['cpu_limit', containerMetricsSample(['cpu_limit' => '-1'])],
        'CPU-Obergrenze ueber 1' => ['cpu_limited', containerMetricsSample(['cpu_limited' => '2'])],
        'CPU-Obergrenze als Text' => ['cpu_limited', containerMetricsSample(['cpu_limited' => 'ja'])],
    ];

    foreach ($cases as $case => [$field, $payload]) {
        try {
            ContainerMetricsService::validate($payload);
            Assert::true(false, 'Probe mit ungueltigem ' . $field . ' wurde angenommen (' . $case . ').');
        } catch (ValidationException $exception) {
            Assert::true(isset($exception->errors()[$field]), 'Fehler zu ' . $field . ' fehlt (' . $case . ').');
        }
    }
});

Runner::test('ContainerMetrics: fehlende Werte werden beanstandet', static function (): void {
    try {
        ContainerMetricsService::validate([]);
        Assert::true(false, 'Leere Probe wurde angenommen.');
    } catch (ValidationException $exception) {
        Assert::same(
            ['service', 'cpu_percent', 'cpu_limit', 'cpu_limited'],
            array_keys($exception->errors()),
            'Alle Pflichtfelder'
        );
    }
});

Runner::test('ContainerMetrics: Schwellen der Anzeige', static function (): void {
    Assert::same('ok', ContainerMetricsService::level(0.0), 'ohne Last');
    Assert::same('ok', ContainerMetricsService::level(74.9), 'knapp unter gelb');
    Assert::same('warn', ContainerMetricsService::level(75.0), 'gelb ab 75 %');
    Assert::same('warn', ContainerMetricsService::level(89.9), 'knapp unter rot');
    Assert::same('crit', ContainerMetricsService::level(90.0), 'rot ab 90 %');
    Assert::same('crit', ContainerMetricsService::level(100.0), 'Volllast');
});

Runner::test('ContainerMetrics: Zustand der Kachel', static function (): void {
    $state = static fn (string $cpu, ?string $ram): string => ContainerMetricsService::state([
        'cpu' => ['level' => $cpu],
        'ram' => $ram === null ? null : ['level' => $ram],
    ]);

    Assert::same('ok', $state('ok', 'ok'), 'Beide Kennzahlen unauffaellig');
    Assert::same('ok', $state('ok', null), 'Ohne Arbeitsspeicher zaehlt die CPU');
    Assert::same('warn', $state('ok', 'warn'), 'Arbeitsspeicher gelb');
    Assert::same('warn', $state('warn', 'ok'), 'CPU gelb');
    Assert::same('crit', $state('crit', 'warn'), 'CPU rot');
    Assert::same('crit', $state('warn', 'crit'), 'Arbeitsspeicher rot');
    Assert::same('ok', ContainerMetricsService::state([]), 'Ohne Kennzahlen unauffaellig');
});

Runner::test('ContainerMetrics: Bezugsgroesse der CPU', static function (): void {
    Assert::same(
        'Zugewiesen: 2,00 Kerne',
        ContainerMetricsService::cpuReference(['limit' => 2.0, 'limited' => true]),
        'Mit Obergrenze'
    );
    Assert::same(
        'Bezugsgröße: 8,00 Kerne (Host)',
        ContainerMetricsService::cpuReference(['limit' => 8.0, 'limited' => false]),
        'Ohne Obergrenze gilt der Host'
    );
});

Runner::test('ContainerMetrics: Proben werden gespeichert und alte geraeumt', static function (): void {
    $pdo = containerMetricsPdo();
    $now = time();
    containerMetricsSeed($pdo, [
        [
            'service' => 'db',
            'recorded_at' => date('Y-m-d H:i:s', $now - (ContainerMetricsService::RETENTION_HOURS + 1) * 3600),
            'cpu_percent' => 10,
        ],
    ]);

    containerMetricsService($pdo, $now)->record(containerMetricsSample());

    $rows = $pdo->query('SELECT service, recorded_at, cpu_percent, cpu_limit, cpu_limited, ram_percent, ram_used, ram_total FROM container_metrics')
        ->fetchAll(PDO::FETCH_ASSOC);
    Assert::same(1, count($rows), 'Alte Probe wurde entfernt');
    Assert::same('app', (string) $rows[0]['service'], 'Container der Probe');
    Assert::same(date('Y-m-d H:i:s', $now), (string) $rows[0]['recorded_at'], 'Zeitpunkt der Probe');
    Assert::same(42.5, (float) $rows[0]['cpu_percent'], 'CPU-Auslastung');
    Assert::same(2.0, (float) $rows[0]['cpu_limit'], 'CPU-Bezugsgroesse');
    Assert::same(1, (int) $rows[0]['cpu_limited'], 'CPU-Obergrenze gesetzt');
    Assert::same(62.5, (float) $rows[0]['ram_percent'], 'Arbeitsspeicher-Auslastung');
    Assert::same(1342177280, (int) $rows[0]['ram_used'], 'Belegter Arbeitsspeicher');
    Assert::same(2147483648, (int) $rows[0]['ram_total'], 'Arbeitsspeicher-Bezugsgroesse');
});

Runner::test('ContainerMetrics: ohne Arbeitsspeicher bleibt die Spalte leer', static function (): void {
    $pdo = containerMetricsPdo();
    $now = time();

    containerMetricsService($pdo, $now)->record(containerMetricsSample([
        'ram_percent' => null,
        'ram_used' => null,
        'ram_total' => null,
    ]));

    $row = $pdo->query('SELECT ram_percent, ram_used, ram_total FROM container_metrics')->fetch(PDO::FETCH_ASSOC);
    Assert::null($row['ram_percent'], 'Keine Auslastung gespeichert');
    Assert::null($row['ram_used'], 'Kein Verbrauch gespeichert');
    Assert::null($row['ram_total'], 'Keine Bezugsgroesse gespeichert');
});

Runner::test('ContainerMetrics: Anzeige ohne Proben', static function (): void {
    $card = ContainerMetricsService::buildCard([], time());

    Assert::same(false, $card['available'], 'Keine Messwerte');
    Assert::same(0, $card['samples'], 'Keine Messungen');
    Assert::same(true, $card['stale'], 'Ohne Messwerte gilt die Anzeige als veraltet');
    Assert::null($card['cpu'], 'Keine CPU-Werte');
    Assert::null($card['ram'], 'Keine Arbeitsspeicher-Werte');
    Assert::null($card['recorded_at'], 'Kein Stand');
});

Runner::test('ContainerMetrics: Kennzahlen aus den Proben', static function (): void {
    $now = time();
    $rows = [
        ['recorded_at' => date('Y-m-d H:i:s', $now - 3600), 'cpu_percent' => 10.0, 'cpu_limit' => 2.0, 'cpu_limited' => true, 'ram_percent' => 88.0, 'ram_used' => 1803550720, 'ram_total' => 2147483648],
        ['recorded_at' => date('Y-m-d H:i:s', $now - 1800), 'cpu_percent' => 90.0, 'cpu_limit' => 2.0, 'cpu_limited' => true, 'ram_percent' => 76.0, 'ram_used' => 1024, 'ram_total' => 2048],
        ['recorded_at' => date('Y-m-d H:i:s', $now), 'cpu_percent' => 50.0, 'cpu_limit' => 2.0, 'cpu_limited' => true, 'ram_percent' => 60.0, 'ram_used' => 2048, 'ram_total' => 4096],
    ];
    $card = ContainerMetricsService::buildCard($rows, $now);

    Assert::same(true, $card['available'], 'Messwerte vorhanden');
    Assert::same(3, $card['samples'], 'Anzahl der Messungen');
    Assert::same(false, $card['stale'], 'Messwerte sind aktuell');
    Assert::same(50.0, $card['cpu']['current'], 'CPU aktuell');
    Assert::same(90.0, $card['cpu']['peak'], 'CPU-Spitze');
    Assert::same(date('d.m.Y H:i', $now - 1800), $card['cpu']['peak_at'], 'Zeitpunkt der Spitze');
    Assert::same(50.0, $card['cpu']['avg'], 'CPU-Mittel');
    Assert::same(2.0, $card['cpu']['limit'], 'CPU-Bezug');
    Assert::same(true, $card['cpu']['limited'], 'CPU-Obergrenze gesetzt');
    Assert::same('ok', $card['cpu']['level'], 'CPU-Zustand');
    Assert::same(12, $card['cpu']['window'], 'CPU-Fenster');

    Assert::same(60.0, $card['ram']['current'], 'Arbeitsspeicher aktuell');
    Assert::same(2048, $card['ram']['used'], 'Belegter Arbeitsspeicher');
    Assert::same(4096, $card['ram']['total'], 'Bezugsgroesse des Arbeitsspeichers');
    Assert::same(88.0, $card['ram']['peak'], 'Spitze des Arbeitsspeichers');
    Assert::same(date('d.m.Y H:i', $now - 3600), $card['ram']['peak_at'], 'Zeitpunkt der Spitze');
    Assert::same(74.7, $card['ram']['avg'], 'Mittel des Arbeitsspeichers');
    Assert::same('ok', $card['ram']['level'], 'Arbeitsspeicher-Zustand');
    Assert::same(12, $card['ram']['window'], 'Fenster des Arbeitsspeichers');
    Assert::same(date('d.m.Y H:i', $now), $card['recorded_at'], 'Stand der Messwerte');
});

Runner::test('ContainerMetrics: Fenster der Anzeige ist begrenzt', static function (): void {
    $now = time();
    $rows = [
        ['recorded_at' => date('Y-m-d H:i:s', $now - (ContainerMetricsService::CPU_WINDOW_HOURS + 1) * 3600), 'cpu_percent' => 99.0, 'cpu_limit' => 1.0, 'cpu_limited' => false, 'ram_percent' => null, 'ram_used' => null, 'ram_total' => null],
        ['recorded_at' => date('Y-m-d H:i:s', $now - 3600), 'cpu_percent' => 80.0, 'cpu_limit' => 1.0, 'cpu_limited' => false, 'ram_percent' => null, 'ram_used' => null, 'ram_total' => null],
    ];
    $card = ContainerMetricsService::buildCard($rows, $now);

    Assert::same(2, $card['samples'], 'Beide Proben sind gespeichert');
    Assert::same(80.0, $card['cpu']['peak'], 'Alte Probe zaehlt nicht zur CPU-Spitze');
    Assert::same(80.0, $card['cpu']['avg'], 'Alte Probe zaehlt nicht zum CPU-Mittel');
    Assert::same('warn', $card['cpu']['level'], 'CPU-Zustand gelb');
    Assert::null($card['ram'], 'Ohne Speicherwerte keine Arbeitsspeicher-Anzeige');
});

Runner::test('ContainerMetrics: juengste Speicherwerte bleiben sichtbar', static function (): void {
    $now = time();
    $rows = [
        ['recorded_at' => date('Y-m-d H:i:s', $now - 600), 'cpu_percent' => 20.0, 'cpu_limit' => 1.0, 'cpu_limited' => false, 'ram_percent' => 40.0, 'ram_used' => 1024, 'ram_total' => 2048],
        ['recorded_at' => date('Y-m-d H:i:s', $now), 'cpu_percent' => 30.0, 'cpu_limit' => 1.0, 'cpu_limited' => false, 'ram_percent' => null, 'ram_used' => null, 'ram_total' => null],
    ];
    $card = ContainerMetricsService::buildCard($rows, $now);

    Assert::same(40.0, $card['ram']['current'], 'Juengste Probe mit Speicherwerten');
    Assert::same(1024, $card['ram']['used'], 'Belegter Arbeitsspeicher');
    Assert::same(2048, $card['ram']['total'], 'Bezugsgroesse des Arbeitsspeichers');
    Assert::same(30.0, $card['cpu']['current'], 'CPU der juengsten Probe');
});

Runner::test('ContainerMetrics: veraltete Messwerte werden erkannt', static function (): void {
    $now = time();
    $rows = [
        ['recorded_at' => date('Y-m-d H:i:s', $now - (ContainerMetricsService::STALE_SECONDS + 60)), 'cpu_percent' => 95.0, 'cpu_limit' => 1.0, 'cpu_limited' => false, 'ram_percent' => 95.0, 'ram_used' => 1024, 'ram_total' => 2048],
    ];
    $card = ContainerMetricsService::buildCard($rows, $now);

    Assert::same(true, $card['stale'], 'Messwerte gelten als veraltet');
    Assert::same('crit', $card['cpu']['level'], 'CPU-Zustand rot');
    Assert::same('crit', ContainerMetricsService::state($card), 'Zustand der Kachel rot');
});

Runner::test('ContainerMetrics: Verlauf eines Containers', static function (): void {
    // Fester Versatz innerhalb des Abschnitts: die Proben liegen dadurch in
    // drei aufeinanderfolgenden Abschnitten, unabhaengig von der Uhrzeit.
    $bucket = ContainerMetricsService::HISTORY_BUCKET_MINUTES * 60;
    $now = intdiv(time(), $bucket) * $bucket + intdiv($bucket, 2);
    $rows = [
        ['recorded_at' => date('Y-m-d H:i:s', $now - 600), 'cpu_percent' => 20.0, 'cpu_limit' => 2.0, 'cpu_limited' => true, 'ram_percent' => 40.0, 'ram_used' => 1024, 'ram_total' => 2048],
        ['recorded_at' => date('Y-m-d H:i:s', $now - 300), 'cpu_percent' => 40.0, 'cpu_limit' => 2.0, 'cpu_limited' => true, 'ram_percent' => null, 'ram_used' => null, 'ram_total' => null],
        ['recorded_at' => date('Y-m-d H:i:s', $now - 60), 'cpu_percent' => 60.0, 'cpu_limit' => 2.0, 'cpu_limited' => true, 'ram_percent' => 92.0, 'ram_used' => 2048, 'ram_total' => 4096],
    ];
    $history = ContainerMetricsService::buildHistory($rows, $now);

    Assert::same(true, $history['available'], 'Verlauf vorhanden');
    Assert::same(3, $history['samples'], 'Proben im Verlauf');
    Assert::same(ContainerMetricsService::HISTORY_BUCKET_MINUTES, $history['bucket_minutes'], 'Laenge der Abschnitte');
    Assert::same(
        intdiv(ContainerMetricsService::RETENTION_HOURS * 3600, ContainerMetricsService::HISTORY_BUCKET_MINUTES * 60),
        $history['buckets'],
        'Anzahl der Abschnitte'
    );

    Assert::same(60.0, $history['cpu']['peak'], 'Spitze der CPU');
    Assert::same(40.0, $history['cpu']['avg'], 'Mittel der CPU');
    Assert::same(2.0, $history['cpu']['limit'], 'CPU-Bezug des Verlaufs');
    Assert::same(true, $history['cpu']['limited'], 'CPU-Obergrenze des Verlaufs');
    Assert::same([20.0, 40.0, 60.0], containerMetricsPresent($history['cpu']['values']), 'CPU je Abschnitt');

    Assert::same(92.0, $history['ram']['peak'], 'Spitze des Arbeitsspeichers');
    Assert::same(66.0, $history['ram']['avg'], 'Mittel des Arbeitsspeichers');
    Assert::same([40.0, 92.0], containerMetricsPresent($history['ram']['values']), 'Speicher je Abschnitt');
});

Runner::test('ContainerMetrics: Verlauf ohne Proben', static function (): void {
    $history = ContainerMetricsService::buildHistory([], time());

    Assert::same(false, $history['available'], 'Kein Verlauf');
    Assert::same(0, $history['samples'], 'Keine Proben');
    Assert::null($history['cpu']['peak'], 'Keine CPU-Spitze');
    Assert::null($history['cpu']['avg'], 'Kein CPU-Mittel');
    Assert::null($history['ram']['peak'], 'Keine Speicherspitze');
    Assert::null($history['ram']['avg'], 'Kein Speichermittel');
    Assert::same(1.0, $history['cpu']['limit'], 'Ohne Proben gilt ein Kern');
    Assert::same(false, $history['cpu']['limited'], 'Ohne Proben keine Obergrenze');
});

Runner::test('ContainerMetrics: Kacheln und Verlauf aller Container', static function (): void {
    $pdo = containerMetricsPdo();
    $now = time();
    containerMetricsSeed($pdo, [
        ['service' => 'app', 'recorded_at' => date('Y-m-d H:i:s', $now - 120), 'cpu_percent' => 91.0, 'cpu_limit' => 2.0, 'cpu_limited' => 1, 'ram_percent' => 50.0, 'ram_used' => 1024, 'ram_total' => 2048],
        ['service' => 'db', 'recorded_at' => date('Y-m-d H:i:s', $now - 120), 'cpu_percent' => 12.0, 'cpu_limit' => 4.0, 'cpu_limited' => 0, 'ram_percent' => 80.0, 'ram_used' => 4096, 'ram_total' => 8192],
        ['service' => 'db', 'recorded_at' => date('Y-m-d H:i:s', $now - (ContainerMetricsService::RETENTION_HOURS + 1) * 3600), 'cpu_percent' => 99.0],
    ]);

    $dashboard = containerMetricsService($pdo, $now)->dashboard();
    $cards = $dashboard['cards'];

    Assert::same(
        array_keys(ContainerMetricsService::SERVICES),
        array_map(static fn (array $card): string => (string) $card['service'], $cards),
        'Je beobachtetem Container eine Kachel in fester Reihenfolge'
    );
    Assert::same('Anwendung', $cards[0]['title'], 'Bezeichnung der Anwendung');
    Assert::same(91.0, $cards[0]['cpu']['current'], 'CPU der Anwendung');
    Assert::same('crit', $cards[0]['cpu']['level'], 'CPU der Anwendung rot');
    Assert::same('crit', ContainerMetricsService::state($cards[0]), 'Zustand der Kachel rot');

    Assert::same('Datenbank', $cards[1]['title'], 'Bezeichnung der Datenbank');
    Assert::same(1, $cards[1]['samples'], 'Nur Proben der Aufbewahrung');
    Assert::same(80.0, $cards[1]['ram']['current'], 'Arbeitsspeicher der Datenbank');
    Assert::same('warn', ContainerMetricsService::state($cards[1]), 'Zustand der Kachel gelb');

    Assert::same(
        array_keys(ContainerMetricsService::SERVICES),
        array_keys($dashboard['history']),
        'Verlauf je beobachtetem Container'
    );
    Assert::same(true, $dashboard['history']['db']['available'], 'Verlauf der Datenbank vorhanden');
    Assert::same(
        false,
        $dashboard['history']['nextcloud']['available'],
        'Nicht laufende Container haben keinen Verlauf'
    );
    Assert::same(false, $cards[2]['available'], 'Nicht laufende Container haben keine Messwerte');
});

Runner::test('ContainerMetrics: Ueberschrift der Verlaufsgrafiken', static function (): void {
    $now = time();
    $rows = [
        ['recorded_at' => date('Y-m-d H:i:s', $now - 300), 'cpu_percent' => 20.0, 'cpu_limit' => 1.0, 'cpu_limited' => false, 'ram_percent' => 30.0, 'ram_used' => 1024, 'ram_total' => 2048],
    ];
    $history = ContainerMetricsService::buildHistory($rows, $now);
    $charts = new AuthMetricsCharts();

    Assert::contains('CPU-Auslastung im Reverse-Proxy', $charts->cpu($history), 'Ohne Angabe bleibt es beim Reverse-Proxy');
    Assert::contains('Arbeitsspeicher-Auslastung im Reverse-Proxy', $charts->ram($history), 'Ohne Angabe bleibt es beim Reverse-Proxy');

    $cpu = $charts->cpu($history, 'CPU-Auslastung im Container „Anwendung“');
    Assert::contains('<title>CPU-Auslastung im Container „Anwendung“</title>', $cpu, 'Titel der CPU-Grafik');
    Assert::contains('data-chart=', $cpu, 'Die Grafik traegt ihre Werte fuer die Anzeige beim Ueberfahren');

    Assert::contains(
        'Arbeitsspeicher-Auslastung im Container „Anwendung“',
        $charts->ram($history, 'Arbeitsspeicher-Auslastung im Container „Anwendung“'),
        'Ueberschrift der Speicher-Grafik'
    );
});
