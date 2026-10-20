<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;

/** Protokolliert Fehlerantworten (>= 400) ohne personenbezogene Nutzdaten. */
final class RequestLogMiddleware
{
    public function __construct(private readonly Logger $logger) {}

    public function __invoke(Request $request, callable $next): Response
    {
        $response = $next($request);
        if ($response->status() >= 400 && $response->status() !== 404) {
            $this->logger->warning('HTTP ' . $response->status(), [
                'method' => $request->method(),
                'path' => $request->path(),
            ]);
        }

        return $response;
    }
}
