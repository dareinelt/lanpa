<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Contracts\OrvantaMailBackendInterface;
use App\Core\Logger;
use App\Repositories\OrvantaCalendarRepository;
use App\Support\Html;
use Closure;
use Throwable;

/**
 * Lokaler Kalender fuer Orvanta-Postfaecher am SMTP-/IMAP-Proxy.
 *
 * Postfaecher mit Exchange bleiben unveraendert an EWS angebunden. Postfaecher
 * ohne Exchange erhalten einen eigenen Kalender in der Intranet-Datenbank
 * (orvanta_calendar_events/-attendees); Einladungen, Absagen und Antworten
 * laufen als iCalendar-Nachricht ueber denselben SMTP-Weg wie normale Mail.
 *
 * Der Versand ist idempotent: Jeder Auftrag steht mit einer Kennung aus
 * Methode, Terminkennung, Sequenz und Empfaengerbezug in
 * orvanta_calendar_outbox. Ein wiederholter Aufruf (etwa ein zweiter Klick)
 * findet dieselbe Kennung und legt nichts Neues an.
 */
final class OrvantaCalendarService
{
    /** Groesster abfragbarer Zeitraum in Tagen (wie der Exchange-Weg). */
    public const MAX_RANGE_DAYS = 100;

    /** Groesste Terminlaenge in Sekunden (ein Jahr). */
    private const MAX_LENGTH = 366 * 86400;

    /** Hoechstzahl der Teilnehmer je Termin. */
    private const MAX_ATTENDEES = 100;

    /** Obergrenze der Beschreibung in Zeichen. */
    private const MAX_DESCRIPTION = 60000;

    /** Groesster Erinnerungsvorlauf in Minuten (eine Woche). */
    private const REMINDER_MAX = 10080;

    /** Hoechstzahl der Zustellversuche je Versandauftrag. */
    private const MAX_ATTEMPTS = 5;

    /** Hoechstzahl der Auftraege, die ein Aufruf sofort zustellt. */
    private const MAX_DISPATCH = 3;

    /** Nach dieser Zeit gilt ein laufender Auftrag als abgebrochen (Sekunden). */
    private const CLAIM_TIMEOUT = 900;

    /** Erlaubte Sichtbarkeiten (wie Exchange LegacyFreeBusyStatus). */
    private const FREE_BUSY = ['Free', 'Tentative', 'Busy', 'OOF', 'WorkingElsewhere'];

    /** Antwort der Oberflaeche auf den Teilnehmerstatus (PARTSTAT). */
    private const RESPONSE_PARTSTAT = ['accept' => 'ACCEPTED', 'tentative' => 'TENTATIVE', 'decline' => 'DECLINED'];

    /** Teilnehmerstatus auf die Antwortwerte der Oberflaeche. */
    private const PARTSTAT_RESPONSE = ['ACCEPTED' => 'Accept', 'TENTATIVE' => 'Tentative', 'DECLINED' => 'Decline'];

    /** @var Closure(string,string):OrvantaMailBackendInterface */
    private readonly Closure $backendFor;

    /**
     * @param Closure(string,string):OrvantaMailBackendInterface $backendFor
     *        liefert das Mail-Backend zu AD-Kennung und Postfachadresse
     */
    public function __construct(
        private readonly OrvantaCalendarRepository $repository,
        Closure $backendFor,
        private readonly ?Logger $logger = null
    ) {
        $this->backendFor = $backendFor;
    }

    /**
     * Termine des Zeitraums (ohne abgesagte und vom Benutzer abgelehnte).
     *
     * @return list<array<string,mixed>>
     */
    public function range(string $uid, int $from, int $to): array
    {
        if ($to <= $from || $to - $from > self::MAX_RANGE_DAYS * 86400) {
            throw new OrvantaException('Ungültiger Zeitraum.', 422);
        }
        $rows = $this->repository->range($uid, self::sql($from), self::sql($to));
        $attendees = $this->repository->attendeesFor(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->summary($row, $attendees[(int) $row['id']] ?? []);
        }

        return $items;
    }

    /**
     * Einzelner Termin in der Form des Exchange-Wegs (gleiche Felder, damit die
     * Oberflaeche beide Quellen gleich darstellen kann).
     *
     * @return array<string,mixed>
     */
    public function event(string $uid, string $id): array
    {
        $row = $this->requireEvent($uid, $id);
        $attendees = $this->repository->attendeesFor([(int) $row['id']])[(int) $row['id']] ?? [];
        $data = $this->summary($row, $attendees);
        $description = (string) ($row['description'] ?? '');
        $clean = $description !== '' ? MailHtmlSanitizer::clean($description) : ['html' => '', 'blocked_images' => 0];
        $data['body_html'] = $clean['html'];
        $data['blocked_images'] = $clean['blocked_images'];
        $data['recurring'] = false;
        $data['attachments'] = [];
        $data['required'] = [];
        $data['optional'] = [];
        foreach ($attendees as $attendee) {
            $person = [
                'name' => (string) $attendee['display_name'],
                'email' => (string) $attendee['email'],
                'response' => self::response((string) $attendee['response']),
            ];
            if ((string) $attendee['role'] === 'OPT-PARTICIPANT') {
                $data['optional'][] = $person;
            } else {
                $data['required'][] = $person;
            }
        }
        $data['invite_state'] = $this->inviteState((int) $row['id']);

        return $data;
    }

