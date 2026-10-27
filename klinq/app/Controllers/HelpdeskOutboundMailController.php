<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Security\CurrentUser;
use App\Services\Helpdesk\Mail\SmtpTransport;
use App\Services\Helpdesk\OutboundMailSettingsService;
use App\Services\MailClient;

/**
 * Administration des Versandkontos für ausgehende Help-Desk-E-Mails
 * („Auswertung & System → Administration → E-Mail-Versand“).
 *
 * Hier werden Server, Zugangsdaten, Absender und „Antwort-an“ festgelegt. Verbindung und Anmeldung
 * lassen sich prüfen und eine Testnachricht versenden; ohne aktives Konto versendet der Help Desk
 * weiterhin über den Mail-Container.
 */
final class HelpdeskOutboundMailController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly OutboundMailSettingsService $settings,
        private readonly SmtpTransport $transport,
        private readonly MailClient $mail,
        private readonly Config $config
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

            return $this->redirect('/admin/helpdesk-outgoing-mail');
        }
        $this->flash('success', 'Einstellungen des E-Mail-Versands gespeichert.');

        return $this->redirect('/admin/helpdesk-outgoing-mail');
    }

    /** Verbindung, TLS und Anmeldung mit den gespeicherten Zugangsdaten prüfen (ohne Nachricht). */
    public function test(Request $request): Response
    {
        $this->currentUser->require('settings.manage');
        if (!$this->settings->configured()) {
            $this->flash('error', 'Für den Test sind Server und Absenderadresse erforderlich.');

            return $this->redirect('/admin/helpdesk-outgoing-mail');
        }
        try {
            $banner = $this->transport->probe($this->settings->mailerOptions());
            $this->flash('success', sprintf('Verbindung erfolgreich (Server meldet: %s).', $banner));
        } catch (\Throwable $e) {
            $this->flash('error', 'Verbindung fehlgeschlagen: ' . $e->getMessage());
        }

        return $this->redirect('/admin/helpdesk-outgoing-mail');
    }

    /** Testnachricht über das Versandkonto senden (Empfänger frei wählbar). */
    public function sendTest(Request $request): Response
    {
        $this->currentUser->require('settings.manage');
        $to = trim($request->string('test_recipient'));
        if ($to === '') {
            $to = $this->settings->fromAddress();
        }
        if (!SmtpTransport::isEmail($to)) {
            $this->flash('error', 'Bitte eine gültige Empfängeradresse angeben.');

            return $this->redirect('/admin/helpdesk-outgoing-mail');
        }
        if (!$this->settings->configured()) {
            $this->flash('error', 'Für die Testnachricht sind Server und Absenderadresse erforderlich.');

            return $this->redirect('/admin/helpdesk-outgoing-mail');
        }
        try {
            $this->transport->send(
                $this->settings->mailerOptions(),
                $to,
                'Testnachricht des Help Desks',
                '<p>Diese Nachricht wurde über das in der Administration hinterlegte Versandkonto gesendet.</p>'
                    . '<p>Absender: ' . htmlspecialchars($this->settings->fromAddress(), ENT_QUOTES, 'UTF-8') . '<br>'
                    . 'Antwort an: ' . htmlspecialchars($this->settings->replyTo() !== '' ? $this->settings->replyTo() : $this->settings->fromAddress(), ENT_QUOTES, 'UTF-8') . '</p>'
            );
            $this->flash('success', 'Testnachricht an ' . $to . ' gesendet.');
        } catch (\Throwable $e) {
            $this->flash('error', 'Versand fehlgeschlagen: ' . $e->getMessage());
        }

        return $this->redirect('/admin/helpdesk-outgoing-mail');
    }

    private function renderForm(): Response
    {
        return $this->render('admin.helpdesk_outgoing_mail', [
            'title' => 'E-Mail-Versand',
            'activeNav' => 'admin',
            'areaLabel' => 'Administration',
            'settings' => $this->settings->all(),
            'encryptions' => OutboundMailSettingsService::ENCRYPTIONS,
            'usesAccount' => $this->mail->usesAccount(),
            'containerUrl' => trim((string) $this->config->get('mail.service_url', '')),
        ]);
    }
}
