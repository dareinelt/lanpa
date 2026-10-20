<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

final class SecurityHeadersMiddleware
{
    public function __construct(private readonly Config $config) {}

    public function __invoke(Request $request, callable $next): Response
    {
        $response = $next($request);

        $response = $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');

        // Eine vom Controller gesetzte, strengere CSP (z. B. Sandbox für hochgeladene Bilder) bleibt erhalten
        if (!isset($response->headers()['Content-Security-Policy'])) {
            $response = $response->withHeader('Content-Security-Policy', (string) $this->config->get('app.security.csp'));
        }

        return $response;
    }
}
