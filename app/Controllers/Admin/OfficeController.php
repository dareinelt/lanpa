<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\OfficeController as PublicOfficeController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Services\Office\OfficeConfigService;
use RuntimeException;

/**
 * Adminbereich "Office": Konfiguration, Gesundheit/Diagnose, Fusszeilen-
 * Vorschau, Sicherung und Einrichtung der Kachel.
 */
final class OfficeController extends AdminController
{
    /** Icons, die auch im Navigationsformular waehlbar sind. */
    public const TILE_ICONS = [
        'document', 'app', 'phone', 'alert', 'tools', 'robot', 'link',
        'clock', 'helmet', 'wrench', 'snail', 'beacon', 'ekg', 'warning', 'siren',
    ];

    private const TILE_DESIGN_KEYS = ['title', 'short_description', 'description', 'icon', 'background_color', 'background_opacity'];
    public function index(Request $request): Response
    {
        return $this->render();
    }

    public function update(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $result = OfficeConfigService::validate($request->post);
        if ($result['errors'] !== []) {
            Session::flash('error', 'Bitte prüfen Sie die Office-Einstellungen.');

            return $this->render($result['errors'], $result['values'], 422);
        }

        Container::settings()->update($result['values']);
        app_logger()->info('Office-Einstellungen geändert.', ['admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Office-Einstellungen wurden gespeichert.');

        return $this->redirect('/admin/office');
    }

    public function check(Request $request): Response
    {
        $this->requireValidCsrf($request);

        if (!Container::officeConfig()->isEnabled()) {
            Session::flash('error', 'Office ist nicht aktiviert (OFFICE_ENABLED).');

            return $this->redirect('/admin/office');
        }

        $result = Container::officeHealth()->check(true);
        app_logger()->info('Office-Prüfung ausgeführt.', [
            'admin' => Container::auth()->username(),
            'state' => $result['state'],
        ]);

        Session::flash(
            $result['state'] === 'ok' ? 'success' : 'error',
            $result['state'] === 'ok'
                ? 'Alle Office-Prüfungen waren erfolgreich.'
                : 'Mindestens eine Office-Prüfung ist fehlgeschlagen – Details siehe Statusübersicht.'
        );

        return $this->redirect('/admin/office');
    }

    public function backup(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $action = (string) $request->input('action', 'backup');

        try {
            Container::officeBackup()->request($action === 'refresh' ? 'refresh' : 'backup');
            app_logger()->info('Office-Sicherung angefordert.', [
                'admin' => Container::auth()->username(),
                'action' => $action,
            ]);
            Session::flash('success', $action === 'refresh'
                ? 'Die Übersicht wird aktualisiert.'
                : 'Die Sicherung wurde angefordert. Nextcloud ist währenddessen kurz im Wartungsmodus.');
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
        }

        return $this->redirect('/admin/office#sicherung');
    }

    public function createTile(Request $request): Response
    {
        $this->requireValidCsrf($request);

        if (Container::navigationRepository()->findActiveInternalByUrl(PublicOfficeController::ENTRY_PATH) !== null) {
            Session::flash('error', 'Eine Office-Kachel ist bereits vorhanden.');

            return $this->redirect('/admin/office');
        }

        try {
            $id = Container::navigation()->create([
                'title' => 'Office',
                'type' => 'internal',
                'url' => PublicOfficeController::ENTRY_PATH,
                'icon' => 'document',
                'short_description' => 'Dokumente bearbeiten (Nextcloud mit Euro-Office)',
                'description' => '',
                'active' => '1',
            ]);
        } catch (ValidationException $exception) {
            Session::flash('error', 'Die Kachel konnte nicht angelegt werden: ' . implode(' ', $exception->errors()));

            return $this->redirect('/admin/office');
        }

        app_logger()->info('Office-Kachel angelegt.', ['admin' => Container::auth()->username(), 'id' => $id]);
        Session::flash('success', 'Die Office-Kachel wurde angelegt. Berechtigungen (Benutzer/AD-Gruppen) können in der Navigation gepflegt werden.');

        return $this->redirect('/admin/navigation/berechtigungen?id=' . $id);
    }

    /**
     * Speichert Gestaltung der Office-Kachel und die Darstellung des Status.
     */
    public function updateTile(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $tile = $this->officeTile();
        if ($tile === null) {
            Session::flash('error', 'Es ist keine Office-Kachel vorhanden.');

            return $this->redirect('/admin/office#kachel');
        }

        $mode = (string) ($request->post['office_tile_status'] ?? 'full');
        $errors = [];
        if (!OfficeConfigService::isTileStatusMode($mode)) {
            $errors['office_tile_status'] = 'Ungültige Darstellung.';
        }

        $merged = $this->mergeTileDesign($tile, $request->post);
        try {
            $validated = Container::navigation()->validate($merged, false, (int) $tile['id']);
        } catch (ValidationException $exception) {
            $errors += $exception->errors();
        }

        if ($errors !== []) {
            Session::flash('error', 'Bitte prüfen Sie die Gestaltung der Kachel.');

            return $this->render([], [], 422, $errors, $merged + ['office_tile_status' => $mode]);
        }

        Container::navigationRepository()->update((int) $tile['id'], $validated);
        Container::settings()->update(['office_tile_status' => $mode]);
        app_logger()->info('Office-Kachel gestaltet.', ['admin' => Container::auth()->username(), 'id' => (int) $tile['id']]);
        Session::flash('success', 'Die Gestaltung der Office-Kachel wurde gespeichert.');

        return $this->redirect('/admin/office#kachel');
    }

    /**
     * Vorschau der Kachel mit den (noch ungespeicherten) Formularwerten. Wird
     * im Adminbereich in einem iframe mit dem Stylesheet der Landingpage
     * dargestellt; ungueltige Einzelwerte fallen auf den gespeicherten Stand zurueck.
     */
    public function tilePreview(Request $request): Response
    {
        $tile = $this->officeTile();
        if ($tile === null) {
            return Response::html('<!doctype html><title>Vorschau</title><p>Keine Office-Kachel vorhanden.</p>', 404);
        }

        $merged = $this->mergeTileDesign($tile, $request->query);
        $navigation = Container::navigation();
        $item = null;
        for ($attempt = 0; $attempt < 2 && $item === null; $attempt++) {
            try {
                $item = $navigation->validate($merged, false, (int) $tile['id']);
            } catch (ValidationException $exception) {
                foreach (array_keys($exception->errors()) as $field) {
                    $merged[$field] = $tile[$field] ?? null;
                }
            }
        }
        $item = array_merge($tile, $item ?? []);
        $item['override_background'] = !empty($item['override_background']) ? 1 : 0;

        $mode = (string) ($request->query['office_tile_status'] ?? '');
        if (!OfficeConfigService::isTileStatusMode($mode)) {
            $mode = Container::officeConfig()->tileStatusMode();
        }
        $state = (string) ($request->query['state'] ?? 'ok');
        if (!in_array($state, ['ok', 'degraded', 'down'], true)) {
            $state = 'ok';
        }

        $response = $this->view('admin.office-tile-preview', [
            'items' => [$item],
            'descriptionMode' => Container::settings()->descriptionMode(),
            'officeTileStatus' => $mode !== 'off',
            'officeTileStatusMode' => $mode,
            'officePreviewState' => $state,
        ], 'layouts.preview');

        return $response->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function officeTile(): ?array
    {
        return Container::navigationRepository()->findActiveInternalByUrl(PublicOfficeController::ENTRY_PATH);
    }

    /**
     * Uebernimmt nur die Gestaltungsfelder; Typ, Ziel, Sortierung und
     * Sichtbarkeit bleiben unveraendert (die Kachel muss /office-starten bleiben).
     *
     * @param array<string,mixed> $tile
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    private function mergeTileDesign(array $tile, array $input): array
    {
        $merged = $tile;
        foreach (self::TILE_DESIGN_KEYS as $key) {
            if (array_key_exists($key, $input) && is_string($input[$key])) {
                $merged[$key] = $input[$key];
            }
        }
        $merged['override_background'] = !empty($input['override_background']) ? '1' : '';
        if (isset($merged['icon']) && $merged['icon'] !== '' && !in_array($merged['icon'], self::TILE_ICONS, true)) {
            $merged['icon'] = 'document';
        }
        $merged['active'] = !empty($tile['active']) ? '1' : '';
        $merged['protected_access'] = !empty($tile['protected_access']) ? '1' : '';

        return $merged;
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $values
     * @param array<string,string> $tileErrors
     * @param array<string,mixed>|null $tileValues
     */
    private function render(array $errors = [], array $values = [], int $status = 200, array $tileErrors = [], ?array $tileValues = null): Response
    {
        $office = Container::officeConfig();
        $health = $office->isEnabled() ? Container::officeHealth()->cached() : null;
        $tile = Container::navigationRepository()->findActiveInternalByUrl(PublicOfficeController::ENTRY_PATH);

        $current = array_merge($office->formValues(), $values);
        $previewConfig = $office->footerPayload(
            Container::settings()->theme(),
            Container::logo()->current() !== null,
            $this->assetVersion()
        );
        $previewConfig['enabled'] = true;
        $previewConfig['preview'] = true;

        return $this->adminView('admin.office', [
            'pageTitle' => 'Office (Euro-Office)',
            'activeNav' => 'office',
            'enabled' => $office->isEnabled(),
            'jwtConfigured' => $office->hasJwtSecret(),
            'publicPath' => $office->publicPath(),
            'euroOfficePath' => $office->euroOfficePublicPath(),
            'values' => $current,
            'errors' => $errors,
            'health' => $health,
            'backup' => Container::officeBackup()->status(),
            'tile' => $tile,
            'tileValues' => $tileValues ?? ($tile !== null ? $tile + ['office_tile_status' => $office->tileStatusMode()] : null),
            'tileErrors' => $tileErrors,
            'tileStatusModes' => OfficeConfigService::TILE_STATUS_MODES,
            'tileIcons' => self::TILE_ICONS,
            'previewConfig' => $previewConfig,
            'pageScript' => 'admin-office.js',
        ], $status);
    }
}
