<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\HttpException;

/**
 * Router mit Pfadparametern ({id}) und Berechtigung pro Route.
 * Die Rechteprüfung selbst erfolgt in der AuthorizationMiddleware.
 */
final class Router
{
    /** @var array<string,array<int,Route>> */
    private array $routes = [];

    /** @var array<int,callable(Request,callable(Request):Response):Response> */
    private array $middleware = [];

    private ?Route $matched = null;

    /** @param callable(Request,callable(Request):Response):Response $middleware */
    public function addMiddleware(callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /** @param callable(Request):Response $handler */
    public function get(string $pattern, callable $handler, ?string $permission = null, bool $public = false): void
    {
        $this->add('GET', $pattern, $handler, $permission, $public);
    }

    /** @param callable(Request):Response $handler */
    public function post(string $pattern, callable $handler, ?string $permission = null, bool $public = false): void
    {
        $this->add('POST', $pattern, $handler, $permission, $public);
    }

    /** @param callable(Request):Response $handler */
    public function add(string $method, string $pattern, callable $handler, ?string $permission = null, bool $public = false): void
    {
        $pattern = rtrim($pattern, '/') ?: '/';
        $this->routes[$method][] = new Route($method, $pattern, $handler, $permission, $public);
    }

    /** @return array{0:Route,1:array<string,string>}|null */
    public function resolve(string $method, string $path): ?array
    {
        foreach ($this->routes[$method] ?? [] as $route) {
            $params = $route->match($path);
            if ($params !== null) {
                return [$route, $params];
            }
        }

        return null;
    }

    public function dispatch(Request $request): Response
    {
        $resolved = $this->resolve($request->method(), $request->path());
        if ($resolved === null) {
            if ($request->method() === 'HEAD' && $this->resolve('GET', $request->path()) !== null) {
                $resolved = $this->resolve('GET', $request->path());
            } else {
                throw new HttpException('Seite nicht gefunden', 404);
            }
        }

        [$route, $params] = $resolved;
        $this->matched = $route;
        $request = $request->withRouteParams($params);

        $core = static fn (Request $request): Response => $route->handle($request);
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            static fn (callable $next, callable $mw): callable => static fn (Request $r): Response => $mw($r, $next),
            $core
        );

        return $pipeline($request);
    }

    public function matchedRoute(): ?Route
    {
        return $this->matched;
    }

    /** Alle registrierten Routen (für Tests/Inventur). @return list<Route> */
    public function routes(): array
    {
        return array_merge(...array_values($this->routes ?: [[]]));
    }
}
