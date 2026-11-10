<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Security\CurrentUser;
use App\Services\Helpdesk\CalendarDateSuggestionService;
use App\Services\Helpdesk\HelpdeskCalendarService;
use App\Services\Helpdesk\TicketService;

/**
 * Kalender des Help Desk: Ansichten Tag/Arbeitswoche/Monat, manuelle Termine und Termine aus Tickets
 * (Datumsvorschläge aus dem Tickettext über den KI-Endpunkt).
 */
final class HelpdeskCalendarController extends HelpdeskBaseController
{
    /** Zuletzt gewählte Ansicht je Sitzung. */
    private const VIEW_SESSION_KEY = '_helpdesk_calendar_view';

    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly HelpdeskCalendarService $calendar,
        private readonly CalendarDateSuggestionService $suggestions,
        private readonly TicketService $tickets
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $view = $request->queryString('ansicht');
        if (!isset(HelpdeskCalendarService::VIEWS[$view])) {
            $saved = (string) ($_SESSION[self::VIEW_SESSION_KEY] ?? '');
            $view = isset(HelpdeskCalendarService::VIEWS[$saved]) ? $saved : HelpdeskCalendarService::DEFAULT_VIEW;
        }
        $_SESSION[self::VIEW_SESSION_KEY] = $view;
        $today = $this->calendar->localToday();
        $date = $request->queryString('datum');
        $range = HelpdeskCalendarService::range($view, $date !== '' ? $date : $today);

        return $this->render('helpdesk.kalender.index', [
            'title' => 'Kalender',
            'activeNav' => 'helpdesk-kalender',
            'areaLabel' => 'Help Desk',
            'range' => $range,
            'today' => $today,
            'events' => $this->calendar->eventsIn($range),
            'timezone' => $this->calendar->timezone(),
            'canEdit' => $this->currentUser->can('helpdesk.update'),
            'scripts' => ['/js/helpdesk-calendar.js'],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->currentUser->require('helpdesk.update');
        $date = $request->queryString('datum');
        $time = $request->queryString('zeit');
        $values = [
            'title' => '',
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : $this->calendar->localToday(),
            'start_time' => preg_match('/^\d{2}:\d{2}$/', $time) === 1 ? $time : '09:00',
            'all_day' => $request->queryString('ganztags') === '1' ? '1' : '0',
        ];
        $values['end_date'] = $values['date'];
        $values['end_time'] = $values['all_day'] === '1' ? '' : (new \DateTimeImmutable('2000-01-01 ' . $values['start_time']))->modify('+1 hour')->format('H:i');
        if ($values['all_day'] === '1') {
            $values['start_time'] = '';
        }

        return $this->renderForm(null, $values);
    }

    public function store(Request $request): Response
    {
        $event = null;
        $response = $this->attempt($request, function () use ($request, &$event): void {
            $event = $this->calendar->create($request->all());
        }, '/helpdesk/kalender/neu');

        return $response ?? $this->respond($request, 'Termin angelegt.', $this->calendarUrl($event));
    }

    public function edit(Request $request): Response
    {
        $event = $this->calendar->get($request->paramInt('id'));

        return $this->renderForm($event, HelpdeskCalendarService::formValues($event));
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $event = null;
        $response = $this->attempt($request, function () use ($request, $id, &$event): void {
            $event = $this->calendar->update($id, $request->all());
        }, '/helpdesk/kalender/' . $id);

        return $response ?? $this->respond($request, 'Termin gespeichert.', $this->calendarUrl($event));
    }

    public function delete(Request $request): Response
    {
        $id = $request->paramInt('id');
        $event = $this->calendar->get($id);
        $this->calendar->delete($id);

        return $this->respond($request, 'Termin gelöscht.', $this->calendarUrl($event));
    }

    /** Datumsvorschläge aus dem Tickettext (JSON für den Dialog „Termin anlegen“). */
    public function suggestions(Request $request): Response
    {
        $ticket = $this->tickets->getVisible($request->paramInt('id'));

        return $this->json(['ok' => true] + $this->suggestions->suggest($ticket));
    }

    /** Termin aus dem Ticket anlegen (Dialog „Termin anlegen“ in der Ticketansicht). */
    public function storeFromTicket(Request $request): Response
    {
        $ticketId = $request->paramInt('id');
        $event = null;
        try {
            $event = $this->calendar->createFromTicket($ticketId, $request->all());
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                throw $e;
            }
            $this->flash('error', 'Termin nicht angelegt: ' . implode(' ', $e->errors()));

            return $this->redirect('/helpdesk/tickets/' . $ticketId);
        }
        $start = $event['start_local'];
        $message = 'Termin am ' . $start->format('d.m.Y') . (empty($event['all_day']) ? ' um ' . $start->format('H:i') . ' Uhr' : '') . ' im Kalender angelegt.';

        return $this->respond($request, $message, '/helpdesk/tickets/' . $ticketId, ['id' => (int) $event['id']]);
    }

    /**
     * @param array<string,mixed>|null $event
     * @param array<string,string> $values
     */
    private function renderForm(?array $event, array $values): Response
    {
        $old = $_SESSION['_old_input'] ?? [];
        foreach (array_keys($values + ['location' => '', 'description' => '']) as $key) {
            if (array_key_exists($key, $old)) {
                $values[$key] = (string) $old[$key];
            }
        }
        if ($old !== [] && !array_key_exists('all_day', $old)) {
            $values['all_day'] = '0';
        }

        return $this->render('helpdesk.kalender.form', [
            'title' => $event === null ? 'Neuer Termin' : 'Termin bearbeiten',
            'activeNav' => 'helpdesk-kalender',
            'areaLabel' => 'Help Desk',
            'event' => $event,
            'values' => $values,
            'canEdit' => $this->currentUser->can('helpdesk.update'),
            'scripts' => ['/js/helpdesk-calendar.js'],
        ]);
    }

    /** @param array<string,mixed>|null $event */
    private function calendarUrl(?array $event): string
    {
        if ($event === null) {
            return '/helpdesk/kalender';
        }

        return '/helpdesk/kalender?datum=' . $event['start_local']->format('Y-m-d');
    }
}
