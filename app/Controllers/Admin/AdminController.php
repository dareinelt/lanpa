<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Container;
use App\Core\Response;
use App\Core\View;
use App\Security\Csrf;
use App\Security\Session;

abstract class AdminController extends Controller
{
    /**
     * @param array<string,mixed> $data
     */
    protected function adminView(string $template, array $data = [], int $status = 200): Response
    {
        $shared = [
            'appName' => Container::settings()->get('site_title'),
            'themeCss' => Container::theme()->css(),
            'flashes' => Session::takeFlash(),
            'csrfToken' => Csrf::token(),
            'adminUser' => Container::auth()->username(),
            'adminRole' => Container::auth()->role(),
            'assetVersion' => $this->assetVersion(),
            'errors' => [],
            'old' => [],
        ];

        return Response::html(
            View::render($template, array_merge($shared, $data), 'layouts.admin'),
            $status
        );
    }
}
