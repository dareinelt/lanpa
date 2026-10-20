<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Security\CurrentUser;
use App\Services\Helpdesk\AiSettingsService;
use App\Services\Helpdesk\HelpdeskAiService;

/**
 * Administration der KI-Unterstützung im Help Desk
 * („Auswertung & System → Administration → Help-Desk-KI“).
 *
 * Hier werden Endpunkt, Modell und API-Schlüssel gepflegt, die Verbindung
 * geprüft (Testfrage samt Antwort) und die Modellliste des Endpunkts ermittelt.
 */
final class HelpdeskAiController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly AiSettingsService $settings,
        private readonly HelpdeskAiService $ai
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $this->currentUser->require('settings.manage');

        return $this->renderForm();
    }

    public function save(Request $request): Response
    {
        $this->currentUser->require('settings.manage');
        if ($this->settings->managedByIntranet()) {
            $this->flash('info', 'Die KI-Einstellungen werden im Intranet gepflegt.');

            return $this->redirect('/admin/helpdesk-ai');
        }
        try {
            $this->settings->save($request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());
            $this->flash('error', 'Bitte Eingaben prüfen.');

            return $this->redirect('/admin/helpdesk-ai');
        }
        // Erreichbarkeits-Cache der Ticketfelder verwerfen – die Änderung soll sofort greifen.
        $this->ai->resetAvailability();
        $this->flash('success', 'Einstellungen der KI-Unterstützung gespeichert.');

        return $this->redirect('/admin/helpdesk-ai');
    }

    /** Verbindung, Modell und Testfrage prüfen (JSON für die Seite). */
    public function test(Request $request): Response
    {
        $this->currentUser->require('settings.manage');
        try {
            $result = $this->settings->runTest($request->all());
        } catch (ValidationException $e) {
            return $this->json(['ok' => false, 'errors' => $e->errors(), 'message' => $e->getMessage()], 422);
        }

        return $this->json($result + ['message' => $this->summarize($result)]);
    }

    /** Modelle des Endpunkts ermitteln (Schaltfläche „Modell ermitteln“). */
    public function models(Request $request): Response
    {
        $this->currentUser->require('settings.manage');
        $url = trim($request->string('url'));
        $apiKey = trim($request->string('api_key'));
        if ($apiKey === '' && rtrim($url, '/') === $this->settings->url()) {
            $apiKey = $this->settings->apiKey();
        }
        $result = $this->settings->fetchModels($url, $apiKey);

        return $this->json([
            'ok' => $result['ok'],
            'models' => $result['models'],
            'message' => $result['message'],
            'selected' => $this->settings->model(),
        ], $result['ok'] ? 200 : 422);
    }

    /** @param array<string,mixed> $result */
    private function summarize(array $result): string
    {
        if (($result['ok'] ?? false) === true) {
            return 'Die KI hat geantwortet.';
        }
        $failed = [];
        foreach ((array) ($result['steps'] ?? []) as $step) {
            if (($step['ok'] ?? false) !== true) {
                $failed[] = (string) ($step['message'] ?? '');
                break;
            }
        }

        return $failed === [] ? 'Der Test ist fehlgeschlagen.' : (string) $failed[0];
    }

    private function renderForm(): Response
    {
        return $this->render('admin.helpdesk_ai', [
            'title' => 'Help-Desk-KI',
            'activeNav' => 'admin',
            'areaLabel' => 'Administration',
            'settings' => $this->settings->all(),
            'defaultTestPrompt' => AiSettingsService::DEFAULT_TEST_PROMPT,
            'maxPromptLength' => HelpdeskAiService::MAX_PROMPT,
        ]);
    }
}
