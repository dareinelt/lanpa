<?php

declare(strict_types=1);

use App\Contracts\AiTransportInterface;
use App\Repositories\OrvantaRepository;
use App\Services\Orvanta\OrvantaAiCharts;
use App\Services\Orvanta\OrvantaAiService;
use App\Services\Orvanta\OrvantaException;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Zeichnet KI-Anfragen auf und liefert vorbereitete Antworten.
 */
final class RecordingAiTransport implements AiTransportInterface
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int}> */
    public array $requests = [];

    /** @var list<array{status:int,body:string,error:?string}> */
    public array $responses = [];

    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];

        return array_shift($this->responses) ?? ['status' => 200, 'body' => '{"data":[]}', 'error' => null];
    }

    public static function completion(string $text, int $in = 120, int $out = 40): array
    {
        return ['status' => 200, 'error' => null, 'body' => json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
            'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out],
        ], JSON_THROW_ON_ERROR)];
    }
}

function orvantaAiPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE orvanta_ai_usage (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_uid VARCHAR(100) NOT NULL,
        kind VARCHAR(20) NOT NULL,
        model VARCHAR(100) NOT NULL DEFAULT \'\',
        input_tokens INTEGER NOT NULL DEFAULT 0,
        output_tokens INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL
    )');

    return $pdo;
}

/**
 * @return array{ai:OrvantaAiService,transport:RecordingAiTransport,repo:OrvantaRepository,cache:string}
 */
function orvantaAi(array $settings = OFFICE_AI_ACTIVE, bool $withCache = true): array
{
    $transport = new RecordingAiTransport();
    $repo = new OrvantaRepository(orvantaAiPdo());
    $cache = officeTempDir() . '/orvanta-ai-' . bin2hex(random_bytes(4)) . '.json';
    $ai = new OrvantaAiService(officeAi(new FakeOfficeProbe(), $settings)['ai'], $transport, $repo, $withCache ? $cache : null, 12);

    return ['ai' => $ai, 'transport' => $transport, 'repo' => $repo, 'cache' => $cache];
}

Runner::test('Orvanta-KI: ohne globale KI-Einstellungen nicht verfuegbar, keine Anfrage', static function (): void {
    $env = orvantaAi(['office_ai_url' => ''] + OFFICE_AI_ACTIVE);
    Assert::false($env['ai']->isAvailable());
    Assert::same(0, count($env['transport']->requests), 'Ohne Konfiguration darf kein Endpunkt angefragt werden.');

    $disabled = orvantaAi(['office_ai_enabled' => '0'] + OFFICE_AI_ACTIVE);
    Assert::false($disabled['ai']->isAvailable());
});

Runner::test('Orvanta-KI: Erreichbarkeit per GET /models, Ergebnis gecacht', static function (): void {
    $env = orvantaAi();
    $env['transport']->responses[] = ['status' => 200, 'body' => '{"data":[{"id":"llama3.1:8b"}]}', 'error' => null];
    Assert::true($env['ai']->isAvailable());
    Assert::same('GET', $env['transport']->requests[0]['method']);
    Assert::same('http://ki-server:11434/v1/models', $env['transport']->requests[0]['url']);
    Assert::same(OrvantaAiService::AVAILABILITY_TIMEOUT, $env['transport']->requests[0]['timeout']);
    Assert::true(is_file($env['cache']), 'Erreichbarkeit muss zwischengespeichert werden.');

    // Zweite Instanz mit demselben Cache fragt nicht erneut an.
    $second = new OrvantaAiService(officeAi(new FakeOfficeProbe(), OFFICE_AI_ACTIVE)['ai'], $env['transport'], $env['repo'], $env['cache']);
    Assert::true($second->isAvailable());
    Assert::same(1, count($env['transport']->requests));

    // Anderes Modell -> anderer Fingerabdruck -> neue Pruefung.
    $other = new OrvantaAiService(officeAi(new FakeOfficeProbe(), ['office_ai_model' => 'qwen2.5:7b'] + OFFICE_AI_ACTIVE)['ai'], $env['transport'], $env['repo'], $env['cache']);
    $env['transport']->responses[] = ['status' => 0, 'body' => '', 'error' => 'timeout'];
    Assert::false($other->isAvailable());
    Assert::same(2, count($env['transport']->requests));

    $env['ai']->resetAvailability();
    Assert::false(is_file($env['cache']));
});

