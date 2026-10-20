<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string,string> */
    private array $routeParams = [];

    /** @var array<string,mixed>|null */
    private ?array $jsonBody = null;

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     * @param array<string,mixed> $cookies
     */
    public function __construct(
        private readonly array $server,
        private readonly array $query,
        private readonly array $post,
        private readonly array $files,
        private readonly array $cookies = [],
        private readonly string $rawBody = ''
    ) {}

    public static function capture(): self
    {
        return new self($_SERVER, $_GET, $_POST, $_FILES, $_COOKIE ?? [], (string) file_get_contents('php://input'));
    }

    public function method(): string
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
        // Formular-Override für PUT/DELETE
        if ($method === 'POST' && isset($this->post['_method'])) {
            $override = strtoupper((string) $this->post['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = $path === false || $path === null ? '/' : $path;

        return rtrim($path, '/') ?: '/';
    }

    public function fullUrl(): string
    {
        return (string) ($this->server['REQUEST_URI'] ?? '/');
    }

    public function isJson(): bool
    {
        return str_contains(strtolower((string) ($this->server['CONTENT_TYPE'] ?? '')), 'application/json');
    }

    public function wantsJson(): bool
    {
        return $this->isJson()
            || str_starts_with($this->path(), '/api/')
            || str_contains((string) ($this->server['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->jsonBody === null) {
            $decoded = json_decode($this->rawBody, true);
            $this->jsonBody = is_array($decoded) ? $decoded : [];
        }

        return $this->jsonBody;
    }

    /** Liest Eingabe aus POST-, JSON- oder Query-Daten. */
    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        if ($this->isJson() && array_key_exists($key, $this->json())) {
            return $this->json()[$key];
        }

        return $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function stringOrNull(string $key): ?string
    {
        $value = $this->string($key);

        return $value === '' ? null : $value;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->input($key);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->input($key);

        return filter_var($value, FILTER_VALIDATE_BOOL) === true;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->isJson() ? $this->json() : [], $this->post);
    }

    /** @return array<string,mixed> */
    public function query(): array
    {
        return $this->query;
    }

    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    /** @return array<string,mixed> */
    public function files(): array
    {
        return $this->files;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Adresse des Besuchers. Hinter dem auth-Container des Intranets steht in
     * X-Forwarded-For die Kette der Weiterleitungen; mod_proxy hängt die Adresse des
     * direkten Gegenübers HINTEN an, deshalb zählt der letzte Eintrag. Werte davor
     * sind frei wählbar.
     */
    public function ip(): string
    {
        $forwarded = trim((string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $entries = explode(',', $forwarded);
            $last = trim((string) end($entries));
            if (filter_var($last, FILTER_VALIDATE_IP) !== false) {
                return $last;
            }
        }
        $remote = trim((string) ($this->server['REMOTE_ADDR'] ?? ''));

        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '';
    }

    /** Rohwert aus der Serverumgebung, z. B. REMOTE_USER aus der Windows-Anmeldung am Webserver. */
    public function serverValue(string $key): ?string
    {
        $value = $this->server[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    /**
     * Grobe Geräteerkennung anhand des User-Agent-Headers (kein Fingerprinting, nur Mobil/Desktop).
     * Wird genutzt, um die Kamera-Scan-Erfassung (`/m`) auf Mobilgeräte zu beschränken.
     */
    public function isMobile(): bool
    {
        $ua = $this->userAgent();

        return $ua !== '' && (bool) preg_match('/Mobi|Android|iPhone|iPad|iPod|Windows Phone|BlackBerry|IEMobile|Opera Mini/i', $ua);
    }

    /** @param array<string,string> $params */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;

        return $clone;
    }

    public function param(string $name, ?string $default = null): ?string
    {
        return $this->routeParams[$name] ?? $default;
    }

    public function paramInt(string $name): int
    {
        return (int) ($this->routeParams[$name] ?? 0);
    }
}
