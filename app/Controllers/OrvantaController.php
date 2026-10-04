<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
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
 * (Adminbereich Office → Apps) und die aktivierte Exchange-Anbindung.
 */
final class OrvantaController extends Controller
{
    public const PATH = OfficeAppCatalog::ORVANTA_PATH;

    public function index(Request $request): Response
    {
        $access = self::authorize($request);
        $config = Container::orvantaConfig();
        $attachments = Container::orvantaAttachments();

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
                'defaultModule' => $config->get('default_folder'),
                'pollInterval' => $config->pollInterval(),
                'reminderLead' => $config->reminderLeadMinutes(),
                'officeAvailable' => $attachments->officeAvailable(),
                'nextcloudAvailable' => Container::nextcloudFiles()->unavailableReason() === null,
                'cacheFolder' => $config->cacheFolder(),
                'cacheQuota' => $config->cacheQuotaBytes(),
                'demo' => $config->isDemo(),
                'owaUrl' => $config->owaUrl(),
                // Nur das Flag - Modell, Adresse und Schluessel bleiben auf dem Server.
                'aiAvailable' => Container::orvantaAi()->isAvailable(),
                // Fest zugeordnete Signatur (nur Anzeige; angefuegt wird serverseitig).
                'signature' => Container::orvantaSignatures()->forUser($access['user']),
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
     * (AD-Gruppen wie Euro-Office-Apps) und Exchange-Anbindung aktiv.
     *
     * @return array{user:array<string,mixed>,uid:string,impersonate:string}
     */
    public static function authorize(Request $request): array
    {
        $ssoUser = Container::sso()->resolve($request);
        if ($ssoUser === null) {
            throw new HttpException(403, 'Orvanta steht nur angemeldeten Benutzern zur Verfügung.');
        }
        if (!Container::orvantaConfig()->isEnabled()) {
            throw new HttpException(404, 'Die Exchange-Anbindung (Orvanta) ist in dieser Installation nicht aktiviert.');
        }
        if (Container::officeApps()->findAllowed('orvanta', $ssoUser) === null) {
            throw new HttpException(403, 'Orvanta ist für Ihr Konto nicht freigegeben. Bitte wenden Sie sich an die Administration.');
        }
        $tile = Container::navigationRepository()->findActiveInternalByUrl(OfficeController::ENTRY_PATH);
        if ($tile !== null && !Container::navigation()->isAccessible((int) $tile['id'], $ssoUser)) {
            throw new HttpException(403, 'Für Office fehlt die Berechtigung.');
        }
        $impersonate = Container::orvantaConfig()->impersonationAddress($ssoUser);
        if ($impersonate === '' && !Container::orvantaConfig()->isDemo()) {
            throw new HttpException(403, 'Für Ihr Konto ist im Active Directory keine E-Mail-Adresse hinterlegt. Orvanta kann Ihr Postfach nicht zuordnen.');
        }

        return [
            'user' => $ssoUser,
            'uid' => (string) ($ssoUser['office_uid'] ?? $ssoUser['username']),
            'impersonate' => $impersonate !== '' ? $impersonate : (string) $ssoUser['username'] . '@demo.local',
        ];
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
