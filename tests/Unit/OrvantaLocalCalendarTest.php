<?php

declare(strict_types=1);

use App\Contracts\OrvantaMailBackendInterface;
use App\Repositories\OrvantaCalendarRepository;
use App\Repositories\OrvantaRepository;
use App\Services\Orvanta\DemoExchangeTransport;
use App\Services\Orvanta\ICalendar;
use App\Services\Orvanta\OrvantaCalendarService;
use App\Services\Orvanta\OrvantaConfigService;
use App\Services\Orvanta\OrvantaException;
use App\Services\Orvanta\OrvantaExchangeService;
use App\Services\Orvanta\OrvantaNotificationService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Lokaler Kalender fuer Orvanta-Postfaecher am SMTP-/IMAP-Proxy.
 *
 * Spiegelt database/migrations/057_orvanta_local_calendar.sql (manuell, SQLite)
 * und prueft den iCalendar-Aufbau (RFC 5545), Einladungen, Antworten, Absagen,
 * den idempotenten Versandauftrag und die Erinnerungen. Der Exchange-Weg wird
 * nicht beruehrt.
 */

/**
 * Mail-Backend ohne Netz: merkt sich die Versandauftraege und kann auf Wunsch
 * jeden Versand scheitern lassen.
 */
final class FakeLocalCalendarBackend implements OrvantaMailBackendInterface
{
    /** @var list<array{user:string,mail:array<string,mixed>}> */
    public array $sent = [];

    /** Fehlertext, der jeden Versand scheitern laesst (null = Versand klappt). */
    public ?string $failure = null;

    public function __construct(private readonly string $name = 'proxy')
    {
    }

    public function backendName(): string
    {
        return $this->name;
    }

    /** @return array<string,bool> */
    public function capabilities(): array
    {
        return [self::CAPABILITY_MAIL => true];
    }

    /** @return list<array<string,mixed>> */
    public function folders(string $user): array
    {
        return [];
    }

    /** @return array{id:string,name:string} */
    public function createFolder(string $user, string $parent, string $name): array
    {
        return ['id' => '', 'name' => ''];
    }

    public function markFolderRead(string $user, string $folder): void
    {
    }

    /** @return array<string,mixed> */
    public function folderProperties(string $user, string $folder): array
    {
        return [];
    }

    /** @return array{items:list<array<string,mixed>>,total:int,offset:int,has_more:bool} */
    public function messages(string $user, string $folder, int $offset = 0, int $limit = 50, string $search = ''): array
    {
        return ['items' => [], 'total' => 0, 'offset' => 0, 'has_more' => false];
    }

    /** @return array<string,mixed> */
    public function message(string $user, string $id): array
    {
        return [];
    }

    /** @return array{id:string,subject:string,headers:string,source:string} */
    public function messageHeaders(string $user, string $id): array
    {
        return ['id' => $id, 'subject' => '', 'headers' => '', 'source' => ''];
    }

    /** @param array<string,mixed> $mail */
    public function send(string $user, array $mail, string $draftId = '', string $changeKey = ''): array
    {
        if ($this->failure !== null) {
            throw new OrvantaException($this->failure, 503);
        }
        $this->sent[] = ['user' => $user, 'mail' => $mail];

        return ['id' => 'mpx.gesendet.' . count($this->sent)];
    }

    /** @param array<string,mixed> $mail */
    public function saveDraft(string $user, array $mail, string $draftId = '', string $changeKey = ''): array
    {
        return ['id' => '', 'change_key' => ''];
    }

    /** @param array<string,mixed> $mail */
    public function respond(string $user, string $id, string $mode, array $mail): array
    {
        return ['id' => $id];
    }

    /** @param list<string> $ids */
    public function markRead(string $user, array $ids, bool $read): void
    {
    }

    /** @param list<string> $ids */
    public function flag(string $user, array $ids, bool $flagged): void
    {
    }

    /** @param list<string> $ids */
    public function move(string $user, array $ids, string $folder): void
    {
    }

    /** @param list<string> $ids */
    public function delete(string $user, array $ids, bool $permanent = false): void
    {
    }

    /** @return array{name:string,content_type:string,content:string,size:int} */
    public function attachment(string $user, string $attachmentId): array
    {
        return ['name' => '', 'content_type' => '', 'content' => '', 'size' => 0];
    }

    /** @param array{warning:int,send:int,receive:int}|null $directory */
    public function mailboxUsage(string $user, ?array $directory = null): array
    {
        return [];
    }
}

/**
 * Orvanta-Datenbank plus die vier Tabellen des lokalen Kalenders
 * (Spiegel von database/migrations/057_orvanta_local_calendar.sql).
 */
function localCalendarPdo(): PDO
{
    $pdo = orvantaPdo();
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE orvanta_calendar_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_uid VARCHAR(100) NOT NULL,
        event_uid VARCHAR(191) NOT NULL,
        subject VARCHAR(255) NOT NULL DEFAULT \'\',
        description TEXT NULL,
        location VARCHAR(255) NOT NULL DEFAULT \'\',
        organizer_email VARCHAR(190) NOT NULL DEFAULT \'\',
        organizer_name VARCHAR(190) NOT NULL DEFAULT \'\',
        is_organizer INTEGER NOT NULL DEFAULT 0,
        starts_at DATETIME NOT NULL,
        ends_at DATETIME NOT NULL,
        all_day INTEGER NOT NULL DEFAULT 0,
        timezone VARCHAR(64) NOT NULL DEFAULT \'\',
        free_busy VARCHAR(20) NOT NULL DEFAULT \'Busy\',
        status VARCHAR(20) NOT NULL DEFAULT \'CONFIRMED\',
        sequence INTEGER NOT NULL DEFAULT 0,
        reminder_minutes INTEGER NOT NULL DEFAULT -1,
        my_response VARCHAR(20) NOT NULL DEFAULT \'\',
        attendee_count INTEGER NOT NULL DEFAULT 0,
        source VARCHAR(20) NOT NULL DEFAULT \'local\',
        origin_message_id VARCHAR(255) NOT NULL DEFAULT \'\',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (user_uid, event_uid)
    )');
    $pdo->exec('CREATE TABLE orvanta_calendar_attendees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        email VARCHAR(190) NOT NULL,
        display_name VARCHAR(190) NOT NULL DEFAULT \'\',
        role VARCHAR(20) NOT NULL DEFAULT \'REQ-PARTICIPANT\',
        response VARCHAR(20) NOT NULL DEFAULT \'NEEDS-ACTION\',
        responded_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (event_id, email),
        FOREIGN KEY (event_id) REFERENCES orvanta_calendar_events (id) ON DELETE CASCADE
    )');
    $pdo->exec('CREATE TABLE orvanta_calendar_outbox (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_uid VARCHAR(100) NOT NULL,
        event_id INTEGER NULL,
        kind VARCHAR(20) NOT NULL DEFAULT \'invite\',
        idempotency_key VARCHAR(191) NOT NULL,
        from_email VARCHAR(190) NOT NULL DEFAULT \'\',
        recipients TEXT NULL,
        payload TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT \'pending\',
        attempts INTEGER NOT NULL DEFAULT 0,
        last_error VARCHAR(500) NOT NULL DEFAULT \'\',
        next_attempt_at DATETIME NOT NULL,
        sent_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (idempotency_key),
        FOREIGN KEY (event_id) REFERENCES orvanta_calendar_events (id) ON DELETE SET NULL
    )');
    $pdo->exec('CREATE TABLE orvanta_calendar_processed (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_uid VARCHAR(100) NOT NULL,
        item_hash CHAR(40) NOT NULL,
        event_uid VARCHAR(191) NOT NULL DEFAULT \'\',
        method VARCHAR(20) NOT NULL DEFAULT \'\',
        sequence INTEGER NOT NULL DEFAULT 0,
        event_id INTEGER NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (user_uid, item_hash),
        FOREIGN KEY (event_id) REFERENCES orvanta_calendar_events (id) ON DELETE SET NULL
    )');

    return $pdo;
}

