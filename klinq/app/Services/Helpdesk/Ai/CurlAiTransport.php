<?php

declare(strict_types=1);

namespace App\Services\Helpdesk\Ai;

/**
 * cURL-Transport zum KI-Endpunkt.
 *
 * Es werden ausschließlich http/https-Adressen angesprochen und keine Weiterleitungen verfolgt, damit
 * der hinterlegte API-Schlüssel den konfigurierten Endpunkt nicht verlässt.
 */
final class CurlAiTransport implements AiTransportInterface
{
    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'Die PHP-Erweiterung cURL ist nicht verfügbar.'];
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['status' => 0, 'body' => '', 'error' => 'Es sind nur http- und https-Adressen zulässig.'];
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'body' => '', 'error' => 'Die Verbindung konnte nicht aufgebaut werden.'];
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $timeout = max(1, $timeout);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headerLines,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        unset($handle); // curl_close() ist seit PHP 8.5 nicht mehr nötig

        if ($response === false) {
            return ['status' => 0, 'body' => '', 'error' => $error !== '' ? $error : 'Die Verbindung ist fehlgeschlagen.'];
        }

        return ['status' => $status, 'body' => (string) $response, 'error' => null];
    }
}
