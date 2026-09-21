<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Security\Csrf;

final class AlarmTriggerController extends Controller
{
    public function store(Request $request): Response
    {
        if (!Csrf::isValid($request->input('_token'))) {
            return Response::json(['status' => 'error', 'message' => 'Die Sitzung ist abgelaufen. Bitte Seite neu laden.'], 419);
        }

        $navigationId = $request->inputInt('navigation_id', 0);
        if ($navigationId <= 0) {
            return Response::json(['status' => 'error', 'message' => 'Ungültige Anfrage.'], 422);
        }

        $item = Container::navigation()->find($navigationId);
        if ($item !== null && !empty($item['protected_access']) && !Container::smsCode()->isVerified($navigationId)) {
            return Response::json(['status' => 'error', 'message' => 'Für diese Alarmierung ist ein Zugangscode erforderlich.'], 422);
        }

        $additionalText = (string) $request->input('additional_text', '');

        $result = Container::alarm()->trigger($navigationId, $additionalText);

        return Response::json($result, $result['status'] === 'success' ? 200 : 422);
    }
}
