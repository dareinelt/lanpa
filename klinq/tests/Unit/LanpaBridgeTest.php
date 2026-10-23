<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Services\Helpdesk\Ai\AiTransportInterface;
use App\Services\Lanpa\LanpaBridge;
use Tests\Support\TestCase;

/**
 * Bruecke zum Intranet: Abruf der Melderdaten ueber die interne Schnittstelle.
 *
 * Der regulaere Weg sind die Angaben aus dem Einstiegsschein; die Nachfrage
 * greift nur, wenn die Sitzung keine Melderdaten mehr enthaelt.
 */
final class LanpaBridgeTest extends TestCase
{
    public function testReporterSendsUsernameAndIpToTheIntranet(): void
    {
        $transport = new RecordingTransport(['status' => 200, 'body' => (string) json_encode([
            'username' => 'mmuster',
            'display_name' => 'Max Muster',
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'email' => 'max.muster@example.local',
            'phone' => '0815 4711',
            'mobile' => '0170 1234567',
            'department' => 'IT',
            'title' => 'Systembetreuer',
            'ip' => '10.12.3.44',
            'host' => 'PC-MUSTER',
        ]), 'error' => null]);
        $bridge = $this->bridge($transport);

        $reporter = $bridge->reporter('mmuster', '10.12.3.44');

        $this->assertNotNull($reporter);
        $this->assertSame('POST', $transport->method);
        $this->assertSame('http://intranet-app/internal/klinq/melder', $transport->url);
        $this->assertSame('username=mmuster&ip=10.12.3.44', (string) $transport->body);
        $this->assertSame('application/x-www-form-urlencoded', $transport->headers['Content-Type'] ?? '');
        $this->assertSame('dienst-token', $transport->headers['X-Intranet-Sso-Token'] ?? '');
        $this->assertSame('PC-MUSTER', $reporter['host']);
        $this->assertSame('Systembetreuer', $reporter['position']);
        $this->assertSame('IT', $reporter['department']);
    }

    public function testReporterStaysSilentWithoutUsernameOrAnswer(): void
    {
        $transport = new RecordingTransport(['status' => 403, 'body' => '', 'error' => null]);
        $bridge = $this->bridge($transport);

        $this->assertNull($bridge->reporter('  ', '10.12.3.44'));
        $this->assertNull($bridge->reporter('mmuster', '10.12.3.44'));
        $this->assertSame(1, $transport->calls, 'Ohne Anmeldename darf keine Anfrage rausgehen');
    }

    public function testReporterIsInactiveWithoutIntranetConnection(): void
    {
        $transport = new RecordingTransport(['status' => 200, 'body' => '{}', 'error' => null]);
        $bridge = new LanpaBridge(Config::fromArray(['lanpa' => ['enabled' => false]]), $transport);

        $this->assertNull($bridge->reporter('mmuster', '10.12.3.44'));
        $this->assertSame(0, $transport->calls);
        $this->assertSame('', $bridge->designCssUrl());
    }

    public function testPhonebookSearchQueriesTheIntranet(): void
    {
        $transport = new RecordingTransport(['status' => 200, 'body' => (string) json_encode(['items' => [
            [
                'id' => 7,
                'external_id' => '4d1f2c3b5e2a3d4c8f9a0b1c2d3e4f5a',
                'username' => 'mmustermann',
                'display_name' => 'Mustermann, Max',
                'first_name' => 'Max',
                'last_name' => 'Mustermann',
                'title' => 'Systembetreuer',
                'personnel_number' => '4711',
                'phone' => '0815 4711',
                'mobile' => '',
                'email' => 'max@example.local',
                'department' => 'IT',
                'source' => 'Niederlassung Nord',
            ],
            ['id' => 0, 'display_name' => ''],
        ]]), 'error' => null]);
        $bridge = $this->bridge($transport);

        $items = $bridge->phonebook('Muster', 10);

        $this->assertNotNull($items);
        $this->assertCount(1, $items, 'Einträge ohne ID oder Namen werden verworfen.');
        $this->assertSame('GET', $transport->method);
        $this->assertSame('http://intranet-app/internal/klinq/telefonbuch?q=Muster&limit=10', $transport->url);
        $this->assertSame('dienst-token', $transport->headers['X-Intranet-Sso-Token'] ?? '');
        $this->assertSame('mmustermann', $items[0]['username']);
        $this->assertSame('4d1f2c3b5e2a3d4c8f9a0b1c2d3e4f5a', $items[0]['external_id']);
        $this->assertSame('Systembetreuer', $items[0]['title']);
        $this->assertSame('4711', $items[0]['personnel_number']);
        $this->assertSame('Niederlassung Nord', $items[0]['source']);
    }

