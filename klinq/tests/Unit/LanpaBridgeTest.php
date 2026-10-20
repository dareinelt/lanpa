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
