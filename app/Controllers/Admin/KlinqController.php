<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\KlinqController as PublicKlinqController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Services\Klinq\KlinqAiService;
use App\Services\Klinq\KlinqConfigService;

/**
 * Adminbereich "KLINQ": Status/Diagnose, AD-Gruppen-Freigaben und Kachel.
 *
 * Jeder Bereich ist eine eigene Unterseite (siehe PAGES) des
 * Navigationspunkts "KLINQ".
 */
final class KlinqController extends AdminController
{
    /**
     * Unterseiten: Abschnitt => [Pfad, activeNav-Schluessel, Seitentitel].
     *
     * @var array<string,array{0:string,1:string,2:string}>
     */
    public const PAGES = [
        'status' => ['/admin/klinq', 'klinq', 'KLINQ – Status & Diagnose'],
        'access' => ['/admin/klinq/berechtigungen', 'klinq_access', 'KLINQ – Berechtigungen'],
        'ai' => ['/admin/klinq/ki', 'klinq_ai', 'KLINQ – KI-Endpunkt'],
        'tile' => ['/admin/klinq/kachel', 'klinq_tile', 'KLINQ – Kachel im Intranet'],
    ];

    public function index(Request $request): Response
    {
        return $this->render('status');
    }

    public function showAccess(Request $request): Response
    {
        return $this->render('access');
    }

    public function showAi(Request $request): Response
    {
        return $this->render('ai');
    }

    public function showTile(Request $request): Response
    {
        return $this->render('tile');
    }

    /**
     * Prueft die Erreichbarkeit erneut (umgeht den Zwischenspeicher).
     */
    public function refreshHealth(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $health = Container::klinqHealth()->check(true);
        Session::flash(
            $health['available'] ? 'success' : 'error',
            $health['available'] ? 'KLINQ ist erreichbar.' : 'KLINQ ist nicht erreichbar: ' . $health['message']
        );

        return $this->redirect(self::PAGES['status'][0]);
    }

    public function updateAccess(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $config = Container::klinqConfig();
        $result = $config->validate($request->post);
        if ($result['errors'] !== []) {
            Session::flash('error', 'Bitte prüfen Sie die Berechtigungen.');

            return $this->render('access', $result['errors'], $result['values'], 422);
        }

        Container::settings()->update($result['values']);
        app_logger()->info('KLINQ-Berechtigungen geändert.', ['admin' => Container::auth()->username()]);
        Session::flash('success', 'Die KLINQ-Berechtigungen wurden gespeichert.');

        return $this->redirect(self::PAGES['access'][0]);
    }

    /**
     * Legt die KLINQ-Kachel auf der Startseite an. Die Sichtbarkeit wird
     * anschliessend ueber die Kachel-Berechtigungen (AD-Gruppen) gesteuert.
     */
    public function createTile(Request $request): Response
    {
        $this->requireValidCsrf($request);

        if (Container::navigationRepository()->findActiveInternalByUrl(PublicKlinqController::ENTRY_PATH) !== null) {
            Session::flash('error', 'Eine KLINQ-Kachel ist bereits vorhanden.');

            return $this->redirect(self::PAGES['tile'][0]);
        }

        try {
            $id = Container::navigation()->create([
                'title' => 'KLINQ',
                'type' => 'internal',
                'url' => PublicKlinqController::ENTRY_PATH,
                'icon' => 'wrench',
                'short_description' => 'Help Desk, Asset-Management und Statistiken',
                'description' => '',
                'active' => '1',
            ]);
        } catch (ValidationException $exception) {
            Session::flash('error', 'Die Kachel konnte nicht angelegt werden: ' . implode(' ', $exception->errors()));

            return $this->redirect(self::PAGES['tile'][0]);
        }

        app_logger()->info('KLINQ-Kachel angelegt.', ['admin' => Container::auth()->username(), 'id' => $id]);
        Session::flash('success', 'Die KLINQ-Kachel wurde angelegt. Legen Sie nun die AD-Gruppen unter „Berechtigungen“ fest.');

        return $this->redirect(self::PAGES['access'][0]);
    }

    /**
     * Speichert die Gestaltung der KLINQ-Kachel (Typ und Ziel bleiben fest).
     */
    public function updateTile(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $tile = $this->klinqTile();
        if ($tile === null) {
            Session::flash('error', 'Es ist keine KLINQ-Kachel vorhanden.');

            return $this->redirect(self::PAGES['tile'][0]);
        }

        $merged = $tile;
        foreach (['title', 'short_description', 'description', 'icon'] as $key) {
            if (isset($request->post[$key]) && is_string($request->post[$key])) {
                $merged[$key] = $request->post[$key];
            }
        }
        $merged['override_background'] = !empty($request->post['override_background']) ? '1' : '';
        foreach (['background_color', 'background_opacity'] as $key) {
            if (array_key_exists($key, $request->post) && is_string($request->post[$key])) {
                $merged[$key] = $request->post[$key];
            }
        }
        $merged['active'] = !empty($tile['active']) ? '1' : '';
        $merged['protected_access'] = !empty($tile['protected_access']) ? '1' : '';
        $merged['open_in_new_tab'] = !empty($request->post['open_in_new_tab']) ? '1' : '';

        try {
            $validated = Container::navigation()->validate($merged, false, (int) $tile['id']);
        } catch (ValidationException $exception) {
            Session::flash('error', 'Die Kachel konnte nicht gespeichert werden: ' . implode(' ', $exception->errors()));

            return $this->render('tile', $exception->errors(), $merged, 422);
        }

        Container::navigationRepository()->update((int) $tile['id'], $validated);
        app_logger()->info('KLINQ-Kachel gestaltet.', ['admin' => Container::auth()->username(), 'id' => (int) $tile['id']]);
        Session::flash('success', 'Die KLINQ-Kachel wurde gespeichert.');

        return $this->redirect(self::PAGES['tile'][0]);
    }