/**
 * Kalenderumgebung: eine Datenbank fuer Termine, Versandauftraege und
 * Erinnerungen, dazu ein aufzeichnendes Mail-Backend.
 *
 * @return array{service:OrvantaCalendarService,notifications:OrvantaNotificationService,repository:OrvantaCalendarRepository,backend:FakeLocalCalendarBackend,pdo:PDO,config:OrvantaConfigService}
 */
function localCalendarEnv(string $backendName = 'proxy', array $settings = []): array
{
    $pdo = localCalendarPdo();
    $repository = new OrvantaRepository($pdo);
    $repository->saveSettings($settings + ['exchange_enabled' => '1', 'exchange_host' => 'demo']);
    $config = new OrvantaConfigService($repository, orvantaSecrets());
    $calendarRepository = new OrvantaCalendarRepository($pdo);
    $backend = new FakeLocalCalendarBackend($backendName);
    $service = new OrvantaCalendarService(
        $calendarRepository,
        static fn (string $uid, string $email): OrvantaMailBackendInterface => $backend
    );
    $notifications = new OrvantaNotificationService(
        $repository,
        new OrvantaExchangeService(new DemoExchangeTransport(), $config),
        $config
    );

    return [
        'service' => $service,
        'notifications' => $notifications,
        'repository' => $calendarRepository,
        'backend' => $backend,
        'pdo' => $pdo,
        'config' => $config,
    ];
}

/** Beginn eines Termins: morgen zur vollen Stunde (immer in der Zukunft). */
function localCalendarStart(int $days = 1): int
{
    return (int) strtotime(date('Y-m-d H:00:00', time() + $days * 86400));
}

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function localCalendarInput(array $overrides = []): array
{
    $start = localCalendarStart();

    return $overrides + [
        'id' => 0,
        'subject' => 'Jour fixe',
        'location' => 'Raum 3',
        'body' => '',
        'start' => $start,
        'end' => $start + 3600,
        'all_day' => false,
        'reminder' => -1,
        'free_busy' => 'Busy',
        'required' => [],
        'optional' => [],
    ];
}

/**
 * @return array{email:string,name:string}
 */
function localCalendarSender(string $email = 'jan@hh.example', string $name = 'Jan Müller'): array
{
    return ['email' => $email, 'name' => $name];
}

/**
 * @return list<array<string,mixed>>
 */
