<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Helpdesk\CalendarDateSuggestionService;
use App\Services\Helpdesk\HelpdeskCalendarService;
use Tests\Support\TestCase;

/**
 * Help-Desk-Kalender ohne Datenbank: Zeiträume der Ansichten Tag/Arbeitswoche/Monat, Lage überschneidender
 * Termine im Tagesraster und Auswertung der KI-Antwort für Datumsvorschläge aus Tickets.
 */
final class HelpdeskCalendarTest extends TestCase
{
    public function testDayRange(): void
    {
        $r = HelpdeskCalendarService::range('tag', '2026-11-10');

        $this->assertSame(['2026-11-10'], $r['days']);
        $this->assertSame('2026-11-11', $r['end']);
        $this->assertSame('2026-11-09', $r['prev']);
        $this->assertSame('2026-11-11', $r['next']);
        $this->assertSame('Dienstag, 10. November 2026', $r['title']);
    }

    public function testWorkWeekRangeIsMondayToFriday(): void
    {
        $r = HelpdeskCalendarService::range('arbeitswoche', '2026-11-12');

        $this->assertSame(['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'], $r['days']);
        $this->assertSame('2026-11-14', $r['end']);
        $this->assertSame('2026-11-05', $r['prev']);
        $this->assertSame('2026-11-19', $r['next']);
        $this->assertSame('9.–13. November 2026 · KW 46', $r['title']);
    }

    public function testWorkWeekAcrossMonthAndSundayAnchor(): void
    {
        // Sonntag gehört zur Woche ab dem vorangehenden Montag (ISO-Woche)
        $r = HelpdeskCalendarService::range('arbeitswoche', '2026-11-01');

        $this->assertSame('2026-10-26', $r['start']);
        $this->assertSame('26.–30. Oktober 2026 · KW 44', $r['title']);

        $r = HelpdeskCalendarService::range('arbeitswoche', '2026-12-01');
        $this->assertSame('30. November – 4. Dezember 2026 · KW 49', $r['title']);
    }

    public function testMonthRangeCoversFullWeeks(): void
    {
        $r = HelpdeskCalendarService::range('monat', '2026-11-10');

        $this->assertSame('2026-10-26', $r['start']);
        $this->assertSame('2026-12-07', $r['end']);
        $this->assertCount(42, $r['days']);
        $this->assertSame('2026-10-01', $r['prev']);
        $this->assertSame('2026-12-01', $r['next']);
        $this->assertSame('November 2026', $r['title']);

        // Februar 2027 beginnt an einem Montag und endet an einem Sonntag: genau vier Wochen
        $feb = HelpdeskCalendarService::range('monat', '2027-02-15');
        $this->assertCount(28, $feb['days']);
        // Monatsende: vom 31. Januar wird auf Februar (nicht März) weitergeblättert
        $this->assertSame('2026-02-01', HelpdeskCalendarService::range('monat', '2026-01-31')['next']);
    }

    public function testUnknownViewAndInvalidDateFallBack(): void
    {
        $r = HelpdeskCalendarService::range('jahr', '2026-11-12');
        $this->assertSame('arbeitswoche', $r['view']);

        $r = HelpdeskCalendarService::range('tag', '2026-02-30');
        $this->assertSame(1, count($r['days']));
    }

    public function testLayoutPlacesOverlappingEventsSideBySide(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $events = [
            $this->event(1, '2026-11-10 09:00', '2026-11-10 10:00', $tz),
            $this->event(2, '2026-11-10 09:30', '2026-11-10 11:00', $tz),
            $this->event(3, '2026-11-10 13:00', '2026-11-10 13:10', $tz),
            $this->event(4, '2026-11-10 00:00', '2026-11-11 00:00', $tz, true),
        ];

        $items = HelpdeskCalendarService::layoutDay($events, '2026-11-10', $tz);
        $byId = [];
        foreach ($items as $item) {
            $byId[(int) $item['event']['id']] = $item;
        }

        $this->assertCount(3, $items, 'Ganztägige Termine gehören nicht ins Raster');
        $this->assertSame(540, $byId[1]['top']);
        $this->assertSame(60, $byId[1]['height']);
        $this->assertSame(0, $byId[1]['column']);
        $this->assertSame(1, $byId[2]['column']);
        $this->assertSame(2, $byId[1]['columns']);
        $this->assertSame(2, $byId[2]['columns']);
        $this->assertSame(1, $byId[3]['columns']);
        $this->assertSame(15, $byId[3]['height'], 'Kurze Termine bleiben anklickbar');
    }

