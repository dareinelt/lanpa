<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Minimaler iCalendar-Leser und -Schreiber (RFC 5545) fuer den lokalen
 * Orvanta-Kalender.
 *
 * Der Mail-Proxy kann weder rohe MIME-Nachrichten liefern noch beliebige
 * Kopfzeilen versenden. Einladungen laufen deshalb als eigener
 * `text/calendar`-Teil in der Nachricht - beim Versand als Alternative zum
 * Text, beim Empfang als Anhang. Dieses Hilfsmittel kennt genau den
 * Ausschnitt, den Orvanta dafuer braucht: VEVENT mit UID, SEQUENCE, DTSTART,
 * DTEND, SUMMARY, DESCRIPTION, LOCATION, ORGANIZER, ATTENDEE, STATUS sowie die
 * Methoden REQUEST, CANCEL und REPLY.
 *
 * Bewusste Grenzen:
 *   - Wiederholungen (RRULE, RDATE, EXDATE) werden nicht abgebildet: eine
 *     Serieneinladung wird nur mit ihrem ersten Termin gespeichert. Erkannt
 *     wird sie trotzdem - `normalize()` liefert dafuer `recurrence`, damit
 *     der Aufrufer darauf hinweisen kann.
 *   - Zeiten werden immer als UTC uebertragen. Beim Lesen wird eine TZID-
 *     Angabe ausgewertet, damit der Zeitpunkt stimmt.
 *   - Alle Eingaben sind Groessenbegrenzt (siehe MAX_*), damit eine
 *     ueberlange Nachricht den Dienst nicht blockiert.
 */
final class ICalendar
{
    public const MAX_BYTES = 524288;
    public const MAX_LINES = 20000;
    public const MAX_EVENTS = 50;
    public const MAX_ATTENDEES = 200;
    public const MAX_TEXT = 20000;

    /** Methoden, die der lokale Kalender erzeugt und auswertet (RFC 5546). */
    public const METHODS = ['REQUEST', 'CANCEL', 'REPLY'];

    /** Produktkennung; Clients zeigen sie in den Termindetails an. */
    private const PRODID = '-//Orvanta//Lokaler Kalender//DE';

    /**
     * Baut ein vollstaendiges iCalendar-Objekt mit genau einem VEVENT.
     *
     * @param array{
     *     uid: string,
     *     sequence?: int,
     *     summary?: string,
     *     description?: string,
     *     location?: string,
     *     organizer_email?: string,
     *     organizer_name?: string,
     *     starts_at: string,
     *     ends_at: string,
     *     all_day?: bool,
     *     free_busy?: string,
     *     status?: string,
     *     attendees?: list<array{email: string, name?: string, role?: string, response?: string}>,
     *     partstat?: string
     * } $event
     * @param string $method REQUEST, CANCEL oder REPLY
     */
    public static function build(array $event, string $method): string
    {
        $method = strtoupper($method);
        if (!in_array($method, ['REQUEST', 'CANCEL', 'REPLY'], true)) {
            $method = 'REQUEST';
        }
        $uid = self::clip((string) ($event['uid'] ?? ''), 191);
        if ($uid === '') {
            $uid = self::newUid();
        }
        $allDay = (bool) ($event['all_day'] ?? false);
        $start = (int) strtotime((string) ($event['starts_at'] ?? ''));
        $end = (int) strtotime((string) ($event['ends_at'] ?? ''));
        if ($start <= 0) {
            $start = time();
        }
        if ($end <= $start) {
            $end = $start + ($allDay ? 86400 : 3600);
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:' . $method,
            'BEGIN:VEVENT',
            'UID:' . self::escape($uid),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'SEQUENCE:' . max(0, (int) ($event['sequence'] ?? 0)),
        ];
        $lines[] = self::dateLine('DTSTART', $start, $allDay);
        $lines[] = self::dateLine('DTEND', $end, $allDay);
        $summary = self::clip((string) ($event['summary'] ?? ''), 255);
        $lines[] = 'SUMMARY:' . self::escape($summary !== '' ? $summary : 'Termin');
        $description = self::clip(self::toText((string) ($event['description'] ?? '')), self::MAX_TEXT);
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:' . self::escape($description);
        }
        $location = self::clip((string) ($event['location'] ?? ''), 255);
        if ($location !== '') {
            $lines[] = 'LOCATION:' . self::escape($location);
        }
        $status = strtoupper((string) ($event['status'] ?? 'CONFIRMED'));
        $lines[] = 'STATUS:' . (in_array($status, ['CONFIRMED', 'CANCELLED', 'TENTATIVE'], true) ? $status : 'CONFIRMED');
        $lines[] = 'TRANSP:' . (strcasecmp((string) ($event['free_busy'] ?? 'Busy'), 'Free') === 0 ? 'TRANSPARENT' : 'OPAQUE');

