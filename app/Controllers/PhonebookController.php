<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Services\PhonebookService;

final class PhonebookController extends Controller
{
    public function index(Request $request): Response
    {
        $service = Container::phonebook();

        return $this->view('phonebook.index', [
            'pageTitle' => 'Telefonliste',
            'entryCount' => $service->countVisible(Container::auth()->check()),
            'lastSync' => $service->lastSyncedAt(),
            'emergencyNumbers' => Container::emergencyNumbers()->activeItems(),
            'activeNav' => 'phonebook',
            'pageScript' => 'phonebook.js',
        ]);
    }

    /**
     * JSON-Endpunkt fuer die Suche (Debouncing erfolgt im Frontend).
     */
    public function search(Request $request): Response
    {
        $term = (string) $request->query('q', '');
        $limit = $request->queryInt('limit', PhonebookService::DEFAULT_LIMIT);
        $offset = $request->queryInt('offset', 0);

        $result = Container::phonebook()->search($term, $limit, $offset, Container::auth()->check());

        return Response::json($result)->withHeader('Cache-Control', 'no-store');
    }
}
