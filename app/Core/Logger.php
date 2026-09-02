<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Schlanker Datei-Logger. Secrets werden grundsaetzlich maskiert.
 */
final class Logger
{
    private const REDACT_KEYS = ['password', 'passwort', 'secret', 'token', 'pass'];
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];

    public function __construct(
        private readonly string $logFile,
        private readonly string $minLevel = 'info'
    ) {
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 20) < (self::LEVELS[$this->minLevel] ?? 20)) {
            return;
        }

        $line = sprintf(
            '[%s] %s: %s%s',
            date('c'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . $this->encodeContext($context)
        );

        $directory = dirname($this->logFile);
        if (!is_dir($directory)) {
            @mkdir($directory, 0o775, true);
        }

        if (@file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log($line);
        }
    }

    private function encodeContext(array $context): string
    {
        $json = json_encode($this->redact($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    private function redact(array $context): array
    {
        $result = [];
        foreach ($context as $key => $value) {
            $lowerKey = is_string($key) ? strtolower($key) : '';
            $isSecret = false;
            foreach (self::REDACT_KEYS as $needle) {
                if ($lowerKey !== '' && str_contains($lowerKey, $needle)) {
                    $isSecret = true;
                    break;
                }
            }

            $result[$key] = $isSecret ? '***' : (is_array($value) ? $this->redact($value) : $value);
        }

        return $result;
    }
}
