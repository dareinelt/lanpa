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
use App\Security\SsoAuth;

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
            'landingIntroVisible' => $settings->bool('landing_intro_visible'),
            'footerText' => $settings->get('footer_text'),
            'documentationEnabled' => $settings->documentationEnabled(),
            'themeCss' => Container::theme()->css(),
            'hasLogo' => Container::logo()->current() !== null,
            'hasBackground' => Container::backgroundImage()->current() !== null,
            'announcements' => Container::announcements()->activeItems(),
            'flashes' => Session::takeFlash(),
            'csrfToken' => Csrf::token(),
            'assetVersion' => $this->assetVersion(),
            'officeTileStatus' => Container::officeConfig()->isEnabled() && Container::officeConfig()->tileStatusEnabled(),
            'officeTileStatusMode' => Container::officeConfig()->tileStatusMode(),
        ];
        $shared += $this->ssoShared();

        return Response::html(View::render($template, array_merge($shared, $data), $layout), $status);
    }

    /**
     * Erkannter Windows-Benutzer fuer den Seitenkopf und, falls keiner erkannt
     * ist, der Link zur freiwilligen Windows-Anmeldung.
     *
     * @return array{ssoUser:?array<string,mixed>,ssoLoginUrl:string}
     */
    private function ssoShared(): array
    {
        try {
            $sso = Container::sso();
            if (!$sso->isEnabled()) {
                return ['ssoUser' => null, 'ssoLoginUrl' => ''];
            }

            $request = Request::fromGlobals();
            $user = $sso->resolve($request);
            $uri = (string) ($request->server['REQUEST_URI'] ?? '/');

            return [
                'ssoUser' => $user,
                'ssoLoginUrl' => $user === null && !$sso->isFake() ? SsoAuth::loginUrl($uri) : '',
            ];
        } catch (\Throwable) {
            return ['ssoUser' => null, 'ssoLoginUrl' => ''];
        }
    }

    protected function assetVersion(): string
    {
        static $version = null;

        if ($version === null) {
            // Neueste Aenderung aller Stylesheets und Skripte, damit auch
            // Aenderungen an admin.css oder office-footer.css den Cache leeren.
            $files = array_merge(
                glob(BASE_PATH . '/public/assets/css/*.css') ?: [],
                glob(BASE_PATH . '/public/assets/js/*.js') ?: []
            );
            $mtimes = array_map('filemtime', $files);
            $version = $mtimes === [] ? '1' : (string) max($mtimes);
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
