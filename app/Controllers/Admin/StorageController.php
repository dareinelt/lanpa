<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Services\Storage\SnapshotService;
use App\Services\Storage\SnapshotSettings;
use App\Services\Storage\StorageService;
use App\Services\Storage\StorageSettings;

/**
 * Adminbereich "Speicher (HA)": Ablage der Nextcloud-/Euro-Office-Daten auf
 * SMB-Freigaben (UNC) oder S3-kompatiblen Objektspeichern, Speicher-Tiering,
 * HA- und Synchronisationsstatus.
 */
final class StorageController extends AdminController
{
    private const REQUEST_LABELS = [
        'sync_now' => 'Synchronisation angestoßen.',
        'full_scan' => 'Vollständiger Abgleich angestoßen.',
        'remount' => 'Neueinbindung der Speicherziele angestoßen.',
        'confirm_deletes' => 'Löschungen bestätigt – sie werden beim nächsten vollständigen Abgleich übernommen.',
        'snapshot_remount' => 'Neueinbindung des Snapshot-Speichers angestoßen.',
    ];

    public function index(Request $request): Response
    {
        return $this->render();
    }

    public function live(Request $request): Response
    {
        return Response::json(Container::storage()->liveData())->withHeader('Cache-Control', 'no-store');
    }

    public function updateSettings(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $input = [];
        foreach (array_merge(array_keys(StorageSettings::NUMERIC), array_keys(StorageSettings::BOOLEAN)) as $key) {
            $input[$key] = $request->input($key, '');
        }

        try {
            Container::storage()->saveSettings($input);
        } catch (ValidationException $exception) {
            Session::flash('error', 'Bitte prüfen Sie die markierten Eingaben.');

            return $this->render($exception->errors(), array_map('strval', $input), 422);
        }

        app_logger()->info('Einstellungen des Speicher-Tierings geändert.', ['admin' => $this->admin()]);
        Session::flash('success', 'Die Einstellungen wurden gespeichert. storage-sync übernimmt sie innerhalb weniger Sekunden.');

        return $this->redirect('/admin/speicher-ha#einstellungen');
    }

    public function editTarget(Request $request): Response
    {
        $id = $request->queryInt('id');
        $target = $id > 0 ? Container::storage()->target($id) : null;
        if ($id > 0 && $target === null) {
            Session::flash('error', 'Das Speicherziel wurde nicht gefunden.');

            return $this->redirect('/admin/speicher-ha#ziele');
        }

        return $this->renderTarget($target);
    }

    public function saveTarget(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id');
        $service = Container::storage();
        $input = [
            'label' => $request->input('label', ''),
            'kind' => $request->input('kind', StorageService::KIND_SMB),
            'unc_path' => $request->input('unc_path', ''),
            'username' => $request->input('username', ''),
            'domain' => $request->input('domain', ''),
            'password' => is_string($request->post['password'] ?? null) ? $request->post['password'] : '',
            'password_clear' => $request->input('password_clear', '0') === '1',
            'smb_version' => $request->input('smb_version', 'auto'),
            's3_endpoint' => $request->input('s3_endpoint', ''),
            's3_region' => $request->input('s3_region', ''),
            's3_bucket' => $request->input('s3_bucket', ''),
            's3_prefix' => $request->input('s3_prefix', ''),
            's3_access_key' => $request->input('s3_access_key', ''),
            's3_secret_key' => is_string($request->post['s3_secret_key'] ?? null) ? $request->post['s3_secret_key'] : '',
            's3_path_style' => $request->input('s3_path_style', '0') === '1',
            's3_verify_tls' => $request->input('s3_verify_tls', '0') === '1',
            'capacity_gb' => $request->input('capacity_gb', '0'),
            'is_primary' => $request->input('is_primary', '0') === '1',
            'active' => $request->input('active', '0') === '1',
        ];

        try {
            if ($id > 0) {
                $service->updateTarget($id, $input);
            } else {
                $id = $service->createTarget($input);
            }
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->errors()['target'] ?? 'Bitte prüfen Sie die markierten Eingaben.');
            $target = $id > 0 ? $service->target($id) : null;
            unset($input['password'], $input['s3_secret_key']);

            return $this->renderTarget($target, $exception->errors(), $input, 422);
        }

        app_logger()->info('Speicherziel gespeichert.', ['admin' => $this->admin(), 'target' => $id]);
        Session::flash('success', 'Das Speicherziel wurde gespeichert. storage-sync bindet es ein und gleicht die Daten ab.');

