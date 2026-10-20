<?php

declare(strict_types=1);

namespace App\Controllers\Mobile;

use App\Controllers\BaseController;
use App\Controllers\MovementController;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\MovementRepository;
use App\Security\CurrentUser;
use App\Services\DocumentService;
use App\Services\MailClient;
use App\Services\MovementService;

/**
 * Mobile Erfassung (/m): Scannen → Asset → Entnahme/Retoure mit möglichst wenigen Eingaben.
 * Die Seiten funktionieren ohne JavaScript (normale Formulare); JS ergänzt Kamera-Scanner und Schnellauswahl.
 */
final class MobileController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly AssetRepository $assets,
        private readonly MovementRepository $movements,
        private readonly MovementService $service,
        private readonly DocumentService $documents,
        private readonly EmployeeRepository $employees,
        private readonly LocationRepository $locations,
        private readonly CostCenterRepository $costCenters,
        private readonly MailClient $mail
    ) {
        parent::__construct($view, $currentUser);
    }

    public function scan(Request $request): Response
    {
        if (!$request->isMobile()) {
            // Kein Ziel (Entnahme/Retoure) bekannt: Overlay auf der Assetliste lässt es auswählen.
            return $this->redirect('/assets?scan=1');
        }

        return $this->render('mobile.scan', [
            'title' => 'Scannen',
            'activeNav' => 'scan',
            'recent' => $this->currentUser->can('movements.view') ? $this->movements->search(['status' => 'all'], 5) : [],
            'openCount' => $this->currentUser->can('movements.view') ? $this->movements->countSearch(['status' => 'open']) : 0,
            'code' => $request->queryString('code'),
        ]);
    }

    /** Scan/Eingabe auflösen → Assetkarte. */
    public function lookup(Request $request): Response
    {
        if (($guard = $this->requireMobileDevice($request, '/assets')) !== null) {
            return $guard;
        }
        $code = $request->queryString('code');
        $asset = $this->service->resolveAsset($code);
        if ($asset === null) {
            $this->flash('error', 'Kein Asset zu „' . $code . '“ gefunden.');

            return $this->redirect('/m?code=' . rawurlencode($code));
        }
        $action = $request->queryString('action');
        if ($action === 'checkout' || $action === 'return') {
            return $this->redirect('/m/' . $action . '?asset=' . rawurlencode((string) $asset['inventory_number']));
        }

        return $this->redirect('/m/asset/' . rawurlencode((string) $asset['inventory_number']));
    }

    public function asset(Request $request): Response
    {
        $inventory = (string) $request->param('inventory');
        if (($guard = $this->requireMobileDevice($request, '/assets?q=' . rawurlencode($inventory))) !== null) {
            return $guard;
        }
        $asset = $this->assetOrFail($inventory);

        return $this->render('mobile.asset', [
            'title' => $asset['inventory_number'],
            'activeNav' => 'scan',
            'asset' => $asset,
            'children' => $this->assets->children((int) $asset['id']),
            'open' => $this->movements->openForAsset((int) $asset['id']),
            'lastMovements' => $this->movements->forAsset((int) $asset['id'], 3),
        ]);
    }

    public function checkoutForm(Request $request): Response
    {
        if (($guard = $this->requireMobileDevice($request, '/movements/checkout?asset=' . rawurlencode($request->queryString('asset')))) !== null) {
            return $guard;
        }
        $this->currentUser->require('movements.checkout');
        $asset = $this->assetOrFail($request->queryString('asset'));
        if ($asset['employee_id'] !== null) {
            $this->flash('warning', 'Das Asset ist bereits an ' . $asset['employee_name'] . ' ausgegeben. Bitte zuerst die Rückgabe erfassen.');

            return $this->redirect('/m/asset/' . rawurlencode((string) $asset['inventory_number']));
        }
        if ((int) $asset['status_final'] === 1) {
            $this->flash('error', 'Das Asset ist ' . mb_strtolower((string) $asset['status_name']) . ' und kann nicht ausgegeben werden.');

            return $this->redirect('/m/asset/' . rawurlencode((string) $asset['inventory_number']));
        }
        $old = $_SESSION['_old_input'] ?? [];
        $employee = !empty($old['employee_id']) ? $this->employees->find((int) $old['employee_id']) : null;
        $locationId = $old['location_id'] ?? null;
        $location = !empty($locationId) ? $this->locations->find((int) $locationId) : null;
        $costCenterId = $old['cost_center_id'] ?? ($asset['cost_center_id'] ?? null);

        return $this->render('mobile.checkout', [
            'title' => 'Entnahme ' . $asset['inventory_number'],
            'activeNav' => 'scan',
            'asset' => $asset,
            'employee' => $employee,
            'location' => $location,
            'costCenterId' => $costCenterId,
            'costCenters' => $this->costCenters->activeForSelect(),
            'today' => date('Y-m-d'),
            'mailEnabled' => $this->mail->enabled(),
        ]);
    }

    public function checkout(Request $request): Response
    {
        if (($guard = $this->requireMobileDevice($request, '/movements/checkout?asset=' . rawurlencode((string) $request->input('inventory_number', '')))) !== null) {
            return $guard;
        }
        $input = $request->all();
        try {
            $movement = $this->service->checkout($input, 'mobile');
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/m/checkout?asset=' . rawurlencode((string) ($input['inventory_number'] ?? '')));
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/m/asset/' . rawurlencode((string) ($input['inventory_number'] ?? '')));
        }

        return $this->redirect('/m/done/' . (int) $movement['id']);
    }

    public function returnForm(Request $request): Response
    {
        if (($guard = $this->requireMobileDevice($request, '/movements/return?asset=' . rawurlencode($request->queryString('asset')))) !== null) {
            return $guard;
        }
        $this->currentUser->require('movements.return');
        $asset = $this->assetOrFail($request->queryString('asset'));
        if ($asset['employee_id'] === null && !in_array($asset['status_code'], ['issued', 'return_expected'], true)) {
            $this->flash('warning', 'Das Asset ist derzeit nicht ausgegeben (' . $asset['status_name'] . ').');

            return $this->redirect('/m/asset/' . rawurlencode((string) $asset['inventory_number']));
        }
        $old = $_SESSION['_old_input'] ?? [];
        $locationId = $old['to_location_id'] ?? null;
        // Vorschlag: Standort der letzten Entnahme (woher das Gerät kam), sonst nichts
        if (empty($locationId) && empty($old)) {
            $last = $this->movements->lastCheckoutForAsset((int) $asset['id']);
            $locationId = $last['from_location_id'] ?? null;
        }

        return $this->render('mobile.return', [
            'title' => 'Rückgabe ' . $asset['inventory_number'],
            'activeNav' => 'scan',
            'asset' => $asset,
            'children' => $this->assets->children((int) $asset['id']),
            'location' => !empty($locationId) ? $this->locations->find((int) $locationId) : null,
            'conditions' => MovementService::CONDITIONS,
            'returnTargets' => MovementService::RETURN_TARGETS,
            'canRetire' => $this->currentUser->can('assets.retire'),
            'today' => date('Y-m-d'),
            'mailEnabled' => $this->mail->enabled(),
        ]);
    }

    public function returnAsset(Request $request): Response
    {
        if (($guard = $this->requireMobileDevice($request, '/movements/return?asset=' . rawurlencode((string) $request->input('inventory_number', '')))) !== null) {
            return $guard;
        }
        $input = $request->all();
        $photos = MovementController::normalizeUploads($request->files()['photos'] ?? null);
        try {
            $movement = $this->service->returnAsset($input, 'mobile');
            foreach ($photos as $photo) {
                $this->documents->store('movement', (int) $movement['id'], 'photo', $photo, null, $this->documents->imageExtensions(), 'photos');
            }
        } catch (ValidationException $e) {
            if (isset($movement)) {
                // Rückgabe ist gespeichert, nur ein Foto war ungültig
                $this->flash('warning', 'Rückgabe gespeichert, aber ein Foto wurde abgelehnt: ' . implode(' ', $e->errors()));

                return $this->redirect('/m/done/' . (int) $movement['id']);
            }
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/m/return?asset=' . rawurlencode((string) ($input['inventory_number'] ?? '')));
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/m/asset/' . rawurlencode((string) ($input['inventory_number'] ?? '')));
        }

        return $this->redirect('/m/done/' . (int) $movement['id']);
    }

    public function done(Request $request): Response
    {
        if (($guard = $this->requireMobileDevice($request, '/movements/' . $request->paramInt('id'))) !== null) {
            return $guard;
        }
        $movement = $this->findOrFail($this->movements->find($request->paramInt('id')), 'Vorgang nicht gefunden');

        return $this->render('mobile.done', [
            'title' => $movement['type'] === 'checkout' ? 'Entnahme gespeichert' : 'Rückgabe gespeichert',
            'activeNav' => 'scan',
            'movement' => $movement,
            'missing' => MovementService::missingLabels($movement),
        ]);
    }

    /** Kompakte Liste offener Vorgänge für unterwegs. */
    public function open(Request $request): Response
    {
        if (($guard = $this->requireMobileDevice($request, '/movements/open')) !== null) {
            return $guard;
        }
        $this->currentUser->require('movements.view');

        return $this->render('mobile.open', [
            'title' => 'Offene Vorgänge',
            'activeNav' => 'open',
            'rows' => $this->movements->search(['status' => 'open'], 100),
        ]);
    }

    /** @return array<string,mixed> */
    private function assetOrFail(string $inventory): array
    {
        return $this->findOrFail($this->service->resolveAsset($inventory), 'Asset „' . $inventory . '“ nicht gefunden');
    }

    /**
     * Die Kamera-Scan-Erfassung (`/m`) ist auf Mobilgeräte beschränkt (Kamera, Touch-Bedienung).
     * Auf dem Desktop wird stattdessen die manuelle Erfassung mit elektronischer Signatur verwendet.
     */
    private function requireMobileDevice(Request $request, string $desktopRedirect): ?Response
    {
        if ($request->isMobile()) {
            return null;
        }
        $this->flash('info', 'Die Scan-Funktion ist nur auf Mobilgeräten verfügbar. Für den Desktop bitte die manuelle Erfassung nutzen.');

        return $this->redirect($desktopRedirect);
    }
}
