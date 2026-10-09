<?php

declare(strict_types=1);

namespace App\Services\MailProxy;

use App\Contracts\MailProxyTransportInterface;
use App\Security\SecretBox;
use App\Services\Orvanta\OrvantaException;

/**
 * HTTP-Transport zum Container mail-proxy (nur internes Docker-Netz).
 *
 * Jede Anfrage ist mit HMAC-SHA256 signiert (Zeitstempel, Einmalwert,
 * Operation, SHA-256 des Rumpfs). Der Schluessel wird aus dem SecretBox-
 * Schluessel abgeleitet (SecretBox::deriveKey('mail-proxy')); der Proxy
 * liest dieselbe Schluesseldatei schreibgeschuetzt aus dem Speicher-Volume.
 * Es gibt dadurch kein zusaetzliches Geheimnis in .env oder im Image.
 *
 * Die Adresse kommt ausschliesslich aus der Umgebung (MAIL_PROXY_URL), nie
 * aus dem Frontend. Weiterleitungen werden nicht verfolgt.
 */
final class HttpMailProxyTransport implements MailProxyTransportInterface
{
    public const KEY_PURPOSE = 'mail-proxy';
    private const MAX_RESPONSE = 64 * 1024 * 1024;

    /** @var array<string,array{0:string,1:int}> Fehlercode des Proxys => [Meldung, HTTP-Status] */
    private const ERRORS = [
        'unreachable' => ['Der Mailserver ist nicht erreichbar. Bitte später erneut versuchen oder die Administration informieren.', 502],
        'tls' => ['Die TLS-Verbindung zum Mailserver ist fehlgeschlagen (Zertifikat oder TLS-Modus prüfen).', 502],
        'auth_failed' => ['Die Anmeldung am Postfach wurde abgelehnt. Möglicherweise wurde das Kennwort geändert.', 409],
        'timeout' => ['Der Mailserver hat nicht rechtzeitig geantwortet.', 504],
        'not_found' => ['Das Element wurde nicht gefunden.', 404],
        'invalid' => ['Die Anfrage an den Mailserver ist ungültig.', 422],
        'busy' => ['Der Mail-Proxy ist ausgelastet. Bitte gleich erneut versuchen.', 503],
        'forbidden_target' => ['Der konfigurierte Mailserver ist als Ziel nicht zulässig.', 502],
        'unauthorized' => ['Der Mail-Proxy hat die Anfrage abgelehnt (Schlüssel prüfen).', 502],
        'smtp_rejected' => ['Der Mailserver hat die Nachricht abgelehnt.', 502],
        'too_large' => ['Die Nachricht ist zu groß.', 413],
    ];

    /**
     * Fehlercode des Proxys => maschinenlesbarer Grund fuer den Nachrichtenfluss.
     * Nur Fehler, die am Mailserver einer Identitaetsquelle liegen, sind
     * Quellenstoerungen; Auslastung, Schluessel- und Inhaltsfehler des Proxys
     * sind es bewusst nicht.
     *
     * @var array<string,string>
     */
    private const REASONS = [
        'auth_failed' => OrvantaException::MAIL_AUTH,
        'unreachable' => OrvantaException::MAIL_SOURCE,
        'tls' => OrvantaException::MAIL_SOURCE,
        'timeout' => OrvantaException::MAIL_SOURCE,
        'forbidden_target' => OrvantaException::MAIL_SOURCE,
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly SecretBox $secrets,
        private readonly int $connectTimeout = 3
    ) {
    }

