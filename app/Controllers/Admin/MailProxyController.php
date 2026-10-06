<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Security\Session;
use App\Support\Validator;

/**
 * Adminbereich "Office → SMTP-/IMAP-Proxy": Mailserver je
 * Identitaetsquelle, Postfaecher (Passwoerter verschluesselt) und Zuordnung
 * AD-Benutzer → Postfach fuer Orvanta-Benutzer ohne Exchange-Postfach.
 *
 *   GET  /admin/office/mail-proxy?quelle=&bearbeiten=&postfach=   Seite
 *   POST /admin/office/mail-proxy/server                  Konfiguration speichern
 *   POST /admin/office/mail-proxy/server/status           aktivieren/deaktivieren
 *   POST /admin/office/mail-proxy/server/loeschen         loeschen (ohne Postfaecher)
 *   POST /admin/office/mail-proxy/postfach                Postfach speichern
 *   POST /admin/office/mail-proxy/postfach/loeschen       Postfach loeschen
 *   POST /admin/office/mail-proxy/postfach/test           Verbindung testen (ohne Versand)
 *   POST /admin/office/mail-proxy/zuordnung               Zuordnung speichern
 *   POST /admin/office/mail-proxy/zuordnung/loeschen      Zuordnung entfernen
 *   GET  /admin/office/mail-proxy/users?quelle=&q=        Vorschlaege AD-Benutzer (JSON)
 *   GET  /admin/office/mail-proxy/mailboxes?quelle=&q=    Vorschlaege Postfaecher (JSON)
 *
 * Alle Routen liegen in der Admin-Gruppe; schreibende Aktionen pruefen das
 * CSRF-Token als Erstes. Passwoerter werden nie ausgegeben.
 */
final class MailProxyController extends AdminController
{
    public const BASE = '/admin/office/mail-proxy';

    public function index(Request $request): Response
    {
        $service = Container::mailProxy();
        $sourceParam = $request->query('quelle');
        $sourceId = $sourceParam !== null && $sourceParam !== '' ? max(0, (int) $sourceParam) : null;
        $tablesMissing = false;
        try {
            $overview = $service->overview($sourceId);
        } catch (\PDOException) {
            $tablesMissing = true;
            $overview = ['sources' => $service->sources(), 'servers' => [], 'selected' => null, 'server' => null, 'mailboxes' => [], 'mappings' => []];
        }
        $editServer = null;
        $editId = $request->queryInt('bearbeiten', 0);
        foreach ($overview['servers'] as $server) {
            if ((int) $server['id'] === $editId) {
                $editServer = $server;
            }
        }
        $editMailbox = null;
        $mailboxId = $request->queryInt('postfach', 0);
        foreach ($overview['mailboxes'] as $mailbox) {
            if ((int) $mailbox['id'] === $mailboxId) {
                $editMailbox = $mailbox;
            }
        }

        return $this->adminView('admin.mail-proxy', [
            'pageTitle' => 'Office – SMTP-/IMAP-Proxy',
            'activeNav' => 'office_mail_proxy',
            'pageScript' => 'admin-mail-proxy.js',
            'overview' => $overview,
            'editServer' => $editServer,
            'editMailbox' => $editMailbox,
            'tablesMissing' => $tablesMissing,
            'orvantaEnabled' => Container::orvantaConfig()->isEnabled(),
            'smtpPorts' => \App\Services\MailProxy\MailProxyService::SMTP_PORTS,
            'imapPorts' => \App\Services\MailProxy\MailProxyService::IMAP_PORTS,
        ]);
    }

    public function saveServer(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        $input = [
            'identity_source_id' => $request->inputInt('identity_source_id', -1),
            'name' => (string) $request->input('name', ''),
            'smtp_host' => (string) $request->input('smtp_host', ''),
            'smtp_port' => $request->inputInt('smtp_port', 0),
            'smtp_security' => (string) $request->input('smtp_security', ''),
            'smtp_auth' => $request->input('smtp_auth', '') !== '',
            'imap_host' => (string) $request->input('imap_host', ''),
            'imap_port' => $request->inputInt('imap_port', 0),
            'imap_security' => (string) $request->input('imap_security', ''),
            'verify_tls' => $request->input('verify_tls', '') !== '',
            'timeout_seconds' => $request->inputInt('timeout_seconds', 20),
            'active' => $request->input('active', '') !== '',
        ];
        try {
            $savedId = Container::mailProxy()->saveServer($input, $id);
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());

            return $this->redirect(self::BASE . ($id > 0 ? '?bearbeiten=' . $id : ''));
        }
        $server = Container::mailProxyRepository()->findServer($savedId);
        app_logger()->info('Mail-Proxy: Konfiguration gespeichert.', ['admin' => Container::auth()->username(), 'server_id' => $savedId]);
        Session::flash('success', 'Die Proxy-Konfiguration wurde gespeichert.' . (!$input['verify_tls'] ? ' Achtung: Die Zertifikatsprüfung ist deaktiviert.' : ''));

