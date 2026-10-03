<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Admin\AdminController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Services\EmergencyPlanDefinition;
use App\Services\EmergencyPlanPreview;
use App\Services\EmergencyPlanService;

final class EmergencyPlanController extends AdminController
{
    private function access(Request $request, bool $managerOnly = false): array
    {
        $admin = str_starts_with($request->path, '/admin/');
        if ($admin || $managerOnly) {
            $auth = Container::auth();
            if (!$auth->check() || !EmergencyPlanService::isManager($auth->role())) {
                throw new HttpException(403, 'Nur Administratoren und das KAEP-Team haben Zugriff.');
            }
            // Auch bei lokaler Admin-Anmeldung die erkannte AD-Person verwenden:
            // Wechsel zwischen lokalem Konto und SSO ist kein zweites Augenpaar.
            $identity = Container::sso()->resolve($request);
            $actor = $identity !== null && empty($identity['fake']) ? EmergencyPlanService::actor($identity)
                : ($auth->isDirectoryUser() ? 'ad:' : 'local:') . mb_strtolower((string) $auth->username());

            return ['manager' => true, 'restricted' => false, 'actor' => $actor, 'base' => '/admin/notfallplan'];
        }
        $user = Container::sso()->resolve($request);
        $level = Container::emergencyPlans()->accessLevel($user);
        if ($level === null) {
            throw new HttpException(403, 'Notfallpläne sind nicht aktiviert oder Ihre Windows-Anmeldung ist nicht über die freigegebene AD-Gruppe berechtigt.');
        }

        // Auslösegruppe: nur auslösen und eigene laufende Ereignisse abarbeiten, keine Historie.
        return ['manager' => false, 'restricted' => $level === EmergencyPlanService::ACCESS_TRIGGER,
            'actor' => EmergencyPlanService::actor($user), 'base' => '/notfallplan', 'user' => $user,
            'sharedGroup' => Container::emergencyPlans()->triggerGroup($user)];
    }

    private function render(string $template, array $access, array $data, int $status = 200): Response
    {
        $data += $access + ['activeNav' => 'emergency_plan', 'pageScript' => 'emergency-plan.js'];
        $response = $access['manager'] ? $this->adminView($template, $data, $status) : $this->view($template, $data, 'layouts.base', $status);

        return $response->withHeader('Cache-Control', 'no-store');
    }