    public function testLayoutClipsMultiDayEventsAndHandlesDst(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $events = [$this->event(1, '2026-10-24 22:00', '2026-10-25 08:00', $tz)];

        $first = HelpdeskCalendarService::layoutDay($events, '2026-10-24', $tz);
        $this->assertSame(1320, $first[0]['top']);
        $this->assertTrue($first[0]['continues_after']);

        // 25.10.2026: Umstellung auf Winterzeit, der Tag hat 25 Stunden
        $second = HelpdeskCalendarService::layoutDay($events, '2026-10-25', $tz);
        $this->assertSame(0, $second[0]['top']);
        $this->assertSame(540, $second[0]['height']);
        $this->assertTrue($second[0]['continues_before']);
    }

    public function testOnDayFindsMultiDayAndAllDayEvents(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $events = [
            $this->event(1, '2026-11-09 00:00', '2026-11-12 00:00', $tz, true),
            $this->event(2, '2026-11-10 08:00', '2026-11-10 09:00', $tz),
        ];

        $this->assertCount(1, HelpdeskCalendarService::onDay($events, '2026-11-11', $tz));
        $this->assertCount(0, HelpdeskCalendarService::onDay($events, '2026-11-12', $tz));
        $this->assertCount(1, HelpdeskCalendarService::onDay($events, '2026-11-10', $tz, true));
        $this->assertCount(2, HelpdeskCalendarService::onDay($events, '2026-11-10', $tz));
    }

    public function testFormValuesOfAllDayEventShowInclusiveEndDate(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $values = HelpdeskCalendarService::formValues($this->event(1, '2026-11-09 00:00', '2026-11-12 00:00', $tz, true));

        $this->assertSame('2026-11-09', $values['date']);
        $this->assertSame('2026-11-11', $values['end_date']);
        $this->assertSame('1', $values['all_day']);
        $this->assertSame('', $values['start_time']);
    }

    public function testParseChecklistDropsEmptyRowsAndNormalizesWhitespace(): void
    {
        $items = HelpdeskCalendarService::parseChecklist([
            ['id' => '7', 'label' => "  Backup\n geprüft "],
            ['label' => '   '],
            'kaputt',
            ['id' => 'x', 'label' => 'Neu'],
        ]);

        $this->assertSame([['id' => 7, 'label' => 'Backup geprüft'], ['id' => null, 'label' => 'Neu']], $items);
        $this->assertSame([], HelpdeskCalendarService::parseChecklist('kein Array'));
    }

    public function testTicketTitle(): void
    {
        $this->assertSame('HD-2026-000042 · Umzug Büro 3.12', HelpdeskCalendarService::ticketTitle(['number' => 'HD-2026-000042', 'subject' => 'Umzug Büro 3.12']));
        $this->assertSame('Ohne Nummer', HelpdeskCalendarService::ticketTitle(['number' => '', 'subject' => 'Ohne Nummer']));
    }

    // ------------------------------------------------------------------ KI-Datumsvorschläge

    public function testParseReadsJsonObjectAndSortsAndDeduplicates(): void
    {
        $content = '{"termine":[{"datum":"2026-11-20","beginn":"14:00","ende":"15:30","beschreibung":"Vor-Ort-Termin","textstelle":"am 20.11. um 14 Uhr"},'
            . '{"datum":"2026-11-18","beginn":null,"ende":null,"beschreibung":"Frist","textstelle":"bis 18.11."},'
            . '{"datum":"2026-11-20","beginn":"14:00","beschreibung":"doppelt"}]}';

        $list = CalendarDateSuggestionService::parse($content);

        $this->assertCount(2, $list);
        $this->assertSame('2026-11-18', $list[0]['date']);
        $this->assertNull($list[0]['start']);
        $this->assertSame('2026-11-20', $list[1]['date']);
        $this->assertSame('14:00', $list[1]['start']);
        $this->assertSame('15:30', $list[1]['end']);
        $this->assertSame('Vor-Ort-Termin', $list[1]['label']);
    }