        return $this->redirect(self::BASE . '?quelle=' . (int) ($server['identity_source_id'] ?? 0));
    }

    public function toggleServer(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        $active = $request->input('active', '') === '1';
        try {
            Container::mailProxy()->setServerActive($id, $active);
            Session::flash('success', $active ? 'Die Proxy-Konfiguration ist aktiv.' : 'Die Proxy-Konfiguration wurde deaktiviert. Zugeordnete Benutzer verwenden wieder Exchange.');
            app_logger()->info('Mail-Proxy: Konfiguration ' . ($active ? 'aktiviert' : 'deaktiviert') . '.', ['admin' => Container::auth()->username(), 'server_id' => $id]);
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        }

        return $this->redirect(self::BASE . $this->sourceQuery($request));
    }

    public function deleteServer(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        try {
            Container::mailProxy()->deleteServer($id);
            Session::flash('success', 'Die Proxy-Konfiguration wurde gelöscht.');
            app_logger()->info('Mail-Proxy: Konfiguration gelöscht.', ['admin' => Container::auth()->username(), 'server_id' => $id]);
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        }

        return $this->redirect(self::BASE);
    }

    public function saveMailbox(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        $serverId = $request->inputInt('server_id', 0);
        // Passwort roh lesen (Request::input() kuerzt Leerzeichen); nie protokollieren.
        $password = is_string($request->post['password'] ?? null) ? $request->post['password'] : '';
        $input = [
            'username' => (string) $request->input('username', ''),
            'email_address' => (string) $request->input('email_address', ''),
            'display_name' => (string) $request->input('display_name', ''),
            'quota_mb' => (string) $request->input('quota_mb', '0'),
            'active' => $request->input('active', '') !== '',
        ];
        try {
            $savedId = Container::mailProxy()->saveMailbox($serverId, $input, $password, $id);
        } catch (\InvalidArgumentException $exception) {
            unset($password);
            Session::flash('error', $exception->getMessage());

            return $this->redirect(self::BASE . $this->sourceQuery($request) . ($id > 0 ? '&postfach=' . $id : '') . '#postfaecher');
        }
        unset($password);
        app_logger()->info('Mail-Proxy: Postfach gespeichert.', ['admin' => Container::auth()->username(), 'mailbox_id' => $savedId]);
        Session::flash('success', 'Das Postfach wurde gespeichert.');

        return $this->redirect(self::BASE . $this->sourceQuery($request) . '#postfaecher');
    }

    public function deleteMailbox(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        try {
            Container::mailProxy()->deleteMailbox($id);
            Session::flash('success', 'Das Postfach und seine Zuordnung wurden gelöscht.');
            app_logger()->info('Mail-Proxy: Postfach gelöscht.', ['admin' => Container::auth()->username(), 'mailbox_id' => $id]);
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        }

        return $this->redirect(self::BASE . $this->sourceQuery($request) . '#postfaecher');
    }

    public function testMailbox(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        $result = Container::mailProxy()->testConnection($id);
        $details = array_map(
            static fn (array $check): string => $check['name'] . ': ' . ($check['ok'] ? 'OK' : 'Fehler') . ($check['message'] !== '' ? ' (' . $check['message'] . ')' : ''),
            $result['checks']
        );
        Session::flash($result['ok'] ? 'success' : 'error', $result['message'] . ($details !== [] ? ' – ' . implode('; ', $details) : ''));

        return $this->redirect(self::BASE . $this->sourceQuery($request) . '#postfaecher');
    }

    public function saveMapping(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        $sourceId = $request->inputInt('quelle', -1);
        try {
            $savedId = Container::mailProxy()->saveMapping($sourceId, $request->inputInt('phonebook_id', 0), $request->inputInt('mailbox_id', 0), $id);
            Session::flash('success', 'Die Zuordnung wurde gespeichert.');
            app_logger()->info('Mail-Proxy: Zuordnung gespeichert.', ['admin' => Container::auth()->username(), 'mapping_id' => $savedId]);
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        }

        return $this->redirect(self::BASE . $this->sourceQuery($request) . '#zuordnung');
    }

    public function deleteMapping(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        try {
            Container::mailProxy()->deleteMapping($id);
            Session::flash('success', 'Die Zuordnung wurde entfernt. Der Benutzer verwendet wieder Exchange.');
            app_logger()->info('Mail-Proxy: Zuordnung entfernt.', ['admin' => Container::auth()->username(), 'mapping_id' => $id]);
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        }

        return $this->redirect(self::BASE . $this->sourceQuery($request) . '#zuordnung');
    }

    /**
     * Vorschlaege AD-Benutzer der Identitaetsquelle (synchronisierter Bestand).
     */
    public function users(Request $request): Response
    {
        return $this->json(fn (): array => Container::mailProxy()->suggestUsers(
            max(0, $request->queryInt('quelle', 0)),
            Validator::cleanText((string) $request->query('q', ''), 100)
        ));
    }

    /**
     * Vorschlaege aktiver, noch nicht vergebener Postfaecher der Identitaetsquelle.
     */
    public function mailboxes(Request $request): Response
    {
        return $this->json(fn (): array => Container::mailProxy()->suggestMailboxes(
            max(0, $request->queryInt('quelle', 0)),
            Validator::cleanText((string) $request->query('q', ''), 100),
            max(0, $request->queryInt('zuordnung', 0))
        ));
    }

    /**
     * @param \Closure(): list<array<string,mixed>> $items
     */
    private function json(\Closure $items): Response
    {
        try {
            $list = $items();
        } catch (\PDOException) {
            $list = [];
        }

        return Response::json(['items' => $list])->withHeader('Cache-Control', 'no-store');
    }

    private function sourceQuery(Request $request): string
    {
        return '?quelle=' . max(0, $request->inputInt('quelle', 0));
    }
}
