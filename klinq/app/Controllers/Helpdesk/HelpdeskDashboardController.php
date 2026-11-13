<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\TicketRepository;
use App\Repositories\TicketRuleRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\HelpdeskCalendarService;
use App\Services\Helpdesk\MailboxSettingsService;
use App\Services\Helpdesk\MentionService;
use App\Services\Helpdesk\SupportShiftService;
use App\Services\Helpdesk\TicketReportService;
use App\Services\Helpdesk\TicketService;
use App\Services\Helpdesk\TicketSlaService;

/** Help-Desk-Dashboard: Kennzahlen, eigene Tickets, offene Warteschlange, SLA-Überblick. */
final class HelpdeskDashboardController extends HelpdeskBaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly TicketReportService $reports,
        private readonly TicketRepository $tickets,
        private readonly SupportShiftService $supportShifts,
        private readonly TicketSlaService $slaService,
        private readonly HelpdeskCalendarService $calendar,
        private readonly MentionService $mentions,
        private readonly TicketRuleRepository $rules,
        private readonly MailboxSettingsService $mailSettings
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $data = $this->reports->dashboard();
        $shift = $this->supportShifts->today();
        $todayEvents = $this->todayOverlay($shift);

        return $this->render('helpdesk.dashboard', [
            'title' => 'Help Desk',
            'activeNav' => 'helpdesk',
            'areaLabel' => 'Help Desk',
            'data' => $data,
            'pendingForwards' => $this->tickets->pendingForwards((int) $this->currentUser->id()),
            'mentions' => $this->mentions->unreadForCurrentUser(),
            'views' => TicketService::views(),
            'viewCounts' => $this->tickets->viewCounts((int) $this->currentUser->id()),
            'now' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'supportShift' => $shift,
            'slaService' => $this->slaService,
            'isFirstLevel' => $shift['first'] !== null && (int) $shift['first']['user_id'] === (int) $this->currentUser->id(),
            'todayEvents' => $todayEvents,
            'ignoredMails' => $this->ignoredMails(),
            'allowedSenders' => $this->currentUser->can('helpdesk.admin') ? $this->mailSettings->allowedSenders() : [],
            'calendarTimezone' => $this->calendar->timezone(),
            'scripts' => ['/js/helpdesk.js'],
        ]);
    }

    /**
     * Für Help-Desk-Admins: in den letzten 24 Stunden ignorierte Eingangsmails (Hinweis mit Overlay,
     * aus dem Absender freigegeben werden können).
     * @return list<array<string,mixed>>
     */
    private function ignoredMails(): array
    {
        if (!$this->currentUser->can('helpdesk.admin')) {
            return [];
        }

        $rows = $this->rules->ignoredInboundMailsSince(gmdate('Y-m-d H:i:s', time() - 86400));

        // Eigene Systemadresse wird immer ignoriert (Schleifenschutz) – eine Freigabe wäre wirkungslos.
        return array_values(array_filter($rows, static fn (array $r): bool => !str_starts_with((string) ($r['detail'] ?? ''), 'Absender ist die eigene Systemadresse')));
    }

    /** Markiert eine Erwähnung des angemeldeten Agenten als gelesen. */
    public function mentionRead(Request $request): Response
    {
        $this->mentions->markRead($request->paramInt('id'));

        return $this->respond($request, '', '/helpdesk#erwaehnungen');
    }

    /** Markiert alle Erwähnungen des angemeldeten Agenten als gelesen. */
    public function mentionsReadAll(Request $request): Response
    {
        $this->mentions->markAllRead();

        return $this->respond($request, 'Alle Erwähnungen als gelesen markiert.', '/helpdesk');
    }

    /**
     * Termin-Overlay nach Übernahme der Zuständigkeit: Die Übernahme setzt einen einmaligen Sitzungsmarker;
     * das Dashboard zeigt danach die Termine des Tages, sofern der Benutzer weiterhin aktiver 1st- oder
     * 2nd-Level-Zuständiger ist. Ohne noch anstehende Termine genügt ein Hinweis statt des Overlays.
     *
     * @param array{date:string,first:?array<string,mixed>,second:?array<string,mixed>} $shift
     * @return list<array<string,mixed>>|null null = kein Overlay
     */
    private function todayOverlay(array $shift): ?array
    {
        if (!isset($_SESSION[HelpdeskCalendarService::OVERLAY_SESSION_KEY])) {
            return null;
        }
        unset($_SESSION[HelpdeskCalendarService::OVERLAY_SESSION_KEY]);
        $userId = (int) $this->currentUser->id();
        $isActive = false;
        foreach (['first', 'second'] as $level) {
            if ($shift[$level] !== null && (int) $shift[$level]['user_id'] === $userId) {
                $isActive = true;
            }
        }
        if (!$isActive) {
            return null;
        }
        $events = $this->calendar->today();
        // „Anstehend“: mindestens ein Termin ist noch nicht vorbei; vergangene bleiben zur Einordnung sichtbar.
        $now = new \DateTimeImmutable('now', $this->calendar->timezone());
        $upcoming = array_filter($events, static fn (array $e): bool => $e['end_local'] > $now);
        if ($upcoming === []) {
            $this->flash('info', $events === []
                ? 'Für heute stehen keine Termine im Help-Desk-Kalender an.'
                : 'Für heute stehen keine weiteren Termine im Help-Desk-Kalender an.');

            return null;
        }

        return $events;
    }
}