function localCalendarOutbox(PDO $pdo, string $uid = ''): array
{
    $sql = 'SELECT * FROM orvanta_calendar_outbox';
    $params = [];
    if ($uid !== '') {
        $sql .= ' WHERE user_uid = :uid';
        $params['uid'] = $uid;
    }
    $statement = $pdo->prepare($sql . ' ORDER BY id ASC');
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** iCalendar-Text eines Versandauftrags. */
function localCalendarIcs(array $row): string
{
    $payload = json_decode((string) $row['payload'], true);

    return is_array($payload) ? (string) ($payload['ics'] ?? '') : '';
}

/** Statuscode einer erwarteten Orvanta-Ausnahme. */
function localCalendarStatus(callable $fn): int
{
    try {
        $fn();
    } catch (OrvantaException $exception) {
        return $exception->status();
    }

    return 0;
}

/** Fehlertext einer erwarteten Orvanta-Ausnahme. */
function localCalendarError(callable $fn): string
{
    try {
        $fn();
    } catch (OrvantaException $exception) {
        return $exception->getMessage();
    }

    return '';
}

/**
 * Eine Termineinladung des Organisators an einen Teilnehmer.
 *
 * @return array{ics:string,uid:string}
 */
function localCalendarInvitation(array $env, string $attendee = 'eva@hh.example'): array
{
    $env['service']->save('jan', localCalendarSender(), localCalendarInput([
        'subject' => 'Projektrunde',
        'location' => 'Besprechungsraum 2',
        'body' => '<p>Bitte vorbereiten.</p>',
        'reminder' => 15,
        'required' => [$attendee],
    ]));
    $row = localCalendarOutbox($env['pdo'])[0];
    $ics = localCalendarIcs($row);

    return ['ics' => $ics, 'uid' => (string) (ICalendar::parseInvitation($ics)['uid'] ?? '')];
}

Runner::test('Lokaler Kalender: iCalendar wird nach RFC 5545 geschrieben und gelesen', static function (): void {
    $summary = trim('Mittagessen – Café & "Team" ' . str_repeat('lang ', 20));
    $ics = ICalendar::build([
        'uid' => 'termin-1@orvanta.local',
        'sequence' => 3,
        'summary' => $summary,
        'description' => "Zeile 1\nZeile 2",
        'location' => 'Raum 1, Änderung',
        'organizer_email' => 'olga@example.org',
        'organizer_name' => 'Olga Öztürk',
        'starts_at' => '2026-06-01 10:00:00',
        'ends_at' => '2026-06-01 11:00:00',
        'free_busy' => 'Free',
        'attendees' => [['email' => 'anna@example.org', 'name' => 'Anna', 'role' => 'OPT-PARTICIPANT', 'response' => 'ACCEPTED']],
    ], 'REQUEST');

    Assert::true(str_starts_with($ics, 'BEGIN:VCALENDAR' . "\r\n"), 'Die Kalenderdatei beginnt mit VCALENDAR.');
    Assert::true(str_ends_with($ics, 'END:VCALENDAR' . "\r\n"), 'Die Kalenderdatei endet mit VCALENDAR und Zeilenende.');
    Assert::same(0, substr_count(str_replace("\r\n", '', $ics), "\n"), 'Es gibt nur CRLF-Zeilenenden.');
    Assert::contains('METHOD:REQUEST', $ics);
    Assert::contains('VERSION:2.0', $ics);
    Assert::contains('PRODID:-//Orvanta//Lokaler Kalender//DE', $ics);
    Assert::contains('UID:termin-1@orvanta.local', $ics);
    Assert::contains('SEQUENCE:3', $ics);
    Assert::contains('TRANSP:TRANSPARENT', $ics, 'Sichtbarkeit "Free" wird zu TRANSPARENT.');
    Assert::contains('LOCATION:Raum 1\, Änderung', $ics, 'Komma und Umlaut bleiben erhalten.');

    // Zeiten gehen als UTC auf die Leitung, damit Empfaenger denselben Zeitpunkt sehen.
    Assert::contains('DTSTART:' . gmdate('Ymd\THis\Z', (int) strtotime('2026-06-01 10:00:00')), $ics);
    Assert::contains('DTEND:' . gmdate('Ymd\THis\Z', (int) strtotime('2026-06-01 11:00:00')), $ics);

    // Zeilen sind auf 75 Oktette gefaltet und Fortsetzungen beginnen mit einem Leerzeichen.
    $folded = false;
    foreach (explode("\r\n", trim($ics, "\r\n")) as $line) {
        Assert::true(strlen($line) <= 75, 'Zeile zu lang: ' . $line);
        if (str_starts_with($line, ' ')) {
            $folded = true;
        }
    }
    Assert::true($folded, 'Der lange Betreff wurde gefaltet.');

    $event = ICalendar::parseInvitation($ics);
    Assert::true(is_array($event), 'Die eigene Kalenderdatei ist wieder lesbar.');
    Assert::same('termin-1@orvanta.local', $event['uid']);
    Assert::same(3, $event['sequence']);
    Assert::same('REQUEST', $event['method']);
    Assert::same($summary, $event['summary'], 'Gefaltete Zeilen werden korrekt zusammengesetzt.');
    Assert::same("Zeile 1\nZeile 2", $event['description']);
    Assert::same('Raum 1, Änderung', $event['location']);
    Assert::same('olga@example.org', $event['organizer']['email']);
    Assert::same('Olga Öztürk', $event['organizer']['name']);
    Assert::same((int) strtotime('2026-06-01 10:00:00'), $event['start']);
    Assert::same('Free', $event['free_busy']);
    Assert::same('CONFIRMED', $event['status']);
    Assert::false($event['all_day']);
    Assert::false($event['invalid']);
    Assert::same(1, count($event['attendees']));
    Assert::same('anna@example.org', $event['attendees'][0]['email']);
    Assert::same('OPT-PARTICIPANT', $event['attendees'][0]['role']);
    Assert::same('ACCEPTED', $event['attendees'][0]['response']);
});

Runner::test('Lokaler Kalender: unbrauchbare Kalenderinhalte werden abgewiesen', static function (): void {
    Assert::null(ICalendar::parseInvitation(''), 'Leerer Inhalt.');
    Assert::null(ICalendar::parseInvitation('<html>kein Kalender</html>'));
    Assert::null(ICalendar::parseInvitation(str_repeat('A', ICalendar::MAX_BYTES + 1)), 'Zu grosser Inhalt.');

    $header = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
    $footer = "BEGIN:VEVENT\r\nUID:x@y\r\nDTSTART:20260601T100000Z\r\nDTEND:20260601T110000Z\r\nORGANIZER:mailto:o@y\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    // Ein Anhang ohne Methode ist keine Einladung, sondern nur eine Datei.
    Assert::null(ICalendar::parseInvitation($header . $footer), 'Ohne METHOD keine Einladung.');
    Assert::null(ICalendar::parseInvitation($header . "METHOD:PUBLISH\r\n" . $footer), 'Unbekannte Methode.');

    // Zwei Termine in einer Datei sind nicht eindeutig zuordenbar.
    $two = str_replace('END:VCALENDAR', "BEGIN:VEVENT\r\nUID:z@y\r\nDTSTART:20260601T120000Z\r\nDTEND:20260601T130000Z\r\nEND:VEVENT\r\nEND:VCALENDAR", $header . "METHOD:REQUEST\r\n" . $footer);
    Assert::null(ICalendar::parseInvitation($two), 'Zwei Termine in einer Nachricht.');

    // Ohne Kennung oder ohne Organisator ist eine Einladung nicht zuzuordnen.
    Assert::null(ICalendar::parseInvitation($header . "METHOD:REQUEST\r\n" . str_replace('UID:x@y' . "\r\n", '', $footer)));
    Assert::null(ICalendar::parseInvitation($header . "METHOD:REQUEST\r\n" . str_replace('ORGANIZER:mailto:o@y' . "\r\n", '', $footer)));

    // Eine Antwort darf ohne Organisator eintreffen (der Empfaenger ist der Organisator).
    Assert::true(is_array(ICalendar::parseInvitation($header . "METHOD:REPLY\r\n" . str_replace('ORGANIZER:mailto:o@y' . "\r\n", '', $footer))));

    // Kaputte oder widerspruechliche Zeiten machen den Termin unbrauchbar.
    Assert::null(ICalendar::parseInvitation($header . "METHOD:REQUEST\r\n" . str_replace('DTSTART:20260601T100000Z', 'DTSTART:kaputt', $footer)));
    Assert::null(ICalendar::parseInvitation($header . "METHOD:REQUEST\r\n" . str_replace('DTEND:20260601T110000Z', 'DTEND:20260601T090000Z', $footer)));

    // HTML wird fuer die Beschreibung in Text umgewandelt.
    Assert::same("Hallo\nWelt", ICalendar::toText('<p>Hallo</p><p>Welt</p>'));
    Assert::same('- eins' . "\n" . '- zwei', ICalendar::toText('<ul><li>eins</li><li>zwei</li></ul>'));
});

Runner::test('Lokaler Kalender: Termin anlegen, lesen, verschieben und löschen', static function (): void {
    $env = localCalendarEnv();
    $service = $env['service'];
    $start = localCalendarStart();

    $created = $service->save('jan', localCalendarSender(), localCalendarInput([
        'subject' => 'Jour fixe',
        'location' => 'Raum 3',
        'body' => '<p>Agenda &amp; mehr</p>',
        'reminder' => 15,
        'required' => ['Eva@hh.example'],
        'optional' => ['max@hh.example'],
    ]));
    Assert::same('Organizer', $created['my_response'], 'Der Organisator ist kein Teilnehmer.');
    Assert::true(ctype_digit($created['id']));
    Assert::contains('erstellt', $created['message']);

    $event = $service->event('jan', $created['id']);
    Assert::same('Jour fixe', $event['subject']);
    Assert::same('Raum 3', $event['location']);
    Assert::same($start, $event['start']);
    Assert::same($start + 3600, $event['end']);
    Assert::false($event['all_day']);
    Assert::true($event['local'], 'Der Termin ist als lokaler Termin gekennzeichnet.');
    Assert::true($event['is_meeting']);
    Assert::same('Organizer', $event['my_response']);
    Assert::same('Single', $event['type']);
    Assert::true($event['reminder_set']);
    Assert::same(15, $event['reminder_minutes']);
    Assert::false($event['is_cancelled']);
    Assert::contains('Agenda', $event['body_html']);
    Assert::same(0, $event['blocked_images']);
    Assert::same([], $event['attachments']);
    Assert::false($event['recurring'], 'Serien sind nicht Teil des lokalen Kalenders.');
    Assert::same(1, count($event['required']));
    Assert::same('eva@hh.example', $event['required'][0]['email'], 'Adressen werden kleingeschrieben.');
    Assert::same('NoResponseReceived', $event['required'][0]['response']);
    Assert::same(1, count($event['optional']));
    Assert::same('max@hh.example', $event['optional'][0]['email']);
    Assert::same('Einladung gesendet', $event['invite_state']);

    // Die Einladung ging ueber den Proxy-SMTP-Weg als eigener Kalenderteil hinaus.
    Assert::same(1, count($env['backend']->sent));
    $mail = $env['backend']->sent[0]['mail'];
    Assert::same('jan@hh.example', $env['backend']->sent[0]['user']);
    Assert::same(['eva@hh.example', 'max@hh.example'], $mail['to']);
    Assert::same('Projektrunde' !== $mail['subject'], true, 'Der Betreff der Einladung ist gesetzt.');
    Assert::contains('Jour fixe', (string) $mail['subject']);
    Assert::same('REQUEST', $mail['calendar'][0]['method']);
    Assert::same('termin-1@orvanta.local' !== (string) ICalendar::parseInvitation((string) $mail['calendar'][0]['content'])['uid'], true);

    $rows = localCalendarOutbox($env['pdo']);
    Assert::same(1, count($rows));
    Assert::same('request', $rows[0]['kind']);
    Assert::same('sent', $rows[0]['status']);
    Assert::same(1, (int) $rows[0]['attempts']);
    Assert::same('jan@hh.example', $rows[0]['from_email'], 'Der Absender wird im Auftrag festgehalten.');
    Assert::same('["eva@hh.example","max@hh.example"]', $rows[0]['recipients']);
    Assert::true($rows[0]['sent_at'] !== null);

    // Bereichsabfrage liefert den Termin, auch wenn er den Rand ueberlappt.
    $items = $service->range('jan', $start - 86400, $start + 86400);
    Assert::same(1, count($items));
    Assert::same($created['id'], $items[0]['id']);
    Assert::same($start, $items[0]['start']);

    // Verschieben informiert die Teilnehmer und erhoeht die Sequenz.
    $moved = $service->move('jan', ['id' => $created['id'], 'start' => $start + 7200, 'end' => $start + 10800]);
    Assert::same($start + 7200, $moved['start']);
    Assert::same($start + 10800, $moved['end']);
    Assert::same(1, (int) $env['pdo']->query('SELECT sequence FROM orvanta_calendar_events WHERE id = ' . (int) $created['id'])->fetchColumn());
    Assert::same(2, count($env['backend']->sent));
    Assert::same(['eva@hh.example', 'max@hh.example'], $env['backend']->sent[1]['mail']['to']);
    $movedEvent = ICalendar::parseInvitation((string) $env['backend']->sent[1]['mail']['calendar'][0]['content']);
    Assert::same($start + 7200, $movedEvent['start']);
    Assert::same(1, $movedEvent['sequence']);

    // Loeschen mit Teilnehmern sagt ab.
    $removed = $service->remove('jan', 'jan@hh.example', $created['id']);
    Assert::contains('abgesagt', $removed['message']);
    Assert::same(3, count($env['backend']->sent));
    Assert::same('CANCEL', $env['backend']->sent[2]['mail']['calendar'][0]['method']);
    Assert::same(404, localCalendarStatus(static fn () => $service->event('jan', $created['id'])));
    Assert::same([], $service->range('jan', $start - 86400, $start + 86400));
    Assert::same(0, (int) $env['pdo']->query('SELECT COUNT(*) FROM orvanta_calendar_attendees')->fetchColumn(), 'Teilnehmer werden mitgelöscht.');
});

Runner::test('Lokaler Kalender: Eingaben werden geprüft', static function (): void {
    $env = localCalendarEnv();
    $service = $env['service'];
    $start = localCalendarStart();

    Assert::same(422, localCalendarStatus(static fn () => $service->save('jan', localCalendarSender(), localCalendarInput(['subject' => '   ']))));
    Assert::same(422, localCalendarStatus(static fn () => $service->save('jan', localCalendarSender(), localCalendarInput(['subject' => str_repeat('x', 256)]))));
    Assert::same(422, localCalendarStatus(static fn () => $service->save('jan', localCalendarSender(), localCalendarInput(['location' => str_repeat('x', 256)]))));
    Assert::same(422, localCalendarStatus(static fn () => $service->save('jan', localCalendarSender(), localCalendarInput(['body' => str_repeat('x', 60001)]))));
    Assert::same(422, localCalendarStatus(static fn () => $service->save('jan', localCalendarSender(), localCalendarInput(['reminder' => 10081]))));
    Assert::same(422, localCalendarStatus(static fn () => $service->save('jan', localCalendarSender(), localCalendarInput(['reminder' => -2]))));
    Assert::same(422, localCalendarStatus(static fn () => $service->save('jan', localCalendarSender(), localCalendarInput(['required' => ['keine-adresse']]))));
    Assert::same(422, localCalendarStatus(static fn () => $service->save('jan', localCalendarSender(), localCalendarInput(['start' => 0]))));
    Assert::same(422, localCalendarStatus(static fn () => $service->save('jan', localCalendarSender(), localCalendarInput(['start' => $start, 'end' => $start]))));
    Assert::same(422, localCalendarStatus(static fn () => $service->save('jan', localCalendarSender(), localCalendarInput(['start' => $start, 'end' => $start + 400 * 86400]))));

    // Unbekannte Sichtbarkeit faellt auf "Busy" zurueck, statt zu scheitern.
    $id = $service->save('jan', localCalendarSender(), localCalendarInput(['free_busy' => 'Unsinn']))['id'];
    Assert::same('Busy', $service->event('jan', $id)['free_busy']);

    // Ganztagstermine werden auf Tagesgrenzen gelegt.
    $allDay = $service->save('jan', localCalendarSender(), localCalendarInput([
        'subject' => 'Betriebsausflug',
        'start' => $start + 3600,
        'end' => $start + 7200,
        'all_day' => true,
    ]));
    $event = $service->event('jan', $allDay['id']);
    Assert::true($event['all_day']);
    Assert::same((int) strtotime(date('Y-m-d 00:00:00', $start)), $event['start']);
    Assert::same(86400, $event['end'] - $event['start']);

    // Ungueltige Zeitraeume der Bereichsabfrage.
    Assert::same(422, localCalendarStatus(static fn () => $service->range('jan', $start, $start)));
    Assert::same(422, localCalendarStatus(static fn () => $service->range('jan', $start, $start + 101 * 86400)));
    // Die beiden zuvor angelegten Termine liegen beide im Bereich.
    Assert::same(2, count($service->range('jan', $start - 86400, $start + 99 * 86400)));

    // Keine Einladung ohne Empfaenger: kein Versandauftrag.
    Assert::same([], localCalendarOutbox($env['pdo'], 'jan'));
});

Runner::test('Lokaler Kalender: Benutzer sehen und ändern nur eigene Termine', static function (): void {
    $env = localCalendarEnv();
    $service = $env['service'];
    $start = localCalendarStart();

    $id = $service->save('jan', localCalendarSender(), localCalendarInput(['subject' => 'Geheim']))['id'];

    Assert::same(404, localCalendarStatus(static fn () => $service->event('eva', $id)));
    Assert::same([], $service->range('eva', $start - 86400, $start + 86400));
    Assert::same(404, localCalendarStatus(static fn () => $service->save('eva', localCalendarSender('eva@hh.example', 'Eva'), localCalendarInput(['id' => (int) $id, 'subject' => 'Übernommen']))));
    Assert::same(404, localCalendarStatus(static fn () => $service->move('eva', ['id' => (int) $id, 'start' => $start + 3600, 'end' => $start + 7200])));
    Assert::same(404, localCalendarStatus(static fn () => $service->remove('eva', 'eva@hh.example', $id)));
    Assert::same(404, localCalendarStatus(static fn () => $service->respond('eva', 'eva@hh.example', $id, 'accept')));

    // Beschädigte Kennungen führen nicht zu Fremdzugriffen.
    Assert::same(404, localCalendarStatus(static fn () => $service->event('jan', 'abc')));
    Assert::same(404, localCalendarStatus(static fn () => $service->event('jan', '0')));

    Assert::same('Geheim', $service->event('jan', $id)['subject'], 'Der eigene Termin bleibt unverändert.');
    Assert::same(0, (int) $env['pdo']->query('SELECT COUNT(*) FROM orvanta_calendar_events WHERE user_uid = \'eva\'')->fetchColumn());
});

Runner::test('Lokaler Kalender: Einladung annehmen, ablehnen und doppelt beantworten', static function (): void {
    $env = localCalendarEnv();
    $service = $env['service'];
    $invitation = localCalendarInvitation($env, 'eva@hh.example');

    // Der Teilnehmer bekommt die Einladung als Nachricht.
    $received = $service->ingest('eva', 'mpx.1.eingang.7.42', [$invitation['ics']]);
    Assert::true(is_array($received), 'Die Einladung wurde übernommen.');
    Assert::same('Projektrunde', $received['subject']);
    Assert::same('Besprechungsraum 2', $received['location']);
    Assert::same('NoResponseReceived', $received['my_response']);
    Assert::true($received['is_meeting']);
    Assert::false($received['is_cancelled']);
    Assert::same('jan@hh.example', $received['organizer']['email']);
    Assert::same('Jan Müller', $received['organizer']['name']);
    Assert::same(1, count($received['required']));
    Assert::same('eva@hh.example', $received['required'][0]['email']);
    Assert::same('NoResponseReceived', $received['required'][0]['response']);
    $id = $received['id'];
    Assert::same(0, (int) $env['pdo']->query('SELECT is_organizer FROM orvanta_calendar_events WHERE id = ' . (int) $id)->fetchColumn());
    Assert::same('invitation', (string) $env['pdo']->query('SELECT source FROM orvanta_calendar_events WHERE id = ' . (int) $id)->fetchColumn());

    // Zweimal oeffnen aendert nichts (Idempotenz über den Fingerabdruck).
    $again = $service->ingest('eva', 'mpx.1.eingang.7.42', [$invitation['ics']]);
    Assert::same($id, $again['id']);
    Assert::same(1, (int) $env['pdo']->query('SELECT COUNT(*) FROM orvanta_calendar_events WHERE user_uid = \'eva\'')->fetchColumn());
    Assert::same(1, (int) $env['pdo']->query('SELECT COUNT(*) FROM orvanta_calendar_processed WHERE user_uid = \'eva\'')->fetchColumn());

    // Eine Aktualisierung mit neuer Sequenz pflegt den Termin nach.
    $updated = ICalendar::build([
        'uid' => $invitation['uid'],
        'sequence' => 1,
        'summary' => 'Projektrunde (verschoben)',
        'organizer_email' => 'jan@hh.example',
        'organizer_name' => 'Jan Müller',
        'starts_at' => date('Y-m-d H:i:s', localCalendarStart() + 7200),
        'ends_at' => date('Y-m-d H:i:s', localCalendarStart() + 10800),
        'attendees' => [['email' => 'eva@hh.example']],
    ], 'REQUEST');
    $changed = $service->ingest('eva', 'mpx.1.eingang.7.43', [$updated]);
    Assert::same($id, $changed['id'], 'Die Aktualisierung trifft denselben Termin.');
    Assert::same('Projektrunde (verschoben)', $changed['subject']);
    Assert::same(1, (int) $env['pdo']->query('SELECT sequence FROM orvanta_calendar_events WHERE id = ' . (int) $id)->fetchColumn());

    // Zusagen: der Organisator erhält eine Antwort als Kalendernachricht.
    $sentBefore = count($env['backend']->sent);
    $answer = $service->respond('eva', 'eva@hh.example', $id, 'accept');
    Assert::same('Accept', $answer['my_response']);
    Assert::same('Accept', $service->event('eva', $id)['my_response']);
    Assert::same('ACCEPTED', (string) $env['pdo']->query('SELECT response FROM orvanta_calendar_attendees WHERE event_id = ' . (int) $id)->fetchColumn());
    Assert::same($sentBefore + 1, count($env['backend']->sent));
    $reply = $env['backend']->sent[$sentBefore]['mail'];
    Assert::same(['jan@hh.example'], $reply['to']);
    Assert::same('REPLY', $reply['calendar'][0]['method']);
    $replyEvent = ICalendar::parseInvitation((string) $reply['calendar'][0]['content']);
    Assert::same('ACCEPTED', $replyEvent['partstat']);
    Assert::same(1, count($replyEvent['attendees']), 'Eine Antwort nennt nur den antwortenden Teilnehmer.');

    // Dieselbe Antwort erneut loest keinen zweiten Versand aus.
    $sentBefore = count($env['backend']->sent);
    $service->respond('eva', 'eva@hh.example', $id, 'accept');
    Assert::same($sentBefore, count($env['backend']->sent), 'Doppelte Antwort wird nicht erneut versendet.');
    Assert::same(1, count(localCalendarOutbox($env['pdo'], 'eva')));

    // Der Organisator übernimmt die Antwort in seinen Termin.
    $organizerEvent = $service->ingest('jan', 'mpx.2.gesendet.1.1', [(string) $reply['calendar'][0]['content']]);
    Assert::true(is_array($organizerEvent));
    Assert::same(1, count($organizerEvent['required']));
    Assert::same('Accept', $organizerEvent['required'][0]['response'], 'Der Organisator sieht die Zusage.');
    Assert::true($organizerEvent['invite_state'] !== '', 'Der Versandstand bleibt sichtbar.');

    // Eine bereits abgelehnte Einladung wird durch eine erneute Zustellung nicht zurückgesetzt.
    $service->respond('eva', 'eva@hh.example', $id, 'decline');
    Assert::same('Decline', $service->event('eva', $id)['my_response']);
    $service->ingest('eva', 'mpx.1.eingang.7.44', [$updated]);
    Assert::same('Decline', $service->event('eva', $id)['my_response'], 'Die eigene Antwort bleibt erhalten.');

    // Abgelehnte Besprechungen erscheinen nicht im Kalender.
    Assert::same([], $service->range('eva', localCalendarStart(0), localCalendarStart(3)));
    Assert::true(count($service->range('jan', localCalendarStart(0), localCalendarStart(3))) === 1, 'Beim Organisator bleibt der Termin.');

    // Nach einer Absage des Organisators ist keine Antwort mehr möglich.
    $service->remove('jan', 'jan@hh.example', (string) $env['pdo']->query('SELECT id FROM orvanta_calendar_events WHERE user_uid = \'jan\'')->fetchColumn());
    $cancel = '';
    foreach (localCalendarOutbox($env['pdo'], 'jan') as $row) {
        if ((string) $row['kind'] === 'cancel') {
            $cancel = localCalendarIcs($row);
        }
    }
    Assert::true($cancel !== '', 'Die Absage liegt als Versandauftrag vor.');
    $cancelled = $service->ingest('eva', 'mpx.1.eingang.7.45', [$cancel]);
    Assert::true($cancelled['is_cancelled']);
    Assert::same(409, localCalendarStatus(static fn () => $service->respond('eva', 'eva@hh.example', $id, 'accept')));
    Assert::same([], $service->range('eva', localCalendarStart(0), localCalendarStart(3)));
});

Runner::test('Lokaler Kalender: Antwort auf eine Nachricht wird über die Kennung zugeordnet', static function (): void {
    $env = localCalendarEnv();
    $service = $env['service'];
    $invitation = localCalendarInvitation($env, 'eva@hh.example');

    // Antwort direkt aus der Nachrichtenansicht, ohne den Termin vorher geöffnet zu haben.
    $result = $service->respondToMessage('eva', 'eva@hh.example', 'mpx.1.eingang.7.50', [$invitation['ics']], 'tentative');
    Assert::same('Tentative', $result['my_response']);
    $event = $service->event('eva', $result['id']);
    Assert::same('Projektrunde', $event['subject']);
    Assert::same('Tentative', $event['my_response']);

    // Zuordnung über die Kalenderkennung, nicht über den Betreff: eine zweite
    // Einladung mit gleichem Betreff darf nicht getroffen werden.
    $other = ICalendar::build([
        'uid' => 'anderer-termin@orvanta.local',
        'summary' => 'Projektrunde',
        'organizer_email' => 'klaus@hh.example',
        'starts_at' => date('Y-m-d H:i:s', localCalendarStart(2)),
        'ends_at' => date('Y-m-d H:i:s', localCalendarStart(2) + 3600),
        'attendees' => [['email' => 'eva@hh.example']],
    ], 'REQUEST');
    $second = $service->respondToMessage('eva', 'eva@hh.example', 'mpx.1.eingang.7.51', [$other], 'decline');
    Assert::same('Decline', $second['my_response']);
    Assert::true($second['id'] !== $result['id'], 'Der gleichnamige Termin ist ein anderer.');
    Assert::same('Tentative', $service->event('eva', $result['id'])['my_response'], 'Der erste Termin bleibt unberührt.');

    // Ohne Kalenderteil gibt es keine Antwortleiste.
    Assert::same(422, localCalendarStatus(static fn () => $service->respondToMessage('eva', 'eva@hh.example', 'mpx.1.eingang.7.52', ['<p>Kein Termin</p>'], 'accept')));
    Assert::same(422, localCalendarStatus(static fn () => $service->respondToMessage('eva', 'eva@hh.example', 'mpx.1.eingang.7.53', [], 'accept')));
    Assert::same(422, localCalendarStatus(static fn () => $service->respondToMessage('eva', 'eva@hh.example', 'mpx.1.eingang.7.54', [
        "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:ohne-methode@orvanta.local\r\n"
        . "DTSTART:20260601T100000Z\r\nDTEND:20260601T110000Z\r\nORGANIZER:mailto:jan@hh.example\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
    ], 'accept')), 'Ein Anhang ohne Methode ist keine Einladung.');

    // Eine Antwort zu einem unbekannten Termin findet nichts.
    $reply = ICalendar::build([
        'uid' => 'unbekannt@orvanta.local',
        'summary' => 'Nichts',
        'starts_at' => date('Y-m-d H:i:s', localCalendarStart(2)),
        'ends_at' => date('Y-m-d H:i:s', localCalendarStart(2) + 3600),
    ], 'REPLY');
    Assert::same(404, localCalendarStatus(static fn () => $service->respondToMessage('eva', 'eva@hh.example', 'mpx.1.eingang.7.55', [$reply], 'accept')));
});

Runner::test('Lokaler Kalender: Absage und Rückzug durch Teilnehmer', static function (): void {
    $env = localCalendarEnv();
    $service = $env['service'];
    $invitation = localCalendarInvitation($env, 'eva@hh.example');
    $received = $service->ingest('eva', 'mpx.1.eingang.7.60', [$invitation['ics']]);

    // Der Teilnehmer zieht seine Zusage zurueck: Absage an den Organisator.
    $sentBefore = count($env['backend']->sent);
    $removed = $service->remove('eva', 'eva@hh.example', $received['id']);
    Assert::same('Der Termin wurde gelöscht.', $removed['message']);
    Assert::same(404, localCalendarStatus(static fn () => $service->event('eva', $received['id'])));
    Assert::same($sentBefore + 1, count($env['backend']->sent));
    $reply = ICalendar::parseInvitation((string) $env['backend']->sent[$sentBefore]['mail']['calendar'][0]['content']);
    Assert::same('REPLY', $reply['method']);
    Assert::same('DECLINED', $reply['partstat']);
    Assert::same(['jan@hh.example'], $env['backend']->sent[$sentBefore]['mail']['to']);

    // Ohne eigene Antwort und ohne Organisator entsteht keine Nachricht.
    $plain = $service->save('klaus', localCalendarSender('klaus@hh.example', 'Klaus'), localCalendarInput(['subject' => 'Ohne Teilnehmer']))['id'];
    $sentBefore = count($env['backend']->sent);
    Assert::same('Der Termin wurde gelöscht.', $service->remove('klaus', 'klaus@hh.example', $plain)['message']);
    Assert::same($sentBefore, count($env['backend']->sent), 'Ein Termin ohne Teilnehmer versendet keine Absage.');
});

Runner::test('Lokaler Kalender: Versandaufträge sind idempotent und werden wiederholt', static function (): void {
    $env = localCalendarEnv();
    $service = $env['service'];
    $pdo = $env['pdo'];
    $env['backend']->failure = 'SMTP nicht erreichbar.';

    $created = $service->save('jan', localCalendarSender(), localCalendarInput([
        'subject' => 'Mit Einladung',
        'required' => ['eva@hh.example'],
    ]));
    $rows = localCalendarOutbox($pdo);
    Assert::same(1, count($rows));
    Assert::same('failed', $rows[0]['status'], 'Ein SMTP-Fehler verwirft den Auftrag nicht.');
    Assert::same(1, (int) $rows[0]['attempts']);
    Assert::contains('SMTP nicht erreichbar', (string) $rows[0]['last_error']);
    Assert::null($rows[0]['sent_at']);
    Assert::true((int) strtotime((string) $rows[0]['next_attempt_at']) > time(), 'Der nächste Versuch liegt in der Zukunft.');
    Assert::same('Einladung wird erneut versucht', $service->event('jan', $created['id'])['invite_state']);
    Assert::same([], $env['backend']->sent);

    // Vor dem naechsten Versuch passiert nichts.
    Assert::same(0, $service->dispatch('jan'));
    Assert::same(1, (int) $pdo->query('SELECT attempts FROM orvanta_calendar_outbox')->fetchColumn());

    // Nach Ablauf der Wartezeit gelingt der Versand.
    $pdo->exec('UPDATE orvanta_calendar_outbox SET next_attempt_at = \'' . date('Y-m-d H:i:s', time() - 10) . '\'');
    $env['backend']->failure = null;
    Assert::same(1, $service->dispatch('jan'));
    $row = localCalendarOutbox($pdo)[0];
    Assert::same('sent', $row['status']);
    Assert::same(2, (int) $row['attempts']);
    Assert::true($row['sent_at'] !== null);
    Assert::same(1, count($env['backend']->sent));

    // Ein neuer Auftrag mit gleicher Kennung wird nicht doppelt angelegt.
    $key = (string) $row['idempotency_key'];
    Assert::false($env['repository']->enqueue('jan', (int) $created['id'], 'jan@hh.example', 'request', $key, ['eva@hh.example'], (string) $row['payload'], date('Y-m-d H:i:s')), 'Doppelte Kennung wird abgewiesen.');
    Assert::same(1, count(localCalendarOutbox($pdo)));

    // Nach fuenf erfolglosen Versuchen gilt der Auftrag als endgueltig gescheitert.
    $env['backend']->failure = 'Postfach gesperrt.';
    $env['repository']->enqueue('jan', (int) $created['id'], 'jan@hh.example', 'request', 'request:test:9', ['eva@hh.example'], (string) $row['payload'], date('Y-m-d H:i:s'));
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $pdo->exec('UPDATE orvanta_calendar_outbox SET next_attempt_at = \'' . date('Y-m-d H:i:s', time() - 10) . '\' WHERE idempotency_key = \'request:test:9\'');
        $service->dispatch('jan');
    }
    $failed = $pdo->query('SELECT status, attempts, next_attempt_at FROM orvanta_calendar_outbox WHERE idempotency_key = \'request:test:9\'')->fetch(PDO::FETCH_ASSOC);
    Assert::same('failed', $failed['status']);
    Assert::same(5, (int) $failed['attempts']);
    Assert::same(0, $service->dispatch('jan'), 'Endgültig gescheiterte Aufträge werden nicht mehr versucht.');
    Assert::true((int) strtotime((string) $failed['next_attempt_at']) > time() + 300 * 86400);
    Assert::same('Einladung konnte nicht gesendet werden', $service->event('jan', $created['id'])['invite_state']);

    // Abgebrochene Auftraege werden nach der Sperrfrist wieder freigegeben.
    $env['backend']->failure = null;
    $pdo->exec('UPDATE orvanta_calendar_outbox SET status = \'sending\', attempts = 0, updated_at = \'' . date('Y-m-d H:i:s', time() - 2000) . '\', next_attempt_at = \'' . date('Y-m-d H:i:s', time() - 2000) . '\' WHERE idempotency_key = \'request:test:9\'');
    Assert::same(1, $service->dispatch('jan'));
    Assert::same('sent', (string) $pdo->query('SELECT status FROM orvanta_calendar_outbox WHERE idempotency_key = \'request:test:9\'')->fetchColumn());

    // Ein frisch uebernommener Auftrag bleibt gesperrt.
    $pdo->exec('UPDATE orvanta_calendar_outbox SET status = \'sending\', attempts = 0, updated_at = \'' . date('Y-m-d H:i:s') . '\', next_attempt_at = \'' . date('Y-m-d H:i:s', time() - 10) . '\' WHERE idempotency_key = \'request:test:9\'');
    Assert::same(0, $service->dispatch('jan'));
    Assert::same('sending', (string) $pdo->query('SELECT status FROM orvanta_calendar_outbox WHERE idempotency_key = \'request:test:9\'')->fetchColumn());
});

