<?php

declare(strict_types=1);

namespace App\Services\Helpdesk\Ai;

/**
 * Chat-Anfrage an einen OpenAI-kompatiblen Endpunkt.
 *
 * Denkfähige Modelle verbrennen das Token-Budget häufig vollständig in ihrer
 * Denkspur; `content` bleibt dann leer. Deshalb wird die Anfrage mit
 * `reasoning_effort: "none"` gestellt (Ollama, vLLM und weitere Server deuten
 * das als „nicht denken“). Kennt der Endpunkt den Parameter nicht und antwortet
 * mit 400/422, wird die Anfrage einmal ohne ihn wiederholt – so bleiben auch
 * Anbieter ohne Denkmodus unverändert nutzbar.
 */
final class AiChatClient
{
    public function __construct(private readonly AiTransportInterface $transport) {}

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $payload
     * @return array{status:int,body:string,error:?string,decoded:?array,content:string,reasoning_only:bool}
     */
    public function complete(string $url, array $headers, array $payload, int $timeout): array
    {
        $result = $this->send($url, $headers, $payload + ['reasoning_effort' => 'none'], $timeout);
        if (!in_array($result['status'], [400, 422], true)) {
            return $result;
        }

        return $this->send($url, $headers, $payload, $timeout);
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $payload
     * @return array{status:int,body:string,error:?string,decoded:?array,content:string,reasoning_only:bool}
     */
    private function send(string $url, array $headers, array $payload, int $timeout): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return [
                'status' => 0,
                'body' => '',
                'error' => 'Die Anfrage an die KI konnte nicht erzeugt werden.',
                'decoded' => null,
                'content' => '',
                'reasoning_only' => false,
            ];
        }

        $response = $this->transport->request('POST', $url, $headers, $body, $timeout);
        $raw = (string) ($response['body'] ?? '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $decoded = is_array($decoded) ? $decoded : null;

        return [
            'status' => (int) ($response['status'] ?? 0),
            'body' => $raw,
            'error' => $response['error'] ?? null,
            'decoded' => $decoded,
            'content' => AiChatResponse::content($decoded),
            'reasoning_only' => AiChatResponse::reasoningOnly($decoded),
        ];
    }
}
