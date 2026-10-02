<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Config;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Support\Validator;

/**
 * Adminbereich "Netzlaufwerke": gemeldete Laufwerke der Windows-Clients,
 * Liste der nie weitergereichten Laufwerke und Anmeldeskript.
 */
final class NetworkDriveController extends AdminController
{
    private const SCRIPT = '/scripts/network-drives-report.ps1';

    public function index(Request $request): Response
    {
        return $this->render();
    }

    public function updateSettings(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $excluded = (string) $request->input('excluded', '');
        $enabled = $request->input('enabled', '0') === '1';

        try {
            Container::networkDrives()->saveSettings($enabled, $excluded);
        } catch (ValidationException $exception) {
            Session::flash('error', 'Bitte prüfen Sie die Liste der ausgeschlossenen Laufwerke.');

            return $this->render($exception->errors(), ['excluded' => $excluded, 'enabled' => $enabled ? '1' : '0'], 422);
        }

        app_logger()->info('Einstellungen der Netzlaufwerke geändert.', ['admin' => $this->admin()]);
        $this->flashSaved('Die Einstellungen wurden gespeichert.');

        return $this->redirect('/admin/netzlaufwerke#einstellungen');
    }

    public function deleteUser(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $uid = Validator::cleanText((string) $request->input('uid', ''), 100);
        $count = $uid === '' ? 0 : Container::networkDrives()->deleteUser($uid);
        if ($count === 0) {
            Session::flash('error', 'Für diesen Benutzer sind keine Netzlaufwerke gemeldet.');

            return $this->redirect('/admin/netzlaufwerke#gemeldet');
        }

        app_logger()->info('Gemeldete Netzlaufwerke entfernt.', ['admin' => $this->admin(), 'user' => $uid]);
        $this->flashSaved('Die gemeldeten Netzlaufwerke von „' . $uid . '“ wurden entfernt (bis zur nächsten Meldung des Clients).');

        return $this->redirect('/admin/netzlaufwerke#gemeldet');
    }

    public function push(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $service = Container::networkDrives();
        if (!$service->officeEnabled()) {
            Session::flash('error', 'Office ist nicht aktiviert (OFFICE_ENABLED) – es gibt kein Nextcloud, an das übertragen werden kann.');

            return $this->redirect('/admin/netzlaufwerke#einstellungen');
        }

        $result = $service->pushToNextcloud();
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return $this->redirect('/admin/netzlaufwerke#einstellungen');
    }

    /**
     * Anmeldeskript mit voreingestellter Intranet-Adresse.
     */
    public function script(Request $request): Response
    {
        $script = @file_get_contents(BASE_PATH . self::SCRIPT);
        if ($script === false) {
            Session::flash('error', 'Das Anmeldeskript wurde nicht gefunden.');

            return $this->redirect('/admin/netzlaufwerke#skript');
        }

        $url = $this->reportUrl();
        $script = str_replace("'https://intranet.example.internal/sso/laufwerke'", "'" . str_replace("'", "''", $url) . "'", $script);

        return Response::download($script, 'netzlaufwerke-melden.ps1', 'text/plain; charset=utf-8');
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $values
     */
    private function render(array $errors = [], array $values = [], int $status = 200): Response
    {
        $service = Container::networkDrives();

        return $this->adminView('admin.network_drives', [
            'pageTitle' => 'Netzlaufwerke',
            'activeNav' => 'drives',
            'officeEnabled' => $service->officeEnabled(),
            'ssoEnabled' => Container::sso()->isEnabled() && !Container::sso()->isFake(),
            'enabled' => $service->isEnabled(),
            'excluded' => $service->excludedLetters(),
            'summary' => $service->summary(),
            'rows' => $service->rows(),
            'lastPush' => $service->lastPush(),
            'reportUrl' => $this->reportUrl(),
            'errors' => $errors,
            'values' => $values,
        ], $status);
    }

    private function reportUrl(): string
    {
        return rtrim((string) Config::get('app.url', 'http://localhost:8080'), '/') . '/sso/laufwerke';
    }

    private function flashSaved(string $message): void
    {
        $result = Container::networkDrives()->pushIfEnabled();
        if ($result === null || $result['ok']) {
            Session::flash('success', $message . ($result !== null ? ' ' . $result['message'] : ''));
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
