<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Netzwerkzugriffe der Office-Gesundheitspruefung (austauschbar fuer Tests).
 */
interface OfficeProbeInterface
{
    /**
     * @param array<string,string> $headers
     *
     * @return array{status:int,body:string,error:?string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 4): array;

    /**
     * Oeffnet eine TCP-Verbindung, sendet optional Daten und liefert die
     * ersten empfangenen Bytes (bzw. '' ohne Antwort) oder null bei Fehlern.
     */
    public function tcp(string $host, int $port, string $payload = '', int $timeout = 3, int $readBytes = 64): ?string;
}