Runner::test('Orvanta-KI: Verbesserung ruft chat/completions mit Kontext auf und liefert Text und Token', static function (): void {
    $env = orvantaAi();
    $env['transport']->responses[] = RecordingAiTransport::completion("  Sehr geehrte Damen und Herren,\n\nanbei der Bericht.  ", 200, 50);
    $result = $env['ai']->improve('hi, hier der bericht', 'höflicher', 'mail_compose', null, ['subject' => 'Bericht Q3', 'recipients' => 2]);

    Assert::same("Sehr geehrte Damen und Herren,\n\nanbei der Bericht.", $result['text']);
    Assert::same(['input_tokens' => 200, 'output_tokens' => 50], $result['usage']);
    Assert::same('llama3.1:8b', $result['model']);

    $request = $env['transport']->requests[0];
    Assert::same('POST', $request['method']);
    Assert::same('http://ki-server:11434/v1/chat/completions', $request['url']);
    Assert::same(12, $request['timeout']);
    $payload = json_decode((string) $request['body'], true);
    Assert::same('llama3.1:8b', $payload['model']);
    Assert::same('system', $payload['messages'][0]['role']);
    Assert::contains('Bericht Q3', json_encode($payload['messages'], JSON_UNESCAPED_UNICODE), 'Betreff gehoert zum Kontext.');
    Assert::contains('hi, hier der bericht', json_encode($payload['messages'], JSON_UNESCAPED_UNICODE));
    Assert::contains('höflicher', json_encode($payload['messages'], JSON_UNESCAPED_UNICODE));
    Assert::true(($payload['max_tokens'] ?? 0) <= OrvantaAiService::MAX_OUTPUT_TOKENS);
});

Runner::test('Orvanta-KI: Antwort wird von Codezaeunen, Anfuehrungszeilen und Prompt-Echo befreit', static function (): void {
    $cases = [
        "```\nHallo Welt\n```" => 'Hallo Welt',
        "\"\"\nHallo, hier der Bericht.\n\"\"" => 'Hallo, hier der Bericht.',
        "\"\"\"\nZeile 1\n\nZeile 2\n\"\"\"" => "Zeile 1\n\nZeile 2",
        "Überarbeiteter Text: „Guten Tag.“" => 'Guten Tag.',
        "Guten Tag.\n\"\"\"\n\nAnweisung: Bitte höflicher formulieren." => 'Guten Tag.',
        "Guten Tag.\nAnweisung: kürzer" => 'Guten Tag.',
        'Er sagte "hallo" und ging.' => 'Er sagte "hallo" und ging.',
    ];
    foreach ($cases as $raw => $expected) {
        $env = orvantaAi();
        $env['transport']->responses[] = RecordingAiTransport::completion($raw);
        Assert::same($expected, $env['ai']->improve('x', 'y', 'event')['text'], 'Rohantwort: ' . json_encode($raw, JSON_UNESCAPED_UNICODE));
    }
});

Runner::test('Orvanta-KI: Verfeinern uebergibt die bisherige Fassung als Assistenten-Antwort', static function (): void {
    $env = orvantaAi();
    $env['transport']->responses[] = RecordingAiTransport::completion('Noch kürzer.');
    $env['ai']->improve('Original', 'kürzer', 'mail_reply', 'Erste Fassung');
    $payload = json_decode((string) $env['transport']->requests[0]['body'], true);
    $roles = array_column($payload['messages'], 'role');
    Assert::true(in_array('assistant', $roles, true), 'Die bisherige Fassung muss als assistant-Nachricht mitgehen.');
    Assert::contains('Erste Fassung', json_encode($payload['messages'], JSON_UNESCAPED_UNICODE));
});

