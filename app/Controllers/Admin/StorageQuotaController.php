<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Support\Validator;

/**
 * Adminbereich "Speicherplatz": Kontingente (Quota) der Benutzer in
 * Nextcloud – Standard, AD-Gruppenregeln, individuelle Kontingente mit
 * Begruendung und Verlauf.
 */
final class StorageQuotaController extends AdminController
{
    private const HISTORY_PAGE_SIZE = 50;

    public function index(Request $request): Response
    {
        return $this->render($request);
    }

    public function updateDefault(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $input = (string) $request->input('default_quota', '');

        try {
            Container::storageQuotas()->setDefault($input, (string) $request->input('default_reason', ''), $this->admin());
        } catch (ValidationException $exception) {
            Session::flash('error', 'Bitte prüfen Sie den Standard.');

            return $this->render($request, $exception->errors(), ['default_quota' => $input], 422);
        }

        app_logger()->info('Standard-Speicherkontingent geändert.', ['admin' => $this->admin()]);
        $this->flashSaved('Der Standard wurde gespeichert.');

        return $this->redirect('/admin/speicherplatz#standard');
    }

    public function saveGroup(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $values = [
            'group_name' => (string) $request->input('group_name', ''),
            'group_quota' => (string) $request->input('group_quota', ''),
            'group_reason' => (string) $request->input('group_reason', ''),
        ];

        try {
            Container::storageQuotas()->saveGroupRule($values['group_name'], $values['group_quota'], $values['group_reason'], $this->admin());
        } catch (ValidationException $exception) {
            Session::flash('error', 'Bitte prüfen Sie die Angaben zur AD-Gruppe.');

            return $this->render($request, $exception->errors(), $values, 422);
        }

        app_logger()->info('Speicherkontingent für AD-Gruppe gespeichert.', ['admin' => $this->admin(), 'group' => $values['group_name']]);
        $this->flashSaved('Das Kontingent der AD-Gruppe wurde gespeichert.');

        return $this->redirect('/admin/speicherplatz#gruppen');
    }

    public function deleteGroup(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $name = Container::storageQuotas()->deleteGroupRule($request->inputInt('id', 0), $this->admin());
        if ($name === null) {
            Session::flash('error', 'Die Gruppenregel wurde nicht gefunden.');

            return $this->redirect('/admin/speicherplatz#gruppen');
        }

        app_logger()->info('Speicherkontingent für AD-Gruppe entfernt.', ['admin' => $this->admin(), 'group' => $name]);
        $this->flashSaved('Die Regel für „' . $name . '“ wurde entfernt.');

        return $this->redirect('/admin/speicherplatz#gruppen');
    }

    public function editUser(Request $request): Response
    {
        $user = Container::storageQuotas()->findUser($request->queryInt('id', 0));
        if ($user === null) {
            Session::flash('error', 'Der Benutzer wurde nicht gefunden oder hat keine Nextcloud-Kennung.');

            return $this->redirect('/admin/speicherplatz#benutzer');
        }

        return $this->renderUser($user);
    }

    public function saveUser(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        $values = [
            'quota' => (string) $request->input('quota', ''),
            'reason' => (string) $request->input('reason', ''),
        ];

        $service = Container::storageQuotas();
        try {
            $service->saveOverride($id, $values['quota'], $values['reason'], $this->admin());
        } catch (ValidationException $exception) {
            $user = $service->findUser($id);
            if ($user === null) {
                Session::flash('error', 'Der Benutzer wurde nicht gefunden oder hat keine Nextcloud-Kennung.');

                return $this->redirect('/admin/speicherplatz#benutzer');
            }
            Session::flash('error', 'Bitte prüfen Sie Kontingent und Begründung.');

            return $this->renderUser($user, $exception->errors(), $values, 422);
        }

        $user = $service->findUser($id);
        app_logger()->info('Individuelles Speicherkontingent gesetzt.', ['admin' => $this->admin(), 'user' => $user['uid'] ?? $id]);
        $this->flashSaved('Das individuelle Kontingent wurde gespeichert.');

        return $this->redirect('/admin/speicherplatz/benutzer?id=' . $id);
    }

