<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\HelpdeskSupportShiftRepository;
use App\Repositories\TicketRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;

/**
 * Tagesweise Zuständigkeit für 1st- und 2nd-Level-Support (Dashboard-Overlay „Zuständigkeit“).
 *
 * - Ein Agent übernimmt per Klick die Zuständigkeit für den 1st- oder 2nd-Level-Support des Tages;
 *   beide Übernahmen werden in `helpdesk_support_shifts` erfasst (Grundlage für Auswertungen).
 * - Wer den 2nd Level übernimmt, bekommt automatisch alle noch offenen Tickets zugewiesen, die nicht
 *   vom aktuellen Tag stammen.
 * - Die Zuständigkeit endet täglich automatisch um 19:00 Uhr (APP_TIMEZONE) über den Scheduler
 *   (`HelpdeskSchedulerService` → `endExpiredShifts`).
 * - Der 1st Level darf Tickets mit Pflichtkommentar an den 2nd Level des Tages weiterreichen
 *   (im Ticketverlauf sichtbar über die reguläre Zuweisungs-/Kommentarhistorie).
 */
final class SupportShiftService
{
    public const FIRST_LEVEL = 1;
    public const SECOND_LEVEL = 2;
    private const SHIFT_END_HOUR = 19;

    public function __construct(
        private readonly HelpdeskSupportShiftRepository $shifts,
        private readonly TicketRepository $tickets,
        private readonly TicketService $ticketService,
        private readonly CurrentUser $currentUser,
        private readonly AuditLogService $audit,
        private readonly Config $config
    ) {}

    /** Zuständigkeit für heute (1st + 2nd Level, sofern übernommen). @return array{date:string,first:?array<string,mixed>,second:?array<string,mixed>} */
    public function today(): array
    {
        $date = $this->localToday();

        return [
            'date' => $date,
            'first' => $this->shifts->active($date, self::FIRST_LEVEL),
            'second' => $this->shifts->active($date, self::SECOND_LEVEL),
        ];
    }

    public function isFirstLevelToday(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }
        $active = $this->shifts->active($this->localToday(), self::FIRST_LEVEL);

        return $active !== null && (int) $active['user_id'] === $userId;
    }

    /** Nutzer-ID des heutigen 2nd Level Supports, sofern übernommen. */
    public function secondLevelUserIdToday(): ?int
    {
        $active = $this->shifts->active($this->localToday(), self::SECOND_LEVEL);

        return $active !== null ? (int) $active['user_id'] : null;
    }

    /**
     * Übernimmt die Zuständigkeit für den angemeldeten Benutzer. Beim 2nd Level werden zusätzlich
     * alle offenen Tickets, die nicht vom aktuellen Tag stammen, automatisch zugewiesen.
     * @return array<string,mixed> Der neue Zuständigkeits-Eintrag
     */
    public function claim(int $level): array
    {
        if (!in_array($level, [self::FIRST_LEVEL, self::SECOND_LEVEL], true)) {
            throw ValidationException::single('level', 'Ungültige Zuständigkeit.');
        }
        $userId = $this->currentUser->id();
        if ($userId === null) {
            throw new ForbiddenException('Nicht angemeldet.');
        }
        $this->currentUser->require('helpdesk.view');

        $tz = new \DateTimeZone((string) $this->config->get('app.timezone', 'Europe/Berlin'));
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowLocal = $nowUtc->setTimezone($tz);
        $shiftDate = $nowLocal->format('Y-m-d');
        $endsAtLocal = $nowLocal->setTime(self::SHIFT_END_HOUR, 0, 0);
        if ($endsAtLocal <= $nowLocal) {
            $endsAtLocal = $endsAtLocal->modify('+1 day');
        }
        $endsAtUtc = $endsAtLocal->setTimezone(new \DateTimeZone('UTC'));

        $entry = $this->shifts->claim($shiftDate, $level, $userId, $nowUtc, $endsAtUtc);
        $this->audit->log(
            'claim',
            'helpdesk_support_shift',
            (int) $entry['id'],
            ($level === self::FIRST_LEVEL ? '1st Level' : '2nd Level') . ' · ' . $shiftDate,
            null,
            ['level' => $level, 'user' => $this->currentUser->displayName(), 'shift_date' => $shiftDate]
        );

        if ($level === self::SECOND_LEVEL) {
            $this->reassignOpenTicketsToSecondLevel($userId, $nowUtc, $tz);
        }

        return $entry;
    }

    /** Weist alle offenen, nicht heute erstellten Tickets dem neuen 2nd Level zu. Liefert die Anzahl. */
    private function reassignOpenTicketsToSecondLevel(int $userId, \DateTimeImmutable $nowUtc, \DateTimeZone $tz): int
    {
        $nowLocal = $nowUtc->setTimezone($tz);
        $dayStartUtc = $nowLocal->setTime(0, 0, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $dayEndUtc = $nowLocal->setTime(0, 0, 0)->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $count = 0;
        foreach ($this->tickets->openNotCreatedBetween($dayStartUtc, $dayEndUtc) as $ticket) {
            if ((int) ($ticket['assignee_user_id'] ?? 0) === $userId) {
                continue;
            }
            $this->ticketService->assign(
                (int) $ticket['id'],
                $userId,
                $ticket['group_id'] !== null ? (int) $ticket['group_id'] : null,
                $ticket['deputy_user_id'] !== null ? (int) $ticket['deputy_user_id'] : null,
                true
            );
            $count++;
        }

        return $count;
    }

    /**
     * Weist ein Ticket dem 2nd Level des Tages zu (nur der aktuelle 1st Level darf das); Kommentar ist Pflicht
     * und wird als interne Notiz im Ticketverlauf erfasst.
     * @return array<string,mixed> Das aktualisierte Ticket
     */
    public function assignToSecondLevel(int $ticketId, string $note): array
    {
        $userId = $this->currentUser->id();
        if (!$this->isFirstLevelToday($userId)) {
            throw new ForbiddenException('Nur der heutige 1st Level Support kann Tickets an den 2nd Level weiterreichen.');
        }
        $note = trim($note);
        if ($note === '') {
            throw ValidationException::single('note', 'Bitte einen Kommentar für die Weiterleitung angeben.');
        }
        $secondLevelUserId = $this->secondLevelUserIdToday();
        if ($secondLevelUserId === null) {
            throw ValidationException::single('note', 'Für heute ist noch kein 2nd Level Support festgelegt.');
        }

        $ticket = $this->ticketService->get($ticketId);
        $this->ticketService->assign(
            $ticketId,
            $secondLevelUserId,
            $ticket['group_id'] !== null ? (int) $ticket['group_id'] : null,
            $ticket['deputy_user_id'] !== null ? (int) $ticket['deputy_user_id'] : null,
            true
        );
        $this->ticketService->addComment($ticketId, $note, 'internal');

        return $this->ticketService->get($ticketId);
    }

    /** Beendet abgelaufene Zuständigkeiten (täglich 19:00 Uhr, APP_TIMEZONE). */
    public function endExpiredShifts(?\DateTimeImmutable $nowUtc = null): int
    {
        return $this->shifts->endExpired($nowUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    private function localToday(): string
    {
        $tz = new \DateTimeZone((string) $this->config->get('app.timezone', 'Europe/Berlin'));

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
    }
}
