<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\HttpException;
use App\Security\Csrf;
use App\Security\Session;

abstract class Controller
{
    /**
     * @param array<string,mixed> $data
     */
    protected function view(string $template, array $data = [], string $layout = 'layouts.base', int $status = 200): Response
    {
        $settings = Container::settings();

        $shared = [
            'appName' => (string) Config::get('app.name', 'Intranet'),
            'siteTitle' => $settings->get('site_title'),
            'siteSubtitle' => $settings->get('site_subtitle'),
            'siteSubtitleVisible' => $settings->bool('site_subtitle_visible'),
            'themeCss' => Container::theme()->css(),
            'hasLogo' => Container::logo()->current() !== null,
            'flashes' => Session::takeFlash(),
            'csrfToken' => Csrf::token(),
            'assetVersion' => $this->assetVersion(),
        ];

        return Response::html(View::render($template, array_merge($shared, $data), $layout), $status);
    }

    protected function assetVersion(): string
    {
        static $version = null;

        if ($version === null) {
            $file = BASE_PATH . '/public/assets/css/app.css';
            $version = is_file($file) ? (string) filemtime($file) : '1';
        }

        return $version;
    }

    protected function requireValidCsrf(Request $request): void
    {
        if (!Csrf::isValid($request->input('_token'))) {
            app_logger()->warning('CSRF-Token ungültig.', ['path' => $request->path]);

            throw new HttpException(419, 'Die Sitzung ist abgelaufen. Bitte erneut versuchen.');
        }
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect($path);
    }
}
