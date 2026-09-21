<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Security\Csrf;

/**
 * Öffentliche Endpunkte fuer den SMS-Code-Schutz geschuetzter Navigationselemente.
 */
final class SmsCodeController extends Controller
{
    public function send(Request $request): Response
    {
        if (!Csrf::isValid($request->input('_token'))) {
            return Response::json(['status' => 'error', 'message' => 'Die Sitzung ist abgelaufen. Bitte Seite neu laden.'], 419);
        }

        $result = Container::smsCode()->requestCode(
            $request->inputInt('navigation_id', 0),
            (string) $request->input('phone', '')
        );

        return Response::json($result, $result['status'] === 'success' ? 200 : 422);
    }

    public function verify(Request $request): Response
    {
        if (!Csrf::isValid($request->input('_token'))) {
            return Response::json(['status' => 'error', 'message' => 'Die Sitzung ist abgelaufen. Bitte Seite neu laden.'], 419);
        }

        $result = Container::smsCode()->verify(
            $request->inputInt('navigation_id', 0),
            (string) $request->input('phone', ''),
            (string) $request->input('code', '')
        );

        return Response::json($result, $result['status'] === 'success' ? 200 : 422);
    }
}