    public function testParseToleratesCodeFencesProseAndVariants(): void
    {
        $fenced = "```json\n{\"termine\":[{\"datum\":\"03.12.2026\",\"beginn\":\"9 Uhr\"}]}\n```";
        $list = CalendarDateSuggestionService::parse($fenced);
        $this->assertSame('2026-12-03', $list[0]['date']);
        $this->assertSame('09:00', $list[0]['start']);

        $prose = 'Gern! Hier die Daten: [{"date":"2026-12-24","start":"25:00","end":"x"}] – fertig.';
        $list = CalendarDateSuggestionService::parse($prose);
        $this->assertCount(1, $list);
        $this->assertNull($list[0]['start'], 'Ungültige Uhrzeit wird verworfen');

        $this->assertSame([], CalendarDateSuggestionService::parse('{"termine":[]}'));
        $this->assertSame([], CalendarDateSuggestionService::parse('Kein Datum gefunden.'));
        $this->assertCount(1, CalendarDateSuggestionService::parse('{"datum":"2026-12-01"}'), 'Einzelnes Objekt ohne Liste');
        $this->assertSame([], CalendarDateSuggestionService::parse('{"termine":[{"datum":"2026-02-30"},{"datum":"morgen"}]}'));
    }

    public function testParseReadsDateRangesWithinLimits(): void
    {
        $list = CalendarDateSuggestionService::parse('{"termine":[{"datum":"2026-12-23","datum_ende":"2027-01-02","beschreibung":"Urlaub"},{"datum":"2026-11-20","datum_ende":"2026-11-19"},{"datum":"2026-11-21","datum_ende":"2027-03-01"}]}');

        $this->assertSame('2027-01-02', $list[2]['end_date']);
        $this->assertNull($list[0]['end_date'], 'Ende vor Beginn wird verworfen');
        $this->assertNull($list[1]['end_date'], 'Mehr als 31 Tage wird verworfen');
    }

    public function testParseDropsPastDatesAndDerivesEndDateFromExcerpt(): void
    {
        $list = CalendarDateSuggestionService::parse(
            '{"termine":[{"datum":"2026-11-02","textstelle":"Seit dem 2.11."},{"datum":"2026-12-23","textstelle":"vom 23.12. bis 2.1."}]}',
            '2026-11-10'
        );

        $this->assertCount(1, $list, 'Daten vor der Ticketerstellung sind keine Termine');
        $this->assertSame('2027-01-02', $list[0]['end_date']);
        $this->assertSame('2026-11-20', CalendarDateSuggestionService::endDateFromExcerpt('vom 18.11. bis zum 20.11.', '2026-11-18'));
        $this->assertNull(CalendarDateSuggestionService::endDateFromExcerpt('am 18.11. um 9 Uhr', '2026-11-18'));
    }

    public function testCalendarAidListsWeekdaysAndIsoWeeks(): void
    {
        $aid = CalendarDateSuggestionService::calendarAid(new \DateTimeImmutable('2026-11-10 10:00', new \DateTimeZone('Europe/Berlin')));

        $this->assertStringContains('Dienstag 2026-11-10', $aid);
        $this->assertStringContains('Montag 2026-11-23', $aid);
        $this->assertStringContains('KW 48: 2026-11-23 (Mo) bis 2026-11-29 (So)', $aid);
        $this->assertStringContains('KW 53: 2026-12-28 (Mo) bis 2027-01-03 (So)', $aid, '2026 hat 53 ISO-Wochen');
    }

    public function testParseDropsEndBeforeStart(): void
    {
        $list = CalendarDateSuggestionService::parse('{"termine":[{"datum":"2026-11-20","beginn":"14:00","ende":"13:00"}]}');

        $this->assertNull($list[0]['end']);
    }

    public function testPrepareTextStripsEmbeddedImagesAndTruncates(): void
    {
        $text = CalendarDateSuggestionService::prepareText('Umzug', "Bitte am 20.11. kommen.\n![Bild](data:image/png;base64,iVBORw0KGgo=)\nDanke");

        $this->assertStringContains('Betreff: Umzug', $text);
        $this->assertStringContains('[Bild]', $text);
        $this->assertFalse(str_contains($text, 'base64'));

        $long = CalendarDateSuggestionService::prepareText('', str_repeat('a', CalendarDateSuggestionService::MAX_TEXT + 100));
        $this->assertSame(CalendarDateSuggestionService::MAX_TEXT, mb_strlen($long));
    }

    /** @return array<string,mixed> */
    private function event(int $id, string $start, string $end, \DateTimeZone $tz, bool $allDay = false): array
    {
        return [
            'id' => $id,
            'title' => 'Termin ' . $id,
            'all_day' => $allDay ? 1 : 0,
            'start_local' => new \DateTimeImmutable($start, $tz),
            'end_local' => new \DateTimeImmutable($end, $tz),
        ];
    }
}
