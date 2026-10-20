<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRuleRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\Mail\ImapMailboxClient;
use App\Services\Helpdesk\Mail\MailboxClientFactory;
use App\Services\Helpdesk\MailboxSettingsService;
use App\Services\Helpdesk\TicketMailIngestionService;

/**
 * Administration des Help-Desk-Postfachs („Auswertung & System → Administration → E-Mail-Postfach“).
 *
 * Hier wird vollständig in der Anwendung festgelegt, welches Postfach abgefragt wird, ob und wohin
 * abgearbeitete Nachrichten verschoben werden und wie eingehende E-Mails zu Tickets bzw. Kommentaren werden.
 */
final class HelpdeskMailboxController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly MailboxSettingsService $settings,
        private readonly TicketMailIngestionService $ingestion,
        private readonly TicketRuleRepository $rules,
        private readonly TicketMasterDataRepository $masterData,
        private readonly Config $config,
        private readonly string $basePath
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $this->currentUser->require('settings.manage');

        return $this->renderForm();
    }

    public function save(Request $request): Response
    {
        $this->currentUser->require('settings.manage');
        try {
            $this->settings->save($request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());
            $this->flash('error', 'Bitte Eingaben prüfen.');

            return $this->redirect('/admin/helpdesk-mail');
        }
        $this->flash('success', 'Einstellungen des E-Mail-Postfachs gespeichert.');

        return $this->redirect('/admin/helpdesk-mail');
    }

    /** Verbindung mit den gespeicherten Zugangsdaten prüfen und die Ordnerliste einlesen. */
    public function test(Request $request): Response
    {
        $this->currentUser->require('settings.manage');
        $client = null;
        try {
            $client = MailboxClientFactory::create($this->settings->mailboxOptions(), $this->basePath);
            if ($client instanceof ImapMailboxClient) {
                $status = $client->check();
                $_SESSION['_mailbox_folders'] = $status['folders'];
                $this->flash('success', sprintf(
                    'Verbindung erfolgreich: Postfach „%s“ mit %d Nachricht(en), %d Verzeichnis(se) gefunden.',
                    $status['mailbox'],
                    $status['messages'],
                    count($status['folders'])
                ));
            } else {
                $folders = $client->listMailboxes();
                $_SESSION['_mailbox_folders'] = $folders;
                $this->flash('success', sprintf('Quelle erreichbar: %s (%d Unterverzeichnis(se)).', $client->describe(), count($folders)));
            }
        } catch (\Throwable $e) {
            $this->flash('error', 'Verbindung fehlgeschlagen: ' . $e->getMessage());
        } finally {
            $client?->close();
        }

        return $this->redirect('/admin/helpdesk-mail');
    }

    /** Postfach sofort abholen (unabhängig vom Intervall). */
    public function run(Request $request): Response
    {
        $this->currentUser->require('settings.manage');
        if (!$this->settings->enabled()) {
            $this->flash('error', 'Der E-Mail-Eingang ist deaktiviert.');

            return $this->redirect('/admin/helpdesk-mail');
        }
        try {
            $result = $this->ingestion->pull();
            $this->flash('success', sprintf(
                '%d Nachricht(en) abgeholt: %d neue Tickets, %d Kommentare, %d ignoriert, %d fehlgeschlagen.',
                $result['fetched'],
                $result['created'],
                $result['comments'],
                $result['ignored'],
                $result['failed']
            ));
        } catch (\Throwable $e) {
            $this->settings->recordError($e->getMessage());
            $this->flash('error', 'Abruf fehlgeschlagen: ' . $e->getMessage());
        }

        return $this->redirect('/admin/helpdesk-mail');
    }

    private function renderForm(): Response
    {
        $settings = $this->settings->all();
        $folders = $_SESSION['_mailbox_folders'] ?? [];
        unset($_SESSION['_mailbox_folders']);
        $known = array_values(array_unique(array_filter(array_merge(
            is_array($folders) ? $folders : [],
            [(string) $settings['mailbox'], (string) $settings['processed_mailbox']]
        ), static fn (string $f): bool => $f !== '')));
        sort($known, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->render('admin.helpdesk_mail', [
            'title' => 'E-Mail-Postfach',
            'activeNav' => 'admin',
            'areaLabel' => 'Administration',
            'settings' => $settings,
            'drivers' => MailboxSettingsService::DRIVERS,
            'encryptions' => MailboxSettingsService::ENCRYPTIONS,
            'folders' => $known,
            'systemUsers' => $this->settings->systemUserCandidates(),
            'ticketTypes' => $this->masterData->types(),
            'lastRun' => $this->settings->lastRun(),
            'lastResult' => $this->settings->lastResult(),
            'recent' => $this->rules->recentInboundMails(20),
            'helpdeskEnabled' => (bool) $this->config->get('helpdesk.enabled', true),
            'schedulerHint' => (int) $settings['interval_minutes'],
        ]);
    }
}
