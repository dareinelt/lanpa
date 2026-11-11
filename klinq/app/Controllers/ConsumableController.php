<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\ArticleRepository;
use App\Security\CurrentUser;
use App\Services\ArticleService;

/**
 * „Bestand → Verbrauchsmaterial“: Übersicht aller Verbrauchsartikel (nur Menge, keine Einzel-Assets)
 * mit Bestand, Mindestbestand und schneller Bestandskorrektur.
 */
final class ConsumableController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly ArticleRepository $articles,
        private readonly ArticleService $service
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'q' => trim($request->queryString('q')),
            'below' => $request->queryString('below'),
            'active' => $request->queryString('active', '1'),
        ];
        $rows = $this->articles->consumables($filters);
        $belowCount = count(array_filter($rows, static fn (array $r): bool => $r['minimum_stock'] !== null && (int) $r['stock_quantity'] <= (int) $r['minimum_stock']));

        return $this->render('consumables.index', [
            'title' => 'Verbrauchsmaterial',
            'activeNav' => 'consumables',
            'rows' => $rows,
            'filters' => $filters,
            'belowCount' => $belowCount,
            'basePath' => '/consumables',
        ]);
    }

    /** Bestand eines Verbrauchsartikels setzen (Inventur, Ausgabe aus dem Lager). */
    public function stock(Request $request): Response
    {
        $id = $request->paramInt('id');
        $article = $this->findOrFail($this->articles->find($id), 'Artikel nicht gefunden');
        try {
            $stock = $this->service->setStock($id, $article, $request->all());
        } catch (ValidationException $e) {
            $this->flash('error', implode(' ', $e->errors()));

            return $this->redirect($this->backUrl($request, '/consumables'));
        }
        $this->flash('success', 'Bestand von „' . $article['name'] . '“ auf ' . $stock . ' gesetzt.');

        return $this->redirect($this->backUrl($request, '/consumables'));
    }
}
