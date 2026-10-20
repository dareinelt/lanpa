<?php

declare(strict_types=1);

namespace App\Core;

final class Route
{
    /** @var array<int,string> */
    private array $paramNames = [];
    private string $regex;

    /**
     * @param callable(Request):Response $handler
     * @param string|null $permission benötigte Berechtigung (null = nur Login, '' = öffentlich)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        private $handler,
        public readonly ?string $permission,
        public readonly bool $public = false
    ) {
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function (array $m): string {
            $this->paramNames[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $this->regex = '#^' . $regex . '$#';
    }

    /** @return array<string,string>|null */
    public function match(string $path): ?array
    {
        if ($this->paramNames === []) {
            return $path === $this->pattern ? [] : null;
        }

        if (!preg_match($this->regex, $path, $matches)) {
            return null;
        }

        array_shift($matches);

        return array_combine($this->paramNames, array_map('rawurldecode', $matches)) ?: [];
    }

    public function handle(Request $request): Response
    {
        return ($this->handler)($request);
    }
}
