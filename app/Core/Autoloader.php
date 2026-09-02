<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimaler PSR-4 Autoloader (bewusst ohne Composer-Abhaengigkeit).
 */
final class Autoloader
{
    public static function register(string $namespacePrefix, string $baseDir): void
    {
        $prefix = rtrim($namespacePrefix, '\\') . '\\';
        $base = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;

        spl_autoload_register(static function (string $class) use ($prefix, $base): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $file = $base . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