    /**
     * Legt einen Termin an oder speichert ihn. Aenderungen sind dem
     * Organisator vorbehalten; die Einladung an die Teilnehmer entsteht als
     * Versandauftrag in derselben Transaktion wie der Termin selbst.
     *
     * @param array{email:string,name:string} $sender Postfach des Benutzers
     * @param array<string,mixed> $input Felder aus dem Terminformular
     * @return array{id:string,message:string}
     */
    public function save(string $uid, array $sender, array $input): array
    {
        $subject = trim((string) $input['subject']);
        if ($subject === '') {
            throw new OrvantaException('Bitte einen Betreff angeben.', 422);
        }
        if (mb_strlen($subject) > 255) {
            throw new OrvantaException('Der Betreff ist zu lang (höchstens 255 Zeichen).', 422);
        }
        $location = trim((string) $input['location']);
        if (mb_strlen($location) > 255) {
            throw new OrvantaException('Der Ort ist zu lang (höchstens 255 Zeichen).', 422);
        }
        $description = (string) $input['body'];
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new OrvantaException('Die Beschreibung ist zu lang.', 422);
        }
        $allDay = (bool) $input['all_day'];
        [$start, $end] = $this->times((int) $input['start'], (int) $input['end'], $allDay);
        $reminder = (int) $input['reminder'];
        if ($reminder < -1 || $reminder > self::REMINDER_MAX) {
            throw new OrvantaException('Ungültige Erinnerungszeit.', 422);
        }
        $freeBusy = (string) $input['free_busy'];
        if (!in_array($freeBusy, self::FREE_BUSY, true)) {
            $freeBusy = 'Busy';
        }
        $attendees = $this->attendeeList((array) ($input['required'] ?? []), (array) ($input['optional'] ?? []));
        $id = (int) $input['id'];
        $now = time();
        $existing = $id > 0 ? $this->requireEvent($uid, (string) $id) : null;
        if ($existing !== null && (int) $existing['is_organizer'] !== 1) {
            throw new OrvantaException('Nur der Organisator kann diesen Termin ändern.', 409);
        }
        $previous = $existing === null
            ? []
            : array_map(static fn (array $row): string => (string) $row['email'], $this->repository->attendeesFor([$id])[$id] ?? []);
        $sequence = (int) ($existing['sequence'] ?? 0);
        $invited = $attendees !== [];
        // Jede Aenderung an einem Termin mit Teilnehmern erhoeht die Sequenz;
        // Empfaenger koennen so alt und neu unterscheiden (RFC 5546).
        if ($existing !== null && ($invited || $previous !== [])) {
            $sequence++;
        }
        $eventUid = $existing === null ? ICalendar::newUid() : (string) $existing['event_uid'];
        $status = (string) ($existing['status'] ?? 'CONFIRMED');
        if ($status !== 'CONFIRMED') {
            // Ein bearbeiteter, zuvor abgesagter Termin gilt wieder als bestaetigt.
            $status = 'CONFIRMED';
        }
        $fields = [
            'event_uid' => $eventUid,
            'subject' => $subject,
            'description' => $description !== '' ? $description : null,
            'location' => $location,
            'organizer_email' => $sender['email'],
            'organizer_name' => $sender['name'],
            'is_organizer' => 1,
            'starts_at' => self::sql($start),
            'ends_at' => self::sql($end),
            'all_day' => $allDay ? 1 : 0,
            'timezone' => date_default_timezone_get(),
            'free_busy' => $freeBusy,
            'status' => $status,
            'sequence' => $sequence,
            'reminder_minutes' => $reminder,
            'my_response' => 'ORGANIZER',
            'source' => 'local',
        ];
        $removed = array_values(array_diff($previous, array_map(static fn (array $row): string => $row['email'], $attendees)));
        $id = $this->transaction(function () use ($uid, $id, $existing, $fields, $attendees, $sender, $invited, $removed, $sequence, $eventUid, $now): int {
            if ($existing === null) {
                $fields['attendee_count'] = count($attendees);
                $id = $this->repository->create($uid, $fields, self::sql($now));
            } else {
                $this->repository->update($uid, $id, $fields, self::sql($now));
            }
            $this->repository->replaceAttendees($id, $attendees, self::sql($now));
            if ($invited) {
                $this->enqueueEvent($uid, $id, $sender, 'REQUEST', array_map(static fn (array $row): string => $row['email'], $attendees), $sequence);
            }
            foreach ($removed as $address) {
                // Ausgeladene Teilnehmer erhalten eine Absage zu dieser Sequenz.
                $this->enqueueEvent($uid, $id, $sender, 'CANCEL', [$address], $sequence, substr(sha1($address), 0, 12));
            }

            return $id;
        });
        $this->dispatch($uid);

