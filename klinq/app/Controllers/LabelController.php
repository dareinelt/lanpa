<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetRepository;
use App\Security\CurrentUser;
use App\Services\LabelService;

final class LabelController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly LabelService $labels,
        private readonly AssetRepository $assets,
        private readonly AssetController $assetController
    ) {
        parent::__construct($view, $currentUser);
    }

    /** Druckansicht: /labels?ids=1,2,3 oder /labels?<Listenfilter> */
    public function print(Request $request): Response
    {
        $dummy = $request->bool('dummy');
        $ids = $dummy ? [] : $this->idsFromRequest($request);
        $filters = !$dummy && $ids === [] ? $this->assetController->filters($request) : [];
        $rows = $dummy ? [$this->labels->dummyAsset()] : $this->labels->assetsFor($ids, $filters);
        $layout = $this->labels->layout();
        $labels = array_map(fn (array $row): array => $this->labels->labelFor($row, $layout), $rows);
        $copies = max(1, min(20, $request->int('copies', 1) ?? 1));

        return $this->render('labels.print', [
            'title' => 'Etiketten drucken',
            'activeNav' => 'assets',
            'labels' => $labels,
            'layout' => $layout,
            'copies' => $copies,
            'ids' => $dummy ? [] : array_map(static fn (array $l): int => $l['id'], $labels),
            'backUrl' => $this->backUrl($request, $ids !== [] && count($ids) === 1 ? '/assets/' . $ids[0] : '/assets'),
            'limitHit' => !$dummy && count($rows) >= 500,
            'autoprint' => $dummy && $request->bool('autoprint'),
        ]);
    }

    /** JSON: nach ausgelöstem Druck Historie schreiben. */
    public function printed(Request $request): Response
    {
        $ids = $this->idsFromRequest($request);
        if ($ids === []) {
            return $this->json(['ok' => false, 'error' => 'Keine Assets angegeben.'], 422);
        }
        $count = $this->labels->recordPrinted($ids);

        return $this->json(['ok' => true, 'count' => $count]);
    }

    /** QR-Ziel: /a/{inventory} → Assetdetail (oder mobile Aktion, wenn angegeben). */
    public function resolve(Request $request): Response
    {
        $number = strtoupper(trim((string) $request->param('inventory')));
        $asset = $this->assets->findByInventoryNumber($number);
        if ($asset === null) {
            throw new NotFoundException('Kein Asset mit der Inventarnummer ' . $number . ' gefunden.');
        }
        $action = $request->queryString('action');
        if ($action === 'checkout' || $action === 'return') {
            return $this->redirect('/m/' . $action . '?asset=' . rawurlencode($number));
        }

        return $this->redirect('/assets/' . (int) $asset['id']);
    }

    /** Layoutabhängiges CSS; ohne Parameter = gespeichertes Layout, mit Parametern = Vorschau. */
    public function stylesheet(Request $request): Response
    {
        $layout = $request->query() === [] ? $this->labels->layout() : $this->labels->layoutFromInput($request->query());

        return new Response($this->labels->stylesheet($layout), 200, ['Content-Type' => 'text/css; charset=UTF-8', 'Cache-Control' => 'no-cache']);
    }

    public function logo(Request $request): Response
    {
        $path = $this->labels->logoPath();
        $mime = $this->labels->logoMime();
        if (!is_file($path) || $mime === null) {
            throw new NotFoundException('Kein Logo hinterlegt.');
        }

        // Logo nur als Bild darstellen: keine Skripte, kein Zugriff auf die Anwendung (auch bei SVG)
        return Response::file($path, $mime, basename($path), true)
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }

    // ---------------------------------------------------------------- Administration

    public function settings(Request $request): Response
    {
        return $this->renderSettings($this->labels->layout());
    }

    public function saveSettings(Request $request): Response
    {
        try {
            $this->labels->saveLayout($request->all(), $request->file('logo'), $request->string('remove_logo') === '1');
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());
            $this->flash('error', 'Bitte Eingaben prüfen.');

            return $this->redirect('/admin/labels');
        }
        $this->flash('success', 'Etikettenlayout gespeichert.');

        return $this->redirect('/admin/labels');
    }

    /** JSON-Vorschau mit unveränderten (nicht gespeicherten) Layoutwerten. */
    public function preview(Request $request): Response
    {
        $layout = $this->labels->layoutFromInput($request->all());
        $sample = $this->labels->sampleAsset();
        $label = $this->labels->labelFor($sample, $layout);

        return $this->json(['label' => $label, 'layout' => $layout]);
    }

    /** @param array<string,string> $layout */
    private function renderSettings(array $layout): Response
    {
        $sample = $this->labels->sampleAsset();

        return $this->render('admin.labels', [
            'title' => 'Etikettenlayout',
            'activeNav' => 'admin',
            'layout' => $layout,
            'sample' => $this->labels->labelFor($sample, $layout),
            'extraFields' => LabelService::EXTRA_FIELDS,
            'qrPositions' => LabelService::QR_POSITIONS,
            'hasLogo' => $layout['logo_file'] !== '' && is_file($this->labels->logoPath()),
        ]);
    }

    /** @return array<int,int> */
    private function idsFromRequest(Request $request): array
    {
        $raw = $request->all()['ids'] ?? $request->query()['ids'] ?? '';
        $parts = is_array($raw) ? $raw : (preg_split('/[\s,]+/', (string) $raw) ?: []);
        $ids = [];
        foreach ($parts as $part) {
            $id = (int) $part;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return array_slice($ids, 0, 500);
    }
}