Runner::test('Orvanta-KI: API-Schluessel wird als Bearer gesendet, Bearer fehlt ohne Schluessel', static function (): void {
    $withKey = orvantaAi(OFFICE_AI_ACTIVE + ['office_ai_api_key' => 'sk-geheim']);
    $withKey['transport']->responses[] = RecordingAiTransport::completion('x');
    $withKey['ai']->improve('a', 'b', 'event');
    Assert::same('Bearer sk-geheim', $withKey['transport']->requests[0]['headers']['Authorization'] ?? null);

    $noKey = orvantaAi();
    $noKey['transport']->responses[] = RecordingAiTransport::completion('x');
    $noKey['ai']->improve('a', 'b', 'event');
    Assert::false(array_key_exists('Authorization', $noKey['transport']->requests[0]['headers']));
});

Runner::test('Orvanta-KI: Validierung (422) - leerer Text, leere Anweisung, Laengen, Einsatzort', static function (): void {
    $env = orvantaAi();
    $cases = [
        ['', 'kürzer', 'mail_compose'],
        ['Text', '   ', 'mail_compose'],
        [str_repeat('a', OrvantaAiService::MAX_TEXT + 1), 'kürzer', 'mail_compose'],
        ['Text', str_repeat('b', OrvantaAiService::MAX_PROMPT + 1), 'mail_compose'],
        ['Text', 'kürzer', 'chat'],
    ];
    foreach ($cases as $i => [$text, $prompt, $mode]) {
        try {
            $env['ai']->improve($text, $prompt, $mode);
            Assert::true(false, 'Fall ' . $i . ' muss abgewiesen werden.');
        } catch (OrvantaException $exception) {
            Assert::same(422, $exception->status(), 'Fall ' . $i);
        }
    }
    Assert::same(0, count($env['transport']->requests), 'Ungueltige Eingaben erreichen den Endpunkt nicht.');
});

Runner::test('Orvanta-KI: ohne aktive KI 503, Transportfehler/HTTP 500/ungueltiges JSON ergeben 502', static function (): void {
    $off = orvantaAi(['office_ai_url' => ''] + OFFICE_AI_ACTIVE);
    try {
        $off['ai']->improve('Text', 'kürzer', 'mail_compose');
        Assert::true(false);
    } catch (OrvantaException $exception) {
        Assert::same(503, $exception->status());
    }

    $failures = [
        ['status' => 0, 'body' => '', 'error' => 'Operation timed out'],
        ['status' => 500, 'body' => 'kaputt', 'error' => null],
        ['status' => 401, 'body' => '{"error":"unauthorized"}', 'error' => null],
        ['status' => 200, 'body' => 'kein json', 'error' => null],
        ['status' => 200, 'body' => '{"choices":[]}', 'error' => null],
    ];
    foreach ($failures as $i => $response) {
        $env = orvantaAi();
        $env['transport']->responses[] = $response;
        try {
            $env['ai']->improve('Text', 'kürzer', 'mail_compose');
            Assert::true(false, 'Fall ' . $i . ' muss fehlschlagen.');
        } catch (OrvantaException $exception) {
            Assert::same(502, $exception->status(), 'Fall ' . $i);
            Assert::false(str_contains($exception->getMessage(), 'kaputt'), 'Rohantworten gehoeren nicht in Fehlermeldungen.');
        }
    }
});

Runner::test('Orvanta-KI: stripMarkers entfernt Rahmen, Attribute und Kommentare, behaelt Text und Formatierung', static function (): void {
    $html = '<p>Hallo <span class="ov-ai-block" data-ov-ai-id="ai1" title="Von der KI erzeugter Text">schöne <b>neue</b> Welt</span>!</p>'
        . '<!-- ov-ai: intern --><p class="ov-ai-block ov-ai-block--fresh" data-ov-ai-id="ai2">Zweiter Absatz</p>'
        . '<p><span class="wichtig ov-ai-block">bleibt span</span></p>';
    $clean = OrvantaAiService::stripMarkers($html);

    Assert::false(str_contains($clean, 'ov-ai'), 'Keine Marker-Klassen mehr: ' . $clean);
    Assert::false(str_contains($clean, 'data-ov-ai'), 'Keine Marker-Attribute mehr.');
    Assert::false(str_contains($clean, 'title='), 'Tooltips der KI-Bloecke verschwinden.');
    Assert::false(str_contains($clean, '<!--'));
    Assert::contains('Hallo schöne <b>neue</b> Welt!', $clean);
    Assert::contains('<p>Zweiter Absatz</p>', $clean);
    Assert::contains('<span class="wichtig">bleibt span</span>', $clean, 'Fremde Klassen bleiben erhalten.');

    Assert::same('', OrvantaAiService::stripMarkers(''));
    Assert::same('<p>Unverändert</p>', trim(OrvantaAiService::stripMarkers('<p>Unverändert</p>')));
});

