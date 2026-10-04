<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Services\Office\OfficeAppService;
use App\Services\Orvanta\OrvantaSignatureService;

/**
 * Adminbereich "Office → Orvanta → Signaturen": Signaturvorlagen anlegen,
 * bearbeiten, loeschen und in der Vorschau pruefen. Die Zuordnung zu
 * Mitarbeitern erfolgt ueber AD-Gruppen.
 *
 *   GET  /admin/office/signaturen                 Liste
 *   GET  /admin/office/signaturen/vorlage?id=     Formular (0 = neu)
 *   POST /admin/office/signaturen/vorlage         Speichern
 *   POST /admin/office/signaturen/loeschen        Loeschen
 *   GET  /admin/office/signaturen/vorschau?id=    Vorschau (iframe, eigene CSP)
 */
final class OrvantaSignatureController extends AdminController
{
    private const BASE = '/admin/office/signaturen';

    public function index(Request $request): Response
    {
        $service = Container::orvantaSignatures();
        $theme = Container::settings()->theme();

        return $this->adminView('admin.orvanta-signatures', [
            'pageTitle' => 'Orvanta – Signaturvorlagen',
            'activeNav' => 'office',
            'signatures' => $service->all(),
            'hasLogo' => Container::logo()->current() !== null,
            'textColor' => $theme['color_text'],
            'accentColor' => $theme['color_accent'],
            'orvantaEnabled' => Container::orvantaConfig()->isEnabled(),
        ]);
    }

    public function edit(Request $request): Response
    {
        $id = $request->queryInt('id', 0);
        $signature = OrvantaSignatureService::blank();
        if ($id > 0) {
            $signature = Container::orvantaSignatures()->find($id);
            if ($signature === null) {
                Session::flash('error', 'Die Signaturvorlage wurde nicht gefunden.');

                return $this->redirect(self::BASE);
            }
        }

        return $this->renderForm($signature);
    }

    public function save(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        $input = [
            'name' => (string) $request->input('name', ''),
            'greeting' => (string) $request->input('greeting', ''),
            'street' => (string) $request->input('street', ''),
            'postal_city' => (string) $request->input('postal_city', ''),
            'phone_mode' => (string) $request->input('phone_mode', 'prefix'),
            'phone_prefix' => (string) $request->input('phone_prefix', ''),
            'groups' => (string) $request->input('groups', ''),
            'sort_order' => (string) $request->input('sort_order', '1'),
            'active' => $request->input('active', '') !== '',
        ];

        try {
            $savedId = Container::orvantaSignatures()->save($id > 0 ? $id : null, $input);
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['id'])) {
                Session::flash('error', 'Die Signaturvorlage wurde nicht gefunden.');

                return $this->redirect(self::BASE);
            }
            Session::flash('error', 'Bitte prüfen Sie die Angaben zur Signaturvorlage.');

            return $this->renderForm([
                'id' => $id,
                'name' => $input['name'],
                'greeting' => $input['greeting'],
                'street' => $input['street'],
                'postal_city' => $input['postal_city'],
                'phone_mode' => in_array($input['phone_mode'], OrvantaSignatureService::PHONE_MODES, true) ? $input['phone_mode'] : 'prefix',
                'phone_prefix' => $input['phone_prefix'],
                'groups' => OfficeAppService::splitGroups($input['groups']),
                'sort_order' => max(1, (int) $input['sort_order']),
                'active' => $input['active'],
            ], $exception->errors(), 422);
        }

        app_logger()->info('Orvanta-Signaturvorlage gespeichert.', ['admin' => Container::auth()->username(), 'id' => $savedId]);
        Session::flash('success', 'Die Signaturvorlage wurde gespeichert.');

        return $this->redirect(self::BASE);
    }

    public function delete(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        Container::orvantaSignatures()->delete($id);
        app_logger()->info('Orvanta-Signaturvorlage gelöscht.', ['admin' => Container::auth()->username(), 'id' => $id]);
        Session::flash('success', 'Die Signaturvorlage wurde gelöscht.');

        return $this->redirect(self::BASE);
    }

    /**
     * Vorschau mit Beispieldaten als eigenstaendiges Dokument fuer ein iframe.
     * Die Signatur verwendet Inline-Styles (E-Mail-tauglich), deshalb erhaelt
     * die Antwort eine eigene, dafuer geoeffnete CSP.
     */
    public function preview(Request $request): Response
    {
        $service = Container::orvantaSignatures();
        $signature = $service->find($request->queryInt('id', 0));
        if ($signature === null) {
            // Ungespeicherte Formularwerte (Live-Vorschau) – nur geprueft, nie gespeichert.
            try {
                $signature = $service->validate([
                    'name' => (string) ($request->query['name'] ?? 'Vorschau'),
                    'greeting' => (string) ($request->query['greeting'] ?? ''),
                    'street' => (string) ($request->query['street'] ?? ''),
                    'postal_city' => (string) ($request->query['postal_city'] ?? ''),
                    'phone_mode' => (string) ($request->query['phone_mode'] ?? 'prefix'),
                    'phone_prefix' => (string) ($request->query['phone_prefix'] ?? ''),
                    'groups' => '',
                    'sort_order' => '1',
                    'active' => true,
                ]);
            } catch (ValidationException) {
                $signature = OrvantaSignatureService::blank();
            }
        }
        $html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Vorschau</title>'
            . '<style>html,body{margin:0;background:#fff}body{padding:1rem}</style></head><body>'
            . $service->preview($signature)
            . '</body></html>';

        return Response::html($html)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; frame-ancestors 'self'");
    }

    /**
     * @param array<string,mixed> $signature
     * @param array<string,string> $errors
     */
    private function renderForm(array $signature, array $errors = [], int $status = 200): Response
    {
        return $this->adminView('admin.orvanta-signature', [
            'pageTitle' => ((int) $signature['id']) > 0 ? 'Signaturvorlage bearbeiten' : 'Neue Signaturvorlage',
            'activeNav' => 'office',
            'signature' => $signature,
            'errors' => $errors,
            'pageScript' => 'admin-group-autocomplete.js',
            'extraScripts' => ['admin-signature.js'],
        ], $status);
    }
}
