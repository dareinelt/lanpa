<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Contracts\ExchangeTransportInterface;

/**
 * cURL-Transport fuer EWS. Unterstuetzt Negotiate (Kerberos/SPNEGO), NTLM und
 * Basic fuer das Dienstkonto; die Identitaet des angemeldeten Benutzers wird
 * ueber den EWS-Impersonation-Header im SOAP-Umschlag mitgegeben.
 */
final class CurlExchangeTransport implements ExchangeTransportInterface
{
    public function post(string $url, string $xml, array $options): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'Die PHP-Erweiterung curl ist nicht verfügbar.'];
        }

        $headers = [
            'Content-Type: text/xml; charset=utf-8',
            'Accept: text/xml',
            'User-Agent: Orvanta/1.0 (Intranet)',
            'Content-Length: ' . strlen($xml),
        ];
        foreach ($options['headers'] ?? [] as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'body' => '', 'error' => 'cURL konnte nicht initialisiert werden.'];
        }

        $timeout = max(3, (int) $options['timeout']);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => $options['verify_tls'],
            CURLOPT_SSL_VERIFYHOST => $options['verify_tls'] ? 2 : 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HTTPAUTH => match ($options['auth']) {
                'basic' => CURLAUTH_BASIC,
                'ntlm' => CURLAUTH_NTLM,
                'negotiate' => CURLAUTH_NEGOTIATE | CURLAUTH_NTLM,
                default => CURLAUTH_ANYSAFE,
            },
        ]);
        if ($options['username'] !== '' || $options['auth'] === 'negotiate') {
            // Negotiate ohne Konto nutzt die Kerberos-Credentials des Prozesses (Keytab).
            curl_setopt($handle, CURLOPT_USERPWD, $options['username'] !== '' ? $options['username'] . ':' . $options['password'] : ':');
        }

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle) !== 0 ? curl_error($handle) : null;
        unset($handle); // curl_close() ist seit PHP 8.5 veraltet

        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
    }
}