        $organizer = self::addressLine('ORGANIZER', (string) ($event['organizer_email'] ?? ''), (string) ($event['organizer_name'] ?? ''), []);
        if ($organizer !== '') {
            $lines[] = $organizer;
        }
        $partstat = self::partstat((string) ($event['partstat'] ?? ''));
        foreach (array_slice((array) ($event['attendees'] ?? []), 0, self::MAX_ATTENDEES) as $attendee) {
            if (!is_array($attendee)) {
                continue;
            }
            $email = trim((string) ($attendee['email'] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $role = strtoupper((string) ($attendee['role'] ?? 'REQ-PARTICIPANT'));
            if (!in_array($role, ['REQ-PARTICIPANT', 'OPT-PARTICIPANT'], true)) {
                $role = 'REQ-PARTICIPANT';
            }
            $response = $method === 'REPLY'
                ? $partstat
                : self::partstat((string) ($attendee['response'] ?? ''));
            $line = self::addressLine('ATTENDEE', $email, (string) ($attendee['name'] ?? ''), [
                'ROLE' => $role,
                'PARTSTAT' => $response,
                'RSVP' => 'TRUE',
            ]);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return self::fold($lines) . "\r\n";
    }

    /**
     * Liest ein iCalendar-Objekt. Unbekannte Eigenschaften werden ignoriert,
     * unbrauchbare Termine uebersprungen.
     *
     * @return array{method: string, events: list<array<string, mixed>>}
     */
    public static function parse(string $raw): array
    {
        $result = ['method' => '', 'events' => []];
        $raw = trim($raw, "\x00\xEF\xBB\xBF");
        if ($raw === '' || strlen($raw) > self::MAX_BYTES) {
            return $result;
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        if (count($lines) > self::MAX_LINES) {
            return $result;
        }
        $lines = self::unfoldLines($lines);

        $events = [];
        $current = null;
        $inEvent = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $upper = strtoupper($line);
            if ($upper === 'BEGIN:VEVENT') {
                $current = [];
                $inEvent = true;
                continue;
            }
            if ($upper === 'END:VEVENT') {
                if ($inEvent && is_array($current)) {
                    $normalized = self::normalize($current, $result['method']);
                    if ($normalized !== null) {
                        $events[] = $normalized;
                    }
                }
                $current = null;
                $inEvent = false;
                if (count($events) >= self::MAX_EVENTS) {
                    break;
                }
                continue;
            }
            if (!$inEvent || !is_array($current)) {
                if ($result['method'] === '' && str_starts_with($upper, 'METHOD:')) {
                    $result['method'] = strtoupper(substr($line, 7));
                }
                continue;
            }
            $split = self::splitProperty($line);
            if ($split === null) {
                continue;
            }
            [$name, $params, $value] = $split;
            if ($name === 'ATTENDEE') {
                $current['ATTENDEE'][] = ['params' => $params, 'value' => $value];
                continue;
            }
            if (isset($current[$name])) {
                continue;
            }
            $current[$name] = ['params' => $params, 'value' => $value];
        }

        $result['events'] = $events;

        return $result;
    }

    /**
     * Wertet eine eingehende Einladung aus: genau ein Termin, mit Serien-
     * kennung, Organisator und gueltigem Zeitraum.
     *
     * @return array<string, mixed>|null
     */
    public static function parseInvitation(string $raw): ?array
    {
        $parsed = self::parse($raw);
        $method = $parsed['method'];
        if (!in_array($method, self::METHODS, true)) {
            return null;
        }
        if (count($parsed['events']) !== 1) {
            return null;
        }
        $event = $parsed['events'][0];
        if ($event['uid'] === '' || $event['invalid']) {
            return null;
        }
        if ($method !== 'REPLY' && $event['organizer']['email'] === '') {
            return null;
        }

        return $event;
    }

    /**
     * Kennung fuer neue Termine. Die Domaene bleibt stabil, damit Antworten
     * denselben Termin zuordnen koennen.
     */
    public static function newUid(): string
    {
        return bin2hex(random_bytes(16)) . '@orvanta.local';
    }

    /**
     * Wandelt HTML in lesbaren Text um (fuer die DESCRIPTION-Eigenschaft).
     */
    public static function toText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $text = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = preg_replace('/<(br|\/p|\/div|\/li|\/tr)[^>]*>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<li[^>]*>/i', '- ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Maskiert Text nach RFC 5545 (Abschnitt 3.3.11).
     */
    public static function escape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);

        return str_replace([';', ','], ['\\;', '\\,'], $value);
    }

    /**
     * Kehrt {@see escape()} um.
     */
    public static function unescape(string $value): string
    {
        $value = str_replace(['\\n', '\\N'], "\n", $value);

        return str_replace(['\\;', '\\,', '\\\\'], [';', ',', '\\'], $value);
    }

    /**
     * Faltet die Zeilen auf hoechstens 75 Oktette je Zeile (RFC 5545, 3.1),
     * ohne mehrbytige UTF-8-Zeichen zu zerteilen.
     *
     * @param list<string> $lines
     */
    public static function fold(array $lines): string
    {
        $out = [];
        foreach ($lines as $line) {
            $line = (string) $line;
            $length = strlen($line);
            if ($length <= 73) {
                $out[] = $line;
                continue;
            }
            $offset = 0;
            $first = true;
            while ($offset < $length) {
                $limit = $first ? 73 : 72;
                $chunk = substr($line, $offset, $limit);
                // Nicht mitten in einer UTF-8-Folge trennen.
                $cut = strlen($chunk);
                while ($cut > 0 && (ord($chunk[$cut - 1]) & 0xC0) === 0x80) {
                    $cut--;
                }
                if ($cut === 0) {
                    $cut = strlen($chunk);
                }
                $chunk = substr($chunk, 0, $cut);
                $out[] = ($first ? '' : ' ') . $chunk;
                $offset += $cut;
                $first = false;
            }
        }

        return implode("\r\n", $out);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function unfoldLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                if ($out !== []) {
                    $out[count($out) - 1] .= substr($line, 1);
                }
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Zerlegt eine Eigenschaftenzeile in Name, Parameter und Wert.
     *
     * @return array{0: string, 1: array<string, string>, 2: string}|null
     */
    private static function splitProperty(string $line): ?array
    {
        $colon = self::findSeparator($line, ':');
        if ($colon < 0) {
            return null;
        }
        $head = substr($line, 0, $colon);
        $value = substr($line, $colon + 1);
        $semicolon = self::findSeparator($head, ';');
        $name = strtoupper($semicolon < 0 ? $head : substr($head, 0, $semicolon));
        if ($name === '' || preg_match('/^[A-Z0-9-]+$/', $name) !== 1) {
            return null;
        }
        $params = [];
        if ($semicolon >= 0) {
            foreach (explode(';', substr($head, $semicolon + 1)) as $param) {
                if (!str_contains($param, '=')) {
                    continue;
                }
                [$key, $raw] = explode('=', $param, 2);
                $key = strtoupper(trim($key));
                $raw = trim($raw, '"');
                if ($key !== '') {
                    $params[$key] = $raw;
                }
            }
        }

        return [$name, $params, $value];
    }

    /**
     * Sucht ein Trennzeichen ausserhalb von Anfuehrungszeichen.
     */
    private static function findSeparator(string $line, string $needle): int
    {
        $quoted = false;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === '"') {
                $quoted = !$quoted;
                continue;
            }
            if (!$quoted && $char === $needle) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>|null
     */
    private static function normalize(array $properties, string $calendarMethod): ?array
    {
        $uid = self::clip(self::unescape(self::value($properties, 'UID')), 191);
        $sequence = (int) preg_replace('/\D/', '', self::value($properties, 'SEQUENCE'));
        $start = self::parseDate($properties, 'DTSTART');
        $end = self::parseDate($properties, 'DTEND');
        $allDay = $start['all_day'];
        if ($start['ts'] <= 0) {
            return null;
        }
        $startTs = $start['ts'];
        $endTs = $end['ts'];
        $invalid = false;
        if ($endTs <= $startTs) {
            if ($end['ts'] <= 0) {
                $endTs = $startTs + ($allDay ? 86400 : 3600);
            } else {
                $invalid = true;
                $endTs = $startTs + ($allDay ? 86400 : 3600);
            }
        }
        $timezone = $start['timezone'] !== '' ? $start['timezone'] : $end['timezone'];

        $organizer = ['name' => '', 'email' => ''];
        $organizerValue = self::value($properties, 'ORGANIZER');
        if ($organizerValue !== '') {
            $organizer = self::parseAddress($organizerValue, $properties['ORGANIZER']['params'] ?? []);
        }

        $attendees = [];
        foreach ((array) ($properties['ATTENDEE'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $address = self::parseAddress((string) ($entry['value'] ?? ''), (array) ($entry['params'] ?? []));
            if ($address['email'] === '') {
                continue;
            }
            $role = strtoupper((string) (($entry['params'] ?? [])['ROLE'] ?? 'REQ-PARTICIPANT'));
            if (!in_array($role, ['REQ-PARTICIPANT', 'OPT-PARTICIPANT'], true)) {
                $role = 'REQ-PARTICIPANT';
            }
            $attendees[] = [
                'email' => $address['email'],
                'name' => $address['name'],
                'role' => $role,
                'response' => self::partstat((string) (($entry['params'] ?? [])['PARTSTAT'] ?? '')),
            ];
            if (count($attendees) >= self::MAX_ATTENDEES) {
                break;
            }
        }

        $status = strtoupper(self::value($properties, 'STATUS'));
        if (!in_array($status, ['CONFIRMED', 'CANCELLED', 'TENTATIVE'], true)) {
            $status = 'CONFIRMED';
        }

        // Eine Antwort traegt den Status am ATTENDEE (RFC 5545, 3.8.4.1) und
        // nicht als eigenes Feld; ohne diesen Rueckgriff ginge sie verloren.
        $partstat = self::partstat(self::value($properties, 'PARTSTAT'));
        if ($partstat === 'NEEDS-ACTION') {
            foreach ($attendees as $attendee) {
                if ($attendee['response'] !== 'NEEDS-ACTION') {
                    $partstat = $attendee['response'];
                    break;
                }
            }
        }

        return [
            'uid' => $uid,
            'sequence' => max(0, $sequence),
            'method' => $calendarMethod,
            'summary' => self::clip(self::unescape(self::value($properties, 'SUMMARY')), 255),
            'description' => self::clip(self::unescape(self::value($properties, 'DESCRIPTION')), self::MAX_TEXT),
            'location' => self::clip(self::unescape(self::value($properties, 'LOCATION')), 255),
            'organizer' => $organizer,
            'attendees' => $attendees,
            'start' => $startTs,
            'end' => $endTs,
            'all_day' => $allDay,
            'timezone' => $timezone,
            'status' => $status,
            'partstat' => $partstat,
            'free_busy' => self::freeBusy(self::value($properties, 'TRANSP')),
            'recurrence' => isset($properties['RRULE']) || isset($properties['RDATE']),
            'invalid' => $invalid,
        ];
    }

    /**
     * @param array<string, mixed> $properties
     */
    private static function value(array $properties, string $name): string
    {
        $entry = $properties[$name] ?? null;
        if (!is_array($entry)) {
            return '';
        }

        return (string) ($entry['value'] ?? '');
    }

    /**
     * @param array<string, mixed> $properties
     * @return array{ts: int, all_day: bool, timezone: string}
     */
    private static function parseDate(array $properties, string $name): array
    {
        $empty = ['ts' => 0, 'all_day' => false, 'timezone' => ''];
        $entry = $properties[$name] ?? null;
        if (!is_array($entry)) {
            return $empty;
        }
        $value = strtoupper(trim((string) ($entry['value'] ?? '')));
        $params = (array) ($entry['params'] ?? []);
        $isDate = strtoupper((string) ($params['VALUE'] ?? '')) === 'DATE' || preg_match('/^\d{8}$/', $value) === 1;
        if ($isDate) {
            if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $match) !== 1) {
                return $empty;
            }
            $ts = self::makeTimestamp((int) $match[1], (int) $match[2], (int) $match[3], 0, 0, 0, null);

            return ['ts' => $ts, 'all_day' => true, 'timezone' => ''];
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $value, $match) !== 1) {
            return $empty;
        }
        $zone = null;
        $timezone = '';
        if ($match[7] === 'Z') {
            $zone = new DateTimeZone('UTC');
            $timezone = 'UTC';
        } elseif (isset($params['TZID']) && $params['TZID'] !== '') {
            try {
                $zone = new DateTimeZone((string) $params['TZID']);
                $timezone = $zone->getName();
            } catch (Throwable) {
                // Unbekannte Zeitzone: Zeit gilt in der Zeitzone der Anwendung.
                $zone = null;
            }
        }
        $ts = self::makeTimestamp(
            (int) $match[1],
            (int) $match[2],
            (int) $match[3],
            (int) $match[4],
            (int) $match[5],
            (int) $match[6],
            $zone
        );

        return ['ts' => $ts, 'all_day' => false, 'timezone' => $timezone];
    }

    private static function makeTimestamp(int $year, int $month, int $day, int $hour, int $minute, int $second, ?DateTimeZone $zone): int
    {
        if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return 0;
        }
        if ($hour > 23 || $minute > 59 || $second > 60) {
            return 0;
        }
        try {
            $date = new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, min($second, 59)), $zone);
        } catch (Throwable) {
            return 0;
        }

        return $date->getTimestamp();
    }

    /**
     * @param array<string, string> $params
     * @return array{name: string, email: string}
     */
    private static function parseAddress(string $value, array $params): array
    {
        $email = trim($value);
        if (stripos($email, 'mailto:') === 0) {
            $email = substr($email, 7);
        }
        $email = trim($email, " \t\n\r<>\"");
        $name = self::clip(self::unescape((string) ($params['CN'] ?? '')), 190);
        if ($name === '') {
            $name = $email;
        }

        return ['name' => $name, 'email' => $email];
    }

    /**
     * Baut eine Eigenschaftenzeile mit Adresswert. Alle Parameter muessen vor
     * dem Doppelpunkt stehen (RFC 5545, 3.2).
     *
     * @param array<string, string> $params
     */
    private static function addressLine(string $name, string $email, string $displayName, array $params): string
    {
        $email = trim($email, " \t\n\r<>\"");
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }
        $displayName = self::clip($displayName, 190);
        if ($displayName !== '' && strcasecmp($displayName, $email) !== 0) {
            $params = ['CN' => self::escapeParam($displayName)] + $params;
        }
        $line = $name;
        foreach ($params as $key => $value) {
            $line .= ';' . $key . '=' . $value;
        }

        return $line . ':mailto:' . $email;
    }

    /**
     * Parameterwerte mit Sonderzeichen werden in Anfuehrungszeichen gesetzt.
     */
    private static function escapeParam(string $value): string
    {
        $value = str_replace(["\r", "\n", '"'], ['', '', "'"], $value);

        return '"' . $value . '"';
    }

    /**
     * Ganztagstermine tragen ein reines Datum (ohne Zeitzone), damit sie in
     * jedem Empfaengerkalender am richtigen Kalendertag liegen. Zeitgebundene
     * Termine werden als UTC uebertragen.
     */
    private static function dateLine(string $name, int $timestamp, bool $allDay): string
    {
        if ($allDay) {
            return $name . ';VALUE=DATE:' . date('Ymd', $timestamp);
        }

        return $name . ':' . gmdate('Ymd\THis\Z', $timestamp);
    }

    private static function partstat(string $value): string
    {
        $value = strtoupper(trim($value));
        if (!in_array($value, ['NEEDS-ACTION', 'ACCEPTED', 'DECLINED', 'TENTATIVE'], true)) {
            return 'NEEDS-ACTION';
        }

        return $value;
    }

    private static function freeBusy(string $transparency): string
    {
        return strtoupper(trim($transparency)) === 'TRANSPARENT' ? 'Free' : 'Busy';
    }

    private static function clip(string $value, int $limit): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit, 'UTF-8'));
    }
}
