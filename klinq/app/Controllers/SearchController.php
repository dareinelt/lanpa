<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Security\CurrentUser;
use App\Services\SearchService;

final class SearchController extends BaseController
{
    public function __construct(View $view, CurrentUser $currentUser, private readonly SearchService $search)
    {
        parent::__construct($view, $currentUser);
    }

    /** JSON für die Topbar-Suche (Tipp-Vorschläge). */
    public function api(Request $request): Response
    {
        $term = $request->queryString('q');

        return $this->json([
            'direct' => $this->search->resolveDirect($term),
            'groups' => $this->search->search($term, 5),
        ]);
    }

    /** Ergebnisseite (Enter in der Suche). Exakte Inventarnummer → direkt zum Asset. */
    public function page(Request $request): Response
    {
        $term = trim($request->queryString('q'));
        $direct = $this->search->resolveDirect($term);
        if ($direct !== null) {
            return $this->redirect($direct);
        }

        return $this->render('search.index', [
            'title' => 'Suche',
            'activeNav' => '',
            'term' => $term,
            'groups' => $this->search->search($term, 25),
        ]);
    }
}
