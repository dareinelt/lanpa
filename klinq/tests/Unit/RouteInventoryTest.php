<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Container;
use App\Core\Route;
use App\Core\Router;
use App\Security\Permissions;
use Tests\Support\TestCase;

/**
 * Routen-Inventur: Jede Route ist entweder öffentlich (Login/Health), erfordert eine
 * Anmeldung oder ein konkret existierendes Recht. Schreibende Routen sind nie öffentlich.
 */
final class RouteInventoryTest extends TestCase
{
    /** @return list<Route> */
    private function routes(): array
    {
        $basePath = dirname(__DIR__, 2);
        $c = new Container();
        $c->instance('basePath', $basePath);
        $router = new Router();
        $register = require $basePath . '/routes/web.php';
        $register($router, $c);

        return $router->routes();
    }

    public function testEveryRouteIsProtectedOrExplicitlyPublic(): void
    {
        $permissions = new Permissions(new Config(dirname(__DIR__, 2) . '/config'));
        $known = $permissions->all();
        // Öffentlich sind nur die Anmeldung, der Health-Check, das Störungsformular für Anwender
        // und der Einstieg aus dem Intranet (LANPA) mit signiertem Schein.
        $publicAllowed = [
            'GET /login', 'POST /login', 'GET /health',
            'GET /stoerung', 'POST /stoerung', 'GET /stoerung/gesendet',
            // Personenauswahl im Zusatzfeld „Zusätzlicher Benutzer“ des öffentlichen Formulars
            'GET /stoerung/benutzer', 'POST /stoerung/benutzer/uebernehmen',
            'GET /sso/lanpa',
        ];
        $routes = $this->routes();

        $this->assertTrue(count($routes) > 80, 'Routen nicht geladen');
        foreach ($routes as $route) {
            $key = $route->method . ' ' . $route->pattern;
            if ($route->public) {
                $this->assertContains($key, $publicAllowed, "{$key} ist öffentlich, steht aber nicht auf der Positivliste");
                continue;
            }
            if ($route->permission !== null) {
                $this->assertContains($route->permission, $known, "{$key}: Recht {$route->permission} existiert nicht");
            }
        }
    }

    public function testWriteRoutesRequireAPermissionOrAreSessionScoped(): void
    {
        // POST ohne Recht ist nur erlaubt, wenn die Aktion nur den eigenen Kontext betrifft.
        // POST /stoerung ist bewusst öffentlich: das Formular legt Tickets über ein Systemkonto an
        // und ist durch CSRF-Token, Netzfreigabe und Ratenbegrenzung abgesichert. Dasselbe gilt für
        // POST /stoerung/benutzer/uebernehmen, das nur Telefonbucheinträge als Mitarbeiter übernimmt
        // (Stammdaten aus dem Intranet, eigene Obergrenze je Sitzung und Stunde).
        $sessionScoped = ['POST /logout', 'POST /login', 'POST /profile/password', 'POST /stoerung', 'POST /stoerung/benutzer/uebernehmen'];
        $publicWriteAllowed = ['POST /login', 'POST /stoerung', 'POST /stoerung/benutzer/uebernehmen'];
        foreach ($this->routes() as $route) {
            if ($route->method !== 'POST') {
                continue;
            }
            $key = $route->method . ' ' . $route->pattern;
            $this->assertFalse($route->public && !in_array($key, $publicWriteAllowed, true), "{$key} darf nicht öffentlich sein");
            if ($route->permission === null) {
                $this->assertContains($key, $sessionScoped, "{$key} hat kein Recht – bewusst?");
            }
        }
    }

    public function testNoDuplicateRoutes(): void
    {
        $seen = [];
        foreach ($this->routes() as $route) {
            $key = $route->method . ' ' . $route->pattern;
            $this->assertFalse(isset($seen[$key]), "Route {$key} ist doppelt registriert");
            $seen[$key] = true;
        }
    }
}