    public function testPhonebookEntryIsRequestedById(): void
    {
        $transport = new RecordingTransport(['status' => 200, 'body' => (string) json_encode([
            'id' => 7,
            'display_name' => 'Mustermann, Max',
            'username' => 'mmustermann',
        ]), 'error' => null]);
        $bridge = $this->bridge($transport);

        $entry = $bridge->phonebookEntry(7);

        $this->assertNotNull($entry);
        $this->assertSame('http://intranet-app/internal/klinq/telefonbuch?id=7', $transport->url);
        $this->assertSame('mmustermann', $entry['username']);
        $this->assertNull($bridge->phonebookEntry(0));
        $this->assertSame(1, $transport->calls, 'Ungültige IDs lösen keine Anfrage aus.');
    }

    public function testPhonebookStaysSilentWithoutTermOrIntranetConnection(): void
    {
        $transport = new RecordingTransport(['status' => 200, 'body' => '{"items":[]}', 'error' => null]);
        $this->assertNull($this->bridge($transport)->phonebook('   '));
        $this->assertSame(0, $transport->calls, 'Ohne Suchbegriff darf keine Anfrage rausgehen');

        $inactive = new LanpaBridge(Config::fromArray(['lanpa' => ['enabled' => false]]), $transport);
        $this->assertNull($inactive->phonebook('Muster'));
        $this->assertNull($inactive->phonebookEntry(7));
        $this->assertSame(0, $transport->calls);
    }

    public function testSignatureIsRequestedFromTheIntranet(): void
    {
        $transport = new RecordingTransport(['status' => 200, 'body' => (string) json_encode([
            'available' => true,
            'name' => 'Max Muster',
            'html' => '<div class="ov-signature-block">Max Muster</div>',
        ]), 'error' => null]);
        $bridge = $this->bridge($transport);

        $signature = $bridge->signature('mmuster');

        $this->assertNotNull($signature);
        $this->assertSame('GET', $transport->method);
        $this->assertSame('http://intranet-app/internal/klinq/signatur?username=mmuster', $transport->url);
        $this->assertSame('dienst-token', $transport->headers['X-Intranet-Sso-Token'] ?? '');
        $this->assertSame('Max Muster', $signature['name']);
        $this->assertSame('<div class="ov-signature-block">Max Muster</div>', $signature['html']);
    }

    public function testSignatureIsRequestedOnlyOncePerUsername(): void
    {
        $transport = new RecordingTransport(['status' => 200, 'body' => (string) json_encode([
            'available' => true,
            'name' => 'Max Muster',
            'html' => '<div class="ov-signature-block">Max Muster</div>',
        ]), 'error' => null]);
        $bridge = $this->bridge($transport);

        $bridge->signature('mmuster');
        $bridge->signature('mmuster');

        $this->assertSame(1, $transport->calls, 'Die Signatur wird je Anfrage einmal geladen.');
    }

    public function testSignatureStaysEmptyWithoutAnswer(): void
    {
        $unavailable = new RecordingTransport(['status' => 200, 'body' => '{"available":false,"html":""}', 'error' => null]);
        $this->assertNull($this->bridge($unavailable)->signature('mmuster'));

        $broken = new RecordingTransport(['status' => 500, 'body' => '', 'error' => null]);
        $this->assertNull($this->bridge($broken)->signature('mmuster'));

        $empty = new RecordingTransport(['status' => 200, 'body' => '{"available":true,"name":"Max","html":"   "}', 'error' => null]);
        $this->assertNull($this->bridge($empty)->signature('mmuster'));
    }

    public function testSignatureIsNotRequestedWithoutUsernameOrIntranetConnection(): void
    {
        $transport = new RecordingTransport(['status' => 200, 'body' => '{}', 'error' => null]);
        $this->assertNull($this->bridge($transport)->signature('   '));

        $inactive = new LanpaBridge(Config::fromArray(['lanpa' => ['enabled' => false]]), $transport);
        $this->assertNull($inactive->signature('mmuster'));
        $this->assertSame(0, $transport->calls);
    }

    private function bridge(RecordingTransport $transport): LanpaBridge
    {
        return new LanpaBridge(Config::fromArray(['lanpa' => [
            'enabled' => true,
            'internal_url' => 'http://intranet-app',
            'service_token' => 'dienst-token',
            'design_css' => '',
        ]]), $transport);
    }
}

/** Merkt sich die Anfrage und antwortet mit einer festen Antwort. */
final class RecordingTransport implements AiTransportInterface
{
    public int $calls = 0;

    public string $method = '';

    public string $url = '';

    /** @var array<string,string> */
    public array $headers = [];

    public ?string $body = null;

    /** @param array{status:int,body:string,error:?string} $response */
    public function __construct(private readonly array $response)
    {
    }

    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $this->calls++;
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;

        return $this->response;
    }
}