Runner::test('Lokaler Kalender: höchstens drei Zustellungen je Aufruf', static function (): void {
    $env = localCalendarEnv();
    $service = $env['service'];
    $env['backend']->failure = 'SMTP nicht erreichbar.';

    for ($index = 1; $index <= 5; $index++) {
        $service->save('jan', localCalendarSender(), localCalendarInput([
            'subject' => 'Termin ' . $index,
            'required' => ['eva@hh.example'],
        ]));
    }
    Assert::same(5, count(localCalendarOutbox($env['pdo'])));

    $env['pdo']->exec('UPDATE orvanta_calendar_outbox SET next_attempt_at = \'' . date('Y-m-d H:i:s', time() - 10) . '\'');
    $env['backend']->failure = null;

    Assert::same(3, $service->dispatch('jan'), 'Ein Aufruf stellt höchstens drei Aufträge zu.');
    Assert::same(3, count($env['backend']->sent));
    Assert::same(2, (int) $env['pdo']->query('SELECT COUNT(*) FROM orvanta_calendar_outbox WHERE status = \'failed\'')->fetchColumn());
    Assert::same(2, $service->dispatch('jan'), 'Der nächste Aufruf holt den Rest.');
    Assert::same(5, count($env['backend']->sent));
    Assert::same(0, $service->dispatch('jan'));
});