Runner::test('Orvanta-KI: Nutzung wird nur als Zaehler gespeichert und anonymisiert ausgewertet', static function (): void {
    $env = orvantaAi();
    $repo = $env['repo'];
    $env['ai']->recordUsage('S-1-5-21-ANNA', 'mail_compose', 100, 20, 'llama3.1:8b');
    $env['ai']->recordUsage('S-1-5-21-ANNA', 'mail_reply', 50, 10, 'llama3.1:8b');
    $env['ai']->recordUsage('S-1-5-21-BERT', 'event', 30, 5, 'llama3.1:8b');
    $env['ai']->recordUsage('S-1-5-21-CARL', 'reminder', 10, 1, 'llama3.1:8b');

    $from = (new DateTimeImmutable('-1 day'))->format('Y-m-d 00:00:00');
    $to = (new DateTimeImmutable('+1 day'))->format('Y-m-d 23:59:59');
    $users = $repo->aiUsagePerUser($from, $to);
    Assert::same(3, count($users));
    Assert::same('Benutzer 1', $users[0]['label']);
    Assert::same(2, (int) $users[0]['requests']);
    Assert::same(150, (int) $users[0]['input_tokens']);
    foreach ($users as $row) {
        Assert::false(array_key_exists('user_uid', $row), 'Die Auswertung darf keine Kennungen enthalten.');
        Assert::false(str_contains(json_encode($row), 'S-1-5'), 'Keine SIDs im Bericht.');
    }

    $totals = $repo->aiTokenTotals($from, $to);
    Assert::same(4, (int) $totals['requests']);
    Assert::same(3, (int) $totals['users']);
    Assert::same(190, (int) $totals['input_tokens']);
    Assert::same(36, (int) $totals['output_tokens']);

    $days = $repo->aiUsagePerDay($from, $to);
    Assert::same(1, count($days));
    Assert::same(4, (int) $days[0]['requests']);

    // Ungueltige Art wird abgewiesen, ohne eine Ausnahme nach aussen zu geben.
    $env['ai']->recordUsage('S-1-5-21-ANNA', 'chat', 1, 1, 'x');
    Assert::same(4, (int) $repo->aiTokenTotals($from, $to)['requests']);
});

Runner::test('Orvanta-KI: Adminbericht erzeugt SVG ohne style-Attribute und ohne Kennungen', static function (): void {
    $env = orvantaAi();
    $env['ai']->recordUsage('S-1-5-21-ANNA', 'mail_compose', 100, 20, 'm');
    $env['ai']->recordUsage('S-1-5-21-BERT', 'mail_compose', 10, 2, 'm');
    $charts = new OrvantaAiCharts($env['repo']);

    Assert::same(30, OrvantaAiCharts::period(null));
    Assert::same(7, OrvantaAiCharts::period('7'));
    Assert::same(30, OrvantaAiCharts::period('13'));
    Assert::same(30, OrvantaAiCharts::period('abc'));

    $report = $charts->report(7);
    Assert::same(7, count($report['days']));
    Assert::same(2, (int) $report['totals']['requests']);
    foreach (['users', 'requests', 'tokens'] as $key) {
        $svg = $report['svg'][$key];
        Assert::contains('<svg', $svg);
        Assert::false((bool) preg_match('/\sstyle=/', $svg), 'SVG ohne style-Attribute (CSP): ' . $key);
        Assert::false(str_contains($svg, 'S-1-5'), 'SVG ohne Kennungen: ' . $key);
        Assert::false(str_contains($svg, '<script'), 'SVG ohne Skripte.');
    }
    Assert::contains('Benutzer 1', $report['svg']['users']);

    $empty = (new OrvantaAiCharts(new OrvantaRepository(orvantaAiPdo())))->report(30);
    Assert::contains('Keine Anfragen', $empty['svg']['users']);
    Assert::same(30, count($empty['days']));
});
