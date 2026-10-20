<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Helpdesk\Ai\AiChatClient;
use App\Services\Helpdesk\Ai\AiChatResponse;
use App\Services\Helpdesk\Ai\AiTransportInterface;
use Tests\Support\TestCase;

/**
 * Auswertung OpenAI-kompatibler Antworten: denkfähige Modelle legen ihre
 * Überlegungen in `reasoning`/`reasoning_content`/`thinking` ab und lassen
 * `content` leer, wenn das Token-Budget für die Antwort nicht mehr reicht.
 */
final class HelpdeskAiChatTest extends TestCase
{
    public function testContentWinsOverReasoning(): void
    {
        $decoded = [
            'choices' => [[
                'message' => ['content' => '  Hallo Welt  ', 'reasoning' => 'erst überlegen'],
            ]],
        ];

        $this->assertSame('Hallo Welt', AiChatResponse::content($decoded));
        $this->assertSame('erst überlegen', AiChatResponse::reasoning($decoded));
        $this->assertFalse(AiChatResponse::reasoningOnly($decoded));
    }

    public function testReasoningOnlyIsDetected(): void
    {
        foreach (['reasoning', 'reasoning_content', 'thinking'] as $key) {
            $decoded = ['choices' => [['message' => ['content' => '', $key => 'Denkspur']]]];

            $this->assertSame('', AiChatResponse::content($decoded), $key);
            $this->assertTrue(AiChatResponse::reasoningOnly($decoded), $key);
        }
    }

    public function testLegacyCompletionTextIsUsed(): void
    {
        $decoded = ['choices' => [['text' => ' Alter Stil ']]];

        $this->assertSame('Alter Stil', AiChatResponse::content($decoded));
        $this->assertFalse(AiChatResponse::reasoningOnly($decoded));
    }

    public function testEmptyOrBrokenAnswersAreHandled(): void
    {
        $this->assertSame('', AiChatResponse::content(null));
        $this->assertSame('', AiChatResponse::content([]));
        $this->assertFalse(AiChatResponse::reasoningOnly([]));
    }

    public function testRequestDisablesReasoning(): void
    {
        $transport = new class implements AiTransportInterface {
            /** @var list<array<string,mixed>> */
            public array $payloads = [];

            public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
            {
                $this->payloads[] = (array) json_decode((string) $body, true);

                return ['status' => 200, 'body' => '{"choices":[{"message":{"content":"ok"}}]}', 'error' => null];
            }
        };

        $result = (new AiChatClient($transport))->complete('http://ki/v1/chat/completions', [], ['model' => 'm'], 10);

        $this->assertSame('ok', $result['content']);
        $this->assertCount(1, $transport->payloads);
        $this->assertSame('none', $transport->payloads[0]['reasoning_effort'] ?? null);
        $this->assertSame('m', $transport->payloads[0]['model'] ?? null);
    }

    public function testUnknownParameterIsRetriedWithoutIt(): void
    {
        $transport = new class implements AiTransportInterface {
            /** @var list<array<string,mixed>> */
            public array $payloads = [];

            public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
            {
                $payload = (array) json_decode((string) $body, true);
                $this->payloads[] = $payload;
                if (array_key_exists('reasoning_effort', $payload)) {
                    return ['status' => 400, 'body' => '{"error":"unknown parameter"}', 'error' => null];
                }

                return ['status' => 200, 'body' => '{"choices":[{"message":{"content":"zweiter Versuch"}}]}', 'error' => null];
            }
        };

        $result = (new AiChatClient($transport))->complete('http://ki/v1/chat/completions', [], ['model' => 'm'], 10);

        $this->assertCount(2, $transport->payloads);
        $this->assertSame('zweiter Versuch', $result['content']);
        $this->assertFalse(array_key_exists('reasoning_effort', $transport->payloads[1]));
    }

    public function testReasoningOnlyResponseIsReported(): void
    {
        $transport = new class implements AiTransportInterface {
            public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
            {
                return [
                    'status' => 200,
                    'body' => '{"choices":[{"message":{"content":"","reasoning":"nur gedacht"}}]}',
                    'error' => null,
                ];
            }
        };

        $result = (new AiChatClient($transport))->complete('http://ki/v1/chat/completions', [], ['model' => 'm'], 10);

        $this->assertSame('', $result['content']);
        $this->assertTrue($result['reasoning_only']);
    }
}
