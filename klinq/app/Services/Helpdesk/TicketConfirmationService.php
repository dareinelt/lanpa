<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;

/**
 * Eingangsbestätigung für Meldungen, die nicht von einem Agenten im Help Desk erfasst wurden:
 * Störungsformular (/stoerung) und Störungspostfach. Bei Agenten-Tickets wird sie nur auf
 * ausdrücklichen Wunsch (Checkbox im Assistenten) versendet.
 *
 * Der Text stammt aus Help-Desk-Einstellungen → Vorlageneinstellungen und wird je Empfänger
 * mit Anrede und Namen gefüllt. Bei Sammelkonten erhält zusätzlich der verpflichtend
 * angegebene Benutzer eine Bestätigung.
 */
final class TicketConfirmationService
{
    /** Ereignisschlüssel in `ticket_notifications` – je Empfänger wird nur einmal zugestellt. */
    public const EVENT_KEY = 'created_confirmation';

    public function __construct(
        private readonly ReplyTemplateSettingsService $templates,
        private readonly TicketNotificationService $notifications,
        private readonly Config $config
    ) {
    }

    /**
     * Versendet die Bestätigung an den Melder und – falls abweichend – an den zusätzlichen
     * Benutzer (Betroffener bzw. bei Sammelkonten die verpflichtend angegebene Person).
     *
     * @param array<string,mixed> $ticket
     * @return int Anzahl erfolgreich versendeter Mails (0 = keine Vorlage hinterlegt)
     */
    public function send(array $ticket): int
    {
        $template = $this->templates->confirmationText();
        if ($template === '') {
            return 0;
        }

        $sent = 0;
        foreach ($this->recipients($ticket) as $email => $name) {
            $html = ReplyTemplateSettingsService::render($template, $this->values($ticket, $name));
            $sent += $this->notifications->sendTo($ticket, self::EVENT_KEY, [$email], 'Eingangsbestätigung', $html);
        }

        return $sent;
    }

    /**
     * Empfänger der Bestätigung mit Anzeigename für die Anrede.
     *
     * @param array<string,mixed> $ticket
     * @return array<string,string> E-Mail (klein geschrieben) → Anzeigename
     */
    private function recipients(array $ticket): array
    {
        $candidates = [
            [(string) ($ticket['requester_email'] ?? ''), (string) ($ticket['requester_name'] ?? '')],
            [(string) ($ticket['requester_user_email'] ?? ''), (string) ($ticket['requester_user_name'] ?? '')],
            [(string) ($ticket['affected_email'] ?? ''), (string) ($ticket['affected_name'] ?? '')],
        ];

        $out = [];
        foreach ($candidates as [$email, $name]) {
            $email = mb_strtolower(trim($email));
            if ($email === '' || isset($out[$email]) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $out[$email] = trim($name);
        }

        return $out;
    }

    /**
     * Platzhalterwerte für einen Empfänger.
     *
     * @param array<string,mixed> $ticket
     * @return array<string,string>
     */
    private function values(array $ticket, string $name): array
    {
        // Ohne eigenen Namen: Meldername, bei externen Mails der Anzeigename des Absenders.
        // Bleibt beides leer, wird {name} aus dem Text entfernt.
        $name = $name !== '' ? $name : trim((string) ($ticket['requester_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($ticket['mail_from_name'] ?? ''));
        }
        $base = rtrim((string) $this->config->get('app.url', ''), '/');

        return [
            '{anrede}' => 'Guten Tag',
            '{name}' => $name,
            '{ticketnummer}' => (string) ($ticket['number'] ?? ''),
            '{betreff}' => (string) ($ticket['subject'] ?? ''),
            '{datum}' => $this->formatDate((string) ($ticket['created_at'] ?? '')),
            '{link}' => $base . '/portal/tickets/' . (int) ($ticket['id'] ?? 0),
            '{firma}' => (string) $this->config->get('app.company_name', ''),
        ];
    }

    /** Zeitstempel der Datenbank (UTC) in der Zeitzone des Servers ausgeben. */
    private function formatDate(string $value): string
    {
        if ($value === '' || str_starts_with($value, '0000')) {
            return '';
        }
        $date = date_create($value, new \DateTimeZone('UTC'));
        if ($date === false) {
            return '';
        }
        $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        return $date->format('d.m.Y H:i');
    }
}
