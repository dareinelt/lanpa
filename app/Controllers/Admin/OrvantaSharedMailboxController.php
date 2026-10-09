<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Security\Session;
use App\Services\Orvanta\OrvantaSharedMailboxService;
use PDOException;
use Throwable;

/**
 * Adminbereich "Office → Orvanta – Weitere Postfächer": je Benutzer die
 * Postfächer, auf die er auf dem Exchange-Server per Vollzugriff bzw.
 * "Senden als" berechtigt ist.
 *
 *   GET  /admin/office/orvanta/postfaecher             Übersicht (nur lesend)
 *   POST /admin/office/orvanta/postfaecher/pruefen     Erreichbarkeit über EWS erneut prüfen
 *
 * Die Berechtigungen werden ausschließlich im Exchange (ECP) gepflegt; Orvanta
 * übernimmt die per Auto-Mapping eingebundenen Postfächer beim Öffnen der App
 * aus dem AD (OrvantaDelegateDirectory). Eine manuelle Zuordnung gibt es
 * nicht. Orvanta prüft jedes Postfach als der Benutzer (Exchange bestätigt
 * dessen Vollzugriff) und blendet nicht erreichbare Postfächer aus. „Senden
 * als“ prüft Exchange beim Versand. Archiviert wird weiterhin ausschließlich
 * das primäre Benutzerpostfach.
 */
final class OrvantaSharedMailboxController extends AdminController
{
    public const BASE = '/admin/office/orvanta/postfaecher';

    public function index(Request $request): Response
    {
        $tablesMissing = false;
        try {
            $entries = Container::orvantaSharedMailboxes()->forAdmin('');
        } catch (PDOException) {
            $entries = [];
            $tablesMissing = true;
        }

        return $this->adminView('admin.orvanta-shared-mailboxes', [
            'pageTitle' => 'Office – Orvanta: Weitere Postfächer',
            'activeNav' => 'office_orvanta_shared',
            'entries' => $entries,
            'orvantaEnabled' => Container::orvantaConfig()->isEnabled(),
            'orvantaDemo' => Container::orvantaConfig()->isDemo(),
            'tablesMissing' => $tablesMissing,
            'base' => self::BASE,
        ]);
    }

    /**
     * Erreichbarkeit eines Postfachs erneut prüfen (z. B. nachdem die
     * Berechtigung auf dem Exchange-Server eingerichtet wurde).
     */
    public function verify(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        try {
            $error = Container::orvantaSharedMailboxes()->verify($id, true);
        } catch (Throwable $exception) {
            $error = 'Prüfung fehlgeschlagen: ' . $exception->getMessage();
        }

        app_logger()->info('Orvanta: zusätzliches Postfach geprüft.', [
            'admin' => Container::auth()->username(),
            'mailbox_id' => $id,
            'ok' => $error === '',
        ]);
        if ($error === '') {
            Session::flash('success', 'Exchange bestätigt den Vollzugriff des Benutzers; das Postfach steht ihm in Orvanta zur Verfügung.');
        } elseif ($error === OrvantaSharedMailboxService::PENDING) {
            Session::flash('success', $error);
        } else {
            Session::flash('error', 'Das Postfach ist nicht erreichbar: ' . $error);
        }

        return $this->redirect(self::BASE);
    }
}
