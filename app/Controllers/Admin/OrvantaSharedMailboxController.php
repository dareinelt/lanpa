<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Security\Session;
use PDOException;
use Throwable;

/**
 * Adminbereich "Office → Orvanta – Weitere Postfächer": je Benutzer die
 * Postfächer, auf die er auf dem Exchange-Server per Vollzugriff bzw.
 * "Senden als" berechtigt ist.
 *
 *   GET  /admin/office/orvanta/postfaecher             Übersicht und Formular
 *   POST /admin/office/orvanta/postfaecher/speichern   Zuordnung anlegen/ändern
 *   POST /admin/office/orvanta/postfaecher/pruefen     Erreichbarkeit über EWS prüfen
 *   POST /admin/office/orvanta/postfaecher/loeschen    Zuordnung entfernen
 *
 * Über EWS lässt sich nicht ermitteln, wer auf welche Postfächer berechtigt
 * ist; die Liste wird deshalb hier gepflegt. Orvanta prüft jede Zuordnung mit
 * dem Dienstkonto und blendet nicht erreichbare Postfächer aus. Archiviert
 * wird weiterhin ausschließlich das primäre Benutzerpostfach.
 */
final class OrvantaSharedMailboxController extends AdminController
{
    public const BASE = '/admin/office/orvanta/postfaecher';

    public function index(Request $request): Response
    {
        $service = Container::orvantaSharedMailboxes();
        $term = trim((string) $request->query('suche', ''));
        $editId = $request->queryInt('id', 0);
        $uid = trim((string) $request->query('benutzer', ''));
        $tablesMissing = false;
        $users = [];
        try {
            $entries = $service->forAdmin('');
            if ($term !== '') {
                $users = $service->searchUsers($term);
            }
        } catch (PDOException) {
            $entries = [];
            $tablesMissing = true;
        }

        $entry = null;
        foreach ($entries as $row) {
            if ($editId > 0 && $row['id'] === $editId) {
                $entry = $row;
                break;
            }
        }

        return $this->adminView('admin.orvanta-shared-mailboxes', [
            'pageTitle' => 'Office – Orvanta: Weitere Postfächer',
            'activeNav' => 'office_orvanta_shared',
            'entries' => $entries,
            'entry' => $entry,
            'term' => $term,
            'users' => $users,
            'uid' => $entry !== null ? $entry['uid'] : $uid,
            'orvantaEnabled' => Container::orvantaConfig()->isEnabled(),
            'orvantaDemo' => Container::orvantaConfig()->isDemo(),
            'tablesMissing' => $tablesMissing,
            'base' => self::BASE,
        ]);
    }

    /**
     * Zuordnung anlegen oder ändern. Die Erreichbarkeit des Postfachs wird
     * sofort über EWS geprüft; das Ergebnis entscheidet, ob es in Orvanta
     * erscheint.
     */
    public function save(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        try {
            $result = Container::orvantaSharedMailboxes()->save(
                (string) $request->input('benutzer', ''),
                (string) $request->input('postfach', ''),
                (string) $request->input('anzeigename', ''),
                [
                    'send_as' => !empty($request->post['senden_als']),
                    'active' => !empty($request->post['aktiv']),
                    'sort_order' => $request->inputInt('sortierung', 1),
                ]
            );
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());

            return $this->redirect(self::BASE . ($id > 0 ? '?id=' . $id : ''));
        } catch (PDOException $exception) {
            app_logger()->error('Orvanta: zusätzliches Postfach konnte nicht gespeichert werden.', ['error' => $exception->getMessage()]);
            Session::flash('error', 'Die Zuordnung konnte nicht gespeichert werden. Bitte die Datenbankmigrationen ausführen (php scripts/migrate.php, Migration 046).');

            return $this->redirect(self::BASE);
        }

        app_logger()->info('Orvanta: zusätzliches Postfach gespeichert.', [
            'admin' => Container::auth()->username(),
            'mailbox_id' => $result['id'],
            'verified' => $result['ok'],
        ]);
        if ($result['ok']) {
            Session::flash('success', 'Die Zuordnung wurde gespeichert; das Postfach ist über EWS erreichbar und erscheint für den Benutzer in Orvanta.');
        } else {
            Session::flash('error', 'Die Zuordnung wurde gespeichert, das Postfach ist aber nicht erreichbar: ' . $result['error']
                . ' Es erscheint erst, wenn es über das Dienstkonto geöffnet werden kann.');
        }

        return $this->redirect(self::BASE);
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
            Session::flash('success', 'Das Postfach ist über EWS erreichbar und steht dem Benutzer in Orvanta zur Verfügung.');
        } else {
            Session::flash('error', 'Das Postfach ist nicht erreichbar: ' . $error);
        }

        return $this->redirect(self::BASE);
    }

    public function delete(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        try {
            Container::orvantaSharedMailboxes()->delete($id);
            app_logger()->info('Orvanta: zusätzliches Postfach entfernt.', [
                'admin' => Container::auth()->username(),
                'mailbox_id' => $id,
            ]);
            Session::flash('success', 'Die Zuordnung wurde entfernt. Das Postfach wird dem Benutzer nicht mehr angezeigt.');
        } catch (PDOException $exception) {
            app_logger()->error('Orvanta: zusätzliches Postfach konnte nicht entfernt werden.', ['error' => $exception->getMessage()]);
            Session::flash('error', 'Die Zuordnung konnte nicht entfernt werden.');
        }

        return $this->redirect(self::BASE);
    }
}
