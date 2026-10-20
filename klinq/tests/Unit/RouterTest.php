<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Exceptions\HttpException;
use Tests\Support\TestCase;

final class RouterTest extends TestCase
{
    private function request(string $method, string $path): Request
    {
        return new Request(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path], [], [], []);
    }

    public function testResolvesStaticRoute(): void
    {
        $router = new Router();
        $router->get('/dashboard', static fn (Request $r): Response => Response::text('ok'));
        $response = $router->dispatch($this->request('GET', '/dashboard'));
        $this->assertSame('ok', $response->content());
        $this->assertSame(200, $response->status());
    }

    public function testExtractsRouteParameters(): void
    {
        $router = new Router();
        $router->get('/assets/{id}/history/{page}', static fn (Request $r): Response => Response::text($r->param('id') . '-' . $r->paramInt('page')));
        $response = $router->dispatch($this->request('GET', '/assets/42/history/3'));
        $this->assertSame('42-3', $response->content());
    }

    public function testThrows404ForUnknownRoute(): void
    {
        $router = new Router();
        $e = $this->assertThrows(HttpException::class, fn () => $router->dispatch($this->request('GET', '/nope')));
        $this->assertSame(404, $e->statusCode());
    }

    public function testMiddlewareRunsInOrderAndCanShortCircuit(): void
    {
        $router = new Router();
        $log = [];
        $router->addMiddleware(static function (Request $r, callable $next) use (&$log): Response { $log[] = 'a'; $res = $next($r); $log[] = 'a-out'; return $res; });
        $router->addMiddleware(static function (Request $r, callable $next) use (&$log): Response { $log[] = 'b'; return Response::text('blocked', 403); });
        $router->get('/x', static function () use (&$log): Response { $log[] = 'handler'; return Response::text('ok'); });
        $response = $router->dispatch($this->request('GET', '/x'));
        $this->assertSame(403, $response->status());
        $this->assertSame(['a', 'b', 'a-out'], $log);
    }

    public function testMatchedRouteCarriesPermissionAndPublicFlag(): void
    {
        $router = new Router();
        $router->get('/login', static fn (): Response => Response::text(''), null, true);
        $router->post('/assets', static fn (): Response => Response::text(''), 'assets.manage');
        $router->dispatch($this->request('POST', '/assets'));
        $this->assertSame('assets.manage', $router->matchedRoute()?->permission);
        $router->dispatch($this->request('GET', '/login'));
        $this->assertTrue($router->matchedRoute()?->public);
    }

    public function testMethodOverrideViaFormField(): void
    {
        $request = new Request(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/a/1'], [], ['_method' => 'DELETE'], []);
        $this->assertSame('DELETE', $request->method());
    }
}
