<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Zugriff auf Umgebungsvariablen inkl. optionalem Laden einer .env-Datei
 * (nur fuer lokale Entwicklung; im Container kommen die Werte aus Docker/Secrets).
 *
 * Zusaetzlich wird das Docker-Secret-Muster <VAR>_FILE unterstuetzt.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $file): void
    {
        self::$loaded = true;

        if (!is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (strlen($value) >= 2) {
                $first = $value[0];
                if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                    $value = substr($value, 1, -1);
                }
            }

            // Echte Umgebungsvariablen haben Vorrang vor der .env-Datei.
            if (getenv($key) === false && !isset($_ENV[$key], $_SERVER[$key])) {
                self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $filePath = self::raw($key . '_FILE');
        if ($filePath !== null && $filePath !== '' && is_readable($filePath)) {
            $contents = file_get_contents($filePath);
            if ($contents !== false) {
                return trim($contents);
            }
        }

        $value = self::raw($key);

        return $value === null || $value === '' ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }

    private static function raw(string $key): ?string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }

        return self::$values[$key] ?? null;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }
}
