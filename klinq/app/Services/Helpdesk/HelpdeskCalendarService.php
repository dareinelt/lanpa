<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\HelpdeskCalendarChecklistRepository;
use App\Repositories\HelpdeskCalendarRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;

/**
 * Kalender des Help Desk: Termine für alle Agenten in den Ansichten Tag, Arbeitswoche (Mo–Fr) und Monat.
 *
 * - Termine werden manuell im Kalender oder aus einem Ticket angelegt („Termin anlegen“, Datumsvorschlag
 *   über {@see CalendarDateSuggestionService}).
 * - Gespeichert wird in UTC; Zeiträume und Anzeige rechnen in APP_TIMEZONE. Tage werden mit
 *   modify('+1 day') fortgeschrieben, damit Sommer-/Winterzeit korrekt bleibt.
 * - Ganztägige Termine reichen vom lokalen Tagesbeginn bis zum Beginn des Tages nach dem Enddatum.
 * - Nach Übernahme der 1st-/2nd-Level-Zuständigkeit zeigt das Dashboard die Termine des Tages ({@see today()}).
 * - Serientermine stehen einmal in der Tabelle und werden beim Lesen aufgefächert ({@see eventsIn()}).
 *   Einzelne Termine einer Serie lassen sich abweichend ändern oder absagen; sie verweisen über
 *   `series_parent_id`/`series_date` auf ihren Serientermin ({@see self::SCOPE_OCCURRENCE}).
 * - Optional trägt ein Termin eine Checkliste („Checkliste anlegen“), deren Punkte der bearbeitende Agent
 *   als „Bestätigt“ abhakt. Die Punkte hängen am Einzel- bzw. Serientermin und gelten für alle Vorkommen;
 *   bestätigt wird je Vorkommen ({@see confirmChecklistItem()}).
 */
final class HelpdeskCalendarService
{
    public const VIEWS = ['tag' => 'Tag', 'arbeitswoche' => 'Arbeitswoche', 'monat' => 'Monat'];
    public const DEFAULT_VIEW = 'arbeitswoche';

    /** Wiederholungen eines Serientermins. */
    public const RECURRENCES = ['daily' => 'Täglich', 'weekly' => 'Wöchentlich', 'monthly' => 'Monatlich', 'yearly' => 'Jährlich'];

    /** Umfang einer Änderung: gesamte Serie (auch Einzeltermin) oder nur ein Termin der Serie. */
    public const SCOPE_SERIES = 'series';
    public const SCOPE_OCCURRENCE = 'occurrence';

    /** Sitzungsschlüssel für das Termin-Overlay nach Übernahme der Zuständigkeit. */
    public const OVERLAY_SESSION_KEY = '_helpdesk_calendar_overlay';

    private const MAX_TITLE = 200;
    private const MAX_LOCATION = 200;
    private const MAX_DESCRIPTION = 5000;
    private const MAX_DAYS = 31;
    private const MAX_INTERVAL = 99;

    /** Obergrenzen der Checkliste eines Termins. */
    public const MAX_CHECKLIST_ITEMS = 30;
    public const MAX_CHECKLIST_LABEL = 300;

    /** Obergrenze je Serie und Zeitraum (Schutz vor Endlosschleifen bei täglicher Wiederholung). */
    private const MAX_OCCURRENCES = 400;

