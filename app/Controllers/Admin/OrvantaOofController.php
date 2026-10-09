<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Services\Office\OfficeAppService;
use App\Services\Orvanta\OrvantaOofService;
use App\Services\Orvanta\OrvantaSignatureService;

/**
 * Adminbereich "Office → Orvanta → Abwesenheit": Vorlagen fuer
 * Abwesenheitsnotizen anlegen, bearbeiten, loeschen und in der Vorschau
 * pruefen. Die Zuordnung zu Mitarbeitern erfolgt ueber AD-Gruppen.
 *
 *   GET  /admin/office/abwesenheit                 Liste
 *   GET  /admin/office/abwesenheit/vorlage?id=     Formular (0 = neu)
 *   POST /admin/office/abwesenheit/vorlage         Speichern
 *   POST /admin/office/abwesenheit/loeschen        Loeschen
 *   GET  /admin/office/abwesenheit/vorschau?id=    Vorschau (iframe, eigene CSP)
 */
final class OrvantaOofController extends AdminController
{
    private const BASE = '/admin/office/abwesenheit';

    public function index(Request $request): Response
    {
        return $this->adminView('admin.orvanta-oof-templates', [
            'pageTitle' => 'Orvanta – Abwesenheitsnotizen',
            'activeNav' => 'office_oof',
            'templates' => Container::orvantaOof()->all(),
            'orvantaEnabled' => Container::orvantaConfig()->isEnabled(),
        ]);
    }

    public function edit(Request $request): Response
    {
        $id = $request->queryInt('id', 0);
        $template = OrvantaOofService::blank();
        if ($id > 0) {
            $template = Container::orvantaOof()->find($id);
            if ($template === null) {
                Session::flash('error', 'Die Abwesenheitsvorlage wurde nicht gefunden.');

                return $this->redirect(self::BASE);
            }
        }

        return $this->renderForm($template);
    }

    public function save(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        $input = [
            'name' => (string) $request->input('name', ''),
            'fixed_text' => (string) $request->input('fixed_text', ''),
            'example_text' => (string) $request->input('example_text', ''),
            'groups' => (string) $request->input('groups', ''),
            'sort_order' => (string) $request->input('sort_order', '1'),
            'active' => $request->input('active', '') !== '',
        ];

        try {
            $savedId = Container::orvantaOof()->save($id > 0 ? $id : null, $input);
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['id'])) {
                Session::flash('error', 'Die Abwesenheitsvorlage wurde nicht gefunden.');

                return $this->redirect(self::BASE);
            }
            Session::flash('error', 'Bitte prüfen Sie die Angaben zur Abwesenheitsvorlage.');

            return $this->renderForm([
                'id' => $id,
                'name' => $input['name'],
                'fixed_text' => $input['fixed_text'],
                'example_text' => $input['example_text'],
                'groups' => OfficeAppService::splitGroups($input['groups']),
                'sort_order' => max(1, (int) $input['sort_order']),
                'active' => $input['active'],
            ], $exception->errors(), 422);
        }

        app_logger()->info('Orvanta-Abwesenheitsvorlage gespeichert.', ['admin' => Container::auth()->username(), 'id' => $savedId]);
        Session::flash('success', 'Die Abwesenheitsvorlage wurde gespeichert.');

        return $this->redirect(self::BASE);
    }

    public function delete(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        Container::orvantaOof()->delete($id);
        app_logger()->info('Orvanta-Abwesenheitsvorlage gelöscht.', ['admin' => Container::auth()->username(), 'id' => $id]);
        Session::flash('success', 'Die Abwesenheitsvorlage wurde gelöscht.');

        return $this->redirect(self::BASE);
    }

    /**
     * Vorschau der fertigen Abwesenheitsnotiz als eigenstaendiges Dokument
     * fuer ein iframe. Als Signatur dient die erste Signaturvorlage mit
     * Beispieldaten; die Signatur verwendet Inline-Styles
     * (E-Mail-tauglich), deshalb erhaelt die Antwort eine eigene CSP.
     */
    public function preview(Request $request): Response
    {
        $service = Container::orvantaOof();
        $template = $service->find($request->queryInt('id', 0));
        if ($template === null) {
            // Ungespeicherte Formularwerte (Live-Vorschau) – nur geprueft, nie gespeichert.
            try {
                $template = $service->validate([
                    'name' => (string) ($request->query['name'] ?? 'Vorschau'),
                    'fixed_text' => (string) ($request->query['fixed_text'] ?? ''),
                    'example_text' => (string) ($request->query['example_text'] ?? ''),
                    'groups' => '',
                    'sort_order' => '1',
                    'active' => true,
                ]);
            } catch (ValidationException) {
                $template = OrvantaOofService::blank();
            }
        }
        $signatures = Container::orvantaSignatures();
        $sample = $signatures->all()[0] ?? null;
        $html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Vorschau</title>'
            . '<style>html,body{margin:0;background:#fff}body{padding:1rem}</style></head><body>'
            . $service->html($template, $template['example_text'], $sample === null ? '' : $signatures->preview($sample))
            . '</body></html>';

        return Response::html($html)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; frame-ancestors 'self'");
    }

    /**
     * @param array<string,mixed> $template
     * @param array<string,string> $errors
     */
    private function renderForm(array $template, array $errors = [], int $status = 200): Response
    {
        return $this->adminView('admin.orvanta-oof-template', [
            'pageTitle' => ((int) $template['id']) > 0 ? 'Abwesenheitsvorlage bearbeiten' : 'Neue Abwesenheitsvorlage',
            'activeNav' => 'office_oof',
            'oofTemplate' => $template,
            'errors' => $errors,
            'maxFixedText' => OrvantaOofService::MAX_FIXED_TEXT,
            'maxDynamicText' => OrvantaOofService::MAX_DYNAMIC_TEXT,
            'pageScript' => 'admin-group-autocomplete.js',
            'extraScripts' => ['admin-oof.js'],
        ], $status);
    }
}
