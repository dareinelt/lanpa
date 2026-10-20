<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Security\CurrentUser;
use App\Services\OfflineSyncService;

/** JSON-Schnittstelle der Offline-Erfassung: Stammdaten-Cache laden und Warteschlange synchronisieren. */
final class OfflineController extends BaseController
{
    public function __construct(View $view, CurrentUser $currentUser, private readonly OfflineSyncService $sync)
    {
        parent::__construct($view, $currentUser);
    }

    public function bootstrap(Request $request): Response
    {
        return $this->json($this->sync->bootstrap());
    }

    /** POST {transactions: [...]} → {results: [...]} */
    public function sync(Request $request): Response
    {
        $body = $request->json();
        $transactions = $body['transactions'] ?? null;
        if (!is_array($transactions)) {
            throw ValidationException::single('transactions', 'Es wurden keine Vorgänge übermittelt.');
        }

        return $this->json(['results' => $this->sync->process(array_values($transactions)), 'server_time' => gmdate('c')]);
    }
}