Runner::test('Lokaler Kalender: ohne Mail-Proxy wird nichts stillschweigend versendet', static function (): void {
    $env = localCalendarEnv('exchange');
    $service = $env['service'];

    $created = $service->save('jan', localCalendarSender(), localCalendarInput([
        'subject' => 'Kein Proxy',
        'required' => ['eva@hh.example'],
    ]));

    Assert::same([], $env['backend']->sent, 'Es wurde nichts über Exchange versendet.');
    $row = localCalendarOutbox($env['pdo'])[0];
    Assert::same('failed', $row['status']);
    Assert::contains('Mail-Proxy', (string) $row['last_error']);
    Assert::same('Einladung wird erneut versucht', $service->event('jan', $created['id'])['invite_state']);
});

Runner::test('Lokaler Kalender: Erinnerungen kommen aus dem lokalen Termin', static function (): void {
    $env = localCalendarEnv();
    $service = $env['service'];
    $notifications = $env['notifications'];
    $start = localCalendarStart();

    $id = $service->save('jan', localCalendarSender(), localCalendarInput([
        'subject' => 'Zahnarzt',
        'location' => 'Praxis',
        'reminder' => 30,
    ]))['id'];

    $items = $service->upcomingReminders('jan', time(), OrvantaNotificationService::SYNC_WINDOW_HOURS);
    Assert::same(1, count($items));
    Assert::same($id, $items[0]['id'], 'Die Kennung ist die Terminkennung, damit "Öffnen" funktioniert.');
    Assert::same('Zahnarzt', $items[0]['subject']);
    Assert::same('Praxis', $items[0]['location']);
    Assert::same($start, $items[0]['start']);
    Assert::true($items[0]['reminder_set']);
    Assert::same($start - 1800, $items[0]['remind_at']);
    Assert::false($items[0]['is_cancelled']);
    Assert::same('Organizer', $items[0]['my_response']);

    $notifications->syncLocal('jan', $items);
    $rows = $env['pdo']->query('SELECT item_id, subject, location, starts_at, remind_at FROM orvanta_reminders WHERE user_uid = \'jan\'')->fetchAll(PDO::FETCH_ASSOC);
    Assert::same(1, count($rows));
    Assert::same($id, (string) $rows[0]['item_id']);
    Assert::same('Zahnarzt', (string) $rows[0]['subject']);
    Assert::same(date('Y-m-d H:i:s', $start - 1800), (string) $rows[0]['remind_at']);
    Assert::same(date('Y-m-d H:i:s', $start), (string) $rows[0]['starts_at']);

    // Die vorhandene Erinnerungsliste zeigt den Termin ohne Aenderung an.
    $poll = $notifications->poll('jan', $start - 1800);
    Assert::same(1, count($poll['due']));
    Assert::same($id, (string) $poll['due'][0]['item_id']);
    Assert::same(1, count($poll['active']));

    // Ohne eigene Erinnerung gilt die allgemeine Vorlaufzeit (Standard 15 Minuten).
    $second = $service->save('jan', localCalendarSender(), localCalendarInput(['subject' => 'Ohne Erinnerung']))['id'];
    $items = $service->upcomingReminders('jan', time(), OrvantaNotificationService::SYNC_WINDOW_HOURS);
    Assert::same(2, count($items));
    $without = $items[0]['id'] === $second ? $items[0] : $items[1];
    Assert::false($without['reminder_set']);
    Assert::same(0, $without['remind_at']);
    $notifications->syncLocal('jan', $items);
    Assert::same(2, (int) $env['pdo']->query('SELECT COUNT(*) FROM orvanta_reminders')->fetchColumn());
    Assert::same(date('Y-m-d H:i:s', $start - 900), (string) $env['pdo']->query('SELECT remind_at FROM orvanta_reminders WHERE item_id = \'' . $second . '\'')->fetchColumn());

    // Abgesagte Termine erinnern nicht mehr.
    $service->remove('jan', 'jan@hh.example', $id);
    $notifications->syncLocal('jan', $service->upcomingReminders('jan', time(), OrvantaNotificationService::SYNC_WINDOW_HOURS));
    Assert::same(1, (int) $env['pdo']->query('SELECT COUNT(*) FROM orvanta_reminders')->fetchColumn());
    Assert::same(0, (int) $env['pdo']->query('SELECT COUNT(*) FROM orvanta_reminders WHERE item_id = \'' . $id . '\'')->fetchColumn());

    // Vergangene Termine lösen keine Erinnerung mehr aus.
    $service->save('jan', localCalendarSender(), localCalendarInput([
        'subject' => 'Vorbei',
        'start' => time() - 7200,
        'end' => time() - 3600,
        'reminder' => 10,
    ]));
    $notifications->syncLocal('jan', $service->upcomingReminders('jan', time() - 86400, OrvantaNotificationService::SYNC_WINDOW_HOURS));
    Assert::same(0, (int) $env['pdo']->query('SELECT COUNT(*) FROM orvanta_reminders WHERE subject = \'Vorbei\'')->fetchColumn());
});

