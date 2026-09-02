<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\HttpException;

/**
 * Sehr einfacher Router mit exakten Pfaden.
 */
final class Router
{
    /** @var list<array{method:string,pattern:string,handler:callable|array{0:class-string,1:string},middleware:list<callable>}> */
    private array $routes = [];

    /** @var list<callable> */
    private array $groupMiddleware = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /**
     * @param list<callable> $middleware
     */
    public function group(array $middleware, callable $callback): void
    {
        $previous = $this->groupMiddleware;
        $this->groupMiddleware = array_merge($previous, $middleware);
        $callback($this);
        $this->groupMiddleware = $previous;
    }

    private function add(string $method, string $path, callable|array $handler): void
    {
        $this->routes[] = [
            'method' => $method,
            'pattern' => $path === '/' ? '/' : rtrim($path, '/'),
            'handler' => $handler,
            'middleware' => $this->groupMiddleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if ($route['pattern'] !== $request->path) {
                continue;
            }

            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            foreach ($route['middleware'] as $middleware) {
                $result = $middleware($request);
                if ($result instanceof Response) {
                    return $result;
                }
            }

            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $method] = $handler;
                $controller = new $class();
                $result = $controller->{$method}($request);
            } else {
                $result = $handler($request);
            }

            return $result instanceof Response ? $result : Response::html((string) $result);
        }

        throw new HttpException($pathMatched ? 405 : 404, $pathMatched ? 'Methode nicht erlaubt.' : 'Seite nicht gefunden.');
    }
}
