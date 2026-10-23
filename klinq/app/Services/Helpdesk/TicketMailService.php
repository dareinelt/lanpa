<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Core\Logger;
use App\Exceptions\ValidationException;
use App\Security\CurrentUser;
use App\Services\Lanpa\LanpaBridge;
use App\Services\MailClient;
use App\Support\HtmlSanitizer;

/**
 * E-Mail eines Agenten aus einem Ticket an die meldende und optional an die
 * betroffene Person.
 *
 * Der Text kommt aus dem Editor im Ticket, die Signatur stammt unveränderlich
 * aus dem Intranet (Orvanta-Signatur des angemeldeten Agenten) und wird erst
 * beim Versand serverseitig angehängt. Jede gesendete E-Mail steht danach als
 * öffentlicher Kommentar im Ticketverlauf; auf Wunsch wird das Ticket im
 * selben Schritt mit Grund und Ende pausiert.
 */
final class TicketMailService
{
    /** Obergrenze für den Nachrichtentext aus dem Editor (Zeichen). */
    public const MAX_BODY_CHARS = 200000;

    public const MAX_SUBJECT_CHARS = 180;

    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketNotificationService $notifications,
        private readonly MailClient $mail,
        private readonly LanpaBridge $bridge,
        private readonly CurrentUser $currentUser,
        private readonly Config $config,
        private readonly Logger $logger
    ) {}

    /**
     * Angaben für den Sendedialog. Die Signatur wird bewusst nicht mitgeliefert,
     * sondern beim Öffnen des Dialogs über {@see signature()} nachgeladen, damit
     * die Ticketseite nicht auf das Intranet warten muss.
     *
     * @param array<string,mixed> $ticket
     * @return array{available:bool,reason:string,requester:array{name:string,email:string},
     *               affected:array{name:string,email:string},subject:string,signature_supported:bool,
     *               signature_name:string,pauses:list<array{code:string,name:string}>,
     *               pause_default_until:string,max_chars:int}
     */
    public function composeData(array $ticket): array
    {
        $requester = [
            'name' => trim((string) ($ticket['requester_name'] ?? '')),
            'email' => self::firstEmail((string) ($ticket['requester_email'] ?? ''), (string) ($ticket['requester_user_email'] ?? '')),
        ];
        $affected = [
            'name' => trim((string) ($ticket['affected_name'] ?? '')),
            'email' => self::firstEmail((string) ($ticket['affected_email'] ?? ''), ''),
        ];
        if ($affected['email'] === $requester['email']) {
            $affected = ['name' => '', 'email' => ''];
        }

        $data = [
            'available' => false,
            'reason' => '',
            'requester' => $requester,
            'affected' => $affected,
            'subject' => $this->defaultSubject($ticket),
            'signature_supported' => $this->bridge->enabled(),
            'signature_name' => '',
            'pauses' => [],
            'pause_default_until' => $this->defaultPauseUntil(),
            'max_chars' => self::MAX_BODY_CHARS,
        ];

        if (!$this->tickets->isAgent()) {
            $data['reason'] = 'Nur Agenten können E-Mails aus einem Ticket senden.';

            return $data;
        }
        if (!empty($ticket['merged_into_ticket_id'])) {
            $data['reason'] = 'Zusammengeführte Tickets sind schreibgeschützt.';

            return $data;
        }
        if (!$this->mail->enabled()) {
            $data['reason'] = 'Der E-Mail-Versand ist nicht eingerichtet.';

            return $data;
        }
        if ($requester['email'] === '') {
            $data['reason'] = 'Für die meldende Person ist keine E-Mail-Adresse hinterlegt.';

            return $data;
        }

        $data['available'] = true;
        $pauses = [];
        foreach ($this->tickets->availableTransitions($ticket) as $transition) {
            if (empty($transition['is_pause'])) {
                continue;
            }
            $pauses[] = ['code' => (string) $transition['code'], 'name' => (string) $transition['name']];
        }
        $data['pauses'] = $pauses;

        return $data;
    }

    /**
     * Feste Signatur des angemeldeten Agenten aus dem Intranet.
     *
     * @return array{html:string,name:string}
     */
    public function signature(): array
    {
        $signature = $this->bridge->signature($this->currentUser->username());

        return [
            'html' => $signature['html'] ?? '',
            'name' => $signature['name'] ?? '',
        ];
    }

    /**
     * E-Mail versenden, im Ticketverlauf protokollieren und das Ticket optional pausieren.
     *
     * @param array<string,mixed> $input Formulardaten: subject, body_html, to_affected, pause_code, pause_until
     * @return array{sent:list<string>,subject:string,paused:bool,pause_name:string,pause_warning:string}
     */
    public function send(int $id, array $input): array
    {
        $this->currentUser->require('helpdesk.comment');
        $ticket = $this->tickets->getVisible($id);
        $compose = $this->composeData($ticket);
        if (!$compose['available']) {
            throw ValidationException::single('subject', $compose['reason']);
        }

        $subject = $this->cleanSubject((string) ($input['subject'] ?? ''));
        $html = HtmlSanitizer::clean((string) ($input['body_html'] ?? ''), self::MAX_BODY_CHARS);
        $text = HtmlSanitizer::plain($html);
        if ($text === '' && !str_contains($html, '<img')) {
            throw ValidationException::single('body_html', 'Bitte einen Nachrichtentext eingeben.');
        }

        $recipients = [$compose['requester']['email']];
        if (($input['to_affected'] ?? '') === '1' && $compose['affected']['email'] !== '') {
            $recipients[] = $compose['affected']['email'];
        }

        // Pausierung vorab prüfen, damit sie nicht erst nach dem Versand scheitert.
        $pause = $this->resolvePause($compose, $ticket, $input);

        $body = $this->renderBody($html, $pause);
        $root = $this->notifications->threadRootId((int) $ticket['id']);
        $messageId = $this->notifications->messageId((int) $ticket['id'], 'agent-mail');
        $sent = [];
        $failed = [];
        foreach ($recipients as $email) {
            $ok = $this->mail->send($email, $subject, $body, [], [
                'Message-ID' => $messageId,
                'In-Reply-To' => $root,
                'References' => $root,
                'X-Ticket-Number' => (string) $ticket['number'],
            ]);
            if ($ok) {
                $sent[] = $email;
            } else {
                $failed[] = $email;
            }
        }
        if ($sent === []) {
            throw ValidationException::single('body_html', 'Der E-Mail-Versand ist fehlgeschlagen. Bitte später erneut versuchen.');
        }
        if ($failed !== []) {
            $this->logger->warning('Ticket-E-Mail nur teilweise versendet', ['ticket' => $ticket['number'], 'failed' => $failed]);
        }

        $this->tickets->addOutboundMailComment(
            (int) $ticket['id'],
            $this->commentText($subject, $sent, $text),
            $subject,
            $sent,
            $messageId
        );

        $paused = false;
        $warning = $failed === [] ? '' : 'Nicht erreicht: ' . implode(', ', $failed) . '.';
        if ($pause !== null) {
            try {
                $this->tickets->changeStatus((int) $ticket['id'], $pause['code'], null, null, null, null, false, $pause['until'], false);
                $paused = true;
            } catch (\Throwable $e) {
                $this->logger->warning('Pausierung nach Ticket-E-Mail fehlgeschlagen', ['ticket' => $ticket['number'], 'error' => $e->getMessage()]);
                $warning = trim($warning . ' Die Pausierung konnte nicht gesetzt werden: ' . $e->getMessage());
            }
        }

        return [
            'sent' => $sent,
            'subject' => $subject,
            'paused' => $paused,
            'pause_name' => $pause['name'] ?? '',
            'pause_warning' => $warning,
        ];
    }

    /**
     * Pausengrund und -ende aus dem Formular prüfen.
     *
     * @param array<string,mixed> $compose
     * @param array<string,mixed> $ticket
     * @param array<string,mixed> $input
     * @return array{code:string,name:string,until:string,until_utc:string}|null
     */
    private function resolvePause(array $compose, array $ticket, array $input): ?array
    {
        $code = trim((string) ($input['pause_code'] ?? ''));
        if ($code === '') {
            return null;
        }
        $name = '';
        foreach ($compose['pauses'] as $pause) {
            if ($pause['code'] === $code) {
                $name = $pause['name'];
                break;
            }
        }
        if ($name === '') {
            throw ValidationException::single('pause_code', 'Dieser Pausengrund ist für das Ticket nicht zulässig.');
        }
        $until = trim((string) ($input['pause_until'] ?? ''));
        $untilUtc = $this->tickets->validatePauseUntil($until);

        return ['code' => $code, 'name' => $name, 'until' => $until, 'until_utc' => $untilUtc];
    }

    /**
     * Nachrichtentext mit Pausenhinweis und fester Signatur.
     *
     * @param array{code:string,name:string,until:string,until_utc:string}|null $pause
     */
    private function renderBody(string $html, ?array $pause): string
    {
        $body = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#222">' . $html;
        if ($pause !== null) {
            $until = $this->formatLocal($pause['until_utc']);
            $body .= '<p style="margin:18px 0 0;padding:10px 12px;border-left:3px solid #b0b0b0;background:#f4f4f4;font-size:13px;color:#444">'
                . 'Ihr Ticket ist bis zum ' . self::e($until) . ' wegen „' . self::e($pause['name']) . '“ pausiert. '
                . 'Ihre Rückmeldung startet die Bearbeitung automatisch wieder.</p>';
        }
        $signature = $this->signature();
        if ($signature['html'] !== '') {
            $body .= $signature['html'];
        }

        return $body . '</div>';
    }

    /**
     * @param list<string> $recipients
     */
    private function commentText(string $subject, array $recipients, string $text): string
    {
        return 'E-Mail an ' . implode(', ', $recipients) . "\nBetreff: " . $subject . "\n\n" . $text;
    }

    /** @param array<string,mixed> $ticket */
    private function defaultSubject(array $ticket): string
    {
        $subject = trim((string) ($ticket['subject'] ?? ''));

        return mb_substr('[' . (string) ($ticket['number'] ?? '') . '] ' . $subject, 0, self::MAX_SUBJECT_CHARS);
    }

    private function cleanSubject(string $subject): string
    {
        $subject = trim((string) preg_replace('/[\r\n]+/', ' ', $subject));
        if ($subject === '') {
            throw ValidationException::single('subject', 'Bitte einen Betreff angeben.');
        }
        if (mb_strlen($subject) > self::MAX_SUBJECT_CHARS) {
            throw ValidationException::single('subject', 'Der Betreff ist zu lang (max. ' . self::MAX_SUBJECT_CHARS . ' Zeichen).');
        }

        return $subject;
    }

    /** Vorschlag für das Pausenende: nächster Werktagmittag in der Zeitzone der Anwendung. */
    private function defaultPauseUntil(): string
    {
        $timezone = new \DateTimeZone((string) $this->config->get('app.timezone', 'Europe/Berlin'));
        $tomorrow = (new \DateTimeImmutable('tomorrow 12:00', $timezone));

        return $tomorrow->format('Y-m-d\TH:i');
    }

    /** UTC-Zeitstempel in der Zeitzone der Anwendung ausgeben. */
    private function formatLocal(string $utc): string
    {
        $timezone = new \DateTimeZone((string) $this->config->get('app.timezone', 'Europe/Berlin'));

        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($timezone)->format('d.m.Y H:i');
    }

    private static function firstEmail(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = mb_strtolower(trim($candidate));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return $candidate;
            }
        }

        return '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
