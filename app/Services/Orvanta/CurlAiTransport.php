<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Contracts\AiTransportInterface;

/**
 * cURL-Transport zum KI-Endpunkt: keine Weiterleitungen, nur HTTP(S); im
 * Produktionsbetrieb ausschliesslich HTTPS.
 */
final class CurlAiTransport implements AiTransportInterface
{
    public function __construct(private readonly bool $production = false)
    {
    }

    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && ($this->production || $scheme !== 'http')) {
            return ['status' => 0, 'body' => '', 'error' => $this->production ? 'KI-Endpunkt muss über HTTPS erreichbar sein.' : 'Ungültige Adresse des KI-Endpunkts.'];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'cURL ist nicht verfügbar.'];
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'body' => '', 'error' => 'Verbindung konnte nicht vorbereitet werden.'];
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeout)),
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_HTTPHEADER => $headerLines,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle); // curl_close() ist seit PHP 8.5 veraltet

        if ($response === false) {
            return ['status' => 0, 'body' => '', 'error' => $error !== '' ? $error : 'Keine Antwort des KI-Endpunkts.'];
        }

        return ['status' => $status, 'body' => (string) $response, 'error' => null];
    }
}
