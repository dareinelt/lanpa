<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Security\Session;
use App\Services\Office\OfficeAppService;
use Throwable;

/**
 * Oeffentliche Endpunkte der Office-Integration.
 *
 *   GET /office-starten           Office-Kachel: Uebersicht der freigegebenen Apps
 *                                 (?ziel=/office/... -> Weiterleitung zu Nextcloud)
 *   GET /office-app?app=<key>     Start einer App (Euro-Office-Webapp, Dateien, OWA)
 *   GET /office-nicht-verfuegbar  Hinweisseite (auch Fehlerseite des auth-Proxys)
 *   GET /api/office/footer        Konfiguration der Fusszeile in Nextcloud
 *   GET /api/office/status        Verfuegbarkeit fuer die Kachelanzeige
 */
final class OfficeController extends Controller
{
    public const ENTRY_PATH = OfficeAppService::ENTRY_PATH;
    public const SESSION_KEY = 'office_entered_at';

    /**
     * Office-Kachel: Uebersicht der freigegebenen Apps. Mit ?ziel=... (Einstieg
     * aus der Fusszeile/Direktaufruf) wie bisher Weiterleitung zu Nextcloud.
     */
    public function start(Request $request): Response
    {
        $ssoUser = $this->authorize($request);
        $apps = Container::officeApps()->allowedFor($ssoUser);

        $target = $request->query('ziel');
        if ($target !== null && $target !== '') {
            if (!$this->hasNextcloudApp($apps)) {
                throw new HttpException(403, 'Für Office fehlt die Berechtigung.');
            }

            return $this->enterNextcloud(Container::officeConfig()->entryTarget($target));
        }

        $tile = Container::navigationRepository()->findActiveInternalByUrl(self::ENTRY_PATH);

        return $this->view('office.apps', [
            'pageTitle' => $tile !== null ? (string) $tile['title'] : 'Office',
            'tile' => $tile,
            'apps' => $apps,
            'breadcrumb' => $tile !== null ? Container::navigation()->breadcrumb((int) $tile['id']) : [],
            'ssoUser' => $ssoUser,
            'descriptionMode' => Container::settings()->descriptionMode(),
            'activeNav' => '',
            'pageScript' => 'landing.js',
        ])->withHeader('Cache-Control', 'no-store')->withHeader('Vary', 'Cookie');
    }

    /**
     * Startet eine einzelne Office-App (Pruefung der AD-Gruppen-Freigabe).
     */
    public function launch(Request $request): Response
    {
        $tile = Container::navigationRepository()->findActiveInternalByUrl(self::ENTRY_PATH);
        if ($tile !== null && !empty($tile['protected_access']) && !Container::smsCode()->isVerified((int) $tile['id'])) {
            return $this->redirect('/zugriff?id=' . (int) $tile['id']);
        }

        $ssoUser = $this->authorize($request);
        $app = Container::officeApps()->findAllowed((string) $request->query('app', ''), $ssoUser);
        if ($app === null) {
            throw new HttpException(403, 'Diese Office-App ist für Sie nicht freigegeben.');
        }

        app_logger()->info('Office-App gestartet.', ['app' => $app['key'], 'user' => $ssoUser['username'] ?? '']);

        if ($app['external']) {
            return $this->redirect($app['target']);
        }

        return $this->enterNextcloud($app['target']);
    }
    public function unavailable(Request $request): Response
    {
        $response = null;

        try {
            $response = $this->view('office.unavailable', [
                'pageTitle' => 'Office nicht verfügbar',
                'activeNav' => '',
            ], 'layouts.minimal', 503);
        } catch (Throwable) {
            // Ohne Datenbank: statische Fassung.
            $response = Response::html(
                '<!doctype html><html lang="de"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>Office nicht verfügbar</title></head><body style="font-family:system-ui,sans-serif;padding:2rem">'
                . '<h1>Office ist derzeit nicht verfügbar</h1>'
                . '<p>Bitte versuchen Sie es in einigen Minuten erneut.</p>'
                . '<p><a href="/">Zur Startseite</a></p></body></html>',
                503
            );
        }

        return $response
            ->withHeader('Retry-After', '60')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function footer(Request $request): Response
    {
        $office = Container::officeConfig();
        if (!$office->isEnabled()) {
            return Response::json(['enabled' => false]);
        }

        $payload = $office->footerPayload(
            Container::settings()->theme(),
            Container::logo()->current() !== null,
            $this->footerAssetVersion()
        );

        if ($office->directAccessMode() === 'redirect' && !self::hasEntered($office->entryLifetime())) {
            $payload['redirect'] = self::ENTRY_PATH . '?ziel=' . rawurlencode($office->entryTarget($request->query('seite')));
        }

        return Response::json($payload)->withHeader('Vary', 'Cookie');
    }

    public function status(Request $request): Response
    {
        if (!Container::officeConfig()->isEnabled()) {
            return Response::json(['state' => 'disabled', 'available' => false, 'label' => 'Nicht aktiviert']);
        }

        return Response::json(Container::officeHealth()->publicSummary());
    }

    /**
     * Gemeinsame Pruefung: Office aktiv, Kachel-Berechtigung, angemeldeter Benutzer.
     *
     * @return array{id:int,username:string,display_name:string,groups:list<string>}
     */
    private function authorize(Request $request): array
    {
        if (!Container::officeConfig()->isEnabled()) {
            throw new HttpException(404, 'Office ist in dieser Installation nicht aktiviert.');
        }

        $ssoUser = Container::sso()->resolve($request);
        if ($ssoUser === null) {
            throw new HttpException(403, 'Office-Apps stehen nur angemeldeten Benutzern zur Verfügung.');
        }

        // Kachel-Berechtigungen (Benutzer/AD-Gruppen) auch beim Direktaufruf beachten.
        $tile = Container::navigationRepository()->findActiveInternalByUrl(self::ENTRY_PATH);
        if ($tile !== null && !Container::navigation()->isAccessible((int) $tile['id'], $ssoUser)) {
            throw new HttpException(403, 'Für Office fehlt die Berechtigung.');
        }

        return $ssoUser;
    }

    /**
     * @param list<array<string,mixed>> $apps
     */
    private function hasNextcloudApp(array $apps): bool
    {
        foreach ($apps as $app) {
            if (empty($app['external'])) {
                return true;
            }
        }

        return false;
    }

    private function enterNextcloud(string $target): Response
    {
        Session::put(self::SESSION_KEY, time());

        if (!Container::officeHealth()->publicSummary()['available']) {
            return $this->redirect('/office-nicht-verfuegbar');
        }

        return $this->redirect($target);
    }

    public static function hasEntered(int $lifetime): bool
    {
        $entered = (int) Session::get(self::SESSION_KEY, 0);

        return $entered > 0 && time() - $entered <= $lifetime;
    }

    private function footerAssetVersion(): string
    {
        $css = BASE_PATH . '/public/assets/css/office-footer.css';
        $js = BASE_PATH . '/public/assets/js/office-footer.js';

        return (string) max(is_file($css) ? (int) filemtime($css) : 1, is_file($js) ? (int) filemtime($js) : 1);
    }
}
