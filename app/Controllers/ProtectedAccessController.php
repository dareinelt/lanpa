<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;

/**
 * Zugangscode-Schranke fuer geschuetzte interne Navigationselemente.
 *
 * Interne Elemente (z. B. /telefonliste) werden nicht ueber einen eigenen
 * PageController ausgeliefert, deshalb uebernimmt dieser Controller die
 * serverseitige Zugriffssperre. Unterseiten und Textseiten werden bereits in
 * PageController geschuetzt.
 */
final class ProtectedAccessController extends Controller
{
    public function show(Request $request): Response
    {
        $item = Container::navigation()->find($request->queryInt('id', 0));

        if ($item === null || empty($item['protected_access'])) {
            throw new HttpException(404, 'Die Seite wurde nicht gefunden.');
        }

        if (Container::smsCode()->isVerified((int) $item['id'])) {
            return $this->redirect(Container::smsCode()->targetUrl($item));
        }

        $id = (int) $item['id'];

        return $this->view('pages.access_required', [
            'pageTitle' => 'Geschützter Zugriff',
            'item' => $item,
            'breadcrumb' => Container::navigation()->breadcrumb($id),
            'href' => Container::smsCode()->targetUrl($item),
            'pageScript' => 'landing.js',
        ]);
    }
}
