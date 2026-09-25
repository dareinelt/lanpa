<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Contracts\OfficeProbeInterface;

/**
 * Netzwerkzugriffe per PHP-Streams (das App-Image besitzt keine curl-Erweiterung).
 */
final class StreamOfficeProbe implements OfficeProbeInterface
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 4): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return ['status' => 0, 'body' => '', 'error' => 'Ungültige URL.'];
        }

        $headerLines = ['Accept: application/json, text/plain, */*', 'Connection: close'];
        foreach ($headers as $name => $value) {
            $headerLines[] = str_replace(["\r", "\n"], '', $name . ': ' . $value);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.1,
            ],
        ]);

        $error = null;
        set_error_handler(static function (int $errno, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $stream = fopen($url, 'rb', false, $context);
            if ($stream === false) {
                return ['status' => 0, 'body' => '', 'error' => $this->shortError($error)];
            }

            stream_set_timeout($stream, $timeout);
            $content = stream_get_contents($stream, 1024 * 1024);
            $meta = stream_get_meta_data($stream);
            fclose($stream);
        } finally {
            restore_error_handler();
        }

        $status = 0;
        foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
            if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        if (!empty($meta['timed_out'])) {
            return ['status' => $status, 'body' => '', 'error' => 'Zeitüberschreitung.'];
        }

        return ['status' => $status, 'body' => $content === false ? '' : $content, 'error' => null];
    }

    public function tcp(string $host, int $port, string $payload = '', int $timeout = 3, int $readBytes = 64): ?string
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            return null;
        }

        stream_set_timeout($socket, $timeout);
        if ($payload !== '' && @fwrite($socket, $payload) === false) {
            fclose($socket);

            return null;
        }

        $data = $payload === '' ? '' : (string) @fread($socket, $readBytes);
        fclose($socket);

        return $data;
    }

    private function shortError(?string $error): string
    {
        $error = trim((string) $error);
        if ($error === '') {
            return 'Verbindung fehlgeschlagen.';
        }

        // "fopen(http://...): Failed to open stream: ..." -> nur die Ursache.
        $pos = strrpos($error, ': ');

        return $pos === false ? $error : substr($error, $pos + 2);
    }
}
