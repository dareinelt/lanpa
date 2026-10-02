<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Services\Storage\IncidentSettings;

/**
 * Adminbereich "Vorfaelle": erkannte Sicherheitsvorfaelle im Speicher-Tiering
 * (auffaelliges Ueberschreiben, Ransomware-Endungen) mit Erledigung und
 * Einstellungen der Erkennung.
 */
final class IncidentController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->render($request->queryInt('erledigen'));
    }

    public function resolve(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id');
        if ($request->input('confirm', '') !== 'ja') {
            return $this->redirect('/admin/vorfaelle');
        }
        $admin = (string) (Container::auth()->username() ?? '');
        $result = Container::incidents()->resolve($id, $admin);
        if (!$result['resolved']) {
            Session::flash('error', 'Der Vorfall wurde nicht gefunden oder ist bereits erledigt.');

            return $this->redirect('/admin/vorfaelle');
        }

        app_logger()->warning('Sicherheitsvorfall als erledigt markiert.', ['admin' => $admin, 'incident' => $id, 'uid' => $result['uid']]);
        $message = sprintf('Vorfall Nr. %d wurde als erledigt markiert. Die Einschränkung von „%s“ wird innerhalb weniger Sekunden aufgehoben.', $id, $result['uid']);
        if ($result['remaining'] === 0) {
            $message .= $result['target'] !== ''
                ? ' Das Speicherziel „' . $result['target'] . '“ wird wieder beschreibbar eingebunden und die Synchronisation des Cold-Tiers fortgesetzt.'
                : ' Die Synchronisation des Cold-Tiers läuft normal weiter.';
        } else {
            $message .= sprintf(' Es sind noch %d Vorfall/Vorfälle offen – das Schutzziel bleibt bis dahin schreibgeschützt.', $result['remaining']);
        }
        Session::flash('success', $message);

        return $this->redirect('/admin/vorfaelle');
    }

    public function updateSettings(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $service = Container::incidents();
        if ($request->input('reset_patterns', '') === '1') {
            $service->resetPatterns();
            Session::flash('success', 'Die Standardliste der Ransomware-Endungen wurde wiederhergestellt.');

            return $this->redirect('/admin/vorfaelle#einstellungen');
        }

        $input = [];
        foreach (array_merge(array_keys(IncidentSettings::NUMERIC), array_keys(IncidentSettings::BOOLEAN)) as $key) {
            $input[$key] = $request->input($key, '');
        }
        $input['incident_extensions'] = is_string($request->post['incident_extensions'] ?? null) ? $request->post['incident_extensions'] : '';
        $input['incident_support_contact'] = $request->input('incident_support_contact', '');

        try {
            $service->saveSettings($input);
        } catch (ValidationException $exception) {
            Session::flash('error', 'Bitte prüfen Sie die markierten Eingaben.');

            return $this->render(0, $exception->errors(), array_map('strval', $input), 422);
        }

        app_logger()->info('Einstellungen der Vorfallerkennung geändert.', ['admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Einstellungen wurden gespeichert. storage-sync übernimmt sie beim nächsten Durchlauf.');

        return $this->redirect('/admin/vorfaelle#einstellungen');
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $values
     */
    private function render(int $confirmId = 0, array $errors = [], array $values = [], int $status = 200): Response
    {
        $service = Container::incidents();
        $confirm = $confirmId > 0 ? $service->find($confirmId) : null;
        if ($confirm !== null && !$confirm['open']) {
            $confirm = null;
        }

        return $this->adminView('admin.incidents', [
            'pageTitle' => 'Vorfälle',
            'activeNav' => 'incidents',
            'pageScript' => 'admin-incidents.js',
            'incidents' => $service->list(),
            'alert' => $service->dashboardAlert(),
            'confirm' => $confirm,
            'settings' => $service->settings(),
            'targets' => $service->targetOptions(),
            'tieringEnabled' => Container::storage()->settings()->enabled(),
            'errors' => $errors,
            'values' => $values,
        ], $status);
    }
}
