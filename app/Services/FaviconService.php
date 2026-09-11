<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Ermittelt und speichert automatisch das Favicon einer verlinkten Seite.
 *
 * Die Ermittlung ist rein informativ (Layout-Icon der "Wichtige Links") und
 * darf niemals das Speichern des Links selbst verhindern: Schlaegt sie fehl,
 * wird einfach kein Icon hinterlegt.
 */
final class FaviconService
{
    // SVG wird bewusst nicht unterstuetzt: aus dem Internet geladene SVGs
    // koennten aktive Inhalte (Skripte) enthalten.
    private const ALLOWED = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    private const MAX_BYTES = 262144; // 256 KB

    private const TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly string $uploadPath = BASE_PATH . '/storage/uploads'
    ) {
    }

    /**
     * Versucht, ein Favicon fuer die angegebene URL zu laden und lokal
     * abzulegen. Interne Pfade (ohne Host) werden ausgelassen.
     *
     * @return array{icon_file:string,icon_mime:string}|null
     */
    public function fetchAndStore(string $url): ?array
    {
        if (!function_exists('stream_context_create')) {
            return null;
        }

        $origin = $this->originOf($url);
        if ($origin === null) {
            return null;
        }

        foreach ($this->candidateUrls($origin) as $candidate) {
            $result = $this->tryDownload($candidate);
            if ($result !== null) {
                return $this->save($result['contents'], $result['mime']);
            }
        }

        return null;
    }

    public function deleteStoredIcon(?string $filename): void
    {
        if ($filename === null || !$this->isValidFilename($filename)) {
            return;
        }

        $path = rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function iconPath(string $filename): ?string
    {
        if (!$this->isValidFilename($filename)) {
            return null;
        }

        $path = rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . $filename;

        return is_readable($path) ? $path : null;
    }

    /**
     * @return null|array{scheme:string,host:string}
     */
    private function originOf(string $url): ?array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return null;
        }

        if (!is_string($host) || $host === '' || !$this->isPubliclyRoutable($host)) {
            return null;
        }

        return ['scheme' => strtolower($scheme), 'host' => $host];
    }

    /**
     * Blockiert Ziele in privaten, lokalen oder reservierten Adressbereichen (SSRF-Schutz).
     */
    private function isPubliclyRoutable(string $host): bool
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : (@dns_get_record($host, DNS_A | DNS_AAAA) ?: []);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $entry) {
            $ip = is_array($entry) ? (string) ($entry['ip'] ?? $entry['ipv6'] ?? '') : (string) $entry;
            if ($ip === '') {
                return false;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{scheme:string,host:string} $origin
     *
     * @return list<string>
     */
    private function candidateUrls(array $origin): array
    {
        $base = $origin['scheme'] . '://' . $origin['host'];

        return [$base . '/favicon.ico'];
    }

    /**
     * @return array{contents:string,mime:string}|null
     */
    private function tryDownload(string $url): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'follow_location' => 1,
                'max_redirects' => 3,
                'ignore_errors' => true,
                'header' => "User-Agent: Intranet-Favicon-Fetcher/1.0\r\n",
            ],
            'https' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'follow_location' => 1,
                'max_redirects' => 3,
                'ignore_errors' => true,
                'header' => "User-Agent: Intranet-Favicon-Fetcher/1.0\r\n",
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            return null;
        }

        try {
            $status = $this->responseStatus($handle);
            if ($status === null || $status >= 400) {
                return null;
            }

            $contents = '';
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    break;
                }

                $contents .= $chunk;
                if (strlen($contents) > self::MAX_BYTES) {
                    return null;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($contents === '') {
            return null;
        }

        $mime = $this->detectMime($contents);
        if ($mime === null) {
            return null;
        }

        return ['contents' => $contents, 'mime' => $mime];
    }

    /**
     * @param resource $handle
     */
    private function responseStatus($handle): ?int
    {
        $meta = stream_get_meta_data($handle);
        foreach ($meta['wrapper_data'] ?? [] as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 200;
    }

    private function detectMime(string $contents): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_buffer($finfo, $contents);
                finfo_close($finfo);
                if (is_string($detected) && isset(self::ALLOWED[$detected])) {
                    return $detected;
                }
            }
        }

        // .ico-Dateien werden von finfo oft nicht sauber erkannt.
        if (strlen($contents) > 4 && substr($contents, 0, 4) === "\x00\x00\x01\x00") {
            return 'image/x-icon';
        }

        return null;
    }

    /**
     * @return array{icon_file:string,icon_mime:string}|null
     */
    private function save(string $contents, string $mime): ?array
    {
        $extension = self::ALLOWED[$mime] ?? null;
        if ($extension === null) {
            return null;
        }

        if (!is_dir($this->uploadPath) && !@mkdir($this->uploadPath, 0o775, true) && !is_dir($this->uploadPath)) {
            return null;
        }

        $filename = 'favicon-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $target = rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (@file_put_contents($target, $contents) === false) {
            return null;
        }

        @chmod($target, 0o644);

        return ['icon_file' => $filename, 'icon_mime' => $mime];
    }

    private function isValidFilename(string $filename): bool
    {
        return preg_match('/^favicon-[a-f0-9]{16}\.(png|jpg|webp|ico)$/', $filename) === 1;
    }

    public static function fromConfig(): self
    {
        return new self((string) Config::get('app.upload_path', BASE_PATH . '/storage/uploads'));
    }
}
