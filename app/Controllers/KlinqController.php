<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Security\Session;
use App\Security\SsoAuth;
use App\Services\Klinq\KlinqConfigService;
use App\Support\ClientAddress;
use Throwable;

/**
 * Einstieg in KLINQ ueber den auth-Container.
 *
 *   GET /klinq-starten   KLINQ-Kachel: signierter Einstieg in die Anwendung
 *   GET /stoerung        Schnellbericht (Help-Desk-Ticket), ohne Gruppenzwang
 *   GET /klinq-nicht-verfuegbar   Hinweisseite (auch Fehlerseite des auth-Proxys)
 *
 * Die Windows-Anmeldung und die SSO-Sitzung kommen aus dem auth-Container.
 * Das Intranet stellt daraus einen kurzlebigen, signierten Einstiegsschein aus
 * und uebergibt ihn an KLINQ; dort wird die Sitzung ohne Kennworteingabe
 * erzeugt. Kontaktdaten fuer das Help-Desk-Ticket werden mitgegeben.
 */
final class KlinqController extends Controller
{
    public const ENTRY_PATH = KlinqConfigService::ENTRY_PATH;
    public const REPORT_PATH = KlinqConfigService::REPORT_PATH;
    public const SESSION_KEY = 'klinq_entered_at';

    /** Gewaehltes Design des Intranets (siehe public/assets/js/app.js). */
    public const THEME_COOKIE = 'intranet_theme';

    private const DEFAULT_TARGET = '/dashboard';
    private const REPORT_TARGET = '/stoerung';

    /**
     * KLINQ-Kachel: Weiterleitung in die Anwendung.
     */
    public function start(Request $request): Response
    {
        return $this->enter($request, $this->requestedTarget($request), true);
    }

    /**
     * Störmeldung IT: fuer jede ueber SSO angemeldete Person freigegeben.
     */
    public function report(Request $request): Response
    {
        return $this->enter($request, self::REPORT_TARGET, false);
    }

