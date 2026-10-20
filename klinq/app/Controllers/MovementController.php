<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\MovementRepository;
use App\Security\CurrentUser;
use App\Services\AuthService;
use App\Services\DocumentService;
use App\Services\LocationService;
use App\Services\MovementService;
use App\Support\Paginator;

/** Desktop: Bewegungsliste/Tagesübersicht, offene Vorgänge (Arbeitsliste) und Nachbearbeitung. */
final class MovementController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly MovementRepository $movements,
        private readonly MovementService $service,
        private readonly DocumentRepository $documents,
        private readonly DocumentService $documentService,
        private readonly EmployeeRepository $employees,
        private readonly LocationRepository $locations,
        private readonly CostCenterRepository $costCenters,
        private readonly AssetTypeRepository $types,
        private readonly AuthService $auth
    ) {
        parent::__construct($view, $currentUser);
    }

    /**
     * Manuelle Entnahme am Desktop (kein Kamera-Scan, siehe /m). Der Vorgang wird erst nach
     * erneuter Eingabe des eigenen Passworts (elektronische Signatur) gespeichert.
     */
    public function checkoutCaptureForm(Request $request): Response
    {
        $this->currentUser->require('movements.checkout');
        if (($guard = $this->requireSignatureOptIn()) !== null) {
            return $guard;
        }
        $code = $request->queryString('asset');
        if ($code === '') {
            return $this->pickAssetForm('checkout');
        }
        $asset = $this->service->resolveAsset($code);
        if ($asset === null) {
            $this->flash('error', 'Asset „' . $code . '“ nicht gefunden.');

            return $this->pickAssetForm('checkout');
        }
        if ($asset['employee_id'] !== null) {
            $this->flash('warning', 'Das Asset ist bereits an ' . $asset['employee_name'] . ' ausgegeben. Bitte zuerst die Rückgabe erfassen.');

            return $this->redirect('/assets/' . (int) $asset['id']);
        }
        if ((int) $asset['status_final'] === 1) {
            $this->flash('error', 'Das Asset ist ' . mb_strtolower((string) $asset['status_name']) . ' und kann nicht ausgegeben werden.');

            return $this->redirect('/assets/' . (int) $asset['id']);
        }
        $old = $_SESSION['_old_input'] ?? [];
        $costCenterId = $old['cost_center_id'] ?? ($asset['cost_center_id'] ?? null);

        return $this->render('movements.checkout_capture', array_merge($this->formOptions(), [
            'title' => 'Entnahme ' . $asset['inventory_number'],
            'activeNav' => 'assets',
            'asset' => $asset,
            'costCenterId' => $costCenterId,
            'today' => date('Y-m-d'),
        ]));
    }

    public function checkoutCaptureSubmit(Request $request): Response
    {
        $this->currentUser->require('movements.checkout');
        if (($guard = $this->requireSignatureOptIn()) !== null) {
            return $guard;
        }
        $input = $request->all();
        if (!$this->verifySignaturePassword($request)) {
            $this->withOldInput($request, ['signature_password' => 'Passwort falsch. Bitte das eigene Passwort zur elektronischen Signatur erneut eingeben.']);

            return $this->redirect('/movements/checkout?asset=' . rawurlencode((string) ($input['inventory_number'] ?? '')));
        }
        try {
            $movement = $this->service->checkout($input, 'web', true);
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/movements/checkout?asset=' . rawurlencode((string) ($input['inventory_number'] ?? '')));
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/assets?q=' . rawurlencode((string) ($input['inventory_number'] ?? '')));
        }
        $this->flash('success', 'Entnahme gespeichert und elektronisch signiert.');

        return $this->redirect('/movements/' . (int) $movement['id']);
    }

    public function returnCaptureForm(Request $request): Response
    {
        $this->currentUser->require('movements.return');
        if (($guard = $this->requireSignatureOptIn()) !== null) {
            return $guard;
        }
        $code = $request->queryString('asset');
        if ($code === '') {
            return $this->pickAssetForm('return');
        }
        $asset = $this->service->resolveAsset($code);
        if ($asset === null) {
            $this->flash('error', 'Asset „' . $code . '“ nicht gefunden.');

            return $this->pickAssetForm('return');
        }
        if ($asset['employee_id'] === null && !in_array($asset['status_code'], ['issued', 'return_expected'], true)) {
            $this->flash('warning', 'Das Asset ist derzeit nicht ausgegeben (' . $asset['status_name'] . ').');

            return $this->redirect('/assets/' . (int) $asset['id']);
        }
        $old = $_SESSION['_old_input'] ?? [];
        $locationId = $old['to_location_id'] ?? null;
        if (empty($locationId) && empty($old)) {
            $last = $this->movements->lastCheckoutForAsset((int) $asset['id']);
            $locationId = $last['from_location_id'] ?? null;
        }

        return $this->render('movements.return_capture', array_merge($this->formOptions(), [
            'title' => 'Rückgabe ' . $asset['inventory_number'],
            'activeNav' => 'assets',
            'asset' => $asset,
            'locationId' => $locationId,
            'canRetire' => $this->currentUser->can('assets.retire'),
            'today' => date('Y-m-d'),
        ]));
    }

    public function returnCaptureSubmit(Request $request): Response
    {
        $this->currentUser->require('movements.return');
        if (($guard = $this->requireSignatureOptIn()) !== null) {
            return $guard;
        }
        $input = $request->all();
        if (!$this->verifySignaturePassword($request)) {
            $this->withOldInput($request, ['signature_password' => 'Passwort falsch. Bitte das eigene Passwort zur elektronischen Signatur erneut eingeben.']);

            return $this->redirect('/movements/return?asset=' . rawurlencode((string) ($input['inventory_number'] ?? '')));
        }
        $photos = self::normalizeUploads($request->files()['photos'] ?? null);
        try {
            $movement = $this->service->returnAsset($input, 'web', true);
            foreach ($photos as $photo) {
                $this->documentService->store('movement', (int) $movement['id'], 'photo', $photo, null, $this->documentService->imageExtensions(), 'photos');
            }
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/movements/return?asset=' . rawurlencode((string) ($input['inventory_number'] ?? '')));
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/assets?q=' . rawurlencode((string) ($input['inventory_number'] ?? '')));
        }
        $this->flash('success', 'Rückgabe gespeichert und elektronisch signiert.');

        return $this->redirect('/movements/' . (int) $movement['id']);
    }

    /** Bewegungen mit Zeitraum – Standard: heute. */
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $range = $request->queryString('range', $filters['date_from'] === '' && $filters['date_to'] === '' ? 'today' : 'custom');
        [$filters['date_from'], $filters['date_to']] = $this->rangeDates($range, $filters['date_from'], $filters['date_to']);
        $paginator = new Paginator($this->movements->countSearch($filters), $request->int('page', 1) ?? 1, $request->int('per_page', 50) ?? 50);

        return $this->render('movements.index', array_merge($this->formOptions(), [
            'title' => 'Bewegungen',
            'activeNav' => 'movements',
            'rows' => $this->movements->search($filters, $paginator->perPage, $paginator->offset()),
            'filters' => $filters,
            'range' => $range,
            'summary' => $this->movements->summary($filters['date_from'] ?: '1970-01-01', $filters['date_to'] ?: '2999-12-31'),
            'paginator' => $paginator,
            'basePath' => '/movements',
            'query' => $request->query(),
        ]));
    }

    /** Arbeitsliste: offene Entnahmen/Retouren zur Nachbearbeitung. */
    public function open(Request $request): Response
    {
        $filters = $this->filters($request);
        $filters['status'] = 'open';
        $paginator = new Paginator($this->movements->countSearch($filters), $request->int('page', 1) ?? 1, $request->int('per_page', 50) ?? 50);

        return $this->render('movements.open', array_merge($this->formOptions(), [
            'title' => 'Offene Vorgänge',
            'activeNav' => 'open-checkouts',
            'rows' => $this->movements->search($filters, $paginator->perPage, $paginator->offset()),
            'filters' => $filters,
            'summary' => $this->movements->summary(date('Y-m-d'), date('Y-m-d')),
            'paginator' => $paginator,
            'basePath' => '/movements/open',
            'query' => $request->query(),
        ]));
    }

    public function show(Request $request): Response
    {
        $row = $this->findOrFail($this->movements->find($request->paramInt('id')), 'Vorgang nicht gefunden');

        return $this->render('movements.show', array_merge($this->formOptions(), [
            'title' => ($row['type'] === 'checkout' ? 'Entnahme' : 'Retoure') . ' ' . $row['inventory_number'],
            'activeNav' => $row['status'] === 'open' ? 'open-checkouts' : 'movements',
            'row' => $row,
            'documents' => $this->documents->forEntity('movement', (int) $row['id']),
            'missing' => MovementService::missingLabels($row),
            'back' => $this->backUrl($request, $row['status'] === 'open' ? '/movements/open' : '/movements'),
        ]));
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->movements->find($id), 'Vorgang nicht gefunden');
        $complete = $request->string('action') === 'complete';
        try {
            $updated = $this->service->update($id, $row, $request->all(), $complete);
            foreach ($this->photoUploads($request) as $photo) {
                $this->documentService->store('movement', $id, 'photo', $photo, null, $this->documentService->imageExtensions(), 'photos');
            }
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/movements/' . $id);
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/movements/' . $id);
        }
        $this->flash('success', $updated['status'] === 'completed' && $row['status'] !== 'completed' ? 'Vorgang abgeschlossen.' : 'Vorgang gespeichert.');

        return $this->redirect($updated['status'] === 'completed' && $row['status'] === 'open' ? '/movements/open' : '/movements/' . $id);
    }

    public function cancel(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->movements->find($id), 'Vorgang nicht gefunden');
        try {
            $this->service->cancel($id, $row, $request->string('reason'));
        } catch (ValidationException $e) {
            $this->flash('error', implode(' ', $e->errors()));

            return $this->redirect('/movements/' . $id);
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/movements/' . $id);
        }
        $this->flash('success', 'Vorgang storniert; das Asset wurde zurückgesetzt.');

        return $this->redirect('/assets/' . (int) $row['asset_id']);
    }

    /** @return array<string,string> */
    private function filters(Request $request): array
    {
        return [
            'q' => $request->queryString('q'),
            'type' => $request->queryString('type'),
            'status' => $request->queryString('status'),
            'date_from' => $request->queryString('date_from'),
            'date_to' => $request->queryString('date_to'),
            'employee_id' => $request->queryString('employee_id'),
            'location_id' => $request->queryString('location_id'),
            'cost_center_id' => $request->queryString('cost_center_id'),
            'asset_type_id' => $request->queryString('asset_type_id'),
            'missing' => $request->queryString('missing'),
            'source' => $request->queryString('source'),
        ];
    }

    /** @return array{0:string,1:string} */
    private function rangeDates(string $range, string $from, string $to): array
    {
        $today = date('Y-m-d');

        return match ($range) {
            'today' => [$today, $today],
            'yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
            'week' => [date('Y-m-d', strtotime('monday this week')), $today],
            'month' => [date('Y-m-01'), $today],
            'all' => ['', ''],
            default => [$from, $to],
        };
    }

    /** @return array<string,mixed> */
    private function formOptions(): array
    {
        return [
            'employees' => $this->employees->activeForSelect(),
            'locationOptions' => LocationService::flatten($this->locations->all(true)),
            'costCenters' => $this->costCenters->activeForSelect(),
            'types' => $this->types->all(true),
            'conditions' => MovementService::CONDITIONS,
            'returnTargets' => MovementService::RETURN_TARGETS,
            'sources' => MovementService::SOURCES,
        ];
    }

    /** Mehrfach-Upload `photos[]` in einzelne $_FILES-Einträge zerlegen. @return array<int,array<string,mixed>> */
    public static function normalizeUploads(?array $files): array
    {
        if ($files === null || !isset($files['name'])) {
            return [];
        }
        if (!is_array($files['name'])) {
            return ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$files];
        }
        $out = [];
        foreach ($files['name'] as $i => $name) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $name, 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '', 'error' => $files['error'][$i] ?? UPLOAD_ERR_OK, 'size' => $files['size'][$i] ?? 0];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function photoUploads(Request $request): array
    {
        return self::normalizeUploads($request->files()['photos'] ?? null);
    }

    /** @return array<string,mixed> */
    private function assetOrFail(string $inventory): array
    {
        return $this->findOrFail($this->service->resolveAsset($inventory), 'Asset „' . $inventory . '“ nicht gefunden');
    }

    /**
     * Zwischenschritt, wenn beim Aufruf der Entnahme-/Rückgabe-Erfassung noch kein Asset feststeht
     * (z. B. nach der Wahl „Entnahme“/„Retoure“ im Scan-Overlay auf dem Desktop).
     */
    private function pickAssetForm(string $action): Response
    {
        return $this->render('movements.pick_asset', [
            'title' => ($action === 'checkout' ? 'Entnahme' : 'Rückgabe') . ' – Asset wählen',
            'activeNav' => 'assets',
            'action' => $action,
        ]);
    }

    /**
     * Die digitale Signatur (Passwort-Bestätigung) ist ein Opt-in je Benutzer, das in der
     * Benutzerverwaltung von einem Administrator freigeschaltet werden muss.
     */
    private function requireSignatureOptIn(): ?Response
    {
        if ($this->currentUser->canSignElectronically()) {
            return null;
        }
        $this->flash('error', 'Die digitale Signatur für Entnahme/Rückgabe ist für Ihr Konto nicht freigeschaltet. Bitte eine Administratorin oder einen Administrator bitten, dies in der Benutzerverwaltung zu aktivieren.');

        return $this->redirect('/assets');
    }

    /**
     * Elektronische Signatur: der angemeldete Benutzer bestätigt Entnahme/Rückgabe durch erneute
     * Eingabe seines eigenen Passworts (kein Kamera-Scan/keine Unterschrift wie im mobilen Bereich).
     */
    private function verifySignaturePassword(Request $request): bool
    {
        $password = (string) $request->input('signature_password', '');
        if ($password === '') {
            return false;
        }

        return $this->auth->attempt($this->currentUser->username(), $password) !== null;
    }
}
