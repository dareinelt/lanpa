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
        $canEdit = $this->currentUser->can('helpdesk.update');

        // Overlay „Neuer Termin“: nach einem Validierungsfehler mit der Eingabe erneut geöffnet.
        $old = $_SESSION['_old_input'] ?? [];
        $reopen = $canEdit && ($old['dialog'] ?? '') === 'neu';
        $newValues = $this->newValues(in_array($today, $range['days'], true) ? $today : $range['date'], '09:00', false);
        if ($reopen) {
            $newValues = $this->withOld($newValues, $old);
        }

        return $this->render('helpdesk.kalender.index', [
            'title' => 'Kalender',
            'activeNav' => 'helpdesk-kalender',
            'areaLabel' => 'Help Desk',
            'range' => $range,
            'today' => $today,
            'events' => $this->calendar->eventsIn($range),
            'timezone' => $this->calendar->timezone(),
            'canEdit' => $canEdit,
            'newValues' => $newValues,
            'newChecklistItems' => $reopen ? HelpdeskCalendarService::parseChecklist($old['checklist'] ?? []) : [],
            'newDialogOpen' => $reopen,
            'scripts' => ['/js/helpdesk-calendar.js'],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->currentUser->require('helpdesk.update');
        $date = $request->queryString('datum');
        $time = $request->queryString('zeit');

        return $this->renderForm(null, $this->newValues(
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : $this->calendar->localToday(),
            preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : '09:00',
            $request->queryString('ganztags') === '1'
        ));
    }

    public function store(Request $request): Response
    {
        $event = null;
        // Aus dem Overlay im Kalender: bei Fehlern zurück in dieselbe Ansicht, das Overlay öffnet sich erneut.
        $errorRedirect = $request->string('dialog') === 'neu'
            ? $this->safeBack($request, '/helpdesk/kalender')
            : '/helpdesk/kalender/neu';
        $response = $this->attempt($request, function () use ($request, &$event): void {
            $event = $this->calendar->create($request->all());
        }, $errorRedirect);

        return $response ?? $this->respond($request, 'Termin angelegt.', $this->calendarUrl($event));
    }

    public function edit(Request $request): Response
    {
        $id = $request->paramInt('id');
        $event = $this->calendar->get($id);
        $occurrence = $request->queryString('termin');
        $values = $occurrence !== ''
            ? $this->calendar->occurrenceValues($id, $occurrence)
            : HelpdeskCalendarService::formValues($event);

        return $this->renderForm($event, $values, $occurrence);
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $scope = $this->scope($request);
        $event = null;
        $response = $this->attempt($request, function () use ($request, $id, $scope, &$event): void {
            $event = $this->calendar->update($id, $request->all(), $scope);
        }, '/helpdesk/kalender/' . $id);

        return $response ?? $this->respond(
            $request,
            $scope === HelpdeskCalendarService::SCOPE_OCCURRENCE ? 'Einzelner Termin der Serie gespeichert.' : 'Termin gespeichert.',
            $this->calendarUrl($event, $scope === HelpdeskCalendarService::SCOPE_OCCURRENCE ? $request->string('occurrence') : '')
        );
    }

    public function delete(Request $request): Response
    {
        $id = $request->paramInt('id');
        $scope = $this->scope($request);
        $occurrence = $request->string('termin');
        $event = $this->calendar->get($id);
        $this->calendar->delete($id, $scope, $occurrence !== '' ? $occurrence : null);

        $message = $scope === HelpdeskCalendarService::SCOPE_OCCURRENCE ? 'Termin der Serie gelöscht.' : 'Termin gelöscht.';

        return $this->respond($request, $message, $this->calendarUrl($event, $scope === HelpdeskCalendarService::SCOPE_OCCURRENCE ? $occurrence : ''));
    }

    /** Checklistenpunkt eines Termins bestätigen oder Bestätigung zurücknehmen (je Vorkommen bei Serien). */
    public function checklist(Request $request): Response
    {
        $id = $request->paramInt('id');
        $occurrence = $request->string('termin');
        $confirm = $request->string('confirm');
        $itemId = (int) ($confirm !== '' ? $confirm : $request->string('revoke'));
        $back = '/helpdesk/kalender/' . $id . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $occurrence) === 1 ? '?termin=' . $occurrence : '');
        $response = $this->attempt($request, function () use ($id, $itemId, $occurrence, $confirm): void {
            $this->calendar->confirmChecklistItem($id, $itemId, $occurrence, $confirm !== '');
        }, $back, false);

        return $response ?? $this->respond(
            $request,
            $confirm !== '' ? 'Checklistenpunkt bestätigt.' : 'Bestätigung zurückgenommen.',
            $back
        );
    }

    /** Umfang einer Änderung an einem Serientermin; ohne Angabe gilt die ganze Serie. */
    private function scope(Request $request): string
    {
        return $request->string('scope') === HelpdeskCalendarService::SCOPE_OCCURRENCE
            ? HelpdeskCalendarService::SCOPE_OCCURRENCE
            : HelpdeskCalendarService::SCOPE_SERIES;
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
    private function renderForm(?array $event, array $values, string $occurrence = ''): Response
    {
        $old = $_SESSION['_old_input'] ?? [];
        $values = $this->withOld($values, $old);
        // Bei Serienterminen wird beim Speichern gefragt, ob nur dieser Termin gilt (Vorgabe) oder die Serie.
        $isSeries = $event !== null && !empty($event['recurrence']);
        $values['occurrence'] = (string) ($old['occurrence'] ?? ($occurrence !== '' ? $occurrence : ($values['occurrence'] ?? '')));
        $values['scope'] = (string) ($old['scope'] ?? ($isSeries ? HelpdeskCalendarService::SCOPE_OCCURRENCE : HelpdeskCalendarService::SCOPE_SERIES));
        $checklistItems = array_key_exists('checklist_submitted', $old)
            ? HelpdeskCalendarService::parseChecklist($old['checklist'] ?? [])
            : ($event !== null ? $this->calendar->checklistItems($event) : []);

        return $this->render('helpdesk.kalender.form', [
            'title' => $event === null ? 'Neuer Termin' : 'Termin bearbeiten',
            'activeNav' => 'helpdesk-kalender',
            'areaLabel' => 'Help Desk',
            'event' => $event,
            'values' => $values,
            'checklistItems' => $checklistItems,
            'checklist' => $event !== null ? $this->calendar->checklist($event, $isSeries ? $values['occurrence'] : '') : null,
            'canEdit' => $this->currentUser->can('helpdesk.update'),
            'scripts' => ['/js/helpdesk-calendar.js'],
        ]);
    }

    /**
     * Vorgaben eines neuen Termins: Ende eine Stunde nach Beginn, höchstens 23:59 (der Termin bleibt am selben Tag).
     * @return array<string,string>
     */
    private function newValues(string $date, string $time, bool $allDay): array
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        $endMinutes = min(23 * 60 + 59, $h * 60 + $m + 60);

        return [
            'title' => '',
            'date' => $date,
            'end_date' => $date,
            'start_time' => $allDay ? '' : $time,
            'end_time' => $allDay ? '' : sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60),
            'all_day' => $allDay ? '1' : '0',
        ];
    }

    /**
     * Formularwerte mit der alten Eingabe nach einem Validierungsfehler überschreiben.
     * @param array<string,string> $values
     * @param array<string,mixed> $old
     * @return array<string,string>
     */
    private function withOld(array $values, array $old): array
    {
        foreach (array_keys($values + ['location' => '', 'description' => '', 'recurrence' => '', 'recurrence_interval' => '', 'recurrence_until' => '']) as $key) {
            if (array_key_exists($key, $old)) {
                $values[$key] = (string) $old[$key];
            }
        }
        if ($old !== [] && !array_key_exists('all_day', $old)) {
            $values['all_day'] = '0';
        }

        return $values;
    }

    /** @param array<string,mixed>|null $event */
    private function calendarUrl(?array $event, string $day = ''): string
    {
        if ($day !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1) {
            return '/helpdesk/kalender?datum=' . $day;
        }
        if ($event === null) {
            return '/helpdesk/kalender';
        }

        return '/helpdesk/kalender?datum=' . $event['start_local']->format('Y-m-d');
    }
}