    /**
     * Speichert den KI-Endpunkt der KLINQ-Integration. Nach dem Speichern wird
     * der Endpunkt geprueft; ein Fehler dabei verhindert das Speichern nicht.
     */
    public function updateAi(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $result = KlinqAiService::validate($request->post);
        if ($result['errors'] !== []) {
            Session::flash('error', 'Bitte prüfen Sie die KI-Einstellungen.');

            return $this->render('ai', [], [], 422, $result['errors'], $result['values']);
        }

        Container::settings()->update($result['values']);
        app_logger()->info('KLINQ-KI-Einstellungen geändert.', [
            'admin' => Container::auth()->username(),
            'enabled' => $result['values']['klinq_ai_enabled'],
            'model' => $result['values']['klinq_ai_model'],
            'api_key_changed' => array_key_exists('klinq_ai_api_key', $result['values']),
        ]);

        $ai = Container::klinqAi();
        $messages = ['Die KI-Einstellungen für KLINQ wurden gespeichert.'];
        $ok = true;
        if ($ai->isActive()) {
            $endpoint = $ai->testEndpoint();
            $messages[] = $endpoint['message'];
            $ok = $endpoint['ok'];
        } else {
            $messages[] = 'Die KI ist für KLINQ nicht aktiv; KLINQ arbeitet ohne KI-Unterstützung.';
        }

        Session::flash($ok ? 'success' : 'error', implode(' ', $messages));

        return $this->redirect(self::PAGES['ai'][0]);
    }

    /**
     * Verbindungstest des KLINQ-KI-Endpunkts mit den aktuellen Formularwerten
     * (ohne Speichern): /models und Testnachricht. Antwort als JSON.
     */
    public function testAi(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $maxTimeout = 120;
        @set_time_limit($maxTimeout + 30);
        $result = Container::klinqAi()->runTest($request->post, $maxTimeout);
        app_logger()->info('KLINQ-KI-Verbindungstest ausgeführt.', [
            'admin' => Container::auth()->username(),
            'ok' => $result['ok'],
            'model' => $result['model'],
            'duration_ms' => $result['duration_ms'],
        ]);

        return Response::json($result, $result['errors'] !== [] ? 422 : 200);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function klinqTile(): ?array
    {
        return Container::navigationRepository()->findActiveInternalByUrl(PublicKlinqController::ENTRY_PATH);
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $values
     * @param array<string,string> $aiErrors
     * @param array<string,string> $aiValues
     */
    private function render(
        string $section,
        array $errors = [],
        array $values = [],
        int $status = 200,
        array $aiErrors = [],
        array $aiValues = []
    ): Response {
        $config = Container::klinqConfig();
        $tile = $this->klinqTile();
        $ai = Container::klinqAi();

        [, $activeNav, $pageTitle] = self::PAGES[$section];

        return $this->adminView('admin.klinq', [
            'pageTitle' => $pageTitle,
            'activeNav' => $activeNav,
            'section' => $section,
            'enabled' => $config->isEnabled(),
            'publicUrl' => $config->publicUrl(),
            'publicHost' => $config->publicHost(),
            'upstream' => $config->upstream(),
            'secretConfigured' => $config->hasSecret(),
            'health' => $config->isEnabled() ? Container::klinqHealth()->check(false) : null,
            'groupKeys' => KlinqConfigService::GROUP_KEYS,
            'roleLabels' => KlinqConfigService::ROLE_LABELS,
            'areas' => KlinqConfigService::AREA_LABELS,
            'defaultRole' => $config->defaultRole(),
            'quickReportOpen' => $config->quickReportOpen(),
            'groups' => $config->allGroups(),
            'values' => $values,
            'errors' => $errors,
            'aiErrors' => $aiErrors,
            'aiValues' => $aiValues !== [] ? array_diff_key($aiValues, ['klinq_ai_api_key' => true]) : $ai->formValues(),
            'aiConfigured' => $ai->isConfigured(),
            'aiActive' => $ai->isActive(),
            'aiKeyFromSecret' => $ai->apiKeyFromSecret(),
            'aiHasKey' => $ai->hasApiKey(),
            'tile' => $tile,
            'tileIcons' => OfficeController::TILE_ICONS,
            'exampleAccess' => $config->resolve([]),
            'entryPath' => PublicKlinqController::ENTRY_PATH,
            'reportPath' => PublicKlinqController::REPORT_PATH,
            'pages' => self::PAGES,
        ], $status);
    }
}
