<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * Healthcheck ohne sensible Informationen.
 */
final class HealthController extends Controller
{
    public function index(Request $request): Response
    {
        $database = 'error';

        try {
            Database::connection()->query('SELECT 1');
            $database = 'ok';
        } catch (Throwable $exception) {
            app_logger()->error('Healthcheck: Datenbank nicht erreichbar.', ['error' => $exception->getMessage()]);
        }

        $status = $database === 'ok' ? 200 : 503;

        return Response::json([
            'status' => $status === 200 ? 'ok' : 'degraded',
            'database' => $database,
        ], $status);
    }
}