    public static function isValidBaseUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'], $parts['pass'])
            && ($parts['query'] ?? '') === '';
    }

    public function request(string $operation, array $payload): array
    {
        if (preg_match('/^[a-z]+\.[a-z_]+$/', $operation) !== 1) {
            throw new OrvantaException('Unbekannte Proxy-Operation.', 500);
        }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            throw new OrvantaException('Die Anfrage an den Mail-Proxy konnte nicht erstellt werden.', 500);
        }
        // Proxy-Zeitlimit je Mailserver-Verbindung (Verbinden, Anmelden,
        // Befehl) plus Reserve; Anfragen ohne Postfach (Invalidierung) kurz.
        $account = is_array($payload['account'] ?? null) ? $payload['account'] : null;
        $timeout = $account !== null ? max(10, min(180, (int) ($account['timeout'] ?? 20) * 3 + 5)) : 5;
        [$status, $response, $error] = $this->post('/v1/' . $operation, $operation, $body, $timeout);
        unset($body, $payload);
        if ($error !== null) {
            throw new OrvantaException('Der Mail-Proxy-Dienst ist nicht erreichbar.', 503);
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new OrvantaException('Ungültige Antwort des Mail-Proxys.', 502);
        }
        if ($status === 200 && ($data['ok'] ?? false) === true) {
            return is_array($data['data'] ?? null) ? $data['data'] : [];
        }
        $code = is_array($data['error'] ?? null) ? (string) ($data['error']['code'] ?? '') : '';
        $message = is_array($data['error'] ?? null) ? (string) ($data['error']['message'] ?? '') : '';
        [$text, $httpStatus] = self::ERRORS[$code] ?? [$message !== '' ? mb_substr($message, 0, 300) : 'Der Mail-Proxy meldet einen Fehler.', 502];

        throw new OrvantaException($text, $httpStatus, null, self::REASONS[$code] ?? '');
    }

    public function health(): array
    {
        $handle = $this->handle(rtrim($this->baseUrl, '/') . '/health', 5);
        if ($handle === null) {
            return ['ok' => false, 'message' => 'cURL ist nicht verfügbar.', 'details' => []];
        }
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = $raw === false ? curl_error($handle) : null;
        unset($handle); // curl_close() ist seit PHP 8.5 veraltet
        if ($error !== null || !is_string($raw)) {
            return ['ok' => false, 'message' => 'Der Proxy-Dienst ist nicht erreichbar.', 'details' => []];
        }
        $data = json_decode($raw, true);
        $details = is_array($data) ? array_intersect_key($data, array_flip(['status', 'version', 'uptime', 'connections', 'max_connections', 'pooled', 'requests', 'errors'])) : [];

        return [
            'ok' => $status === 200 && is_array($data) && ($data['status'] ?? '') === 'ok',
            'message' => $status === 200 ? 'Proxy-Dienst erreichbar.' : 'Proxy-Dienst antwortet mit HTTP ' . $status . '.',
            'details' => $details,
        ];
    }

    /**
     * Signatur einer Anfrage (auch vom Python-Proxy so berechnet).
     */
    public static function signature(string $key, string $timestamp, string $nonce, string $operation, string $body): string
    {
        return hash_hmac('sha256', "v1\n" . $timestamp . "\n" . $nonce . "\n" . $operation . "\n" . hash('sha256', $body), $key);
    }

    /**
     * @return array{0:int,1:string,2:?string}
     */
    private function post(string $path, string $operation, string $body, int $timeout): array
    {
        $handle = $this->handle(rtrim($this->baseUrl, '/') . $path, $timeout);
        if ($handle === null) {
            return [0, '', 'curl'];
        }
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $key = $this->secrets->deriveKey(self::KEY_PURPOSE);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Mail-Proxy-Timestamp: ' . $timestamp,
                'X-Mail-Proxy-Nonce: ' . $nonce,
                'X-Mail-Proxy-Signature: ' . self::signature($key, $timestamp, $nonce, $operation, $body),
            ],
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = $raw === false ? 'curl' : null;
        unset($handle); // curl_close() ist seit PHP 8.5 veraltet

        return [$status, is_string($raw) ? $raw : '', $error];
    }

    /**
     * @return \CurlHandle|null
     */
    private function handle(string $url, int $timeout): ?\CurlHandle
    {
        if (!function_exists('curl_init') || !self::isValidBaseUrl($this->baseUrl)) {
            return null;
        }
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE => self::MAX_RESPONSE,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);

        return $handle;
    }
}
