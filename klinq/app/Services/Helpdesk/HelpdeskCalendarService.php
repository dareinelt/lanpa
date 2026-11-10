<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
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
 */
final class HelpdeskCalendarService
{
    public const VIEWS = ['tag' => 'Tag', 'arbeitswoche' => 'Arbeitswoche', 'monat' => 'Monat'];
    public const DEFAULT_VIEW = 'arbeitswoche';

    /** Sitzungsschlüssel für das Termin-Overlay nach Übernahme der Zuständigkeit. */
    public const OVERLAY_SESSION_KEY = '_helpdesk_calendar_overlay';

    private const MAX_TITLE = 200;
    private const MAX_LOCATION = 200;
    private const MAX_DESCRIPTION = 5000;
    private const MAX_DAYS = 31;

    private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    private const WEEKDAYS = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    public function __construct(
        private readonly HelpdeskCalendarRepository $events,
        private readonly TicketService $tickets,
        private readonly CurrentUser $currentUser,
        private readonly AuditLogService $audit,
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
        $from = $this->toUtc(new \DateTimeImmutable($range['start'] . ' 00:00:00', $tz));
        $to = $this->toUtc(new \DateTimeImmutable($range['end'] . ' 00:00:00', $tz));

        return array_map(fn (array $e): array => $this->decorate($e), $this->events->between($from, $to));
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
     * @param array<string,mixed> $input title, date, end_date, start_time, end_time, all_day, location, description
     * @return array<string,mixed> Der angelegte Termin
     */
    public function create(array $input, ?int $ticketId = null): array
    {
        $this->currentUser->require('helpdesk.update');
        $data = $this->validate($input);
        if ($ticketId !== null) {
            $this->tickets->get($ticketId);
        }
        $id = $this->events->create($data + [
            'ticket_id' => $ticketId,
            'created_by' => $this->currentUser->id(),
            'created_by_name' => $this->currentUser->displayName() !== '' ? $this->currentUser->displayName() : 'system',
            'updated_by' => $this->currentUser->id(),
        ]);
        $this->audit->log('create', 'helpdesk_calendar_event', $id, $data['title'], null, $data + ['ticket_id' => $ticketId]);

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

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(int $id, array $input): array
    {
        $this->currentUser->require('helpdesk.update');
        $old = $this->events->find($id);
        if ($old === null) {
            throw new NotFoundException('Termin nicht gefunden.');
        }
        $data = $this->validate($input);
        $this->events->update($id, $data + ['updated_by' => $this->currentUser->id()]);
        $this->audit->log('update', 'helpdesk_calendar_event', $id, $data['title'], array_intersect_key($old, $data), $data);

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $this->currentUser->require('helpdesk.update');
        $event = $this->events->find($id);
        if ($event === null) {
            throw new NotFoundException('Termin nicht gefunden.');
        }
        $this->events->delete($id);
        $this->audit->log('delete', 'helpdesk_calendar_event', $id, (string) $event['title']);
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
     * @return array{title:string,description:?string,location:?string,starts_at:string,ends_at:string,all_day:int}
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

        return [
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'location' => $location !== '' ? $location : null,
            'starts_at' => $this->toUtc($start),
            'ends_at' => $this->toUtc($end),
            'all_day' => $allDay ? 1 : 0,
        ];
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function decorate(array $event): array
    {
        $tz = $this->timezone();
        $utc = new \DateTimeZone('UTC');
        $event['start_local'] = (new \DateTimeImmutable((string) $event['starts_at'], $utc))->setTimezone($tz);
        $event['end_local'] = (new \DateTimeImmutable((string) $event['ends_at'], $utc))->setTimezone($tz);
        $event['all_day'] = (int) ($event['all_day'] ?? 0);

        return $event;
    }

    private function toUtc(\DateTimeImmutable $local): string
    {
        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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