    public function removeUser(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $uid = Validator::cleanText((string) $request->input('uid', ''), 100);
        $id = $request->inputInt('id', 0);
        $reason = (string) $request->input('remove_reason', '');
        $back = $id > 0 ? '/admin/speicherplatz/benutzer?id=' . $id : '/admin/speicherplatz#uebersicht';

        $service = Container::storageQuotas();
        try {
            $service->removeOverride($uid, $reason, $this->admin());
        } catch (ValidationException $exception) {
            $user = $id > 0 ? $service->findUser($id) : null;
            if ($user !== null) {
                Session::flash('error', 'Bitte geben Sie eine Begründung für das Entfernen an.');

                return $this->renderUser($user, $exception->errors(), ['remove_reason' => $reason], 422);
            }
            Session::flash('error', (string) (array_values($exception->errors())[0] ?? 'Das Kontingent konnte nicht entfernt werden.'));

            return $this->redirect($back);
        }

        app_logger()->info('Individuelles Speicherkontingent entfernt.', ['admin' => $this->admin(), 'user' => $uid]);
        $this->flashSaved('Das individuelle Kontingent wurde entfernt; es gilt wieder Gruppe bzw. Standard.');

        return $this->redirect($back);
    }

    public function push(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $service = Container::storageQuotas();
        if (!$service->officeEnabled()) {
            Session::flash('error', 'Office ist nicht aktiviert (OFFICE_ENABLED) – es gibt kein Nextcloud, an das übertragen werden kann.');

            return $this->redirect('/admin/speicherplatz#standard');
        }

        $result = $service->pushToNextcloud();
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return $this->redirect('/admin/speicherplatz#standard');
    }

    public function history(Request $request): Response
    {
        $service = Container::storageQuotas();
        $type = (string) $request->query('art', '');
        $type = in_array($type, ['user', 'group', 'default'], true) ? $type : null;
        $total = $service->countHistory($type);
        $pages = max(1, (int) ceil($total / self::HISTORY_PAGE_SIZE));
        $page = max(1, min($pages, $request->queryInt('seite', 1)));

        return $this->adminView('admin.quota.history', [
            'pageTitle' => 'Speicherplatz – Verlauf',
            'activeNav' => 'quota',
            'entries' => $service->history(self::HISTORY_PAGE_SIZE, ($page - 1) * self::HISTORY_PAGE_SIZE, $type),
            'type' => $type ?? '',
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $values
     */
    private function render(Request $request, array $errors = [], array $values = [], int $status = 200): Response
    {
        $service = Container::storageQuotas();
        $term = Validator::cleanText((string) $request->query('suche', ''), 100);

        return $this->adminView('admin.quota.index', [
            'pageTitle' => 'Speicherplatz (Quota)',
            'activeNav' => 'quota',
            'officeEnabled' => $service->officeEnabled(),
            'defaultMb' => $service->defaultMb(),
            'summary' => $service->summary(),
            'groupRules' => $service->groupRules(),
            'aboveDefault' => $service->aboveDefault(),
            'belowDefault' => $service->overridesNotAboveDefault(),
            'term' => $term,
            'results' => $term === '' ? [] : $service->searchUsers($term),
            'lastPush' => $service->lastPush(),
            'recentHistory' => $service->history(10),
            'errors' => $errors,
            'values' => $values,
            'pageScript' => 'admin-group-autocomplete.js',
        ], $status);
    }

    /**
     * @param array<string,mixed>  $user
     * @param array<string,string> $errors
     * @param array<string,string> $values
     */
    private function renderUser(array $user, array $errors = [], array $values = [], int $status = 200): Response
    {
        $service = Container::storageQuotas();

        return $this->adminView('admin.quota.user', [
            'pageTitle' => 'Speicherplatz für ' . (string) $user['display_name'],
            'activeNav' => 'quota',
            'user' => $user,
            'defaultMb' => $service->defaultMb(),
            'history' => $service->history(100, 0, 'user', (string) $user['uid']),
            'errors' => $errors,
            'values' => $values,
        ], $status);
    }

    /**
     * Speichern und – bei aktivem Office – sofort an Nextcloud uebertragen.
     */
    private function flashSaved(string $message): void
    {
        $result = Container::storageQuotas()->pushIfEnabled();
        if ($result === null) {
            Session::flash('success', $message);
        } elseif ($result['ok']) {
            Session::flash('success', $message . ' ' . $result['message']);
        } else {
            Session::flash('success', $message);
            Session::flash('error', 'Übertragung an Nextcloud fehlgeschlagen: ' . $result['message'] . ' Die Gesundheitsprüfung wiederholt den Abgleich automatisch.');
        }
    }

    private function admin(): string
    {
        return (string) (Container::auth()->username() ?? '');
    }
}