        return [
            'id' => (string) $id,
            // Antwortstatus fuer die sofortige Aktualisierung der Anzeige: der
            // Organisator ist nicht Teilnehmer und antwortet nicht.
            'my_response' => 'Organizer',
            'message' => $invited
                ? ($existing === null ? 'Der Termin wurde erstellt und die Einladungen versendet.' : 'Der Termin wurde gespeichert und die Teilnehmer informiert.')
                : ($existing === null ? 'Der Termin wurde erstellt.' : 'Der Termin wurde gespeichert.'),
        ];
    }

    /**
     * Verschiebt einen Termin (Drag and Drop). Teilnehmer werden ueber die
     * neue Zeit informiert.
     *
     * @param array<string,mixed> $input
     * @return array{id:string,start:int,end:int,message:string}
     */
    public function move(string $uid, array $input): array
    {
        $id = (int) $input['id'];
        $row = $this->requireEvent($uid, (string) $id);
        if ((int) $row['is_organizer'] !== 1) {
            throw new OrvantaException('Nur der Organisator kann diesen Termin verschieben.', 409);
        }
        $allDay = (int) $row['all_day'] === 1;
        [$start, $end] = $this->times((int) $input['start'], (int) $input['end'], $allDay);
        $attendees = $this->repository->attendeesFor([$id])[$id] ?? [];
        $sequence = (int) $row['sequence'] + ($attendees === [] ? 0 : 1);
        $sender = ['email' => (string) $row['organizer_email'], 'name' => (string) $row['organizer_name']];
        $this->transaction(function () use ($uid, $id, $start, $end, $sequence, $attendees, $sender): void {
            $this->repository->update($uid, $id, [
                'starts_at' => self::sql($start),
                'ends_at' => self::sql($end),
                'sequence' => $sequence,
            ], self::sql(time()));
            if ($attendees !== []) {
                $this->enqueueEvent($uid, $id, $sender, 'REQUEST', array_map(static fn (array $row): string => $row['email'], $attendees), $sequence);
            }
        });
        $this->dispatch($uid);

        return [
            'id' => (string) $id,
            'start' => $start,
            'end' => $end,
            'message' => $attendees === [] ? 'Der Termin wurde verschoben.' : 'Der Termin wurde verschoben und die Teilnehmer informiert.',
        ];
    }

    /**
     * Loescht einen Termin. Der Organisator sagt damit allen Teilnehmern ab,
     * ein Teilnehmer zieht seine Zusage zurueck (Absage an den Organisator).
     *
     * @param string $email Postfachadresse des Benutzers
     * @return array{id:string,message:string}
     */
    public function remove(string $uid, string $email, string $id): array
    {
        $row = $this->requireEvent($uid, $id);
        $eventId = (int) $row['id'];
        $attendees = $this->repository->attendeesFor([$eventId])[$eventId] ?? [];
        $organizer = (int) $row['is_organizer'] === 1;
        $cancel = $organizer && $attendees !== [];
        $decline = !$organizer
            && (string) $row['my_response'] !== 'DECLINED'
            && (string) $row['status'] !== 'CANCELLED'
            && trim((string) $row['organizer_email']) !== '';
        $this->transaction(function () use ($uid, $email, $row, $eventId, $attendees, $organizer, $cancel, $decline): void {
            $sender = $organizer
                ? ['email' => (string) $row['organizer_email'], 'name' => (string) $row['organizer_name']]
                : ['email' => $email, 'name' => ''];
            if ($cancel) {
                $this->enqueueEvent($uid, $eventId, $sender, 'CANCEL', array_map(static fn (array $row): string => (string) $row['email'], $attendees), (int) $row['sequence'] + 1);
            } elseif ($decline) {
                $this->enqueueEvent($uid, $eventId, $sender, 'REPLY', [(string) $row['organizer_email']], (int) $row['sequence'], 'decline', ['partstat' => 'DECLINED']);
            }
            $this->repository->delete($uid, $eventId);
        });
        $this->dispatch($uid);

        return [
            'id' => (string) $eventId,
            'message' => $cancel ? 'Der Termin wurde abgesagt und die Teilnehmer informiert.' : 'Der Termin wurde gelöscht.',
        ];
    }

    /**
     * Antwort eines Teilnehmers (Zusagen, mit Vorbehalt, Ablehnen). Der
     * Organisator erhaelt die Antwort als iCalendar-Nachricht (METHOD:REPLY).
     *
     * @return array{id:string,my_response:string,message:string}
     */
    public function respond(string $uid, string $email, string $id, string $response): array
    {
        $partstat = self::RESPONSE_PARTSTAT[strtolower($response)] ?? null;
        if ($partstat === null) {
            throw new OrvantaException('Unbekannte Antwort.', 422);
        }
        $row = $this->requireEvent($uid, $id);
        if ((int) $row['is_organizer'] === 1) {
            throw new OrvantaException('Sie sind der Organisator dieses Termins.', 409);
        }
        if ((string) $row['status'] === 'CANCELLED') {
            throw new OrvantaException('Der Termin wurde abgesagt.', 409);
        }
        $eventId = (int) $row['id'];
        // Die Teilnehmerliste speichert Adressen in Kleinschreibung.
        $email = strtolower(trim($email));
        $sender = ['email' => $email, 'name' => ''];
        foreach ($this->repository->attendeesFor([$eventId])[$eventId] ?? [] as $attendee) {
            if (strcasecmp((string) $attendee['email'], $email) === 0) {
                $sender['name'] = (string) $attendee['display_name'];
                break;
            }
        }
        $organizer = trim((string) $row['organizer_email']);
        $this->transaction(function () use ($uid, $eventId, $email, $partstat, $sender, $organizer, $row): void {
            $this->repository->setMyResponse($uid, $eventId, $partstat, self::sql(time()));
            if ($email !== '') {
                $this->repository->setAttendeeResponse($uid, $eventId, $email, $partstat, self::sql(time()));
            }
            if ($organizer !== '') {
                $this->enqueueEvent($uid, $eventId, $sender, 'REPLY', [$organizer], (int) $row['sequence'], strtolower($partstat), ['partstat' => $partstat]);
            }
        });
        $this->dispatch($uid);

        return [
            'id' => (string) $eventId,
            'my_response' => self::response($partstat),
            'message' => 'Die Antwort wurde gesendet.',
        ];
    }

    /**
     * Antwort auf eine eingegangene Terminnachricht (Antwortleiste in der
     * Nachrichtenansicht). Der Termin wird ueber die iCalendar-Kennung der
     * Nachricht zugeordnet – niemals ueber den Betreff.
     *
     * @param list<string> $calendarParts iCalendar-Texte aus der Nachricht
     * @return array{id:string,my_response:string,message:string}
     */
    public function respondToMessage(string $uid, string $email, string $messageId, array $calendarParts, string $response): array
    {
        $eventUid = '';
        foreach (array_slice($calendarParts, 0, 3) as $raw) {
            $invitation = ICalendar::parseInvitation((string) $raw);
            if ($invitation !== null) {
                $eventUid = (string) $invitation['uid'];
                break;
            }
        }
        if ($eventUid === '') {
            throw new OrvantaException('Diese Nachricht enthält keine Termineinladung.', 422);
        }
        // Die Einladung zuerst uebernehmen: Auch eine noch nicht geoeffnete
        // Nachricht laesst sich damit beantworten. Mehrfaches Oeffnen aendert
        // nichts (Fingerabdruck in orvanta_calendar_processed).
        $this->ingest($uid, $messageId, $calendarParts);
        $row = $this->repository->findByUid($uid, $eventUid);
        if ($row === null) {
            throw new OrvantaException('Der Termin wurde nicht gefunden.', 404);
        }

        return $this->respond($uid, $email, (string) $row['id'], $response);
    }

    /**
     * Anstehende Erinnerungen fuer die Erinnerungsliste. Die Kennung ist die
     * Terminkennung, damit "Oeffnen" den Termin laden kann.
     *
     * @return list<array<string,mixed>>
     */
    public function upcomingReminders(string $uid, int $from, int $hours): array
    {
        $items = [];
        $until = $from + max(1, $hours) * 3600;
        foreach ($this->repository->reminderWindow($uid, self::sql($from), self::sql($until)) as $row) {
            $start = (int) strtotime((string) $row['starts_at']);
            $minutes = (int) $row['reminder_minutes'];
            $items[] = [
                'id' => (string) $row['id'],
                'subject' => (string) $row['subject'],
                'location' => (string) $row['location'],
                'start' => $start,
                'end' => (int) strtotime((string) $row['ends_at']),
                'reminder_set' => $minutes >= 0,
                'remind_at' => $minutes >= 0 ? $start - $minutes * 60 : 0,
                'is_cancelled' => (string) $row['status'] === 'CANCELLED',
                'my_response' => self::response((string) $row['my_response']),
            ];
        }

        return $items;
    }

    /**
     * Uebernimmt eine eingehende Terminnachricht in den lokalen Kalender.
     * Mehrfaches Oeffnen derselben Nachricht aendert nichts: Kennung und
     * Sequenz bilden einen Fingerabdruck in orvanta_calendar_processed.
     *
     * @param list<string> $parts iCalendar-Texte aus den Kalenderteilen
     * @return array<string,mixed>|null Termin, oder null ohne uebernehmbaren Inhalt
     */
    public function ingest(string $uid, string $messageId, array $parts): ?array
    {
        foreach (array_slice($parts, 0, 3) as $raw) {
            $invitation = ICalendar::parseInvitation((string) $raw);
            if ($invitation === null) {
                continue;
            }
            $result = $this->apply($uid, $messageId, $invitation);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Zustellung offener Versandauftraege. Bricht nie ab: Ein fehlgeschlagener
     * Auftrag bleibt mit Fehlertext und naechstem Versuch stehen.
     *
     * @return int Anzahl der zugestellten Auftraege
     */
    public function dispatch(string $uid): int
    {
        $this->repository->recoverOutbox(self::sql(time() - self::CLAIM_TIMEOUT), self::sql(time()));
        $sent = 0;
        foreach ($this->repository->dueOutbox($uid, self::sql(time()), self::MAX_ATTEMPTS, self::MAX_DISPATCH) as $row) {
            $attempts = (int) $row['attempts'];
            if (!$this->repository->claimOutbox((int) $row['id'], $attempts, self::sql(time()))) {
                continue;
            }
            try {
                $this->deliver($row);
                $this->repository->finishOutbox((int) $row['id'], 'sent', null, null, self::sql(time()), self::sql(time()));
                $sent++;
            } catch (Throwable $exception) {
                $attempts++;
                $final = $attempts >= self::MAX_ATTEMPTS;
                $this->repository->finishOutbox(
                    (int) $row['id'],
                    'failed',
                    mb_substr($exception->getMessage(), 0, 500),
                    // Endgueltig gescheiterte Auftraege werden nicht wieder
                    // faellig; die Obergrenze der Versuche greift ohnehin.
                    $final ? self::sql(time() + 31536000) : self::sql(time() + min(3600, 60 * (2 ** $attempts))),
                    null,
                    self::sql(time())
                );
                $this->logger?->warning('Orvanta: Kalendereinladung konnte nicht versendet werden.', [
                    'outbox' => (int) $row['id'],
                    'kind' => (string) $row['kind'],
                    'attempts' => $attempts,
                    'final' => $final,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * @param array<string,mixed> $invitation normalisierte Einladung
     * @return array<string,mixed>|null
     */
    private function apply(string $uid, string $messageId, array $invitation): ?array
    {
        $method = (string) $invitation['method'];
        $eventUid = (string) $invitation['uid'];
        $sequence = max(0, (int) $invitation['sequence']);
        $hash = sha1(implode("\n", [
            $method,
            $eventUid,
            (string) $sequence,
            (string) $invitation['partstat'],
            (string) $invitation['organizer']['email'],
            (string) $invitation['start'],
            (string) $invitation['end'],
            (string) $invitation['status'],
            (string) count($invitation['attendees']),
        ]));
        $now = time();
        $row = $this->repository->findByUid($uid, $eventUid);
        if ($row !== null && $method !== 'REPLY' && $sequence < (int) $row['sequence']) {
            // Veraltete Fassung (etwa eine spaet zugestellte alte Einladung).
            return $this->event($uid, (string) $row['id']);
        }
        if ($row !== null && $method === 'REPLY' && $sequence < (int) $row['sequence'] - 1) {
            return $this->event($uid, (string) $row['id']);
        }
        $this->transaction(function () use ($uid, $messageId, $invitation, $method, $eventUid, $sequence, $hash, $row, $now): void {
            $item = [
                'event_uid' => $eventUid,
                'method' => $method,
                'sequence' => $sequence,
                'event_id' => $row === null ? null : (int) $row['id'],
            ];
            if (!$this->repository->markProcessed($uid, $hash, $item, self::sql($now))) {
                return;
            }
            if ($method === 'CANCEL') {
                if ($row !== null) {
                    $this->repository->update($uid, (int) $row['id'], ['status' => 'CANCELLED', 'sequence' => $sequence], self::sql($now));
                }

                return;
            }
            if ($method === 'REPLY') {
                $this->applyReply($uid, $invitation, $row, $now);

                return;
            }
            $this->applyRequest($uid, $messageId, $invitation, $row, $sequence, $now);
        });
        $eventId = $row === null ? ($this->repository->findByUid($uid, $eventUid)['id'] ?? null) : $row['id'];
        if ($eventId === null) {
            return null;
        }

        return $this->event($uid, (string) $eventId);
    }

    /**
     * Einladung oder Aktualisierung des Organisators uebernehmen.
     *
     * @param array<string,mixed> $invitation
     * @param array<string,mixed>|null $row
     */
    private function applyRequest(string $uid, string $messageId, array $invitation, ?array $row, int $sequence, int $now): void
    {
        $organizer = $invitation['organizer'];
        $summary = (string) $invitation['summary'];
        $description = (string) $invitation['description'];
        $fields = [
            'subject' => $summary !== '' ? $summary : 'Termin',
            'description' => $description !== '' ? $description : null,
            'location' => (string) $invitation['location'],
            'organizer_email' => (string) $organizer['email'],
            'organizer_name' => (string) $organizer['name'],
            'starts_at' => self::sql((int) $invitation['start']),
            'ends_at' => self::sql((int) $invitation['end']),
            'all_day' => (bool) $invitation['all_day'] ? 1 : 0,
            'timezone' => (string) $invitation['timezone'] !== '' ? (string) $invitation['timezone'] : date_default_timezone_get(),
            'free_busy' => (string) $invitation['free_busy'],
            'status' => (string) $invitation['status'] === 'CANCELLED' ? 'CANCELLED' : 'CONFIRMED',
            'sequence' => $sequence,
            'origin_message_id' => $messageId !== '' ? mb_substr($messageId, 0, 255) : null,
            'source' => 'invitation',
        ];
        if ($row === null) {
            $fields['event_uid'] = (string) $invitation['uid'];
            $fields['is_organizer'] = 0;
            // Eine eigene Antwort wird nicht ueberschrieben: Wer eine Einladung
            // bereits abgelehnt hat, behaelt diese Antwort auch nach einer
            // erneut zugestellten Fassung.
            $fields['my_response'] = (string) $invitation['partstat'] !== '' ? (string) $invitation['partstat'] : 'NEEDS-ACTION';
            $fields['attendee_count'] = count($invitation['attendees']);
            $id = $this->repository->create($uid, $fields, self::sql($now));
        } else {
            $id = (int) $row['id'];
            if ((int) $row['is_organizer'] === 1) {
                // Der Benutzer ist selbst Organisator: Der eigene Stand bleibt
                // erhalten, nur die Antworten der Teilnehmer werden gepflegt.
                $this->repository->replaceAttendees($id, $this->attendeeRows($invitation), self::sql($now));

                return;
            }
            $this->repository->update($uid, $id, $fields, self::sql($now));
        }
        $this->repository->replaceAttendees($id, $this->attendeeRows($invitation), self::sql($now));
    }

    /**
     * Antwort eines Teilnehmers auf einen eigenen Termin uebernehmen.
     *
     * @param array<string,mixed> $invitation
     * @param array<string,mixed>|null $row
     */
    private function applyReply(string $uid, array $invitation, ?array $row, int $now): void
    {
        if ($row === null || (int) $row['is_organizer'] !== 1) {
            // Ohne eigenen Termin ist eine fremde Antwort ohne Belang.
            return;
        }
        $partstat = (string) $invitation['partstat'];
        if ($partstat === '') {
            foreach ($invitation['attendees'] as $attendee) {
                if ((string) $attendee['response'] !== '') {
                    $partstat = (string) $attendee['response'];
                    break;
                }
            }
        }
        if ($partstat === '') {
            return;
        }
        $id = (int) $row['id'];
        foreach ($invitation['attendees'] as $attendee) {
            $this->repository->setAttendeeResponse($uid, $id, (string) $attendee['email'], $partstat, self::sql($now));
        }
    }

    /**
     * Teilnehmerzeilen aus einer Einladung.
     *
     * @param array<string,mixed> $invitation
     * @return list<array{email:string,name:string,role:string,response:string}>
     */
    private function attendeeRows(array $invitation): array
    {
        $rows = [];
        foreach ($invitation['attendees'] as $attendee) {
            $email = strtolower(trim((string) $attendee['email']));
            if ($email === '' || isset($rows[$email])) {
                continue;
            }
            $rows[$email] = [
                'email' => $email,
                'name' => (string) $attendee['name'],
                'role' => (string) $attendee['role'],
                'response' => (string) $attendee['response'] !== '' ? (string) $attendee['response'] : 'NEEDS-ACTION',
            ];
        }

        return array_values($rows);
    }

    /**
     * Stellt einen Versandauftrag zu (nur ueber den SMTP-/IMAP-Proxy).
     *
     * @param array<string,mixed> $row
     */
    private function deliver(array $row): void
    {
        $recipients = json_decode((string) $row['recipients'], true);
        $payload = json_decode((string) $row['payload'], true);
        $from = (string) $row['from_email'];
        if (!is_array($recipients) || $recipients === [] || !is_array($payload) || $from === '') {
            throw new OrvantaException('Der Versandauftrag ist unvollständig.', 500);
        }
        $ics = (string) ($payload['ics'] ?? '');
        if ($ics === '' || strlen($ics) > ICalendar::MAX_BYTES) {
            throw new OrvantaException('Der Kalenderteil des Versandauftrags ist unbrauchbar.', 500);
        }
        $backend = ($this->backendFor)((string) $row['user_uid'], $from);
        if ($backend->backendName() !== 'proxy') {
            // Ohne Proxy gibt es keinen Weg fuer lokale Termine; nichts wird
            // stillschweigend ueber Exchange versendet.
            throw new OrvantaException('Das Postfach wird nicht mehr über den Mail-Proxy geführt.', 409);
        }
        $method = strtoupper((string) ($payload['method'] ?? 'REQUEST'));
        if (!in_array($method, ICalendar::METHODS, true)) {
            $method = 'REQUEST';
        }
        $backend->send($from, [
            'to' => array_values(array_map('strval', $recipients)),
            'subject' => (string) ($payload['subject'] ?? ''),
            'body' => (string) ($payload['body'] ?? ''),
            'html' => true,
            'importance' => 'Normal',
            // Der Kalenderteil geht als eigener MIME-Teil (text/calendar) mit,
            // damit Mailprogramme den Termin auswerten koennen.
            'calendar' => [['method' => $method, 'content' => $ics]],
        ]);
    }

    /**
     * Baut den iCalendar-Auftrag und reiht ihn ein. Die Idempotenzkennung
     * enthaelt Methode, Termin, Sequenz und Empfaengerbezug.
     *
     * @param array{email:string,name:string} $sender
     * @param list<string> $recipients
     * @param array<string,mixed> $overrides zusaetzliche oder ersetzende Felder
     */
    private function enqueueEvent(string $uid, int $eventId, array $sender, string $method, array $recipients, int $sequence, string $suffix = '', array $overrides = []): bool
    {
        $row = $this->repository->find($uid, $eventId);
        if ($row === null) {
            return false;
        }
        $recipients = $this->cleanAddresses($recipients);
        if ($recipients === []) {
            return false;
        }
        $attendees = $this->repository->attendeesFor([$eventId])[$eventId] ?? [];
        $event = array_merge([
            'uid' => (string) $row['event_uid'],
            'sequence' => $sequence,
            'summary' => (string) $row['subject'],
            'description' => (string) ($row['description'] ?? ''),
            'location' => (string) $row['location'],
            'organizer_email' => (string) $row['organizer_email'],
            'organizer_name' => (string) $row['organizer_name'],
            'starts_at' => (string) $row['starts_at'],
            'ends_at' => (string) $row['ends_at'],
            'all_day' => (int) $row['all_day'] === 1,
            'free_busy' => (string) $row['free_busy'],
            'status' => (string) $row['status'],
            'attendees' => $attendees,
        ], $overrides);
        if ($method === 'CANCEL') {
            $event['status'] = 'CANCELLED';
        }
        if ($method === 'REPLY') {
            // Eine Antwort nennt nur den antwortenden Teilnehmer (RFC 5546).
            $event['attendees'] = [[
                'email' => $sender['email'],
                'name' => $sender['name'],
                'role' => 'REQ-PARTICIPANT',
                'response' => (string) ($overrides['partstat'] ?? 'NEEDS-ACTION'),
            ]];
        }
        $payload = json_encode([
            'method' => $method,
            'ics' => ICalendar::build($event, $method),
            'subject' => $this->mailSubject($row, $method),
            'body' => $this->mailBody($row, $method, (string) ($overrides['partstat'] ?? '')),
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return false;
        }
        $key = strtolower($method) . ':' . sha1((string) $row['event_uid']) . ':' . $sequence . ($suffix !== '' ? ':' . $suffix : '');

        return $this->repository->enqueue(
            $uid,
            $eventId,
            $sender['email'],
            strtolower($method),
            $key,
            $recipients,
            $payload,
            self::sql(time())
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function mailSubject(array $row, string $method): string
    {
        $subject = trim((string) $row['subject']);
        $subject = $subject !== '' ? $subject : 'Termin';

        return match ($method) {
            'CANCEL' => 'Absage: ' . $subject,
            'REPLY' => 'Antwort: ' . $subject,
            default => 'Einladung: ' . $subject,
        };
    }

    /**
     * Begleittext der Kalendernachricht (der Termin selbst steckt im
     * Kalenderteil, damit Mailprogramme ihn auswerten koennen).
     *
     * @param array<string,mixed> $row
     */
    private function mailBody(array $row, string $method, string $partstat): string
    {
        $when = date('d.m.Y H:i', (int) strtotime((string) $row['starts_at'])) . ' bis ' . date('d.m.Y H:i', (int) strtotime((string) $row['ends_at']));
        $lines = [];
        if ($method === 'REPLY') {
            $answer = match ($partstat) {
                'ACCEPTED' => 'zugesagt',
                'TENTATIVE' => 'mit Vorbehalt geantwortet',
                'DECLINED' => 'abgesagt',
                default => 'geantwortet',
            };
            $lines[] = '<p>' . Html::e((string) ($row['organizer_name'] !== '' ? $row['organizer_name'] : (string) $row['organizer_email'])) . ' hat für den Termin „' . Html::e((string) $row['subject']) . '“ ' . $answer . '.</p>';
        } elseif ($method === 'CANCEL') {
            $lines[] = '<p>Der Termin „' . Html::e((string) $row['subject']) . '“ wurde abgesagt.</p>';
        } else {
            $lines[] = '<p>' . Html::e((string) ($row['organizer_name'] !== '' ? $row['organizer_name'] : (string) $row['organizer_email'])) . ' lädt zu einem Termin ein.</p>';
        }
        $lines[] = '<p><strong>' . Html::e((string) $row['subject']) . '</strong><br>' . Html::e($when);
        if (trim((string) $row['location']) !== '') {
            $lines[] = '<br>' . Html::e((string) $row['location']);
        }
        $lines[] = '</p>';
        $description = trim((string) ($row['description'] ?? ''));
        if ($description !== '') {
            $clean = MailHtmlSanitizer::clean($description);
            $lines[] = $clean['html'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $attendees
     * @return array<string,mixed>
     */
    private function summary(array $row, array $attendees): array
    {
        $start = (int) strtotime((string) $row['starts_at']);
        $end = (int) strtotime((string) $row['ends_at']);
        $minutes = (int) $row['reminder_minutes'];
        $isOrganizer = (int) $row['is_organizer'] === 1;

        return [
            'id' => (string) $row['id'],
            'subject' => (string) $row['subject'],
            'start' => $start,
            'end' => $end,
            'all_day' => (int) $row['all_day'] === 1,
            'location' => (string) $row['location'],
            'organizer' => [
                'name' => (string) $row['organizer_name'],
                'email' => (string) $row['organizer_email'],
            ],
            'free_busy' => (string) $row['free_busy'],
            'type' => 'Single',
            'reminder_set' => $minutes >= 0,
            'reminder_minutes' => max(0, $minutes),
            'is_meeting' => $isOrganizer ? $attendees !== [] : true,
            'is_cancelled' => (string) $row['status'] === 'CANCELLED',
            'my_response' => self::response((string) $row['my_response']),
            'categories' => [],
            'local' => true,
        ];
    }

    /**
     * Status des Einladungsversands fuer die Terminanzeige. Massgeblich ist der
     * zuletzt angelegte Auftrag; ein fehlgeschlagener Versand bleibt sichtbar,
     * damit der Benutzer nicht von einer zugestellten Einladung ausgeht.
     */
    private function inviteState(int $eventId): string
    {
        $rows = $this->repository->outboxState($eventId);
        if ($rows === []) {
            return '';
        }
        $newest = $rows[0];
        $status = (string) $newest['status'];
        $attempts = (int) $newest['attempts'];
        $failed = 0;
        foreach ($rows as $row) {
            if ((string) $row['status'] === 'failed' && (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
                $failed++;
            }
        }
        if ($failed > 0) {
            return 'Einladung konnte nicht gesendet werden';
        }

        return match ($status) {
            'sent' => 'Einladung gesendet',
            'sending' => 'Einladung wird gesendet',
            'failed' => 'Einladung wird erneut versucht',
            default => 'Einladung wartet auf Versand',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function requireEvent(string $uid, string $id): array
    {
        $row = ctype_digit($id) ? $this->repository->find($uid, (int) $id) : null;
        if ($row === null) {
            throw new OrvantaException('Der Termin wurde nicht gefunden.', 404);
        }

        return $row;
    }

    /**
     * @param list<mixed> $required
     * @param list<mixed> $optional
     * @return list<array{email:string,name:string,role:string,response:string}>
     */
    private function attendeeList(array $required, array $optional): array
    {
        $list = [];
        foreach ([['REQ-PARTICIPANT', $required], ['OPT-PARTICIPANT', $optional]] as [$role, $addresses]) {
            foreach ($addresses as $address) {
                $email = strtolower(trim((string) $address));
                if ($email === '') {
                    continue;
                }
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new OrvantaException('Ungültige Teilnehmeradresse: ' . $email, 422);
                }
                if (!isset($list[$email])) {
                    $list[$email] = ['email' => $email, 'name' => '', 'role' => $role, 'response' => 'NEEDS-ACTION'];
                }
            }
        }
        if (count($list) > self::MAX_ATTENDEES) {
            throw new OrvantaException('Zu viele Teilnehmer (höchstens ' . self::MAX_ATTENDEES . ').', 422);
        }

        return array_values($list);
    }

    /**
     * @param list<string> $addresses
     * @return list<string>
     */
    private function cleanAddresses(array $addresses): array
    {
        $list = [];
        foreach ($addresses as $address) {
            $email = strtolower(trim((string) $address));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || in_array($email, $list, true)) {
                continue;
            }
            $list[] = $email;
        }

        return array_slice($list, 0, self::MAX_ATTENDEES);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function times(int $start, int $end, bool $allDay): array
    {
        if ($start <= 0) {
            throw new OrvantaException('Bitte einen Beginn angeben.', 422);
        }
        if ($allDay) {
            $start = (int) strtotime(date('Y-m-d 00:00:00', $start));
            $end = $end > $start ? (int) strtotime(date('Y-m-d 00:00:00', $end)) : $start + 86400;
            if ($end <= $start) {
                $end = $start + 86400;
            }

            return [$start, $end];
        }
        if ($end <= $start) {
            throw new OrvantaException('Das Ende muss nach dem Beginn liegen.', 422);
        }
        if ($end - $start > self::MAX_LENGTH) {
            throw new OrvantaException('Der Termin ist zu lang.', 422);
        }

        return [$start, $end];
    }

    /**
     * Antwortwert der Oberflaeche zu einem Teilnehmerstatus (PARTSTAT).
     */
    private static function response(string $partstat): string
    {
        $partstat = strtoupper($partstat);

        return self::PARTSTAT_RESPONSE[$partstat] ?? ($partstat === 'ORGANIZER' ? 'Organizer' : 'NoResponseReceived');
    }

    private static function sql(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Fuehrt Aenderungen an Termin, Teilnehmern und Versandauftraegen in einer
     * Transaktion aus: Entweder entsteht alles oder nichts.
     *
     * @template T
     * @param Closure():T $action
     * @return T
     */
    private function transaction(Closure $action): mixed
    {
        $pdo = $this->repository->pdo();
        $pdo->beginTransaction();
        try {
            $result = $action();
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $result;
    }
}
