<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Contracts\OrvantaMailBackendInterface;
use App\Exceptions\HttpException;
use App\Services\MailProxy\MailProxyRoute;
use App\Services\Office\OfficeAppCatalog;
use App\Services\Orvanta\OrvantaAttachmentService;
use App\Services\Orvanta\OrvantaException;
use App\Support\Html;

/**
 * Orvanta – Mail- und Kalender-App (Exchange On-Premise via EWS).
 *
 *   GET /office/orvanta                    Hauptansicht (Vollbild, Editor-Layout)
 *   GET /office/orvanta/anhang/oeffnen     Anhang in Euro-Office (neuer Tab), Bild/PDF
 *                                          im Browser oder als Download (?token=…)
 *   GET /office/orvanta/anhang/datei       Rohdatei fuer den DocumentServer (?token=…)
 *
 * Zugriff wie bei den uebrigen Office-Apps ueber die AD-Gruppenfreigabe
 * (Adminbereich Office → Apps) und die aktivierte Exchange-Anbindung bzw.
 * eine Postfach-Zuordnung zum SMTP-/IMAP-Proxy (docs/mail-proxy.md).
 */
final class OrvantaController extends Controller
{
    public const PATH = OfficeAppCatalog::ORVANTA_PATH;

    public function index(Request $request): Response
    {
        $access = self::authorize($request);
        $config = Container::orvantaConfig();
        $attachments = Container::orvantaAttachments();
        $shared = Container::orvantaSharedMailboxes();
        if (!$access['route']->isProxy()) {
            // Abgelaufene Pruefungen der zusaetzlichen Postfaecher auffrischen,
            // damit der Ordnerbaum nur wirklich erreichbare Postfaecher zeigt.
            // Die Pruefung laeuft als der Benutzer (sein Postfach), damit
            // Exchange dessen Vollzugriff bestaetigt.
            $shared->refresh($access['user'], $access['primary']);
            $access['mailboxes'] = $shared->available($access['user']);
        }

        return $this->view('orvanta.index', [
            'pageTitle' => 'Mail & Kalender',
            'activeNav' => '',
            'pageScript' => 'orvanta.js',
            'titleSuffix' => 'Orvanta',
            'extraStyles' => ['orvanta.css'],
            'ssoUser' => $access['user'],
            'orvanta' => [
                'user' => [
                    'name' => (string) ($access['user']['display_name'] ?? $access['user']['username']),
                    'email' => $access['impersonate'],
                    'username' => (string) $access['user']['username'],
                ],
                // Mail-Backend (exchange|proxy) und verfuegbare Module; ohne
                // Server- oder Zugangsdaten.
                'backend' => $access['backend']->backendName(),
                'capabilities' => $access['backend']->capabilities(),
                'defaultModule' => $config->get('default_folder'),
                'pollInterval' => $config->pollInterval(),
                'reminderLead' => $config->reminderLeadMinutes(),
                'officeAvailable' => $attachments->officeAvailable(),
                'nextcloudAvailable' => Container::nextcloudFiles()->unavailableReason() === null,
                'cacheFolder' => $config->cacheFolder(),
                'cacheQuota' => $config->cacheQuotaBytes(),
                'demo' => $access['route']->isProxy() ? false : $config->isDemo(),
                'owaUrl' => $access['route']->isProxy() ? '' : $config->owaUrl(),
                // Aktueller Exchange-Host dieser Sitzung (Tooltipp an der
                // Verbindungsanzeige im Fussbereich).
                'exchangeHost' => self::exchangeHost($access),
                // Nur das Flag - Modell, Adresse und Schluessel bleiben auf dem Server.
                'aiAvailable' => Container::orvantaAi()->isAvailable(),
                // Nur das Flag - die Woerterbuchdateien bleiben auf dem Server.
                'spellcheckAvailable' => Container::orvantaSpellcheck()->isAvailable(),
                // Fest zugeordnete Signatur (nur Anzeige; angefuegt wird serverseitig).
                'signature' => Container::orvantaSignatures()->forUser($access['user']),
                // Zusaetzlich berechtigte Postfaecher (Vollzugriff / "Senden als")
                // und die gemerkte Kalender-Auswahl. Das eigene Postfach steht
                // immer an erster Stelle und ist nicht abwaehlbar.
                'mailboxes' => array_merge(
                    [['id' => 0, 'email' => $access['primary'], 'name' => '', 'send_as' => true]],
                    $access['mailboxes']
                ),
                'calendarVisible' => $access['route']->isProxy() ? [] : array_map('intval', $shared->calendarSelection($access['user'])['visible']),
                'primaryEmail' => $access['primary'],
            ],
        ], 'layouts.editor')->withHeader('Cache-Control', 'no-store')->withHeader('Vary', 'Cookie');
    }

