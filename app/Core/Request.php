<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     * @param array<string,mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
        public readonly array $files = []
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';
        $path = '/' . trim($path, '/');

        return new self($method, $path, $_GET, $_POST, $_SERVER, $_FILES);
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $value = $this->query($key);

        return $value !== null && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function inputInt(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return $value !== null && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->post);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        return strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
