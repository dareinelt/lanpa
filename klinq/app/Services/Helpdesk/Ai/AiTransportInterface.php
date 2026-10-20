<?php

declare(strict_types=1);

namespace App\Services\Helpdesk\Ai;

/**
 * HTTP-Transport für den OpenAI-kompatiblen KI-Endpunkt.
 *
 * Der Transport kennt nur Methode, Adresse, Kopfzeilen und Rumpf; die fachliche Auswertung
 * (Statuscodes, JSON, Fehlermeldungen) übernimmt der aufrufende Dienst. Dadurch lässt sich der
 * Endpunkt in Tests durch eine Attrappe ersetzen.
 */
interface AiTransportInterface
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,error:?string} status 0 = Verbindung nicht möglich
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array;
}