    private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    private const WEEKDAYS = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    public function __construct(
        private readonly HelpdeskCalendarRepository $events,
        private readonly TicketService $tickets,
        private readonly CurrentUser $currentUser,
        private readonly AuditLogService $audit,
        private readonly HelpdeskCalendarChecklistRepository $checklists,
        private readonly string $timezone = 'Europe/Berlin'
    ) {}

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezone);
    }

    /** Heutiges Datum (lokal, Y-m-d). */
    public function localToday(?\DateTimeImmutable $nowUtc = null): string
    {
        return ($nowUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($this->timezone())->format('Y-m-d');
    }

    /**
     * Sichtbarer Zeitraum einer Ansicht.
     *
     * @return array{view:string,date:string,start:string,end:string,days:list<string>,prev:string,next:string,title:string}
     *         start/end lokal (Y-m-d, end exklusiv); days = angezeigte Tage (Monat: volle Wochen Mo–So)
     */
    public static function range(string $view, string $date): array
    {
        $view = isset(self::VIEWS[$view]) ? $view : self::DEFAULT_VIEW;
        $anchor = self::parseDate($date) ?? new \DateTimeImmutable('today');

        if ($view === 'tag') {
            $start = $anchor;
            $end = $start->modify('+1 day');
            $prev = $anchor->modify('-1 day');
            $next = $anchor->modify('+1 day');
            $title = self::WEEKDAYS[(int) $anchor->format('N') - 1] . ', ' . (int) $anchor->format('j') . '. '
                . self::MONTHS[(int) $anchor->format('n') - 1] . ' ' . $anchor->format('Y');
            $days = [$start->format('Y-m-d')];
        } elseif ($view === 'arbeitswoche') {
            $start = $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days');
            $end = $start->modify('+5 days');
            $prev = $anchor->modify('-7 days');
            $next = $anchor->modify('+7 days');
            $friday = $start->modify('+4 days');
            $title = self::spanTitle($start, $friday) . ' · KW ' . (int) $start->format('W');
            $days = self::days($start, $end);
        } else {
            $first = $anchor->modify('first day of this month');
            $start = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
            $last = $first->modify('last day of this month');
            $end = $last->modify('+' . (8 - (int) $last->format('N')) . ' days');
            $prev = $first->modify('-1 month');
            $next = $first->modify('+1 month');
            $title = self::MONTHS[(int) $first->format('n') - 1] . ' ' . $first->format('Y');
            $days = self::days($start, $end);
        }

        return [
            'view' => $view,
            'date' => $anchor->format('Y-m-d'),
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'days' => $days,
            'prev' => $prev->format('Y-m-d'),
            'next' => $next->format('Y-m-d'),
            'title' => $title,
        ];
    }

    /**
     * Termine eines Zeitraums mit lokalen Zeitangaben.
     * @param array{start:string,end:string} $range
     * @return list<array<string,mixed>>
     */
    public function eventsIn(array $range): array
    {
        $tz = $this->timezone();
        $fromLocal = new \DateTimeImmutable($range['start'] . ' 00:00:00', $tz);
        $toLocal = new \DateTimeImmutable($range['end'] . ' 00:00:00', $tz);
        $to = $this->toUtc($toLocal);

        /** @var array<int|string,array<string,mixed>> $rows Einzeltermine und abweichende Serientermine */
        $rows = [];
        /** @var array<int,array<string,mixed>> $series Serientermine (werden aufgefächert) */
        $series = [];
        foreach ($this->events->overlapping($this->toUtc($fromLocal), $to) as $row) {
            if (self::isSeries($row)) {
                $series[(int) $row['id']] = $row;
                continue;
            }
            $rows[(int) $row['id']] = $row;
        }
        // Serien, die vor dem Zeitraum begonnen haben: ihr erstes Vorkommen liegt nicht im Zeitraum.
        // Das letzte Vorkommen kann bis zu MAX_DAYS über das Serienende hinaus in den Zeitraum reichen.
        $untilFrom = $fromLocal->modify('-' . self::MAX_DAYS . ' days')->format('Y-m-d');
        foreach ($this->events->recurring($untilFrom, $to) as $row) {
            $series[(int) $row['id']] ??= $row;
        }

        foreach ($series as $parent) {
            $children = $this->childrenByDate((int) $parent['id']);
            foreach ($this->occurrences($parent, $fromLocal, $toLocal) as $day => $occurrence) {
                // Ein abweichender oder abgesagter Einzeltermin ersetzt das Vorkommen an diesem Tag.
                if (!isset($children[$day])) {
                    $rows['series-' . $parent['id'] . '-' . $day] = $this->occurrenceRow($parent, $day, $occurrence);
                }
            }
        }

        $list = array_values($rows);
        usort($list, static fn (array $a, array $b): int => [empty($a['all_day']) ? 1 : 0, (string) $a['starts_at'], (string) $a['ends_at']]
            <=> [empty($b['all_day']) ? 1 : 0, (string) $b['starts_at'], (string) $b['ends_at']]);

        return $this->withChecklistCounts(array_map(fn (array $e): array => $this->decorate($e), $list));
    }

    /** Termine des heutigen Tages (lokal), für das Overlay nach Übernahme der Zuständigkeit. @return list<array<string,mixed>> */
    public function today(?\DateTimeImmutable $nowUtc = null): array
    {
        $day = $this->localToday($nowUtc);
        $next = (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');

        return $this->eventsIn(['start' => $day, 'end' => $next]);
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $event = $this->events->find($id);
        if ($event === null) {
            throw new NotFoundException('Termin nicht gefunden.');
        }

        return $this->decorate($event);
    }

    /** Termine eines Tickets. @return list<array<string,mixed>> */
    public function forTicket(int $ticketId): array
    {
        return array_map(fn (array $e): array => $this->decorate($e), $this->events->forTicket($ticketId));
    }

    /**
     * @param array<string,mixed> $input title, date, end_date, start_time, end_time, all_day, location, description,
     *        optional checklist (siehe {@see parseChecklist()}) mit checklist_submitted = 1
     * @return array<string,mixed> Der angelegte Termin
     */
    public function create(array $input, ?int $ticketId = null): array
    {
        $this->currentUser->require('helpdesk.update');
        $checklist = $this->validateChecklist($input);
        $data = $this->validate($input);
        if ($ticketId !== null) {
            $this->tickets->get($ticketId);
        }
        $id = $this->events->transaction(function () use ($data, $ticketId, $checklist): int {
            $id = $this->events->create($data + [
                'ticket_id' => $ticketId,
                'created_by' => $this->currentUser->id(),
                'created_by_name' => $this->currentUser->displayName() !== '' ? $this->currentUser->displayName() : 'system',
                'updated_by' => $this->currentUser->id(),
            ]);
            if ($checklist !== null) {
                $this->saveChecklist($id, $checklist);
            }

            return $id;
        });
        $labels = $checklist !== null ? array_column($checklist, 'label') : [];
        $this->audit->log('create', 'helpdesk_calendar_event', $id, $data['title'], null, $data + ['ticket_id' => $ticketId]
            + ($labels !== [] ? ['checklist' => $labels] : []));

        return $this->get($id);
    }

    /** Termin aus einem Ticket anlegen; ohne Titel wird „Ticketnummer · Betreff“ verwendet. @param array<string,mixed> $input @return array<string,mixed> */
    public function createFromTicket(int $ticketId, array $input): array
    {
        $ticket = $this->tickets->getVisible($ticketId);
        if (trim((string) ($input['title'] ?? '')) === '') {
            $input['title'] = self::ticketTitle($ticket);
        }

        return $this->create($input, $ticketId);
    }

    /**
     * Ändert einen Termin. Bei Serienterminen bestimmt `$scope`, ob die ganze Serie oder nur der Termin
     * am `occurrence`-Tag geändert wird (Serientermine: `date` ist dann der Termintag des Vorkommens).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(int $id, array $input, string $scope = self::SCOPE_SERIES): array
    {
        $this->currentUser->require('helpdesk.update');
        $old = $this->events->find($id);
        if ($old === null) {
            throw new NotFoundException('Termin nicht gefunden.');
        }
        // Die Checkliste gehört zum Einzel- bzw. Serientermin und gilt unabhängig vom Umfang der Änderung.
        $checklist = $this->validateChecklist($input);
        $parent = $this->parentOf($old);
        $event = $this->updateEvent($id, $old, $parent, $input, $scope);
        if ($checklist !== null) {
            $this->updateChecklist($parent !== null ? (int) $parent['id'] : $id, $checklist);
        }

        return $event;
    }

    /**
     * @param array<string,mixed> $old
     * @param array<string,mixed>|null $parent
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function updateEvent(int $id, array $old, ?array $parent, array $input, string $scope): array
    {
        if ($parent !== null && $scope === self::SCOPE_OCCURRENCE) {
            return $this->updateOccurrence($old, $parent, $input);
        }

        if ($parent === null) {
            $data = $this->validate($input);
            $this->events->transaction(function () use ($id, $old, $data): void {
                $this->events->update($id, $data + ['updated_by' => $this->currentUser->id()]);
                // Wird ein Einzeltermin zur Serie, gelten seine Bestätigungen für deren ersten Termin weiter.
                if ((string) ($old['recurrence'] ?? '') === '' && (string) ($data['recurrence'] ?? '') !== '') {
                    $this->checklists->moveConfirmations($id, '', $this->localDay((string) $data['starts_at']));
                }
            });
            $this->audit->log('update', 'helpdesk_calendar_event', $id, $data['title'], array_intersect_key($old, $data), $data);

            return $this->get($id);
        }

        // Ganze Serie: Werte und Wiederholung ersetzen, abweichende Einzeltermine entfallen.
        // Wurde ein einzelnes Vorkommen bearbeitet, bleibt der Beginn der Serie bestehen: Die Daten des
        // Formulars werden vor der Prüfung um den Abstand Vorkommen → erster Termin zurückverschoben,
        // damit auch das Serienende gegen den ersten Termin geprüft wird.
        $occurrence = trim((string) ($input['occurrence'] ?? ''));
        $anchorDay = $this->localDay((string) $parent['starts_at']);
        if ($occurrence !== '' && self::parseDate($occurrence) !== null && $occurrence !== $anchorDay) {
            $input = self::shiftInputDates($input, $occurrence, $anchorDay);
        }
        $data = $this->validate($input);
        $parentId = (int) $parent['id'];
        $this->events->transaction(function () use ($parentId, $data): void {
            $this->events->update($parentId, $data + ['updated_by' => $this->currentUser->id()]);
            $this->events->deleteChildren($parentId);
        });
        $this->audit->log('update', 'helpdesk_calendar_event', $parentId, $data['title'], array_intersect_key($parent, $data), $data);

        return $this->get($parentId);
    }

    /**
     * Löscht einen Termin. `$scope` = SCOPE_OCCURRENCE eines Serientermins sagt nur den Termin am
     * `$occurrence`-Tag ab, sonst verschwindet die ganze Serie samt Einzelterminen.
     */
    public function delete(int $id, string $scope = self::SCOPE_SERIES, ?string $occurrence = null): void
    {
        $this->currentUser->require('helpdesk.update');
        $event = $this->events->find($id);
        if ($event === null) {
            throw new NotFoundException('Termin nicht gefunden.');
        }
        $parent = $this->parentOf($event);
        if ($parent === null || $scope !== self::SCOPE_OCCURRENCE) {
            $target = $parent ?? $event;
            $this->events->delete((int) $target['id']);
            $this->audit->log('delete', 'helpdesk_calendar_event', (int) $target['id'], (string) $target['title'], [
                'recurrence' => (string) ($target['recurrence'] ?? ''),
                'series' => $parent !== null,
            ]);

            return;
        }

        $this->cancelOccurrence($event, $parent, $this->occurrenceDate($occurrence, $event, $parent));
    }

    // ------------------------------------------------------------------ Checkliste

    /**
     * Checklistenpunkte aus dem Formular (`checklist[n][label]`, optional `checklist[n][id]`); leere Zeilen entfallen.
     * @return list<array{id:?int,label:string}>
     */
    public static function parseChecklist(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $items = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim(preg_replace('/\s+/u', ' ', (string) ($row['label'] ?? '')) ?? '');
            if ($label === '') {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $items[] = ['id' => $id > 0 ? $id : null, 'label' => $label];
        }

        return $items;
    }

    /** Punkte der Checkliste eines Termins (am Einzel- bzw. Serientermin). @param array<string,mixed> $event @return list<array<string,mixed>> */
    public function checklistItems(array $event): array
    {
        return $this->checklists->items($this->checklistRootId($event));
    }

    /**
     * Checkliste eines Termins für ein Vorkommen: Punkte mit Bestätigung (wer, wann) und Bearbeitbarkeit.
     * Bei Serienterminen gilt der Stand nur für das aufgerufene Vorkommen; Vorkommen nach dem heutigen
     * Tag lassen sich noch nicht abhaken.
     *
     * @param array<string,mixed> $event
     * @return array{items:list<array<string,mixed>>,total:int,confirmed:int,occurrence:string,is_future:bool,can_confirm:bool}
     */
    public function checklist(array $event, string $occurrence = '', ?\DateTimeImmutable $nowUtc = null): array
    {
        $key = $this->checklistKey($event, $occurrence);
        $day = $this->checklistDay($event, $key);
        $isFuture = $day > $this->localToday($nowUtc);
        $tz = $this->timezone();
        $items = [];
        $confirmed = 0;
        foreach ($this->checklists->itemsWithConfirmations($this->checklistRootId($event), $key) as $row) {
            $row['is_confirmed'] = ($row['confirmed_at'] ?? null) !== null;
            $row['confirmed_local'] = $row['is_confirmed']
                ? (new \DateTimeImmutable((string) $row['confirmed_at'], new \DateTimeZone('UTC')))->setTimezone($tz)
                : null;
            $confirmed += $row['is_confirmed'] ? 1 : 0;
            $items[] = $row;
        }

        return [
            'items' => $items,
            'total' => count($items),
            'confirmed' => $confirmed,
            'occurrence' => $key,
            'is_future' => $isFuture,
            'can_confirm' => !$isFuture && $this->currentUser->can('helpdesk.update'),
        ];
    }

    /**
     * Bestätigt einen Checklistenpunkt für ein Vorkommen oder nimmt die Bestätigung zurück.
     * Vorkommen in der Zukunft (Termintag nach heute, lokal) können nicht abgearbeitet werden.
     */
    public function confirmChecklistItem(int $eventId, int $itemId, string $occurrence, bool $confirmed, ?\DateTimeImmutable $nowUtc = null): void
    {
        $this->currentUser->require('helpdesk.update');
        $event = $this->events->find($eventId);
        if ($event === null) {
            throw new NotFoundException('Termin nicht gefunden.');
        }
        $parent = $this->parentOf($event);
        $key = $parent !== null ? $this->occurrenceDate($occurrence, $event, $parent) : '';
        $day = $this->checklistDay($event, $key);
        $now = $nowUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($day > $this->localToday($now)) {
            throw new ValidationException(['checklist' => 'Checklisten von Terminen in der Zukunft können noch nicht abgearbeitet werden.']);
        }
        $rootId = $parent !== null ? (int) $parent['id'] : (int) $event['id'];
        $item = null;
        foreach ($this->checklists->items($rootId) as $row) {
            if ((int) $row['id'] === $itemId) {
                $item = $row;
                break;
            }
        }
        if ($item === null) {
            throw new NotFoundException('Checklistenpunkt nicht gefunden.');
        }
        if ($confirmed) {
            $this->checklists->confirm(
                $itemId,
                $key,
                $this->currentUser->id(),
                $this->currentUser->displayName() !== '' ? $this->currentUser->displayName() : 'system',
                $now->format('Y-m-d H:i:s')
            );
        } else {
            $this->checklists->revoke($itemId, $key);
        }
        $this->audit->log($confirmed ? 'checklist_confirm' : 'checklist_revoke', 'helpdesk_calendar_event', $rootId, (string) $event['title'], null, [
            'checklist_item' => (string) $item['label'],
            'occurrence' => $day,
        ]);
    }

    /**
     * Prüft die Checkliste aus dem Formular. Ohne `checklist_submitted` bleibt die Checkliste unverändert (null).
     * @param array<string,mixed> $input
     * @return list<array{id:?int,label:string}>|null
     */
    private function validateChecklist(array $input): ?array
    {
        if ((string) ($input['checklist_submitted'] ?? '') !== '1') {
            return null;
        }
        $items = self::parseChecklist($input['checklist'] ?? []);
        if (count($items) > self::MAX_CHECKLIST_ITEMS) {
            throw new ValidationException(['checklist' => 'Eine Checkliste hat höchstens ' . self::MAX_CHECKLIST_ITEMS . ' Punkte.']);
        }
        foreach ($items as $item) {
            if (mb_strlen($item['label']) > self::MAX_CHECKLIST_LABEL) {
                throw new ValidationException(['checklist' => 'Ein Checklistenpunkt ist zu lang (höchstens ' . self::MAX_CHECKLIST_LABEL . ' Zeichen).']);
            }
        }

        return $items;
    }

    /**
     * Gleicht die Checkliste eines Termins ab: bekannte Punkte behalten ihre Bestätigungen, entfernte Punkte
     * verschwinden samt Bestätigungen.
     * @param list<array{id:?int,label:string}> $items
     */
    private function saveChecklist(int $eventId, array $items): void
    {
        $existing = [];
        foreach ($this->checklists->items($eventId) as $row) {
            $existing[(int) $row['id']] = $row;
        }
        $kept = [];
        foreach ($items as $index => $item) {
            $position = $index + 1;
            if ($item['id'] !== null && isset($existing[$item['id']]) && !isset($kept[$item['id']])) {
                $kept[$item['id']] = true;
                $row = $existing[$item['id']];
                if ((string) $row['label'] !== $item['label'] || (int) $row['position'] !== $position) {
                    $this->checklists->updateItem($item['id'], $item['label'], $position);
                }
                continue;
            }
            $this->checklists->createItem(['event_id' => $eventId, 'position' => $position, 'label' => $item['label']]);
        }
        foreach (array_keys($existing) as $id) {
            if (!isset($kept[$id])) {
                $this->checklists->deleteItem($id);
            }
        }
    }

    /** @param list<array{id:?int,label:string}> $items */
    private function updateChecklist(int $eventId, array $items): void
    {
        $before = array_map(static fn (array $r): string => (string) $r['label'], $this->checklists->items($eventId));
        $this->events->transaction(fn () => $this->saveChecklist($eventId, $items));
        $after = array_column($items, 'label');
        if ($before !== $after) {
            $event = $this->events->find($eventId);
            $this->audit->log('update', 'helpdesk_calendar_event', $eventId, (string) ($event['title'] ?? ''), ['checklist' => $before], ['checklist' => $after]);
        }
    }

    /** Termin, an dem die Checkliste hängt (Serientermin bei Vorkommen und Einzelterminen einer Serie). @param array<string,mixed> $event */
    private function checklistRootId(array $event): int
    {
        return ($event['series_parent_id'] ?? null) !== null ? (int) $event['series_parent_id'] : (int) $event['id'];
    }

    /**
     * Schlüssel der Bestätigungen: '' beim Einzeltermin, sonst Termintag des Vorkommens.
     * @param array<string,mixed> $event
     */
    private function checklistKey(array $event, string $occurrence = ''): string
    {
        if (($event['series_parent_id'] ?? null) !== null && (string) ($event['series_date'] ?? '') !== '') {
            return (string) $event['series_date'];
        }
        if ((string) ($event['recurrence'] ?? '') === '') {
            return '';
        }
        if ($occurrence !== '' && self::parseDate($occurrence) !== null) {
            return $occurrence;
        }

        return (string) ($event['occurrence'] ?? $this->localDay((string) $event['starts_at']));
    }

    /**
     * Tag, an dem das Vorkommen stattfindet (verschobene Einzeltermine einer Serie: tatsächlicher Beginn).
     * @param array<string,mixed> $event
     */
    private function checklistDay(array $event, string $key): string
    {
        if ($key !== '' && ($event['series_parent_id'] ?? null) === null) {
            return $key;
        }

        return $this->localDay((string) $event['starts_at']);
    }

    /**
     * Ergänzt Termine um den Stand ihrer Checkliste (checklist_total, checklist_confirmed) für Raster und Overlay.
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    private function withChecklistCounts(array $events): array
    {
        $rootIds = array_values(array_unique(array_map(fn (array $e): int => $this->checklistRootId($e), $events)));
        $totals = $this->checklists->countItems($rootIds);
        if ($totals === []) {
            return $events;
        }
        $confirmations = $this->checklists->countConfirmations(array_keys($totals));
        $today = $this->localToday();
        foreach ($events as $i => $event) {
            $root = $this->checklistRootId($event);
            $key = $this->checklistKey($event);
            $events[$i]['checklist_total'] = $totals[$root] ?? 0;
            $events[$i]['checklist_confirmed'] = $confirmations[$root][$key] ?? 0;
            $events[$i]['checklist_future'] = $this->checklistDay($event, $key) > $today;
        }

        return $events;
    }

    // ------------------------------------------------------------------ Serientermine

    /**
     * Formularwerte eines Vorkommens: Termintag des Vorkommens mit den Uhrzeiten des Serientermins,
     * bei abweichendem Einzeltermin dessen eigene Werte.
     * @return array<string,string>
     */
    public function occurrenceValues(int $id, string $day): array
    {
        $event = $this->get($id);
        $parent = $this->parentOf($event);
        if ($parent === null || self::parseDate($day) === null) {
            return self::formValues($event);
        }
        $child = $this->childrenByDate((int) $parent['id'])[$day] ?? null;
        if ($child !== null) {
            return self::formValues($this->decorate($child));
        }
        $values = self::formValues($this->decorate($parent));
        $offset = (int) (new \DateTimeImmutable($values['date']))->diff(new \DateTimeImmutable($day))->format('%r%a');
        $values['end_date'] = (new \DateTimeImmutable($values['end_date']))->modify(sprintf('%+d day', $offset))->format('Y-m-d');
        $values['date'] = $day;
        $values['occurrence'] = $day;

        return $values;
    }

    /** Wiederholungsangabe für die Anzeige, z. B. „Wöchentlich bis 31.12.2026“. @param array<string,mixed> $event */
    public static function recurrenceLabel(array $event): string
    {
        $rule = (string) ($event['recurrence'] ?? '');
        if (!isset(self::RECURRENCES[$rule])) {
            return '';
        }
        $interval = max(1, (int) ($event['recurrence_interval'] ?? 1));
        $units = ['daily' => 'Tage', 'weekly' => 'Wochen', 'monthly' => 'Monate', 'yearly' => 'Jahre'];
        $label = $interval > 1 ? 'Alle ' . $interval . ' ' . $units[$rule] : self::RECURRENCES[$rule];
        $until = (string) ($event['recurrence_until'] ?? '');

        return $until !== '' ? $label . ' bis ' . (new \DateTimeImmutable($until))->format('d.m.Y') : $label . ' · unbefristet';
    }

    /** @param array<string,mixed> $row */
    private static function isSeries(array $row): bool
    {
        return (string) ($row['recurrence'] ?? '') !== '' && ($row['series_parent_id'] ?? null) === null;
    }

    /**
     * Serientermin, zu dem eine Zeile gehört: der Termin selbst, sein Serientermin oder null (Einzeltermin).
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function parentOf(array $row): ?array
    {
        if (($row['series_parent_id'] ?? null) !== null) {
            return $this->events->find((int) $row['series_parent_id']);
        }

        return self::isSeries($row) ? $row : null;
    }

    /**
     * Einzeltermine eines Serientermins, nach ihrem Ursprungstag. Je Tag gilt der erste Eintrag.
     * @return array<string,array<string,mixed>>
     */
    private function childrenByDate(int $parentId): array
    {
        $byDay = [];
        foreach ($this->events->childrenOf($parentId) as $child) {
            $day = (string) ($child['series_date'] ?? '');
            if ($day !== '') {
                $byDay[$day] ??= $child;
            }
        }

        return $byDay;
    }

    /**
     * Vorkommen einer Serie im Zeitraum [from, to). Der Schlüssel ist der lokale Termintag, damit
     * abweichende Einzeltermine (series_date) zugeordnet werden können.
     *
     * @param array<string,mixed> $parent
     * @return \Generator<string,array{start:\DateTimeImmutable,end:\DateTimeImmutable}>
     */
    private function occurrences(array $parent, \DateTimeImmutable $from, \DateTimeImmutable $to): \Generator
    {
        $rule = (string) ($parent['recurrence'] ?? '');
        if (!isset(self::RECURRENCES[$rule])) {
            return;
        }
        $tz = $this->timezone();
        $utc = new \DateTimeZone('UTC');
        $anchor = (new \DateTimeImmutable((string) $parent['starts_at'], $utc))->setTimezone($tz);
        $anchorEnd = (new \DateTimeImmutable((string) $parent['ends_at'], $utc))->setTimezone($tz);
        // Dauer als Wanduhr-Abstand: Beginn und Ende bleiben auch über die Zeitumstellung gleich.
        $duration = $anchor->diff($anchorEnd);
        $interval = max(1, min(self::MAX_INTERVAL, (int) ($parent['recurrence_interval'] ?? 1)));
        $untilRaw = (string) ($parent['recurrence_until'] ?? '');
        $until = $untilRaw !== '' ? new \DateTimeImmutable($untilRaw . ' 00:00:00', $tz) : null;
        $anchorDay = $anchor->setTime(0, 0);
        $timeOfDay = $anchor->format('H:i:s');

        $first = $this->firstOccurrenceIndex($rule, $interval, $anchorDay, $from->setTime(0, 0));
        for ($i = $first; $i < $first + self::MAX_OCCURRENCES; $i++) {
            $day = self::occurrenceDay($rule, $interval, $anchorDay, $i, $tz);
            if ($day === null || ($until !== null && $day > $until)) {
                return;
            }
            $start = new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $timeOfDay, $tz);
            if ($start >= $to) {
                return;
            }
            $end = $start->add($duration);
            if ($end > $from) {
                yield $day->format('Y-m-d') => ['start' => $start, 'end' => $end];
            }
        }
    }

    /** Sprung zum ersten Vorkommen kurz vor dem Zeitraum (Serien können Jahre zurückliegen). */
    private function firstOccurrenceIndex(string $rule, int $interval, \DateTimeImmutable $anchorDay, \DateTimeImmutable $fromDay): int
    {
        if ($fromDay <= $anchorDay) {
            return 0;
        }
        $days = (int) round(($fromDay->getTimestamp() - $anchorDay->getTimestamp()) / 86400);
        // Mehrtägige Termine können aus dem Vortag in den Zeitraum hineinreichen.
        $slack = self::MAX_DAYS;
        $months = ((int) $fromDay->format('Y') - (int) $anchorDay->format('Y')) * 12
            + ((int) $fromDay->format('n') - (int) $anchorDay->format('n'));

        return match ($rule) {
            'daily' => intdiv(max(0, $days - $slack), $interval),
            'weekly' => intdiv(max(0, $days - $slack), $interval * 7),
            'monthly' => intdiv(max(0, $months - 1), $interval),
            'yearly' => intdiv(max(0, (int) $fromDay->format('Y') - (int) $anchorDay->format('Y') - 1), $interval),
            default => 0,
        };
    }

    /** Tag (lokal) des $i-ten Vorkommens; Monats-/Jahresende werden auf den letzten Tag begrenzt. */
    private static function occurrenceDay(string $rule, int $interval, \DateTimeImmutable $anchorDay, int $i, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $dayOfMonth = (int) $anchorDay->format('j');
        $month = (int) $anchorDay->format('n');
        $year = (int) $anchorDay->format('Y');

        if ($rule === 'daily') {
            return $anchorDay->modify('+' . ($i * $interval) . ' days');
        }
        if ($rule === 'weekly') {
            return $anchorDay->modify('+' . ($i * $interval * 7) . ' days');
        }
        if ($rule === 'monthly') {
            $offset = $month - 1 + $i * $interval;
            $year += intdiv($offset, 12);
            $month = $offset % 12 + 1;
        } elseif ($rule === 'yearly') {
            $year += $i * $interval;
        } else {
            return null;
        }
        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz))->format('t');

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, min($dayOfMonth, $lastDay)), $tz);
    }

    /**
     * Ein Vorkommen als Termin-Zeile (ohne eigene Datenbankzeile).
     * @param array<string,mixed> $parent
     * @param array{start:\DateTimeImmutable,end:\DateTimeImmutable} $occurrence
     * @return array<string,mixed>
     */
    private function occurrenceRow(array $parent, string $day, array $occurrence): array
    {
        $row = $parent;
        $row['starts_at'] = $this->toUtc($occurrence['start']);
        $row['ends_at'] = $this->toUtc($occurrence['end']);
        $row['is_virtual'] = true;
        $row['occurrence'] = $day;
        $row['series_parent_id'] = null;
        $row['series_date'] = null;

        return $row;
    }

    /**
     * Termintag des Vorkommens. Ein vorhandener Einzeltermin behält seinen gespeicherten Tag; beim
     * Serientermin muss der Tag aus dem Formular ein Vorkommen der Serie sein.
     * @param array<string,mixed> $event
     * @param array<string,mixed> $parent
     */
    private function occurrenceDate(?string $occurrence, array $event, array $parent): string
    {
        if ((int) $event['id'] !== (int) $parent['id'] && (string) ($event['series_date'] ?? '') !== '') {
            return (string) $event['series_date'];
        }
        $value = trim((string) $occurrence);
        if ($value === '') {
            return $this->localDay((string) $parent['starts_at']);
        }
        if (self::parseDate($value) === null || $this->occurrenceOn($parent, $value) === null) {
            throw new ValidationException(['occurrence' => 'An diesem Tag gibt es keinen Termin der Serie.']);
        }

        return $value;
    }

    /**
     * Vorkommen der Serie, das am lokalen Tag `$day` beginnt.
     * @param array<string,mixed> $parent
     * @return array{start:\DateTimeImmutable,end:\DateTimeImmutable}|null
     */
    private function occurrenceOn(array $parent, string $day): ?array
    {
        $dayStart = new \DateTimeImmutable($day . ' 00:00:00', $this->timezone());
        foreach ($this->occurrences($parent, $dayStart, $dayStart->modify('+1 day')) as $key => $item) {
            if ($key === $day) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Neuer oder geänderter Einzeltermin einer Serie („nur dieser Termin“).
     * @param array<string,mixed> $event bisherige Zeile (Serientermin oder Einzeltermin)
     * @param array<string,mixed> $parent Serientermin
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function updateOccurrence(array $event, array $parent, array $input): array
    {
        // Die Wiederholung gehört zur Serie; für ein einzelnes Vorkommen wird sie weder geprüft noch geändert.
        $data = $this->validate(['recurrence' => ''] + array_diff_key($input, ['recurrence' => true]));
        $day = $this->occurrenceDate((string) ($input['occurrence'] ?? ''), $event, $parent);
        $data = $this->asOccurrence($data, $parent, $day);

        // Über einen veralteten Aufruf des Serientermins kann es den Einzeltermin schon geben.
        if ((int) $event['id'] === (int) $parent['id']) {
            $event = $this->childrenByDate((int) $parent['id'])[$day] ?? $event;
        }
        if ((int) $event['id'] === (int) $parent['id']) {
            $id = $this->events->create($data + [
                'ticket_id' => $parent['ticket_id'] ?? null,
                'created_by' => $this->currentUser->id(),
                'created_by_name' => $this->currentUser->displayName() !== '' ? $this->currentUser->displayName() : 'system',
                'updated_by' => $this->currentUser->id(),
            ]);
        } else {
            $id = (int) $event['id'];
            $this->events->update($id, $data);
        }
        $this->audit->log('update', 'helpdesk_calendar_event', $id, $data['title'], null, $data + ['occurrence' => $day]);

        return $this->get($id);
    }

    /**
     * Werte eines einzelnen Vorkommens: Wiederholung des Serientermins dient nur der Anzeige.
     * @param array<string,mixed> $data Ergebnis von {@see validate()}
     * @param array<string,mixed> $parent
     * @return array<string,mixed>
     */
    private function asOccurrence(array $data, array $parent, string $day): array
    {
        $data['recurrence'] = (string) ($parent['recurrence'] ?? '');
        $data['recurrence_interval'] = max(1, (int) ($parent['recurrence_interval'] ?? 1));
        $data['recurrence_until'] = ($parent['recurrence_until'] ?? null) !== null ? (string) $parent['recurrence_until'] : null;
        $data['series_parent_id'] = (int) $parent['id'];
        $data['series_date'] = $day;
        $data['series_canceled'] = 0;
        $data['updated_by'] = $this->currentUser->id();

        return $data;
    }

    /** Sagt ein einzelnes Vorkommen ab: vorhandener Einzeltermin wird ausgeblendet, sonst markiert neu. */
    private function cancelOccurrence(array $event, array $parent, string $day): void
    {
        if ((int) $event['id'] !== (int) $parent['id']) {
            // Vorhandener Einzeltermin bleibt als Ausblendemarke stehen.
            $this->events->update((int) $event['id'], ['series_canceled' => 1, 'updated_by' => $this->currentUser->id()]);
        } else {
            $existing = $this->childrenByDate((int) $parent['id'])[$day] ?? null;
            if ($existing !== null) {
                $this->events->update((int) $existing['id'], ['series_canceled' => 1, 'updated_by' => $this->currentUser->id()]);
            } else {
                $this->events->create($this->canceledOccurrence($parent, $day));
            }
        }
        $this->audit->log('delete', 'helpdesk_calendar_event', (int) $parent['id'], (string) $parent['title'], null, [
            'occurrence' => $day,
            'series' => true,
        ]);
    }

    /**
     * Ausblendemarke für ein abgesagtes Vorkommen (eigene Zeile, damit das Vorkommen nicht neu entsteht).
     * @param array<string,mixed> $parent
     * @return array<string,mixed>
     */
    private function canceledOccurrence(array $parent, string $day): array
    {
        $item = $this->occurrenceOn($parent, $day);
        $start = $item !== null ? $this->toUtc($item['start']) : null;
        $end = $item !== null ? $this->toUtc($item['end']) : null;

        return [
            'title' => (string) $parent['title'],
            'description' => $parent['description'] ?? null,
            'location' => $parent['location'] ?? null,
            'starts_at' => $start ?? (string) $parent['starts_at'],
            'ends_at' => $end ?? (string) $parent['ends_at'],
            'all_day' => (int) ($parent['all_day'] ?? 0),
            'recurrence' => '',
            'recurrence_interval' => 1,
            'recurrence_until' => null,
            'series_parent_id' => (int) $parent['id'],
            'series_date' => $day,
            'series_canceled' => 1,
            'ticket_id' => $parent['ticket_id'] ?? null,
            'created_by' => $this->currentUser->id(),
            'created_by_name' => $this->currentUser->displayName() !== '' ? $this->currentUser->displayName() : 'system',
            'updated_by' => $this->currentUser->id(),
        ];
    }

    /** Vorschlagstitel eines Termins aus einem Ticket. @param array<string,mixed> $ticket */
    public static function ticketTitle(array $ticket): string
    {
        $title = trim((string) ($ticket['number'] ?? '') . ' · ' . (string) ($ticket['subject'] ?? ''), ' ·');

        return mb_substr($title, 0, self::MAX_TITLE);
    }

    /**
     * Zeitlich gebundene Termine eines Tages für das Raster (Tag/Arbeitswoche): Lage in Minuten ab
     * Tagesbeginn und Spalten bei Überschneidung. Ganztägige Termine gehören in die Ganztageszeile.
     *
     * @param list<array<string,mixed>> $events mit start_local/end_local (\DateTimeImmutable)
     * @return list<array{event:array<string,mixed>,top:int,height:int,column:int,columns:int,continues_before:bool,continues_after:bool}>
     */
    public static function layoutDay(array $events, string $day, \DateTimeZone $tz): array
    {
        $dayStart = new \DateTimeImmutable($day . ' 00:00:00', $tz);
        $dayEnd = $dayStart->modify('+1 day');
        $dayMinutes = (int) (($dayEnd->getTimestamp() - $dayStart->getTimestamp()) / 60);

        $items = [];
        foreach ($events as $event) {
            if (!empty($event['all_day'])) {
                continue;
            }
            /** @var \DateTimeImmutable $start */
            $start = $event['start_local'];
            /** @var \DateTimeImmutable $end */
            $end = $event['end_local'];
            if ($start >= $dayEnd || $end <= $dayStart) {
                continue;
            }
            $top = max(0, (int) floor(($start->getTimestamp() - $dayStart->getTimestamp()) / 60));
            $bottom = min($dayMinutes, (int) ceil(($end->getTimestamp() - $dayStart->getTimestamp()) / 60));
            $items[] = [
                'event' => $event,
                'top' => $top,
                'height' => max(15, $bottom - $top),
                'column' => 0,
                'columns' => 1,
                'continues_before' => $start < $dayStart,
                'continues_after' => $end > $dayEnd,
            ];
        }
        usort($items, static fn (array $a, array $b): int => [$a['top'], -$a['height']] <=> [$b['top'], -$b['height']]);

        // Überschneidende Termine bilden eine Gruppe; innerhalb der Gruppe erhält jeder die erste freie Spalte.
        $group = [];
        $groupEnd = -1;
        $columnsEnd = [];
        $flush = static function () use (&$group, &$items, &$columnsEnd): void {
            $count = count($columnsEnd);
            foreach ($group as $index) {
                $items[$index]['columns'] = max(1, $count);
            }
            $group = [];
            $columnsEnd = [];
        };
        foreach ($items as $index => $item) {
            $itemEnd = $item['top'] + $item['height'];
            if ($group !== [] && $item['top'] >= $groupEnd) {
                $flush();
                $groupEnd = -1;
            }
            $column = null;
            foreach ($columnsEnd as $c => $end) {
                if ($end <= $item['top']) {
                    $column = $c;
                    break;
                }
            }
            if ($column === null) {
                $column = count($columnsEnd);
            }
            $columnsEnd[$column] = $itemEnd;
            $items[$index]['column'] = $column;
            $group[] = $index;
            $groupEnd = max($groupEnd, $itemEnd);
        }
        if ($group !== []) {
            $flush();
        }

        return $items;
    }

    /**
     * Termine, die an einem Tag liegen (für Ganztageszeile und Monatsansicht).
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    public static function onDay(array $events, string $day, \DateTimeZone $tz, ?bool $allDay = null): array
    {
        $dayStart = new \DateTimeImmutable($day . ' 00:00:00', $tz);
        $dayEnd = $dayStart->modify('+1 day');

        return array_values(array_filter($events, static function (array $e) use ($dayStart, $dayEnd, $allDay): bool {
            if ($allDay !== null && (bool) $e['all_day'] !== $allDay) {
                return false;
            }

            return $e['start_local'] < $dayEnd && $e['end_local'] > $dayStart;
        }));
    }

    /**
     * Formularwerte eines bestehenden Termins (lokal).
     * @param array<string,mixed> $event
     * @return array<string,string>
     */
    public static function formValues(array $event): array
    {
        /** @var \DateTimeImmutable $start */
        $start = $event['start_local'];
        /** @var \DateTimeImmutable $end */
        $end = $event['end_local'];
        $allDay = !empty($event['all_day']);
        $endDate = $allDay ? $end->modify('-1 day') : $end;

        return [
            'title' => (string) $event['title'],
            'date' => $start->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'start_time' => $allDay ? '' : $start->format('H:i'),
            'end_time' => $allDay ? '' : $end->format('H:i'),
            'all_day' => $allDay ? '1' : '0',
            'location' => (string) ($event['location'] ?? ''),
            'description' => (string) ($event['description'] ?? ''),
            'recurrence' => (string) ($event['recurrence'] ?? ''),
            'recurrence_interval' => (string) max(1, (int) ($event['recurrence_interval'] ?? 1)),
            'recurrence_until' => (string) ($event['recurrence_until'] ?? ''),
            'occurrence' => (string) ($event['occurrence'] ?? $start->format('Y-m-d')),
        ];
    }

    /** Zeitangabe eines Termins für Listen, z. B. „09:00–10:30“ oder „Ganztägig“. @param array<string,mixed> $event */
    public static function timeLabel(array $event, ?string $day = null): string
    {
        /** @var \DateTimeImmutable $start */
        $start = $event['start_local'];
        /** @var \DateTimeImmutable $end */
        $end = $event['end_local'];
        if (!empty($event['all_day'])) {
            return 'Ganztägig';
        }
        $sameDay = $start->format('Y-m-d') === $end->format('Y-m-d');
        if ($sameDay) {
            return $start->format('H:i') . '–' . $end->format('H:i');
        }
        if ($day !== null && $start->format('Y-m-d') < $day) {
            return $end->format('Y-m-d') === $day ? 'bis ' . $end->format('H:i') : 'ganztägig (fortlaufend)';
        }

        return $start->format('H:i') . ' – ' . $end->format('d.m. H:i');
    }

    // ------------------------------------------------------------------ intern

    /**
     * @param array<string,mixed> $input
     * @return array{title:string,description:?string,location:?string,starts_at:string,ends_at:string,all_day:int,recurrence:string,recurrence_interval:int,recurrence_until:?string}
     */
    private function validate(array $input): array
    {
        $errors = [];
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = 'Bitte einen Titel angeben.';
        } elseif (mb_strlen($title) > self::MAX_TITLE) {
            $errors['title'] = 'Der Titel ist zu lang (höchstens ' . self::MAX_TITLE . ' Zeichen).';
        }
        $location = trim((string) ($input['location'] ?? ''));
        if (mb_strlen($location) > self::MAX_LOCATION) {
            $errors['location'] = 'Der Ort ist zu lang (höchstens ' . self::MAX_LOCATION . ' Zeichen).';
        }
        $description = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input['description'] ?? '')));
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            $errors['description'] = 'Die Beschreibung ist zu lang (höchstens ' . number_format(self::MAX_DESCRIPTION, 0, ',', '.') . ' Zeichen).';
        }

        $allDay = in_array((string) ($input['all_day'] ?? '0'), ['1', 'on', 'true'], true);
        $date = self::parseDate((string) ($input['date'] ?? ''));
        if ($date === null) {
            $errors['date'] = 'Bitte ein gültiges Datum wählen.';
        }
        $endDateRaw = trim((string) ($input['end_date'] ?? ''));
        $endDate = $endDateRaw !== '' ? self::parseDate($endDateRaw) : $date;
        if ($endDateRaw !== '' && $endDate === null) {
            $errors['end_date'] = 'Bitte ein gültiges Enddatum wählen.';
        }

        $tz = $this->timezone();
        $start = $end = null;
        if ($date !== null && $endDate !== null) {
            if ($allDay) {
                $start = new \DateTimeImmutable($date->format('Y-m-d') . ' 00:00:00', $tz);
                $end = (new \DateTimeImmutable($endDate->format('Y-m-d') . ' 00:00:00', $tz))->modify('+1 day');
            } else {
                $startTime = self::parseTime((string) ($input['start_time'] ?? ''));
                if ($startTime === null) {
                    $errors['start_time'] = 'Bitte eine Beginnzeit angeben (HH:MM) oder „Ganztägig“ wählen.';
                } else {
                    $start = new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $startTime . ':00', $tz);
                    $endTimeRaw = trim((string) ($input['end_time'] ?? ''));
                    $endTime = $endTimeRaw !== '' ? self::parseTime($endTimeRaw) : null;
                    if ($endTimeRaw !== '' && $endTime === null) {
                        $errors['end_time'] = 'Bitte eine gültige Endzeit angeben (HH:MM).';
                    }
                    $end = $endTime !== null
                        ? new \DateTimeImmutable($endDate->format('Y-m-d') . ' ' . $endTime . ':00', $tz)
                        : $start->modify('+1 hour');
                }
            }
            if ($start !== null && $end !== null && $end <= $start) {
                $errors['end_time'] = 'Das Ende muss nach dem Beginn liegen.';
            }
            if ($start !== null && $end !== null && $end > $start->modify('+' . self::MAX_DAYS . ' days')) {
                $errors['end_date'] = 'Ein Termin darf höchstens ' . self::MAX_DAYS . ' Tage dauern.';
            }
        }

        if ($errors !== [] || $start === null || $end === null) {
            throw new ValidationException($errors !== [] ? $errors : ['date' => 'Bitte ein gültiges Datum wählen.']);
        }

        [$recurrence, $interval, $until] = $this->validateRecurrence($input, $date, $start, $end);

        return [
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'location' => $location !== '' ? $location : null,
            'starts_at' => $this->toUtc($start),
            'ends_at' => $this->toUtc($end),
            'all_day' => $allDay ? 1 : 0,
            'recurrence' => $recurrence,
            'recurrence_interval' => $interval,
            'recurrence_until' => $until,
        ];
    }

    /**
     * Prüft die Wiederholung eines Termins.
     * @param array<string,mixed> $input
     * @return array{0:string,1:int,2:?string} Wiederholung, Abstand und letzter Tag (Y-m-d) der Serie
     */
    private function validateRecurrence(array $input, ?\DateTimeImmutable $date, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $errors = [];
        $rule = trim((string) ($input['recurrence'] ?? ''));
        if ($rule !== '' && !isset(self::RECURRENCES[$rule])) {
            $errors['recurrence'] = 'Bitte eine gültige Wiederholung wählen.';
            $rule = '';
        }
        $interval = max(1, min(self::MAX_INTERVAL, (int) ($input['recurrence_interval'] ?? 1)));
        if ($rule === 'daily') {
            // Letzter belegter Augenblick: ganztägige Termine enden exklusiv um 00:00 des Folgetags.
            $spanDays = (int) $start->setTime(0, 0)->diff($end->modify('-1 second')->setTime(0, 0))->days + 1;
            if ($interval < $spanDays) {
                $errors['recurrence_interval'] = 'Bei täglicher Wiederholung muss der Abstand größer als die Termindauer sein.';
            }
        }
        $until = null;
        $untilRaw = $rule !== '' ? trim((string) ($input['recurrence_until'] ?? '')) : '';
        if ($untilRaw !== '') {
            $parsed = self::parseDate($untilRaw);
            if ($parsed === null) {
                $errors['recurrence_until'] = 'Bitte ein gültiges Serienende wählen.';
            } elseif ($date !== null && $parsed < $date) {
                $errors['recurrence_until'] = 'Das Serienende muss am oder nach dem ersten Termin liegen.';
            } else {
                $until = $parsed->format('Y-m-d');
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$rule, $interval, $until];
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function decorate(array $event): array
    {
        $tz = $this->timezone();
        $utc = new \DateTimeZone('UTC');
        $event['start_local'] = (new \DateTimeImmutable((string) $event['starts_at'], $utc))->setTimezone($tz);
        $event['end_local'] = (new \DateTimeImmutable((string) $event['ends_at'], $utc))->setTimezone($tz);
        $event['all_day'] = (int) ($event['all_day'] ?? 0);
        $event['recurrence'] = (string) ($event['recurrence'] ?? '');
        $event['recurrence_interval'] = max(1, (int) ($event['recurrence_interval'] ?? 1));
        $event['recurrence_until'] = ($event['recurrence_until'] ?? null) !== null ? (string) $event['recurrence_until'] : null;
        $event['series_canceled'] = (int) ($event['series_canceled'] ?? 0);
        $event['series_parent_id'] = ($event['series_parent_id'] ?? null) !== null ? (int) $event['series_parent_id'] : null;
        $event['is_series'] = $event['recurrence'] !== '';
        // Termintag des angezeigten Vorkommens (bei abweichenden Einzelterminen der Ursprungstag)
        $event['occurrence'] = (string) ($event['occurrence'] ?? $event['series_date'] ?? $event['start_local']->format('Y-m-d'));

        return $event;
    }

    private function toUtc(\DateTimeImmutable $local): string
    {
        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Lokaler Termintag einer in UTC gespeicherten Zeit. */
    private function localDay(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($this->timezone())->format('Y-m-d');
    }

    /**
     * Verschiebt Beginn- und Enddatum des Formulars um die Tage zwischen zwei Termintagen (Uhrzeiten bleiben).
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private static function shiftInputDates(array $input, string $fromDay, string $toDay): array
    {
        $days = (int) (new \DateTimeImmutable($fromDay))->diff(new \DateTimeImmutable($toDay))->format('%r%a');
        if ($days === 0) {
            return $input;
        }
        foreach (['date', 'end_date'] as $key) {
            $date = self::parseDate((string) ($input[$key] ?? ''));
            if ($date !== null) {
                $input[$key] = $date->modify(sprintf('%+d day', $days))->format('Y-m-d');
            }
        }

        return $input;
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        if ((int) $m[1] < 2000 || (int) $m[1] > 2100) {
            return null;
        }

        return new \DateTimeImmutable($m[0] . ' 00:00:00');
    }

    private static function parseTime(string $value): ?string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    /** @return list<string> */
    private static function days(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $days = [];
        for ($d = $start; $d < $end; $d = $d->modify('+1 day')) {
            $days[] = $d->format('Y-m-d');
        }

        return $days;
    }

    private static function spanTitle(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        if ($from->format('Y-m') === $to->format('Y-m')) {
            return (int) $from->format('j') . '.–' . (int) $to->format('j') . '. ' . self::MONTHS[(int) $to->format('n') - 1] . ' ' . $to->format('Y');
        }
        if ($from->format('Y') === $to->format('Y')) {
            return (int) $from->format('j') . '. ' . self::MONTHS[(int) $from->format('n') - 1] . ' – ' . (int) $to->format('j') . '. '
                . self::MONTHS[(int) $to->format('n') - 1] . ' ' . $to->format('Y');
        }

        return $from->format('j.n.Y') . ' – ' . $to->format('j.n.Y');
    }

    /** Wochentag + Datum, z. B. „Mo 9.11.“. */
    public static function shortDay(string $day): string
    {
        $d = new \DateTimeImmutable($day);

        return mb_substr(self::WEEKDAYS[(int) $d->format('N') - 1], 0, 2) . ' ' . $d->format('j.n.');
    }

    /** Wochentag, z. B. „Dienstag“. */
    public static function weekdayName(string $day): string
    {
        return self::WEEKDAYS[(int) (new \DateTimeImmutable($day))->format('N') - 1];
    }

    /** Tageszahl in der Monatsansicht; am Monatsersten mit Monatskürzel („1. Dez“). */
    public static function monthCellLabel(string $day): string
    {
        $d = new \DateTimeImmutable($day);
        $label = (string) (int) $d->format('j');

        return $label === '1' ? '1. ' . mb_substr(self::MONTHS[(int) $d->format('n') - 1], 0, 3) : $label;
    }

    /** Langform, z. B. „Dienstag, 10. November 2026“. */
    public static function longDay(string $day): string
    {
        $d = new \DateTimeImmutable($day);

        return self::WEEKDAYS[(int) $d->format('N') - 1] . ', ' . (int) $d->format('j') . '. ' . self::MONTHS[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
    }
}
