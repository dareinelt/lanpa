<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Einfacher Datei-Logger (eine Zeile pro Eintrag, JSON-Kontext).
 * Es dürfen niemals Passwörter oder Zugangsdaten übergeben werden.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public function __construct(
        private readonly string $file,
        private readonly string $minLevel = 'info'
    ) {}

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void { $this->log('debug', $message, $context); }
    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void { $this->log('info', $message, $context); }
    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void { $this->log('warning', $message, $context); }
    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void { $this->log('error', $message, $context); }

    /** @param array<string,mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 1) < (self::LEVELS[$this->minLevel] ?? 1)) {
            return;
        }

        $context = $this->redact($context);
        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = $this->redact($value);
            } elseif (preg_match('/pass|secret|token|pwd|key/i', (string) $key)) {
                $context[$key] = '***';
            }
        }

        return $context;
    }
}