    /**
     * Oeffnet einen Anhang ueber einen kurzlebigen signierten Link: Office-Dokumente
     * im Euro-Office-Editor (Lesemodus), Bilder/PDF direkt, sonst (und ohne
     * erreichbaren DocumentServer) als Download.
     */
    public function openAttachment(Request $request): Response
    {
        $access = self::authorize($request);
        $service = Container::orvantaAttachments();
        $claims = $service->verify((string) $request->query('token', ''));
        if ($claims === null || $claims['uid'] !== $access['uid']) {
            throw new HttpException(403, 'Der Link zum Anhang ist abgelaufen. Bitte den Anhang erneut in Orvanta öffnen.');
        }
        $mode = OrvantaAttachmentService::openMode($claims['name']);
        if ($mode === 'office' && $service->officeAvailable()) {
            $viewer = $service->viewerConfig($claims, $access['user'], (string) $request->query('token', ''));

            return $this->view('orvanta.viewer', [
                'pageTitle' => $claims['name'] . ' – Orvanta',
                'activeNav' => '',
                'pageScript' => 'orvanta-viewer.js',
                'titleSuffix' => '',
                'extraStyles' => ['orvanta.css'],
                'ssoUser' => $access['user'],
                'viewer' => $viewer,
                'attachment' => $claims,
                'token' => (string) $request->query('token', ''),
            ], 'layouts.editor')->withHeader('Cache-Control', 'no-store');
        }

        try {
            $file = $service->load($access['uid'], $claims['impersonate'], $claims['attachment_id']);
        } catch (OrvantaException $exception) {
            throw new HttpException($exception->status() >= 500 ? 502 : $exception->status(), $exception->getMessage());
        }
        if ($mode === 'browser' || ($mode === 'office' && OrvantaAttachmentService::browserCapable($claims['name']))) {
            $name = str_replace(["\r", "\n", '"'], '', $file['name']);

            return Response::html($file['content'])
                ->withHeader('Content-Type', self::inlineType($file['content_type'], $file['name']))
                ->withHeader('Content-Disposition', 'inline; filename="' . $name . '"')
                ->withHeader('Content-Length', (string) strlen($file['content']))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
        }

        return Response::download($file['content'], $file['name'], $file['content_type']);
    }