    public function index(Request $request): Response
    {
        $access = $this->access($request);
        $status = $access['restricted'] ? 'active' : $request->query('status', 'active');
        if (!in_array($status, ['', 'active', 'closed'], true)) {
            throw new HttpException(422, 'Ungültiger Ereignisstatus.');
        }
        $from = $access['restricted'] ? '' : $request->query('from', '');
        $to = $access['restricted'] ? '' : $request->query('to', '');
        foreach ([$from, $to] as $date) {
            $parsed = $date === '' ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || $parsed === false || $parsed->format('Y-m-d') !== $date)) {
                throw new HttpException(422, 'Ungültiges Datum.');
            }
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            throw new HttpException(422, 'Der Beginn muss vor dem Ende liegen.');
        }
        $page = max(1, min(100000, $request->queryInt('page', 1)));
        $service = Container::emergencyPlans();

        return $this->render('emergency.index', $access, [
            'pageTitle' => 'Notfallplan', 'plans' => $service->repository->plans(!$access['manager']),
            'events' => $service->repository->events($access['manager'] ? null : $access['actor'], $status, $from, $to, $page, $access['sharedGroup'] ?? null),
            'filter' => compact('status', 'from', 'to', 'page'),
            'enabled' => Container::settings()->bool('emergency_plan_enabled'),
            'group' => Container::settings()->get('emergency_plan_group'),
            'triggerGroup' => Container::settings()->get('emergency_plan_trigger_group'),
            'smtpEnabled' => Container::settings()->bool('smtp_enabled'),
            'canTransfer' => $access['manager'] && Container::auth()->isAdmin(),
        ]);
    }

    public function groups(Request $request): Response
    {
        $this->access($request, true);

        return Response::json(['items' => Container::adGroupRepository()->suggest(mb_substr((string) $request->query('q', ''), 0, 100))]);
    }

    public function settings(Request $request): Response
    {
        $access = $this->access($request, true);
        $this->requireValidCsrf($request);
        $group = trim((string) $request->input('group', ''), " \t,;");
        $triggerGroup = trim((string) $request->input('trigger_group', ''), " \t,;");
        $invalid = static fn (string $value): bool => mb_strlen($value) > 190 || preg_match('/[,;\r\n]/', $value) === 1;
        if ($invalid($group) || $invalid($triggerGroup)) {
            Session::flash('error', 'Bitte je Feld genau eine AD-Gruppe auswählen.');
        } else {
            Container::settings()->update(['emergency_plan_enabled' => $request->has('enabled') ? '1' : '0', 'emergency_plan_group' => $group, 'emergency_plan_trigger_group' => $triggerGroup]);
            app_logger()->info('Notfallplan-Freigabe geändert.', ['actor' => $access['actor'], 'group' => $group, 'trigger_group' => $triggerGroup, 'enabled' => $request->has('enabled')]);
            Session::flash('success', 'Freigabe gespeichert. Ohne AD-Gruppe bleiben Button und Inhalte gesperrt.');
        }

        return $this->redirect('/admin/notfallplan');
    }

    public function edit(Request $request): Response
    {
        $access = $this->access($request, true);
        $id = $request->queryInt('id', 0);
        $plan = $id > 0 ? Container::emergencyPlans()->repository->plan($id) : [
            'id' => 0, 'revision' => 0, 'published' => 0, 'published_revision' => null, 'review_state' => 'draft', 'contributors' => [], 'submitted_by' => null, 'definition' => ['title' => '', 'description' => '', 'nodes' => []],
        ];

        // Eigenständiges Vollbild-Layout: Der Editor öffnet sich in einem eigenen Tab ohne Admin-Seitenmenü.
        $data = $access + ['activeNav' => 'emergency_plan', 'pageScript' => 'emergency-plan.js', 'pageTitle' => $plan['definition']['title'] !== '' ? $plan['definition']['title'] : 'Neuer Notfallplan',
            'plan' => $plan, 'alarms' => Container::emergencyPlans()->alarmOptions(), 'reviews' => $id > 0 ? Container::emergencyPlans()->repository->reviews($id) : []];

        return $this->adminView('emergency.editor', $data, 200, 'layouts.editor')->withHeader('Cache-Control', 'no-store');
    }

    public function save(Request $request): Response
    {
        $access = $this->access($request, true);
        $this->requireValidCsrf($request);
        try {
            $json = (string) $request->input('definition', '');
            if (strlen($json) > 600000) {
                throw new HttpException(422, 'Der Plan ist zu groß.');
            }
            $definition = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($definition)) {
                throw new HttpException(422, 'Ungültiger Plan.');
            }
            $requestedId = $request->inputInt('id');
            $revision = $request->inputInt('revision');
            $id = Container::emergencyPlans()->save($requestedId, $revision, $definition, $access['actor']);

            return Response::json(['id' => $id, 'revision' => $requestedId === 0 ? 1 : $revision + 1, 'message' => 'Entwurf gespeichert. Veröffentlichung benötigt eine zweite Freigabe.']);
        } catch (ValidationException $exception) {
            return Response::json(['error' => implode(' ', $exception->errors())], 422);
        } catch (\JsonException) {
            return Response::json(['error' => 'Ungültiger Plan.'], 422);
        } catch (HttpException $exception) {
            return Response::json(['error' => $exception->getMessage()], $exception->statusCode());
        }
    }

    public function preview(Request $request): Response
    {
        $this->access($request, true);

        return $this->view('emergency.preview', ['pageTitle' => 'Notfallplan – Live-Vorschau',
            'activeNav' => 'emergency_plan', 'pageScript' => 'emergency-plan.js',
            'emergencyPlanVisible' => false])->withHeader('Cache-Control', 'no-store');
    }

    public function previewRender(Request $request): Response
    {
        $this->access($request, true);
        $this->requireValidCsrf($request);
        try {
            $json = (string) $request->input('preview', '');
            if (strlen($json) > 1200000) {
                throw new HttpException(422, 'Die Vorschau ist zu groß. Bitte Simulation zurücksetzen.');
            }
            $input = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($input) || !is_bool($input['started'] ?? null)) {
                throw new HttpException(422, 'Ungültige Vorschau.');
            }
            $data = EmergencyPlanPreview::build($input, Container::emergencyPlans()->alarmOptions());
            $html = View::render($input['started'] ? 'emergency.event' : 'emergency.plan', $data + [
                'preview' => true, 'manager' => false, 'base' => '/admin/notfallplan/vorschau', 'assetVersion' => $this->assetVersion(),
            ]);

            return Response::json(['html' => $html]);
        } catch (ValidationException $exception) {
            return Response::json(['error' => implode(' ', $exception->errors())], 422);
        } catch (\JsonException) {
            return Response::json(['error' => 'Ungültige Vorschau.'], 422);
        } catch (HttpException $exception) {
            return Response::json(['error' => $exception->getMessage()], $exception->statusCode());
        }
    }

    public function plan(Request $request): Response
    {
        $access = $this->access($request);
        $plan = Container::emergencyPlans()->repository->publishedPlan($request->queryInt('id'));

        return $this->render('emergency.plan', $access, ['pageTitle' => $plan['title'], 'plan' => $plan, 'requestKey' => bin2hex(random_bytes(32)), 'secure' => $request->isSecure()]);
    }

    public function review(Request $request): Response
    {
        $access = $this->access($request, true);
        $this->requireValidCsrf($request);
        $repo = Container::emergencyPlans()->repository;
        $id = $request->inputInt('id');
        $revision = $request->inputInt('revision');
        try {
            switch ($request->input('action')) {
                case 'submit':
                    $repo->submit($id, $revision, $access['actor']);
                    Session::flash('success', 'Freigabe angefordert. Eine zweite, unbeteiligte Person muss den Entwurf prüfen.');
                    break;
                case 'approve':
                case 'reject':
                    $approve = $request->input('action') === 'approve';
                    $repo->review($id, $revision, $access['actor'], $approve, (string) $request->input('comment', ''));
                    Session::flash('success', $approve ? 'Plan nach Vier-Augen-Prüfung veröffentlicht.' : 'Freigabe mit Kommentar verweigert.');
                    break;
                case 'withdraw':
                    $repo->withdraw($id, $revision, $access['actor']);
                    Session::flash('success', 'Veröffentlichung zurückgezogen. Laufende Ereignisse bleiben unverändert.');
                    break;
                default:
                    throw new HttpException(422, 'Unbekannte Freigabeaktion.');
            }
        } catch (ValidationException $exception) {
            Session::flash('error', implode(' ', $exception->errors()));
        }

        return $this->redirect('/admin/notfallplan/bearbeiten?id=' . $id);
    }

    public function start(Request $request): Response
    {
        $access = $this->access($request);
        $this->requireValidCsrf($request);
        if (!$request->isSecure()) {
            throw new HttpException(403, 'Zum Schutz Ihres AD-Kennworts ist HTTPS erforderlich.');
        }
        $password = is_string($request->post['password'] ?? null) ? $request->post['password'] : '';
        $id = Container::emergencyPlans()->start($request->inputInt('id'), $request->inputInt('revision'), $access['user'], $password, (string) $request->input('request_key'));
        Session::flash('success', 'Ereignis #' . $id . ' wurde gestartet. E-Mail-Benachrichtigungen sind eingeplant; SMS müssen einzeln bestätigt werden.');

        return $this->redirect('/notfallplan/ereignis?id=' . $id);
    }

    public function event(Request $request): Response
    {
        $access = $this->access($request);
        $service = Container::emergencyPlans();
        $event = $service->requireEvent($request->queryInt('id'), $access['actor'], $access['manager'], false, $access['sharedGroup'] ?? null);
        if ($access['restricted'] && $event['status'] !== 'active') {
            Session::flash('success', 'Ereignis #' . (int) $event['id'] . ' ist abgeschlossen. Die weitere Auswertung erfolgt durch das KAEP-Team.');

            return $this->redirect('/notfallplan');
        }

        return $this->render('emergency.event', $access, [
            'pageTitle' => 'Ereignis #' . $event['id'] . ': ' . $event['title'],
            'event' => $event, 'logs' => $service->repository->logs((int) $event['id']),
            'notifications' => $service->repository->notifications((int) $event['id']),
            'ready' => EmergencyPlanDefinition::readiness($event['snapshot'], $event['state']),
        ]);
    }

    public function status(Request $request): Response
    {
        $access = $this->access($request);
        $event = Container::emergencyPlans()->requireEvent($request->queryInt('id'), $access['actor'], $access['manager'], false, $access['sharedGroup'] ?? null);

        return Response::json(['revision' => (int) $event['revision'], 'status' => $event['status'], 'at' => gmdate('c')]);
    }

    public function update(Request $request): Response
    {
        $access = $this->access($request);
        $this->requireValidCsrf($request);
        try {
            $service = Container::emergencyPlans();
            $event = $service->requireEvent($request->inputInt('id'), $access['actor'], $access['manager'], $access['restricted'], $access['sharedGroup'] ?? null);
            $service->update($event, $access['actor'], $request->post);

            return Response::json(['message' => 'Gespeichert.']);
        } catch (ValidationException $exception) {
            return Response::json(['error' => implode(' ', $exception->errors())], 422);
        } catch (HttpException $exception) {
            return Response::json(['error' => $exception->getMessage()], $exception->statusCode());
        }
    }

    public function export(Request $request): Response
    {
        $access = $this->access($request, true);
        $service = Container::emergencyPlans();
        $event = $service->requireEvent($request->queryInt('id'), $access['actor'], true);
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['Ereignis', 'Plan', 'Start (UTC)', 'Ende (UTC)', 'Zeit (UTC)', 'Maßnahme', 'Person', 'Aktion', 'Inhalt'], ';', '"', '');
        $titles = array_column($event['snapshot']['nodes'], 'title', 'id');
        foreach ($service->repository->logs((int) $event['id']) as $log) {
            $row = [$event['id'], $event['title'], $event['started_at'], $event['closed_at'] ?? '', $log['created_at'], $titles[$log['node_id']] ?? '', $log['actor'], $log['action'], $log['message']];
            $row = array_map(static fn ($cell) => preg_match('/^[\s]*[=+@\-\t\r\n]/u', (string) $cell) ? "'" . $cell : (string) $cell, $row);
            fputcsv($stream, $row, ';', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return Response::download("\xEF\xBB\xBF" . $csv, 'notfallereignis-' . $event['id'] . '.csv', 'text/csv; charset=utf-8');
    }

    public function exportPlans(Request $request): Response
    {
        $access = $this->access($request, true);
        $this->requireAdminRole();
        $this->requireValidCsrf($request);
        $ids = $request->post['plans'] ?? [];
        $ids = is_array($ids) ? array_map(static fn ($id) => is_string($id) && ctype_digit($id) ? (int) $id : 0, array_values($ids)) : [];
        try {
            $contents = Container::emergencyPlans()->exportPlans($ids);
        } catch (ValidationException $exception) {
            Session::flash('error', implode(' ', $exception->errors()));

            return $this->redirect('/admin/notfallplan');
        }
        app_logger()->info('Notfallpläne exportiert.', ['actor' => $access['actor'], 'ids' => $ids]);

        return Response::download($contents, 'notfallplaene-' . gmdate('Y-m-d') . '.json', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function importPlans(Request $request): Response
    {
        $access = $this->access($request, true);
        $this->requireAdminRole();
        $this->requireValidCsrf($request);
        $file = $request->files['file'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        $tmpName = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK ? (string) ($file['tmp_name'] ?? '') : '';
        $contents = $tmpName !== '' && is_uploaded_file($tmpName) && filesize($tmpName) <= EmergencyPlanService::IMPORT_MAX_BYTES
            ? file_get_contents($tmpName) : false;
        if ($contents === false) {
            Session::flash('error', 'Bitte eine gültige Notfallplan-Exportdatei (höchstens 2 MB) auswählen.');

            return $this->redirect('/admin/notfallplan');
        }
        try {
            $ids = Container::emergencyPlans()->importPlans($contents, $access['actor']);
        } catch (ValidationException $exception) {
            Session::flash('error', implode(' ', $exception->errors()));

            return $this->redirect('/admin/notfallplan');
        }
        Session::flash('success', count($ids) . (count($ids) === 1 ? ' Notfallplan wurde' : ' Notfallpläne wurden')
            . ' als neuer Entwurf importiert. Bestehende Pläne bleiben unverändert; die Veröffentlichung benötigt eine Vier-Augen-Freigabe.');

        return $this->redirect('/admin/notfallplan');
    }

    private function requireAdminRole(): void
    {
        if (!Container::auth()->isAdmin()) {
            throw new HttpException(403, 'Nur Administratoren dürfen Notfallpläne exportieren und importieren.');
        }
    }

    public function guide(Request $request): Response
    {
        $access = $this->access($request);

        return $this->render('emergency.guide', $access, ['pageTitle' => 'Notfallplan: Kurzanleitung']);
    }
}
