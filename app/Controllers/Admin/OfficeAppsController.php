<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Services\Office\OfficeAppCatalog;
use App\Services\Office\OfficeAppService;

/**
 * Adminbereich "Office → Apps & Berechtigungen": Link zur Outlook Web App,
 * Freigabe einzelner Apps und App-Pakete fuer AD-Gruppen.
 */
final class OfficeAppsController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->render();
    }

    public function updateOwa(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $url = (string) $request->input(OfficeAppService::OWA_SETTING, '');

        try {
            Container::officeApps()->saveOwaUrl($url);
        } catch (ValidationException $exception) {
            Session::flash('error', 'Bitte prüfen Sie den Link zur Outlook Web App.');

            return $this->render($exception->errors(), [OfficeAppService::OWA_SETTING => $url], 422);
        }

        app_logger()->info('Link zur Outlook Web App geändert.', ['admin' => Container::auth()->username()]);
        Session::flash('success', trim($url) === ''
            ? 'Der Link zur Outlook Web App wurde entfernt.'
            : 'Der Link zur Outlook Web App wurde gespeichert.');

        return $this->redirect('/admin/office/apps#owa');
    }

    public function updatePermissions(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $groups = $request->post['groups'] ?? [];

        Container::officeApps()->saveDirectGroups(is_array($groups) ? $groups : []);
        app_logger()->info('Office-App-Freigaben geändert.', ['admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Freigaben der Office-Apps wurden gespeichert.');

        return $this->redirect('/admin/office/apps#freigaben');
    }

    public function editPackage(Request $request): Response
    {
        $id = $request->queryInt('id', 0);
        $package = null;
        if ($id > 0) {
            $package = Container::officeApps()->findPackage($id);
            if ($package === null) {
                Session::flash('error', 'Das Paket wurde nicht gefunden.');

                return $this->redirect('/admin/office/apps#pakete');
            }
        }

        return $this->renderPackage($package ?? ['id' => 0, 'name' => '', 'description' => '', 'apps' => [], 'groups' => []]);
    }

    public function savePackage(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        $apps = $request->post['apps'] ?? [];
        $input = [
            'name' => (string) $request->input('name', ''),
            'description' => (string) $request->input('description', ''),
            'apps' => is_array($apps) ? $apps : [],
            'groups' => (string) $request->input('groups', ''),
        ];

        try {
            $savedId = Container::officeApps()->savePackage($id > 0 ? $id : null, $input);
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['id'])) {
                Session::flash('error', 'Das Paket wurde nicht gefunden.');

                return $this->redirect('/admin/office/apps#pakete');
            }
            Session::flash('error', 'Bitte prüfen Sie die Angaben zum Paket.');

            return $this->renderPackage([
                'id' => $id,
                'name' => $input['name'],
                'description' => $input['description'],
                'apps' => OfficeAppCatalog::filterKeys($input['apps']),
                'groups' => OfficeAppService::splitGroups($input['groups']),
            ], $exception->errors(), 422);
        }

        app_logger()->info('Office-App-Paket gespeichert.', ['admin' => Container::auth()->username(), 'id' => $savedId]);
        Session::flash('success', 'Das App-Paket wurde gespeichert.');

        return $this->redirect('/admin/office/apps#pakete');
    }

    public function deletePackage(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        Container::officeApps()->deletePackage($id);
        app_logger()->info('Office-App-Paket gelöscht.', ['admin' => Container::auth()->username(), 'id' => $id]);
        Session::flash('success', 'Das App-Paket wurde gelöscht.');

        return $this->redirect('/admin/office/apps#pakete');
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $values
     */
    private function render(array $errors = [], array $values = [], int $status = 200): Response
    {
        $service = Container::officeApps();

        return $this->adminView('admin.office-apps', [
            'pageTitle' => 'Office-Apps und Berechtigungen',
            'activeNav' => 'office',
            'enabled' => Container::officeConfig()->isEnabled(),
            'ssoEnabled' => Container::sso()->isEnabled(),
            'apps' => $service->catalog(),
            'directGroups' => $service->directGroups(),
            'effectiveGroups' => $service->effectiveGroups(),
            'packages' => $service->packages(),
            'owaUrl' => $values[OfficeAppService::OWA_SETTING] ?? Container::settings()->get(OfficeAppService::OWA_SETTING),
            'errors' => $errors,
            'pageScript' => 'admin-group-autocomplete.js',
        ], $status);
    }

    /**
     * @param array{id:int,name:string,description:string,apps:list<string>,groups:list<string>} $package
     * @param array<string,string> $errors
     */
    private function renderPackage(array $package, array $errors = [], int $status = 200): Response
    {
        return $this->adminView('admin.office-app-package', [
            'pageTitle' => $package['id'] > 0 ? 'App-Paket bearbeiten' : 'Neues App-Paket',
            'activeNav' => 'office',
            'package' => $package,
            'apps' => Container::officeApps()->catalog(),
            'errors' => $errors,
            'pageScript' => 'admin-group-autocomplete.js',
        ], $status);
    }
}