Runner::test('Lokaler Kalender: abgelehnte Einladungen erinnern nicht', static function (): void {
    $env = localCalendarEnv();
    $service = $env['service'];
    $notifications = $env['notifications'];
    $invitation = localCalendarInvitation($env, 'eva@hh.example');
    $received = $service->ingest('eva', 'mpx.1.eingang.7.70', [$invitation['ics']]);

    $notifications->syncLocal('eva', $service->upcomingReminders('eva', time(), OrvantaNotificationService::SYNC_WINDOW_HOURS));
    Assert::same(1, (int) $env['pdo']->query('SELECT COUNT(*) FROM orvanta_reminders WHERE user_uid = \'eva\'')->fetchColumn());

    $service->respond('eva', 'eva@hh.example', $received['id'], 'decline');
    $notifications->syncLocal('eva', $service->upcomingReminders('eva', time(), OrvantaNotificationService::SYNC_WINDOW_HOURS));
    Assert::same(0, (int) $env['pdo']->query('SELECT COUNT(*) FROM orvanta_reminders WHERE user_uid = \'eva\'')->fetchColumn(), 'Abgelehnte Besprechungen werden entfernt.');

    // Andere Benutzer sind nicht betroffen.
    Assert::same(0, (int) $env['pdo']->query('SELECT COUNT(*) FROM orvanta_reminders WHERE user_uid = \'jan\'')->fetchColumn());
});

