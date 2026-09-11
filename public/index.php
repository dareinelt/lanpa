<?php

declare(strict_types=1);

/**
 * Front-Controller. Alle HTTP-Anfragen laufen hier hinein.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\AdminUserController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\DescriptionController;
use App\Controllers\Admin\DesignController;
use App\Controllers\Admin\EmergencyNumberController;
use App\Controllers\Admin\ImportantLinkController;
use App\Controllers\Admin\LdapController;
use App\Controllers\Admin\NavigationController;
use App\Controllers\Admin\StatisticsController;
use App\Controllers\ClickController;
use App\Controllers\HealthController;
use App\Controllers\ImportantLinkIconController;
use App\Controllers\LandingController;
use App\Controllers\LogoController;
use App\Controllers\PhonebookController;
use App\Core\Config;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Exceptions\HttpException;
use App\Security\Session;

$request = Request::fromGlobals();
Session::start($request->isSecure());

$nonce = base64_encode(random_bytes(16));
$GLOBALS['csp_nonce'] = $nonce;

$router = new Router();

$router->get('/', [LandingController::class, 'index']);
$router->get('/telefonliste', [PhonebookController::class, 'index']);
$router->get('/api/telefonliste', [PhonebookController::class, 'search']);
$router->post('/api/klick', [ClickController::class, 'store']);
$router->get('/logo', [LogoController::class, 'show']);
$router->get('/wichtige-links/icon', [ImportantLinkIconController::class, 'show']);
$router->get('/health', [HealthController::class, 'index']);

$router->get('/admin/login', [AuthController::class, 'showLogin']);
$router->post('/admin/login', [AuthController::class, 'login']);

$requireAuth = static function (Request $request): ?Response {
    if (Container::auth()->check()) {
        return null;
    }

    if (str_starts_with($request->path, '/admin/api')) {
        return Response::json(['error' => 'Nicht angemeldet.'], 401);
    }

    return Response::redirect('/admin/login');
};

$requireAdmin = static function (Request $request): ?Response {
    if (Container::auth()->isAdmin()) {
        return null;
    }

    if (str_starts_with($request->path, '/admin/api')) {
        return Response::json(['error' => 'Kein Zugriff.'], 403);
    }

    throw new HttpException(403, 'Für diesen Bereich fehlt die Berechtigung.');
};

$router->group([$requireAuth], static function (Router $router) use ($requireAdmin): void {
    $router->post('/admin/logout', [AuthController::class, 'logout']);

    $router->get('/admin', [DashboardController::class, 'index']);

    $router->get('/admin/wichtige-links', [ImportantLinkController::class, 'index']);
    $router->get('/admin/wichtige-links/neu', [ImportantLinkController::class, 'create']);
    $router->post('/admin/wichtige-links/neu', [ImportantLinkController::class, 'store']);
    $router->get('/admin/wichtige-links/bearbeiten', [ImportantLinkController::class, 'edit']);
    $router->post('/admin/wichtige-links/bearbeiten', [ImportantLinkController::class, 'update']);
    $router->post('/admin/wichtige-links/loeschen', [ImportantLinkController::class, 'delete']);
    $router->post('/admin/wichtige-links/status', [ImportantLinkController::class, 'toggle']);

    $router->group([$requireAdmin], static function (Router $router): void {
        $router->get('/admin/navigation', [NavigationController::class, 'index']);
        $router->get('/admin/navigation/neu', [NavigationController::class, 'create']);
        $router->post('/admin/navigation/neu', [NavigationController::class, 'store']);
        $router->get('/admin/navigation/bearbeiten', [NavigationController::class, 'edit']);
        $router->post('/admin/navigation/bearbeiten', [NavigationController::class, 'update']);
        $router->post('/admin/navigation/loeschen', [NavigationController::class, 'delete']);
        $router->post('/admin/navigation/status', [NavigationController::class, 'toggle']);
        $router->post('/admin/navigation/sortieren', [NavigationController::class, 'move']);

        $router->get('/admin/notfallnummern', [EmergencyNumberController::class, 'index']);
        $router->get('/admin/notfallnummern/neu', [EmergencyNumberController::class, 'create']);
        $router->post('/admin/notfallnummern/neu', [EmergencyNumberController::class, 'store']);
        $router->get('/admin/notfallnummern/bearbeiten', [EmergencyNumberController::class, 'edit']);
        $router->post('/admin/notfallnummern/bearbeiten', [EmergencyNumberController::class, 'update']);
        $router->post('/admin/notfallnummern/loeschen', [EmergencyNumberController::class, 'delete']);
        $router->post('/admin/notfallnummern/status', [EmergencyNumberController::class, 'toggle']);
        $router->post('/admin/notfallnummern/sortieren', [EmergencyNumberController::class, 'move']);

        $router->get('/admin/beschreibungen', [DescriptionController::class, 'index']);
        $router->post('/admin/beschreibungen', [DescriptionController::class, 'update']);

        $router->get('/admin/design', [DesignController::class, 'index']);
        $router->post('/admin/design', [DesignController::class, 'update']);
        $router->post('/admin/design/logo', [DesignController::class, 'uploadLogo']);
        $router->post('/admin/design/logo-entfernen', [DesignController::class, 'removeLogo']);

        $router->get('/admin/ad', [LdapController::class, 'index']);
        $router->post('/admin/ad', [LdapController::class, 'update']);
        $router->post('/admin/ad/sync', [LdapController::class, 'sync']);

        $router->get('/admin/statistik', [StatisticsController::class, 'index']);
        $router->get('/admin/api/statistik', [StatisticsController::class, 'data']);

        $router->get('/admin/benutzer', [AdminUserController::class, 'index']);
        $router->get('/admin/benutzer/neu', [AdminUserController::class, 'create']);
        $router->post('/admin/benutzer/neu', [AdminUserController::class, 'store']);
        $router->get('/admin/benutzer/bearbeiten', [AdminUserController::class, 'edit']);
        $router->post('/admin/benutzer/bearbeiten', [AdminUserController::class, 'update']);
        $router->post('/admin/benutzer/loeschen', [AdminUserController::class, 'delete']);
        $router->post('/admin/benutzer/status', [AdminUserController::class, 'toggle']);
    });
});

try {
    $response = $router->dispatch($request);
} catch (HttpException $exception) {
    $response = renderError($exception->statusCode(), $exception->getMessage());
} catch (Throwable $exception) {
    app_logger()->error('Unbehandelter Fehler.', [
        'message' => $exception->getMessage(),
        'path' => $request->path,
    ]);

    $response = renderError(500, 'Es ist ein technischer Fehler aufgetreten.');
}

foreach (securityHeaders($nonce) as $name => $value) {
    $response = $response->withHeader($name, $value);
}

$response->send();

/**
 * @return array<string,string>
 */
function securityHeaders(string $nonce): array
{
    return [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'same-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), interest-cohort=()',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'nonce-" . $nonce
            . "'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'none'",
    ];
}

function renderError(int $status, string $message): Response
{
    $template = $status === 404 ? 'errors.404' : 'errors.generic';

    try {
        $html = View::render($template, [
            'appName' => (string) Config::get('app.name', 'Intranet'),
            'status' => $status,
            'message' => $message,
            'themeCss' => Container::theme()->css(),
            'assetVersion' => '1',
        ], 'layouts.minimal');
    } catch (Throwable) {
        // Fallback ohne Datenbank (z. B. MySQL nicht erreichbar).
        $html = '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Fehler ' . $status . '</title></head><body style="font-family:system-ui,sans-serif;padding:2rem">'
            . '<h1>Fehler ' . $status . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="/">Zur Startseite</a></p></body></html>';
    }

    return Response::html($html, $status);
}