        return $this->redirect('/admin/speicher-ha#ziele');
    }

    /**
     * Formular: alle Cold-Tiers gemeinsam um je ein Ziel derselben Art erweitern.
     */
    public function extendForm(Request $request): Response
    {
        if (StorageService::tiers(Container::storage()->targets()) === []) {
            Session::flash('error', 'Es ist noch kein Cold-Tier eingerichtet.');

            return $this->redirect('/admin/speicher-ha#ziele');
        }

        return $this->renderExtend();
    }

    public function extend(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $raw = $request->post['tiers'] ?? [];
        $input = [];
        foreach (is_array($raw) ? $raw : [] as $rootId => $fields) {
            if (!is_array($fields) || !ctype_digit((string) $rootId)) {
                continue;
            }
            $clean = [];
            foreach (self::EXTEND_FIELDS as $key) {
                $value = $fields[$key] ?? '';
                $clean[$key] = is_string($value) ? $value : '';
            }
            foreach (['s3_path_style', 's3_verify_tls', 'reuse_credentials'] as $flag) {
                $clean[$flag] = $clean[$flag] === '1';
            }
            $input[(int) $rootId] = $clean;
        }

        try {
            $ids = Container::storage()->extendTiers($input);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->errors()['tiers'] ?? 'Bitte prüfen Sie die markierten Eingaben – es wurde noch kein Cold-Tier erweitert.');
            foreach ($input as &$fields) {
                unset($fields['password'], $fields['s3_secret_key']);
            }
            unset($fields);

            return $this->renderExtend($exception->errors(), $input, 422);
        }

        app_logger()->info('Cold-Tiers erweitert.', ['admin' => $this->admin(), 'targets' => $ids]);
        Session::flash('success', sprintf(
            'Alle Cold-Tiers wurden um je ein Ziel erweitert (%d neue Ziele). storage-sync bindet sie ein; neue Dateien werden abgelegt, sobald ein bisheriges Ziel voll ist.',
            count($ids)
        ));

        return $this->redirect('/admin/speicher-ha#ziele');
    }

    private const EXTEND_FIELDS = [
        'label', 'kind', 'unc_path', 'username', 'domain', 'password', 'smb_version', 's3_endpoint', 's3_region', 's3_bucket',
        's3_prefix', 's3_access_key', 's3_secret_key', 's3_path_style', 's3_verify_tls', 'capacity_gb', 'reuse_credentials',
    ];

    /**
     * @param array<string,string> $errors
     * @param array<int,array<string,mixed>> $values
     */
    private function renderExtend(array $errors = [], array $values = [], int $status = 200): Response
    {
        $service = Container::storage();

        return $this->adminView('admin.storage_extend', [
            'pageTitle' => 'Cold-Tiers erweitern',
            'activeNav' => 'storage',
            'pageScript' => 'admin-storage-target.js',
            'tiers' => $service->overview()['targets'],
            'versions' => StorageService::SMB_VERSIONS,
            'errors' => $errors,
            'values' => $values,
        ], $status);
    }

    public function deleteTarget(Request $request): Response
    {
        $this->requireValidCsrf($request);
        try {
            $label = Container::storage()->deleteTarget($request->inputInt('id'));
        } catch (ValidationException $exception) {
            Session::flash('error', implode(' ', $exception->errors()));

            return $this->redirect('/admin/speicher-ha#ziele');
        }

        app_logger()->warning('Speicherziel entfernt.', ['admin' => $this->admin(), 'target' => $label]);
        Session::flash('success', 'Entfernt: „' . $label . '“. Die Daten auf den Freigaben bzw. in den Buckets wurden nicht gelöscht.');

        return $this->redirect('/admin/speicher-ha#ziele');
    }

    public function request(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $action = (string) $request->input('action', '');
        $target = $request->inputInt('target_id');
        try {
            Container::storage()->request($action, $target > 0 ? $target : null, $this->admin());
        } catch (ValidationException $exception) {
            Session::flash('error', implode(' ', $exception->errors()));

            return $this->redirect('/admin/speicher-ha');
        }
        Session::flash('success', self::REQUEST_LABELS[$action] ?? 'Auftrag übermittelt.');

        return $this->redirect('/admin/speicher-ha');
    }

    /**
     * Einstellungen des Snapshot-Speichers (Dateiversionen).
     */
    public function updateSnapshotSettings(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $input = [];
        foreach (array_merge(array_keys(SnapshotSettings::NUMERIC), array_keys(SnapshotSettings::BOOLEAN), array_keys(SnapshotSettings::TEXT)) as $key) {
            $input[$key] = $request->input($key, '');
        }
        $input['storage_snapshot_password_clear'] = $request->input('storage_snapshot_password_clear', '');

        try {
            Container::snapshots()->saveSettings($input);
        } catch (ValidationException $exception) {
            Session::flash('error', 'Bitte prüfen Sie die markierten Eingaben.');
            unset($input['storage_snapshot_password']);

            return $this->render($exception->errors(), array_map('strval', $input), 422);
        }

        app_logger()->info('Einstellungen des Snapshot-Speichers geändert.', ['admin' => $this->admin()]);
        Session::flash('success', 'Die Einstellungen des Snapshot-Speichers wurden gespeichert. storage-sync übernimmt sie innerhalb weniger Sekunden.');

        return $this->redirect('/admin/speicher-ha#snapshots');
    }

    /**
     * Liste der gesicherten Dateiversionen mit Filtern.
     */
    public function versions(Request $request): Response
    {
        $service = Container::snapshots();
        $filter = SnapshotService::filter([
            'limit' => $request->query('limit', ''),
            'from' => $request->query('from', ''),
            'to' => $request->query('to', ''),
            'user' => $request->query('user', ''),
            'path' => $request->query('path', ''),
            'status' => $request->query('status', ''),
            'deleted' => $request->query('deleted', ''),
        ]);
        $list = $service->list($filter);

        return $this->adminView('admin.storage_versions', [
            'pageTitle' => 'Dateiversionen',
            'activeNav' => 'storage',
            'pageScript' => 'admin-storage-versions.js',
            'filter' => $filter,
            'rows' => $list['rows'],
            'total' => $list['total'],
            'users' => $list['users'],
            'snapshot' => $service->status(),
            'results' => $service->restoreResults(),
        ]);
    }

    /**
     * Wiederherstellung einer Dateiversion anfordern (Agent fuehrt sie aus).
     */
    public function restoreVersion(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $uid = (string) $request->input('uid', '');
        $back = '/admin/speicher-ha/dateiversionen' . self::filterQuery($request);
        try {
            $snapshot = Container::snapshots()->requestRestore($uid, $this->admin());
        } catch (ValidationException $exception) {
            Session::flash('error', implode(' ', $exception->errors()));

            return $this->redirect($back);
        }
        app_logger()->info('Wiederherstellung einer Dateiversion angefordert.', ['admin' => $this->admin(), 'uid' => $uid, 'path' => $snapshot['path']]);
        Session::flash('success', 'Wiederherstellung von „' . $snapshot['path'] . '“ (Version ' . $snapshot['version'] . ') angestoßen. Das Ergebnis erscheint in der Liste.');

        return $this->redirect($back);
    }

    private static function filterQuery(Request $request): string
    {
        $query = [];
        foreach (['limit', 'from', 'to', 'user', 'path', 'status', 'deleted'] as $key) {
            $value = (string) $request->input('filter_' . $key, '');
            if ($value !== '') {
                $query[$key] = $value;
            }
        }

        return $query === [] ? '' : '?' . http_build_query($query);
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $values
     */
    private function render(array $errors = [], array $values = [], int $status = 200): Response
    {
        $service = Container::storage();
        $overview = $service->overview();

        return $this->adminView('admin.storage', [
            'pageTitle' => 'Speicher (HA)',
            'activeNav' => 'storage',
            'pageScript' => 'admin-storage.js',
            'overview' => $overview,
            'alert' => $service->dashboardAlert($overview),
            'events' => Container::storageRepository()->events(30),
            'snapshotSettings' => Container::snapshots()->settings(),
            'smbVersions' => StorageService::SMB_VERSIONS,
            'errors' => $errors,
            'values' => $values,
        ], $status);
    }

    /**
     * @param array<string,mixed>|null $target
     * @param array<string,string> $errors
     * @param array<string,mixed> $values
     */
    private function renderTarget(?array $target, array $errors = [], array $values = [], int $status = 200): Response
    {
        $tier = $target === null ? null : Container::storage()->tierOf((int) $target['id']);
        $isExtension = $target !== null && StorageService::parentId($target) !== null && $tier !== null;

        return $this->adminView('admin.storage_target', [
            'pageTitle' => $target === null ? 'Speicherziel hinzufügen' : ($isExtension ? 'Erweiterung bearbeiten' : 'Speicherziel bearbeiten'),
            'activeNav' => 'storage',
            'target' => $target,
            'tierRoot' => $isExtension ? $tier['root'] : null,
            'tierSize' => $tier === null ? 1 : count($tier['members']),
            'pageScript' => 'admin-storage-target.js',
            'versions' => StorageService::SMB_VERSIONS,
            'kinds' => StorageService::KINDS,
            'errors' => $errors,
            'values' => $values,
        ], $status);
    }

    private function admin(): string
    {
        return (string) (Container::auth()->username() ?? '');
    }
}
