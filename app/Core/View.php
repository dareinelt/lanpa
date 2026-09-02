<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Template-Rendering auf Basis reiner PHP-Templates.
 */
final class View
{
    private static string $viewPath = '';

    public static function setViewPath(string $path): void
    {
        self::$viewPath = rtrim($path, '/\\');
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = self::renderFile($template, $data);

        if ($layout !== null) {
            $content = self::renderFile($layout, array_merge($data, ['content' => $content]));
        }

        return $content;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function renderFile(string $template, array $data = []): string
    {
        $file = self::$viewPath . DIRECTORY_SEPARATOR
            . str_replace('.', DIRECTORY_SEPARATOR, $template) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException('Template nicht gefunden: ' . $template);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}
