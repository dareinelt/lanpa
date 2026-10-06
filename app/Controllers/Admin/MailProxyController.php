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
 *   GET  /admin/office/mail-proxy?quelle=                 Uebersicht
 *   GET  /admin/office/mail-proxy/server/neu              Overlay: Konfiguration anlegen
 *   GET  /admin/office/mail-proxy/server/bearbeiten?id=   Overlay: Konfiguration bearbeiten
 *   GET  /admin/office/mail-proxy/postfach/neu?quelle=    Overlay: Postfach anlegen
 *   GET  /admin/office/mail-proxy/postfach/bearbeiten?id= Overlay: Postfach bearbeiten
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
        return $this->render(null, $request->queryInt('quelle', -1));
    }

    /**
     * Uebersicht mit geoeffnetem Overlay zum Anlegen einer Konfiguration.
     */
    public function createServer(Request $request): Response
    {
        return $this->render(
            $this->serverDialog(null, ['identity_source_id' => $request->queryInt('quelle', -1)]),
            $request->queryInt('quelle', -1)
        );
    }

    /**
     * Uebersicht mit geoeffnetem Overlay zum Bearbeiten einer Konfiguration.
     */
    public function editServer(Request $request): Response
    {
        $server = Container::mailProxyRepository()->findServer($request->queryInt('id', 0));
        if ($server === null) {
            Session::flash('error', 'Die Proxy-Konfiguration wurde nicht gefunden.');

            return $this->redirect(self::BASE);
        }

        return $this->render($this->serverDialog($server), (int) $server['identity_source_id']);
    }

    /**
     * Uebersicht mit geoeffnetem Overlay zum Anlegen eines Postfachs.
     */
    public function createMailbox(Request $request): Response
    {
        $server = Container::mailProxyRepository()->findServerBySource($request->queryInt('quelle', 0));
        if ($server === null) {
            Session::flash('error', 'Für diese Identitätsquelle ist noch kein Mailserver konfiguriert.');

            return $this->redirect(self::BASE);
        }

        return $this->render($this->mailboxDialog($server, null), (int) $server['identity_source_id']);
    }

    /**
     * Uebersicht mit geoeffnetem Overlay zum Bearbeiten eines Postfachs.
     */
    public function editMailbox(Request $request): Response
    {
        $mailbox = Container::mailProxyRepository()->findMailbox($request->queryInt('id', 0));
        if ($mailbox === null) {
            Session::flash('error', 'Das Postfach wurde nicht gefunden.');

            return $this->redirect(self::BASE);
        }
        $server = Container::mailProxyRepository()->findServer((int) $mailbox['server_id']);

        return $this->render($this->mailboxDialog($server, $mailbox), $server !== null ? (int) $server['identity_source_id'] : -1);
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
            $server = $id > 0 ? Container::mailProxyRepository()->findServer($id) : null;

            return $this->render(
                $this->serverDialog($server, $input, $exception->getMessage()),
                $server !== null ? (int) $server['identity_source_id'] : (int) $input['identity_source_id'],
                422
            );
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
            $server = Container::mailProxyRepository()->findServer($serverId);
            if ($server === null) {
                return $this->redirect(self::BASE);
            }
            $mailbox = $id > 0 ? Container::mailProxyRepository()->findMailbox($id) : null;

            return $this->render(
                $this->mailboxDialog($server, $mailbox, $input, $exception->getMessage()),
                (int) $server['identity_source_id'],
                422
            );
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
     * Rendert die Uebersicht und - sofern vorhanden - das Bearbeitungs-Overlay
     * einer Konfiguration bzw. eines Postfachs.
     *
     * @param array<string,mixed>|null $dialog
     */
    private function render(?array $dialog, int $sourceId = -1, int $status = 200): Response
    {
        $service = Container::mailProxy();
        $tablesMissing = false;
        try {
            $overview = $service->overview($sourceId >= 0 ? $sourceId : null);
        } catch (\PDOException) {
            $tablesMissing = true;
            $overview = ['sources' => $service->sources(), 'servers' => [], 'selected' => null, 'server' => null, 'mailboxes' => [], 'mappings' => []];
        }

        return $this->adminView('admin.mail-proxy', [
            'pageTitle' => 'Office – SMTP-/IMAP-Proxy',
            'activeNav' => 'office_mail_proxy',
            'pageScript' => 'admin-mail-proxy.js',
            'overview' => $overview,
            'dialog' => $dialog,
            'tablesMissing' => $tablesMissing,
            'orvantaEnabled' => Container::orvantaConfig()->isEnabled(),
            'smtpPorts' => \App\Services\MailProxy\MailProxyService::SMTP_PORTS,
            'imapPorts' => \App\Services\MailProxy\MailProxyService::IMAP_PORTS,
        ], $status);
    }

    /**
     * Overlay-Daten fuer das Anlegen/Bearbeiten einer Konfiguration. Die
     * gespeicherten Werte werden mit den Eingaben eines fehlgeschlagenen POST
     * ueberschrieben, damit das Overlay nach Validierungsfehlern vorbelegt ist.
     *
     * @param array<string,mixed>|null $server
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function serverDialog(?array $server, array $input = [], ?string $error = null): array
    {
        $editing = $server !== null;
        $configured = [];
        foreach (Container::mailProxyRepository()->servers() as $row) {
            $configured[(int) $row['identity_source_id']] = true;
        }
        $freeSources = array_values(array_filter(
            Container::mailProxy()->sources(),
            static fn (array $source): bool => !isset($configured[(int) $source['id']])
        ));
        $values = array_merge([
            'id' => 0,
            'identity_source_id' => 0,
            'name' => '',
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_security' => 'starttls',
            'smtp_auth' => true,
            'imap_host' => '',
            'imap_port' => 993,
            'imap_security' => 'tls',
            'verify_tls' => true,
            'timeout_seconds' => 20,
            'active' => true,
        ], $editing ? $server : [], $input);

        if (!$editing) {
            $requestedSourceId = (int) ($values['identity_source_id'] ?? 0);
            $freeIds = array_map(static fn (array $source): int => (int) $source['id'], $freeSources);
            if (!in_array($requestedSourceId, $freeIds, true)) {
                $values['identity_source_id'] = (int) ($freeSources[0]['id'] ?? 0);
            }
        }

        return [
            'type' => 'server',
            'title' => $editing ? 'Konfiguration bearbeiten' : 'Neue Konfiguration',
            'action' => self::BASE . '/server',
            'error' => $error,
            'editing' => $editing,
            'values' => $values,
            'freeSources' => $freeSources,
        ];
    }

    /**
     * Overlay-Daten fuer das Anlegen/Bearbeiten eines Postfachs.
     *
     * @param array<string,mixed>|null $server
     * @param array<string,mixed>|null $mailbox
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function mailboxDialog(?array $server, ?array $mailbox, array $input = [], ?string $error = null): array
    {
        $editing = $mailbox !== null;
        $values = array_merge([
            'username' => '',
            'email_address' => '',
            'display_name' => '',
            'quota_mb' => 0,
            'active' => true,
        ], $editing ? $mailbox : [], $input);

        return [
            'type' => 'mailbox',
            'title' => $editing ? 'Postfach bearbeiten' : 'Neues Postfach',
            'action' => self::BASE . '/postfach',
            'error' => $error,
            'editing' => $editing,
            'server' => $server,
            'values' => $values,
        ];
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
