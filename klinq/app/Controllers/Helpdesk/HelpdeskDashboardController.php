<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\TicketRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\HelpdeskCalendarService;
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
        private readonly HelpdeskCalendarService $calendar
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
            'views' => TicketService::views(),
            'viewCounts' => $this->tickets->viewCounts((int) $this->currentUser->id()),
            'now' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'supportShift' => $shift,
            'slaService' => $this->slaService,
            'isFirstLevel' => $shift['first'] !== null && (int) $shift['first']['user_id'] === (int) $this->currentUser->id(),
            'todayEvents' => $todayEvents,
            'calendarTimezone' => $this->calendar->timezone(),
            'scripts' => ['/js/helpdesk.js'],
        ]);
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