    /**
     * Rohdatei fuer den DocumentServer (Rueckruf im Docker-Netz, nur mit Token).
     */
    public function attachmentFile(Request $request): Response
    {
        $service = Container::orvantaAttachments();
        $claims = $service->verify((string) $request->query('token', ''));
        if ($claims === null) {
            throw new HttpException(403, 'Ungültiger oder abgelaufener Anhang-Link.');
        }
        try {
            $file = $service->load($claims['uid'], $claims['impersonate'], $claims['attachment_id']);
        } catch (OrvantaException $exception) {
            throw new HttpException(502, $exception->getMessage());
        }

        return Response::download($file['content'], $file['name'], $file['content_type'])->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Gemeinsame Pruefung: angemeldeter SSO-Benutzer, Orvanta freigegeben
     * (AD-Gruppen wie Euro-Office-Apps) und ein Mail-Backend verfuegbar:
     *
     *  - Benutzer mit aktiver Proxy-Zuordnung (Admin → Office → SMTP-/IMAP-
     *    Proxy): Postfach ueber IMAP/SMTP, keine Exchange-Anmeldung
     *  - Zuordnung auf deaktiviertes Postfach: kein Zugriff (kein Rueckfall)
     *  - sonst: Exchange-Anbindung (bestehendes Verhalten)
     *
     * @return array{user:array<string,mixed>,uid:string,impersonate:string,primary:string,mailboxes:list<array{id:int,email:string,name:string,send_as:bool}>,backend:OrvantaMailBackendInterface,route:MailProxyRoute}
     */
    public static function authorize(Request $request): array
    {
        $ssoUser = Container::sso()->resolve($request);
        if ($ssoUser === null) {
            throw new HttpException(403, 'Orvanta steht nur angemeldeten Benutzern zur Verfügung.');
        }
        $router = Container::orvantaMail();
        $route = $router->route($ssoUser);
        if ($route->state === MailProxyRoute::EXCHANGE && !Container::orvantaConfig()->isEnabled()) {
            throw new HttpException(404, 'Die Exchange-Anbindung (Orvanta) ist in dieser Installation nicht aktiviert.');
        }
        if (Container::officeApps()->findAllowed('orvanta', $ssoUser) === null) {
            throw new HttpException(403, 'Orvanta ist für Ihr Konto nicht freigegeben. Bitte wenden Sie sich an die Administration.');
        }
        $tile = Container::navigationRepository()->findActiveInternalByUrl(OfficeController::ENTRY_PATH);
        if ($tile !== null && !Container::navigation()->isAccessible((int) $tile['id'], $ssoUser)) {
            throw new HttpException(403, 'Für Office fehlt die Berechtigung.');
        }
        if ($route->isBlocked()) {
            throw new HttpException(403, 'Das Ihnen zugeordnete Postfach ist deaktiviert. Bitte wenden Sie sich an die Administration.');
        }
        $uid = (string) ($ssoUser['office_uid'] ?? $ssoUser['username']);
        if ($route->isProxy()) {
            // Der SMTP-/IMAP-Proxy kennt nur das eine zugeordnete Postfach.
            return ['user' => $ssoUser, 'uid' => $uid, 'impersonate' => $route->email, 'primary' => $route->email, 'mailboxes' => [], 'backend' => $router->backendForRoute($route), 'route' => $route];
        }
        $impersonate = Container::orvantaMailboxResolver()->address($ssoUser);
        if ($impersonate === '' && !Container::orvantaConfig()->isDemo()) {
            throw new HttpException(403, 'Für Ihr Konto ist im Active Directory keine E-Mail-Adresse hinterlegt. Orvanta kann Ihr Postfach nicht zuordnen.');
        }
        $primary = $impersonate !== '' ? $impersonate : (string) $ssoUser['username'] . '@demo.local';

        return [
            'user' => $ssoUser,
            'uid' => $uid,
            'impersonate' => $primary,
            'primary' => $primary,
            // Zusaetzlich berechtigte Postfaecher (Vollzugriff / "Senden als");
            // nur ueber Exchange erreichbar, der Proxy kennt sie nicht.
            'mailboxes' => Container::orvantaSharedMailboxes()->available($ssoUser),
            'backend' => $router->backendForRoute($route),
            'route' => $route,
        ];
    }

    /**
     * Hostname des Exchange-Hosts, auf dem die aktuelle Sitzung laeuft
     * (Tooltipp an der Verbindungsanzeige im Fussbereich). Leer, wenn kein
     * Exchange beteiligt ist: Proxy-Postfaecher (IMAP/SMTP) und Demomodus.
     *
     * @param array{route:MailProxyRoute,primary?:string} $access
     */
    public static function exchangeHost(array $access): string
    {
        if ($access['route']->isProxy() || Container::orvantaConfig()->isDemo()) {
            return '';
        }

        return (string) (Container::orvantaExchangePool()->currentHost((string) ($access['primary'] ?? ''))['host'] ?? '');
    }

    private static function inlineType(string $contentType, string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $safe = match ($ext) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml', 'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8',
            default => null,
        };

        return $safe ?? ($contentType !== '' ? Html::e($contentType) : 'application/octet-stream');
    }
}
