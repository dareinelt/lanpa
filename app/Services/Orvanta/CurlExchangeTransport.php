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
        $hasAccount = $options['username'] !== '';
        $offered = [];
        curl_setopt_array($handle, [
            // Angebotene Verfahren (WWW-Authenticate) der letzten Antwort fuer die Fehlermeldung.
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$offered): int {
                if (preg_match('#^HTTP/\S+\s+\d{3}#i', $line) === 1) {
                    $offered = [];
                } elseif (preg_match('/^WWW-Authenticate:\s*([A-Za-z0-9_-]+)/i', $line, $match) === 1 && !in_array($match[1], $offered, true)) {
                    $offered[] = $match[1];
                }

                return strlen($line);
            },
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
                // SPNEGO/Kerberos ueber GSSAPI ignoriert Benutzer und Kennwort und
                // braucht ein Ticket im Prozess, das der app-Container nicht hat.
                // Mit Dienstkonto wird Negotiate daher per NTLM ausgehandelt.
                'negotiate' => $hasAccount ? CURLAUTH_NTLM : CURLAUTH_NEGOTIATE,
                default => CURLAUTH_ANYSAFE,
            },
        ]);
        if ($hasAccount || $options['auth'] === 'negotiate') {
            curl_setopt($handle, CURLOPT_USERPWD, $hasAccount ? $options['username'] . ':' . $options['password'] : ':');
        }

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle) !== 0 ? curl_error($handle) : null;
        unset($handle); // curl_close() ist seit PHP 8.5 veraltet

        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error, 'auth_offered' => $offered];
    }
}