    /**
     * Designfarben des Intranets fuer KLINQ (CSS-Variablen).
     *
     * Oeffentlich und ohne Anmeldung, da nur Farbwerte enthalten sind; wird
     * von KLINQ als zusaetzliches Stylesheet eingebunden. Das gewaehlte
     * Design (hell, dunkel, automatisch) kommt als Cookie mit, weil KLINQ ein
     * eigenes Dokument ohne data-theme ist. Ohne Cookie gilt das Standarddesign
     * des Intranets (hell). Die Antwort wird nicht zwischengespeichert, damit
     * ein Wechsel sofort greift.
     */
    public function design(Request $request): Response
    {
        $mode = (string) ($request->cookie(self::THEME_COOKIE) ?? 'light');
        $css = Container::klinqTheme()->css($mode);

        return Response::html($css, 200)
            ->withHeader('Content-Type', 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Vary', 'Cookie')
            ->withHeader('Access-Control-Allow-Origin', '*');
    }

    public function unavailable(Request $request): Response
    {
        try {
            $response = $this->view('klinq.unavailable', [
                'pageTitle' => 'KLINQ nicht verfügbar',
                'activeNav' => '',
            ], 'layouts.minimal', 503);
        } catch (Throwable) {
            $response = Response::html(
                '<!doctype html><html lang="de"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>KLINQ nicht verfügbar</title></head><body style="font-family:system-ui,sans-serif;padding:2rem">'
                . '<h1>KLINQ ist derzeit nicht verfügbar</h1>'
                . '<p>Bitte versuchen Sie es in einigen Minuten erneut.</p>'
                . '<p><a href="/">Zur Startseite</a></p></body></html>',
                503
            );
        }

        return $response
            ->withHeader('Retry-After', '60')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Gemeinsamer Einstieg: Anmeldung pruefen, Berechtigung pruefen, Schein
     * ausstellen und an KLINQ uebergeben.
     */
    private function enter(Request $request, string $target, bool $requireTile): Response
    {
        $config = Container::klinqConfig();

        if (!$config->isEnabled()) {
            throw new HttpException(404, 'KLINQ ist in dieser Installation nicht aktiviert.');
        }

        if ($target === self::REPORT_TARGET && !$config->quickReportOpen()) {
            throw new HttpException(404, 'Die Störmeldung ist derzeit nicht freigegeben.');
        }

        $ssoUser = Container::sso()->resolve($request);
        if ($ssoUser === null) {
            $login = $requireTile ? self::ENTRY_PATH : self::REPORT_PATH;

            return $this->redirect(SsoAuth::loginUrl($login))
                ->withHeader('Cache-Control', 'no-store');
        }

        if (!$config->hasSecret()) {
            app_logger()->warning('KLINQ-SSO: KLINQ_SSO_SECRET ist nicht gesetzt.');

            return $this->redirect('/klinq-nicht-verfuegbar');
        }

        if ($requireTile) {
            $this->assertTileAccess($ssoUser);
        }

        if (!Container::klinqHealth()->check(false)['available']) {
            return $this->redirect('/klinq-nicht-verfuegbar');
        }

        $access = Container::klinqAccess()->resolve($ssoUser);
        $contact = Container::klinqIdentity()->contact($ssoUser);
        $client = ClientAddress::from($request);

        $token = Container::klinqTokens()->issue([
            'sub' => $contact['username'],
            'name' => $contact['display_name'],
            'email' => $contact['email'],
            'first_name' => $contact['first_name'],
            'last_name' => $contact['last_name'],
            'phone' => $contact['phone'],
            'mobile' => $contact['mobile'],
            'department' => $contact['department'],
            'title' => $contact['title'],
            'ip' => $client['ip'],
            'host' => $client['host'],
            'role' => $access['role'],
            'permission_groups' => $access['permission_groups'],
            'areas' => $access['areas'],
            'report_only' => $target === self::REPORT_TARGET,
        ], $config->entryLifetime());

        Session::put(self::SESSION_KEY, time());

        app_logger()->info('KLINQ betreten.', [
            'user' => $contact['username'],
            'ziel' => $target,
            'rolle' => $access['role'],
        ]);

        $url = $config->entryUrl() . '?' . http_build_query([
            'token' => $token,
            'next' => $target,
        ]);

        return $this->redirect($url)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * Kachel-Berechtigung (Benutzer/AD-Gruppen) auch beim Direktaufruf beachten.
     *
     * @param array<string,mixed> $ssoUser
     */
    private function assertTileAccess(array $ssoUser): void
    {
        $tile = Container::navigationRepository()->findActiveInternalByUrl(self::ENTRY_PATH);
        if ($tile === null) {
            return;
        }

        if (!Container::navigation()->isAccessible((int) $tile['id'], $ssoUser)) {
            throw new HttpException(403, 'Für KLINQ fehlt die Freigabe.');
        }
    }

    /**
     * Rücksprungziel aus ?ziel=… bzw. ?next=…; nur lokale KLINQ-Pfade.
     */
    private function requestedTarget(Request $request): string
    {
        $target = (string) ($request->query('ziel') ?? $request->query('next') ?? '');

        return self::safeTarget($target);
    }

    /**
     * Erlaubt ausschliesslich einfache Pfade innerhalb von KLINQ.
     */
    public static function safeTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '' || $target[0] !== '/' || strlen($target) > 512) {
            return self::DEFAULT_TARGET;
        }

        if (str_starts_with($target, '//') || str_contains($target, '\\') || str_contains($target, '..')) {
            return self::DEFAULT_TARGET;
        }

        if (preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/?\-]*$#', $target) !== 1) {
            return self::DEFAULT_TARGET;
        }

        foreach (['/sso', '/login', '/logout'] as $reserved) {
            if ($target === $reserved || str_starts_with($target, $reserved . '/') || str_starts_with($target, $reserved . '?')) {
                return self::DEFAULT_TARGET;
            }
        }

        return $target;
    }

    public static function hasEntered(int $lifetime): bool
    {
        $entered = (int) Session::get(self::SESSION_KEY, 0);

        return $entered > 0 && time() - $entered <= $lifetime;
    }
}
