<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Core\Logger;
use App\Repositories\HelpdeskSupportShiftRepository;
use App\Repositories\TicketCommentRepository;
use App\Repositories\TicketRepository;

/**
 * Erinnerung für den tagesweisen 2nd-Level-Support:
 *
 * - Wer sich für den heutigen Tag als 2nd Level zuständig gemeldet hat, sieht in der Kopfzeile
 *   (Glocke) alle offenen Tickets, die nicht vom heutigen Tag stammen und an diesem Tag noch nicht
 *   durch ihn kommentiert wurden.
 * - Wird ein solches Ticket bis 23:59 Uhr (APP_TIMEZONE) nicht kommentiert, erzeugt der Scheduler
 *   (HelpdeskSchedulerService) automatisch einen internen Kommentar
 *   „Zuständig am dd.mm.yyyy war Anzeigename, kein Kommentar erfolgt.“.
 *
 * Die Verarbeitung ist je Tag/Level idempotent (reminder_finalized_at).
 */
final class SecondLevelReminderService
{
    private const SECOND_LEVEL = 2;
    private const DEADLINE_HOUR = 23;
    private const DEADLINE_MINUTE = 59;

    public function __construct(
        private readonly HelpdeskSupportShiftRepository $shifts,
        private readonly TicketRepository $tickets,
        private readonly TicketCommentRepository $comments,
        private readonly Config $config,
        private readonly Logger $logger
    ) {}

    /** Nutzer-ID des heutigen 2nd Level Supports (neuester Eintrag des Tages, unabhängig vom Aktivstatus). */
    public function secondLevelUserIdToday(?\DateTimeImmutable $nowUtc = null): ?int
    {
        $entry = $this->shifts->secondLevelEntryForDate($this->localToday($nowUtc));

        return $entry !== null ? (int) $entry['user_id'] : null;
    }

    /**
     * Benachrichtigungen (Glocke) für den angemeldeten Benutzer: offene Tickets, die nicht vom heutigen
     * Tag stammen und heute noch nicht durch ihn kommentiert wurden. Leer, wenn er nicht der heutige
     * 2nd Level ist. @return array<int,array{id:int,number:string,subject:string,url:string}>
     */
    public function notificationsFor(int $userId, ?\DateTimeImmutable $nowUtc = null): array
    {
        if ($this->secondLevelUserIdToday($nowUtc) !== $userId) {
            return [];
        }

        return $this->pendingTickets($userId, $this->localToday($nowUtc));
    }

    /**
     * Tagesabschluss: erzeugt für abgelaufene 2nd-Level-Tage die „kein Kommentar erfolgt“-Kommentare.
     * Liefert die Anzahl neu erzeugter Kommentare.
     */
    public function createMissedComments(?\DateTimeImmutable $nowUtc = null): int
    {
        $nowUtc ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowLocal = $nowUtc->setTimezone($this->timezone());

        $byDate = [];
        foreach ($this->shifts->unfinalizedSecondLevelShifts() as $shift) {
            // Neuester Eintrag je Datum gewinnt (eine spätere Übernahme ersetzt die frühere).
            $byDate[(string) $shift['shift_date']] = $shift;
        }

        $created = 0;
        foreach ($byDate as $shiftDate => $shift) {
            if (!$this->deadlinePassed($shiftDate, $nowLocal)) {
                continue;
            }
            try {
                $created += $this->createCommentsForDay(
                    $shiftDate,
                    (int) $shift['user_id'],
                    (string) ($shift['user_display_name'] ?? '')
                );
            } catch (\Throwable $e) {
                $this->logger->warning('2nd-Level-Tagesabschluss fehlgeschlagen', ['shift_date' => $shiftDate, 'error' => $e->getMessage()]);
                continue;
            }
            $this->shifts->finalizeReminder($shiftDate, $nowUtc);
        }

        return $created;
    }

    /** @return array<int,array{id:int,number:string,subject:string,url:string}> */
    private function pendingTickets(int $userId, string $shiftDate): array
    {
        [$startUtc, $endUtc] = $this->dayWindowUtc($shiftDate);

        $out = [];
        foreach ($this->tickets->openCreatedBefore($startUtc) as $ticket) {
            if ($this->comments->hasCommentByUserBetween((int) $ticket['id'], $userId, $startUtc, $endUtc)) {
                continue;
            }
            $out[] = [
                'id' => (int) $ticket['id'],
                'number' => (string) $ticket['number'],
                'subject' => (string) $ticket['subject'],
                'url' => '/helpdesk/tickets/' . (int) $ticket['id'],
            ];
        }

        return $out;
    }

    private function createCommentsForDay(string $shiftDate, int $userId, string $displayName): int
    {
        [$startUtc, $endUtc] = $this->dayWindowUtc($shiftDate);
        $dateLabel = implode('.', array_reverse(explode('-', $shiftDate)));
        $body = sprintf('Zuständig am %s war %s, kein Kommentar erfolgt.', $dateLabel, $displayName !== '' ? $displayName : '–');

        $created = 0;
        foreach ($this->tickets->openCreatedBefore($startUtc) as $ticket) {
            if ($this->comments->hasCommentByUserBetween((int) $ticket['id'], $userId, $startUtc, $endUtc)) {
                continue;
            }
            $this->tickets->transaction(function () use ($ticket, $body): void {
                $this->comments->create([
                    'ticket_id' => (int) $ticket['id'],
                    'type' => 'internal',
                    'body' => $body,
                    'author_user_id' => null,
                    'author_name' => 'System',
                    'is_requester' => 0,
                    'source' => 'system',
                ]);
                $this->tickets->addEvent((int) $ticket['id'], 'internal_note', null, 'System', null, null, mb_substr($body, 0, 120), ['auto' => true, 'via' => 'scheduler']);
            });
            $created++;
        }

        return $created;
    }

    /** @return array{0:string,1:string} [startUtc, endUtc) des lokalen Tages */
    private function dayWindowUtc(string $shiftDate): array
    {
        $tz = $this->timezone();
        $start = (new \DateTimeImmutable($shiftDate . ' 00:00:00', $tz))->setTimezone(new \DateTimeZone('UTC'));
        $end = $start->modify('+1 day');

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    private function deadlinePassed(string $shiftDate, \DateTimeImmutable $nowLocal): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $shiftDate, $this->timezone());
        if ($date === false) {
            return false;
        }

        return $nowLocal >= $date->setTime(self::DEADLINE_HOUR, self::DEADLINE_MINUTE, 0);
    }

    private function localToday(?\DateTimeImmutable $nowUtc = null): string
    {
        return ($nowUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($this->timezone())->format('Y-m-d');
    }

    private function timezone(): \DateTimeZone
    {
        return new \DateTimeZone((string) $this->config->get('app.timezone', 'Europe/Berlin'));
    }
}