Runner::test('Lokaler Kalender: Beschreibungen werden für die Anzeige bereinigt', static function (): void {
    $env = localCalendarEnv();
    $service = $env['service'];

    $id = $service->save('jan', localCalendarSender(), localCalendarInput([
        'subject' => 'Mit Anhang',
        'body' => '<p onclick="alert(1)">Hallo</p><script>alert(2)</script><img src="https://tracker.example/x.gif">',
        'required' => ['eva@hh.example'],
    ]))['id'];

    $event = $service->event('jan', $id);
    Assert::contains('Hallo', $event['body_html']);
    Assert::false(str_contains($event['body_html'], '<script'), 'Skripte werden entfernt.');
    Assert::false(str_contains($event['body_html'], 'onclick'), 'Ereignisbehandler werden entfernt.');
    Assert::same(1, $event['blocked_images'], 'Externe Bilder werden gezählt und blockiert.');
    Assert::contains('data-blocked-src="https://tracker.example/x.gif"', $event['body_html'], 'Externe Bilder werden nur als Ersatzquelle ausgeliefert.');
    Assert::false(str_contains($event['body_html'], '<img src='), 'Externe Bilder werden nicht direkt geladen.');

    // Der Einladungstext enthaelt nur Klartext, keine Auszeichnung.
    $ics = localCalendarIcs(localCalendarOutbox($env['pdo'])[0] ?? []);
    Assert::contains('DESCRIPTION:Hallo', $ics);
    Assert::false(str_contains($ics, '<p'));
    Assert::false(str_contains($ics, 'alert('), 'Skriptinhalte gelangen nicht in die Einladung.');
});
