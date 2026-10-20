<?php

declare(strict_types=1);

namespace App\Services\Klinq;

/**
 * Erreichbarkeitspruefung des KLINQ-Containers.
 *
 * Die Anfrage geht ueber das interne Docker-Netz (KLINQ_HEALTH_URL) und wird
 * kurz zwischengespeichert, damit die Statuskarte im Adminbereich nicht bei
 * jedem Aufruf wartet.
 */
final class KlinqHealthService
{
    public function __construct(private readonly KlinqConfigService $config)
    {
    }

    /**
     * @return array{state:string,available:bool,checked_at:string,latency_ms:int,message:string,url:string}
     */
    public function check(bool $force = false): array
    {
        if (!$this->config->isEnabled()) {
            return [
                'state' => 'disabled',
                'available' => false,
                'checked_at' => gmdate('c'),
                'latency_ms' => 0,
                'message' => 'KLINQ ist nicht aktiviert.',
                'url' => '',
            ];
        }

        if (!$force) {
            $cached = $this->cached();
            if ($cached !== null) {
                return $cached;
            }
        }

        $result = $this->probe();
        $this->store($result);

        return $result;
    }

    /**
     * @return array{state:string,available:bool,checked_at:string,latency_ms:int,message:string,url:string}
     */
    private function probe(): array
    {
        $url = $this->config->healthUrl();
        $started = microtime(true);

        if ($url === '') {
            return [
                'state' => 'down',
                'available' => false,
                'checked_at' => gmdate('c'),
                'latency_ms' => 0,
                'message' => 'Es ist keine interne Adresse fuer die Pruefung hinterlegt.',
                'url' => '',
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->config->healthTimeout(),
                'ignore_errors' => true,
                'follow_location' => 0,
                'header' => "Accept: application/json\r\nUser-Agent: lanpa-klinq-health\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $latency = (int) round((microtime(true) - $started) * 1000);
        $status = 0;

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        if ($body === false) {
            return [
                'state' => 'down',
                'available' => false,
                'checked_at' => gmdate('c'),
                'latency_ms' => $latency,
                'message' => 'Der KLINQ-Container antwortet nicht.',
                'url' => $url,
            ];
        }

        $payload = json_decode($body, true);
        $healthy = $status === 200 && is_array($payload) && (($payload['status'] ?? '') === 'ok');

        return [
            'state' => $healthy ? 'ok' : 'down',
            'available' => $healthy,
            'checked_at' => gmdate('c'),
            'latency_ms' => $latency,
            'message' => $healthy
                ? 'KLINQ ist erreichbar.'
                : 'KLINQ antwortet unerwartet (HTTP ' . $status . ').',
            'url' => $url,
        ];
    }

    /**
     * @return array{state:string,available:bool,checked_at:string,latency_ms:int,message:string,url:string}|null
     */
    private function cached(): ?array
    {
        $file = $this->config->healthCacheFile();
        if ($file === '' || !is_readable($file)) {
            return null;
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $cached = json_decode($contents, true);
        if (!is_array($cached) || !isset($cached['checked_ts'], $cached['state'])) {
            return null;
        }

        if (time() - (int) $cached['checked_ts'] > $this->config->healthCacheTtl()) {
            return null;
        }

        return [
            'state' => (string) $cached['state'],
            'available' => (bool) ($cached['available'] ?? false),
            'checked_at' => (string) ($cached['checked_at'] ?? gmdate('c')),
            'latency_ms' => (int) ($cached['latency_ms'] ?? 0),
            'message' => (string) ($cached['message'] ?? ''),
            'url' => (string) ($cached['url'] ?? ''),
        ];
    }

    /**
     * @param array{state:string,available:bool,checked_at:string,latency_ms:int,message:string,url:string} $result
     */
    private function store(array $result): void
    {
        $file = $this->config->healthCacheFile();
        if ($file === '') {
            return;
        }

        $directory = dirname($file);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        @file_put_contents(
            $file,
            (string) json_encode($result + ['checked_ts' => time()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
