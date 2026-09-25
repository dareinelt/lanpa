<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Security\Session;
use Throwable;

/**
 * Oeffentliche Endpunkte der Office-Integration.
 *
 *   GET /office-starten           Intranet-Einstieg (Kachel) -> /office/...
 *   GET /office-nicht-verfuegbar  Hinweisseite (auch Fehlerseite des auth-Proxys)
 *   GET /api/office/footer        Konfiguration der Fusszeile in Nextcloud
 *   GET /api/office/status        Verfuegbarkeit fuer die Kachelanzeige
 */
final class OfficeController extends Controller
{
    public const ENTRY_PATH = '/office-starten';
    public const SESSION_KEY = 'office_entered_at';

    public function start(Request $request): Response
    {
        $office = Container::officeConfig();
        if (!$office->isEnabled()) {
            throw new HttpException(404, 'Office ist in dieser Installation nicht aktiviert.');
        }

        // Kachel-Berechtigungen (Benutzer/AD-Gruppen) auch beim Direktaufruf
        // des Einstiegs beachten.
        $tile = Container::navigationRepository()->findActiveInternalByUrl(self::ENTRY_PATH);
        if ($tile !== null) {
            $ssoUser = Container::sso()->resolve($request);
            if (!Container::navigation()->isAccessible((int) $tile['id'], $ssoUser)) {
                throw new HttpException(403, 'Für Office fehlt die Berechtigung.');
            }
        }

        Session::put(self::SESSION_KEY, time());

        if (!Container::officeHealth()->publicSummary()['available']) {
            return $this->redirect('/office-nicht-verfuegbar');
        }

        return $this->redirect($office->entryTarget($request->query('ziel')));
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
