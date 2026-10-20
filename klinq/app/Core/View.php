<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    /** @var array<string,mixed> */
    private array $shared = [];

    public function __construct(private readonly string $basePath) {}

    /** Daten, die jedem Template zur Verfügung stehen (z. B. $user, $csrf). */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = rtrim($this->basePath, '/') . '/' . str_replace('.', '/', $template) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View {$template} nicht gefunden.");
        }

        $data = array_merge($this->shared, $data);
        $data['__view'] = $this;

        return (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } finally {
                $output = (string) ob_get_clean();
            }

            return $output;
        })($file, $data);
    }
}
