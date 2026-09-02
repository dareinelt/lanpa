<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;

/**
 * Erfasst Klicks auf Navigationselemente (nur navigation_id + Zeitstempel).
 */
final class ClickController extends Controller
{
    public function store(Request $request): Response
    {
        $navigationId = $request->inputInt('navigation_id', 0);

        if ($navigationId <= 0) {
            $payload = json_decode(file_get_contents('php://input') ?: '', true);
            if (is_array($payload) && isset($payload['navigation_id']) && is_numeric($payload['navigation_id'])) {
                $navigationId = (int) $payload['navigation_id'];
            }
        }

        if ($navigationId <= 0) {
            return Response::json(['recorded' => false], 400);
        }

        $recorded = Container::statistics()->record($navigationId);

        return Response::json(['recorded' => $recorded], $recorded ? 202 : 404);
    }
}
