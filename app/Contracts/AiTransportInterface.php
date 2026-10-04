<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * HTTP-Transport zum OpenAI-kompatiblen KI-Endpunkt (Orvanta-Textunterstuetzung).
 * Austauschbar fuer Tests (aufgezeichnete Antworten) und den Betrieb (cURL).
 */
interface AiTransportInterface
{
    /**
     * @param array<string,string> $headers
     *
     * @return array{status:int,body:string,error:?string}
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array;
}
