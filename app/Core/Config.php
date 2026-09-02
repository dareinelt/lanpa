<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Laedt die Konfigurationsdateien aus /config und stellt sie per Punktnotation bereit.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];
    private static bool $booted = false;

    public static function boot(string $configDir): void
    {
        self::$items = [];

        foreach (glob(rtrim($configDir, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            /** @var mixed $data */
            $data = require $file;
            self::$items[basename($file, '.php')] = $data;
        }

        self::$booted = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$booted) {
            return $default;
        }

        /** @var mixed $value */
        $value = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
