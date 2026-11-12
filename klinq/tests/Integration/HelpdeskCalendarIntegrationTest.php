<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\EmployeeRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\Helpdesk\Ai\AiTransportInterface;
use App\Services\Helpdesk\AiSettingsService;
use App\Services\Helpdesk\CalendarDateSuggestionService;
use App\Services\Helpdesk\HelpdeskCalendarService;
use App\Services\Helpdesk\TicketService;
use App\Services\SettingsService;
use App\Support\Secret;
use Tests\Support\DatabaseTestCase;

/**
 * Help-Desk-Kalender gegen die Test-Datenbank: manuelle Termine (lokal → UTC), Zeitraumabfrage über
 * Überschneidung, Termin aus einem Ticket, Termine des Tages und Datumsvorschläge über einen Fake-KI-Endpunkt.
 */
final class HelpdeskCalendarIntegrationTest extends DatabaseTestCase
{
    private int $agentId;

    protected function setUp(): void
    {
        parent::setUp();
        $stmt = $this->pdo->prepare("INSERT INTO users (username, display_name, email, role_id, auth_source, is_active) SELECT ?, ?, ?, id, 'local', 1 FROM roles WHERE name = ?");
        $stmt->execute(['cal-agent', 'Cal Agent', 'cal-agent@example.test', 'helpdesk_agent']);
        $this->agentId = (int) $this->pdo->lastInsertId();
        $this->loginAs($this->agentId, 'helpdesk_agent');
    }

    private function loginAs(int $userId, string $role): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => $userId, 'username' => 'u' . $userId, 'display_name' => 'Agent ' . $userId, 'role' => $role, 'groups' => []]);
    }

    private function calendar(): HelpdeskCalendarService
    {
        return $this->c->get(HelpdeskCalendarService::class);
    }

    public function testCreateStoresUtcAndRangeQueryFindsOverlaps(): void
    {
        $event = $this->calendar()->create([
            'title' => 'Druckertausch Station 4',
            'date' => '2026-07-14',
            'start_time' => '09:30',
            'end_time' => '11:00',
            'location' => 'Station 4',
        ]);

        $row = $this->pdo->query('SELECT starts_at, ends_at FROM helpdesk_calendar_events WHERE id = ' . (int) $event['id'])->fetch();
        $this->assertSame('2026-07-14 07:30:00', $row['starts_at'], 'Sommerzeit: lokal 09:30 = 07:30 UTC');
        $this->assertSame('2026-07-14 09:00:00', $row['ends_at']);

        $multiDay = $this->calendar()->create(['title' => 'Rollout', 'date' => '2026-07-13', 'end_date' => '2026-07-15', 'all_day' => '1']);

        $day = $this->calendar()->eventsIn(HelpdeskCalendarService::range('tag', '2026-07-14'));
        $ids = array_map(static fn (array $e): int => (int) $e['id'], $day);
        $this->assertContains((int) $event['id'], $ids);
        $this->assertContains((int) $multiDay['id'], $ids, 'Mehrtägige Termine erscheinen an jedem Tag');
        $this->assertSame('09:30', $day[array_search((int) $event['id'], $ids, true)]['start_local']->format('H:i'));

        $next = $this->calendar()->eventsIn(HelpdeskCalendarService::range('tag', '2026-07-16'));
        $this->assertFalse(in_array((int) $multiDay['id'], array_map(static fn (array $e): int => (int) $e['id'], $next), true), 'Ganztägig endet nach dem Enddatum');
    }

    public function testValidationAndDefaults(): void
    {
        $this->assertThrows(ValidationException::class, fn () => $this->calendar()->create(['title' => '', 'date' => '2026-07-14', 'start_time' => '09:00']));
        $this->assertThrows(ValidationException::class, fn () => $this->calendar()->create(['title' => 'X', 'date' => '2026-07-14', 'start_time' => '10:00', 'end_time' => '09:00']));
        $this->assertThrows(ValidationException::class, fn () => $this->calendar()->create(['title' => 'X', 'date' => '14.07.2026', 'start_time' => '10:00']));
        $this->assertThrows(ValidationException::class, fn () => $this->calendar()->create(['title' => 'X', 'date' => '2026-07-14']));

        $event = $this->calendar()->create(['title' => 'Kurz', 'date' => '2026-07-14', 'start_time' => '10:00']);
        $this->assertSame('11:00', $event['end_local']->format('H:i'), 'Ohne Endzeit dauert ein Termin eine Stunde');
    }

    public function testUpdateAndDelete(): void
    {
        $event = $this->calendar()->create(['title' => 'Alt', 'date' => '2026-07-14', 'start_time' => '10:00']);
        $updated = $this->calendar()->update((int) $event['id'], ['title' => 'Neu', 'date' => '2026-07-15', 'all_day' => '1']);

        $this->assertSame('Neu', $updated['title']);
        $this->assertSame(1, $updated['all_day']);
        $this->assertSame('2026-07-15', HelpdeskCalendarService::formValues($updated)['end_date']);

        $this->calendar()->delete((int) $event['id']);
        $this->assertThrows(\App\Exceptions\NotFoundException::class, fn () => $this->calendar()->get((int) $event['id']));
    }

    public function testSeriesIsStoredOnceAndExpandedPerOccurrence(): void
    {
        $series = $this->calendar()->create([
            'title' => 'Wartung Serverraum',
            'date' => '2026-11-10',
            'start_time' => '09:00',
            'end_time' => '10:30',
            'recurrence' => 'weekly',
            'recurrence_interval' => '1',
            'recurrence_until' => '2026-12-08',
        ]);
        $this->assertSame('weekly', $series['recurrence']);
        $this->assertSame('Wöchentlich bis 08.12.2026', HelpdeskCalendarService::recurrenceLabel($series));

        $stored = (int) $this->pdo->query('SELECT COUNT(*) FROM helpdesk_calendar_events')->fetchColumn();
        $this->assertSame(1, $stored, 'Die Serie steht genau einmal in der Datenbank');

        $events = $this->calendar()->eventsIn(['start' => '2026-11-01', 'end' => '2026-12-01']);
        $days = array_map(static fn (array $e): string => $e['occurrence'], $events);

        $this->assertSame(['2026-11-10', '2026-11-17', '2026-11-24'], $days);
        $this->assertSame('09:00', $events[1]['start_local']->format('H:i'));
        $this->assertTrue($events[1]['is_series']);
        $this->assertSame((int) $series['id'], (int) $events[1]['id'], 'Vorkommen tragen den Serientermin als Datensatz');

        $december = $this->calendar()->eventsIn(['start' => '2026-12-01', 'end' => '2027-01-01']);
        $this->assertSame(['2026-12-01', '2026-12-08'], array_map(static fn (array $e): string => $e['occurrence'], $december), 'Serienende wird eingehalten');
    }

    public function testMonthlySeriesClampsToLastDayOfMonth(): void
    {
        $this->calendar()->create([
            'title' => 'Monatsabschluss',
            'date' => '2026-01-31',
            'start_time' => '16:00',
            'recurrence' => 'monthly',
        ]);

        $events = $this->calendar()->eventsIn(['start' => '2026-01-01', 'end' => '2026-05-01']);

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], array_map(static fn (array $e): string => $e['occurrence'], $events));
    }

    public function testOccurrencesKeepWallClockTimeAcrossDaylightSaving(): void
    {
        $series = $this->calendar()->create([
            'title' => 'Bereitschaftsübergabe',
            'date' => '2026-10-18',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'recurrence' => 'weekly',
        ]);

        $events = $this->calendar()->eventsIn(['start' => '2026-10-18', 'end' => '2026-11-02']);
        $this->assertSame(['2026-10-18', '2026-10-25', '2026-11-01'], array_map(static fn (array $e): string => $e['occurrence'], $events));
        $this->assertSame('09:00', $events[1]['start_local']->format('H:i'), 'Sommerzeit');
        $this->assertSame('09:00', $events[2]['start_local']->format('H:i'), 'Winterzeit: Uhrzeit bleibt gleich');
        $this->assertSame('10:00', $events[2]['end_local']->format('H:i'), 'Dauer bleibt eine Stunde');

        $row = $this->pdo->query('SELECT starts_at FROM helpdesk_calendar_events WHERE id = ' . (int) $series['id'])->fetch();
        $this->assertSame('2026-10-18 07:00:00', $row['starts_at']);
    }

    public function testRecurrenceValidation(): void
    {
        $base = ['title' => 'Serie', 'date' => '2026-11-10', 'start_time' => '09:00'];

        $this->assertThrows(ValidationException::class, fn () => $this->calendar()->create($base + ['recurrence' => 'stündlich']));
        $this->assertThrows(ValidationException::class, fn () => $this->calendar()->create($base + ['recurrence' => 'weekly', 'recurrence_until' => '2026-11-09']));
        $this->assertThrows(ValidationException::class, fn () => $this->calendar()->create($base + [
            'recurrence' => 'daily', 'recurrence_interval' => '1', 'end_date' => '2026-11-12', 'end_time' => '10:00',
        ]));

        $this->assertSame('', $this->calendar()->create($base)['recurrence'], 'Ohne Angabe bleibt der Termin einmalig');
    }

    public function testEditingSingleOccurrenceKeepsTheRestOfTheSeries(): void
    {
        $series = $this->calendar()->create([
            'title' => 'Wartung Serverraum',
            'date' => '2026-11-10',
            'start_time' => '09:00',
            'end_time' => '10:30',
            'recurrence' => 'weekly',
        ]);
        $id = (int) $series['id'];

        $occurrence = $this->calendar()->update($id, [
            'title' => 'Wartung Serverraum (nachmittags)',
            'date' => '2026-11-17',
            'end_date' => '2026-11-17',
            'start_time' => '14:00',
            'end_time' => '15:30',
            'occurrence' => '2026-11-17',
        ], HelpdeskCalendarService::SCOPE_OCCURRENCE);

        $this->assertTrue($id !== (int) $occurrence['id'], 'Abweichender Termin ist ein eigener Datensatz');
        $this->assertSame($id, (int) $occurrence['series_parent_id']);
        $this->assertSame('2026-11-17', $occurrence['series_date']);

        $events = $this->calendar()->eventsIn(['start' => '2026-11-09', 'end' => '2026-11-24']);
        $byDay = [];
        foreach ($events as $event) {
            $byDay[$event['occurrence']] = $event;
        }
        $this->assertSame('Wartung Serverraum (nachmittags)', $byDay['2026-11-17']['title']);
        $this->assertSame('14:00', $byDay['2026-11-17']['start_local']->format('H:i'));
        $this->assertSame('Wartung Serverraum', $byDay['2026-11-10']['title']);
        $this->assertSame('09:00', $byDay['2026-11-10']['start_local']->format('H:i'));

        $values = $this->calendar()->occurrenceValues($id, '2026-11-24');
        $this->assertSame('2026-11-24', $values['date']);
        $this->assertSame('09:00', $values['start_time'], 'Andere Vorkommen übernehmen die Uhrzeit des Serientermins');
        $this->assertSame('2026-11-17', $this->calendar()->occurrenceValues($id, '2026-11-17')['date']);
    }

    public function testEditingWholeSeriesKeepsAnchorAndDropsDeviations(): void
    {
        $series = $this->calendar()->create([
            'title' => 'Wartung Serverraum',
            'date' => '2026-11-10',
            'start_time' => '09:00',
            'recurrence' => 'weekly',
            'recurrence_until' => '2026-12-31',
        ]);
        $id = (int) $series['id'];
        $this->calendar()->update($id, [
            'title' => 'Wartung Serverraum',
            'date' => '2026-11-17',
            'start_time' => '14:00',
            'occurrence' => '2026-11-17',
        ], HelpdeskCalendarService::SCOPE_OCCURRENCE);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM helpdesk_calendar_events')->fetchColumn());

        $updated = $this->calendar()->update($id, [
            'title' => 'Wartung Serverraum',
            'date' => '2026-11-17',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'recurrence' => 'weekly',
            'recurrence_interval' => '2',
            'occurrence' => '2026-11-17',
        ], HelpdeskCalendarService::SCOPE_SERIES);

        $this->assertSame('2026-11-10', $updated['start_local']->format('Y-m-d'), 'Der Beginn der Serie bleibt bestehen');
        $this->assertSame('08:00', $updated['start_local']->format('H:i'));
        $this->assertSame(2, $updated['recurrence_interval']);
        $this->assertNull($updated['recurrence_until']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM helpdesk_calendar_events')->fetchColumn(), 'Abweichende Einzeltermine entfallen');

        $this->assertSame(['2026-11-10', '2026-11-24'], array_map(
            static fn (array $e): string => $e['occurrence'],
            $this->calendar()->eventsIn(['start' => '2026-11-01', 'end' => '2026-12-01'])
        ));
    }

    public function testCancelingSingleOccurrenceKeepsSeries(): void
    {
        $series = $this->calendar()->create([
            'title' => 'Wartung Serverraum',
            'date' => '2026-11-10',
            'start_time' => '09:00',
            'recurrence' => 'weekly',
        ]);
        $id = (int) $series['id'];

        $this->calendar()->delete($id, HelpdeskCalendarService::SCOPE_OCCURRENCE, '2026-11-17');
        $days = array_map(static fn (array $e): string => $e['occurrence'], $this->calendar()->eventsIn(['start' => '2026-11-09', 'end' => '2026-11-24']));
        $this->assertSame(['2026-11-10'], $days, 'Abgesagter Termin verschwindet, die Serie bleibt');

        // Erneut absagen: der vorhandene Einzeltermin wird zur Ausblendemarke.
        $this->calendar()->delete($id, HelpdeskCalendarService::SCOPE_OCCURRENCE, '2026-11-17');
        $markers = (int) $this->pdo->query('SELECT COUNT(*) FROM helpdesk_calendar_events WHERE series_canceled = 1')->fetchColumn();
        $this->assertSame(1, $markers);

        $this->calendar()->delete($id, HelpdeskCalendarService::SCOPE_SERIES);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM helpdesk_calendar_events')->fetchColumn(), 'Ganze Serie löscht auch die Ausblendemarken');
    }

    public function testSeriesEdgeCasesFromReview(): void
    {
        // Tägliche ganztägige Serie: das exklusive Ende um 00:00 zählt nicht als zweiter Tag.
        $daily = $this->calendar()->create(['title' => 'Bereitschaft', 'date' => '2026-11-10', 'all_day' => '1', 'recurrence' => 'daily']);
        $this->assertSame('daily', $daily['recurrence']);

        $series = $this->calendar()->create([
            'title' => 'Wartung Serverraum',
            'date' => '2026-11-10',
            'start_time' => '09:00',
            'recurrence' => 'weekly',
            'recurrence_until' => '2026-11-24',
        ]);
        $id = (int) $series['id'];
        $count = fn (): int => (int) $this->pdo->query('SELECT COUNT(*) FROM helpdesk_calendar_events WHERE series_parent_id = ' . $id)->fetchColumn();

        // Einzelnes Vorkommen über das Serienende hinaus verschieben: die Wiederholung wird nicht geprüft.
        $moved = $this->calendar()->update($id, [
            'title' => 'Wartung Serverraum', 'date' => '2026-11-26', 'start_time' => '09:00', 'occurrence' => '2026-11-24',
        ], HelpdeskCalendarService::SCOPE_OCCURRENCE);
        $this->assertSame('2026-11-24', $moved['series_date']);

        // Veralteter Aufruf über den Serientermin ändert den vorhandenen Einzeltermin statt einen zweiten anzulegen.
        $this->calendar()->update($id, [
            'title' => 'Wartung (verschoben)', 'date' => '2026-11-25', 'start_time' => '09:00', 'occurrence' => '2026-11-24',
        ], HelpdeskCalendarService::SCOPE_OCCURRENCE);
        $this->assertSame(1, $count());

        // Tage ohne Vorkommen werden abgelehnt (keine verwaisten Einzeltermine oder Ausblendemarken).
        $this->assertThrows(ValidationException::class, fn () => $this->calendar()->update($id, [
            'title' => 'x', 'date' => '2026-11-11', 'start_time' => '09:00', 'occurrence' => '2026-11-11',
        ], HelpdeskCalendarService::SCOPE_OCCURRENCE));
        $this->assertThrows(ValidationException::class, fn () => $this->calendar()->delete($id, HelpdeskCalendarService::SCOPE_OCCURRENCE, '2026-12-01'));
        $this->assertSame(1, $count());

        // Serie über ein späteres Vorkommen verkürzen: das Serienende wird gegen den ersten Termin geprüft.
        $updated = $this->calendar()->update($id, [
            'title' => 'Wartung Serverraum', 'date' => '2026-11-17', 'start_time' => '09:00',
            'recurrence' => 'weekly', 'recurrence_until' => '2026-11-12', 'occurrence' => '2026-11-17',
        ], HelpdeskCalendarService::SCOPE_SERIES);
        $this->assertSame('2026-11-10', $updated['start_local']->format('Y-m-d'));
        $this->assertSame('2026-11-12', $updated['recurrence_until']);

        // Mehrtägiges Vorkommen, das am Serienende beginnt, erscheint auch an den Folgetagen.
        $this->calendar()->create([
            'title' => 'Inventur', 'date' => '2026-10-22', 'end_date' => '2026-10-26', 'start_time' => '08:00', 'end_time' => '16:00',
            'recurrence' => 'weekly', 'recurrence_until' => '2026-10-29',
        ]);
        $titles = array_map(static fn (array $e): string => $e['title'], $this->calendar()->eventsIn(['start' => '2026-11-01', 'end' => '2026-11-02']));
        $this->assertContains('Inventur', $titles);
    }

    public function testPortalUserCannotWrite(): void
    {
        $this->loginAs($this->agentId, 'portal');
        $this->assertThrows(ForbiddenException::class, fn () => $this->calendar()->create(['title' => 'X', 'date' => '2026-07-14', 'start_time' => '09:00']));
    }

    public function testChecklistIsCreatedWithEventAndConfirmedByAgent(): void
    {
        $event = $this->calendar()->create([
            'title' => 'Serverwartung', 'date' => '2026-07-14', 'start_time' => '09:00',
            'checklist_submitted' => '1',
            'checklist' => [['label' => 'Backup geprüft'], ['label' => '  '], ['label' => 'Dienste neu gestartet']],
        ]);
        $id = (int) $event['id'];
        $items = $this->calendar()->checklistItems($event);
        $this->assertSame(['Backup geprüft', 'Dienste neu gestartet'], array_column($items, 'label'), 'Leere Punkte entfallen');

        $before = new \DateTimeImmutable('2026-07-13 10:00:00', new \DateTimeZone('UTC'));
        $this->assertFalse($this->calendar()->checklist($event, '', $before)['can_confirm'], 'Zukünftige Termine sind gesperrt');
        $this->assertThrows(ValidationException::class, fn () => $this->calendar()->confirmChecklistItem($id, (int) $items[0]['id'], '', true, $before));

        $onDay = new \DateTimeImmutable('2026-07-14 08:00:00', new \DateTimeZone('UTC'));
        $this->calendar()->confirmChecklistItem($id, (int) $items[0]['id'], '', true, $onDay);
        $status = $this->calendar()->checklist($event, '', $onDay);
        $this->assertSame(1, $status['confirmed']);
        $this->assertSame('Agent ' . $this->agentId, $status['items'][0]['confirmed_by_name']);
        $this->assertSame('14.07.2026 10:00', $status['items'][0]['confirmed_local']->format('d.m.Y H:i'));

        $row = $this->calendar()->eventsIn(HelpdeskCalendarService::range('tag', '2026-07-14'))[0];
        $this->assertSame(2, $row['checklist_total']);
        $this->assertSame(1, $row['checklist_confirmed']);

        // Lesende Benutzer sehen den Stand, dürfen aber nicht abhaken
        $this->loginAs($this->agentId, 'portal');
        $this->assertFalse($this->calendar()->checklist($event, '', $onDay)['can_confirm']);
        $this->assertThrows(ForbiddenException::class, fn () => $this->calendar()->confirmChecklistItem($id, (int) $items[1]['id'], '', true, $onDay));
        $this->loginAs($this->agentId, 'helpdesk_agent');

        $this->calendar()->confirmChecklistItem($id, (int) $items[0]['id'], '', false, $onDay);
        $this->assertSame(0, $this->calendar()->checklist($event, '', $onDay)['confirmed'], 'Bestätigung zurückgenommen');
    }

    public function testChecklistUpdateKeepsConfirmationsOfRemainingItems(): void
    {
        $event = $this->calendar()->create([
            'title' => 'Rollout', 'date' => '2026-07-14', 'start_time' => '09:00',
            'checklist_submitted' => '1', 'checklist' => [['label' => 'A'], ['label' => 'B']],
        ]);
        $id = (int) $event['id'];
        [$a, $b] = $this->calendar()->checklistItems($event);
        $now = new \DateTimeImmutable('2026-07-14 12:00:00', new \DateTimeZone('UTC'));
        $this->calendar()->confirmChecklistItem($id, (int) $a['id'], '', true, $now);

        $base = ['title' => 'Rollout', 'date' => '2026-07-14', 'start_time' => '09:00'];
        $this->calendar()->update($id, $base);
        $this->assertCount(2, $this->calendar()->checklistItems($event), 'Ohne Checklistenfelder bleibt die Checkliste unverändert');

        $this->calendar()->update($id, $base + ['checklist_submitted' => '1', 'checklist' => [
            ['id' => (string) $a['id'], 'label' => 'A (geändert)'], ['label' => 'C'],
        ]]);
        $status = $this->calendar()->checklist($event, '', $now);
        $this->assertSame(['A (geändert)', 'C'], array_column($status['items'], 'label'));
        $this->assertSame(1, $status['confirmed'], 'Geänderter Punkt behält seine Bestätigung');
        $this->assertFalse(in_array((int) $b['id'], array_map(static fn (array $i): int => (int) $i['id'], $status['items']), true));

        $tooMany = array_fill(0, HelpdeskCalendarService::MAX_CHECKLIST_ITEMS + 1, ['label' => 'X']);
        $this->assertThrows(ValidationException::class, fn () => $this->calendar()->update($id, $base + ['checklist_submitted' => '1', 'checklist' => $tooMany]));
    }

    public function testSingleEventConvertedToSeriesKeepsConfirmations(): void
    {
        $event = $this->calendar()->create([
            'title' => 'Patchday', 'date' => '2026-07-14', 'start_time' => '09:00',
            'checklist_submitted' => '1', 'checklist' => [['label' => 'Updates eingespielt']],
        ]);
        $id = (int) $event['id'];
        $item = (int) $this->calendar()->checklistItems($event)[0]['id'];
        $now = new \DateTimeImmutable('2026-07-21 12:00:00', new \DateTimeZone('UTC'));
        $this->calendar()->confirmChecklistItem($id, $item, '', true, $now);

        $series = $this->calendar()->update($id, ['title' => 'Patchday', 'date' => '2026-07-14', 'start_time' => '09:00', 'recurrence' => 'weekly']);
        $first = $this->calendar()->checklist($series, '2026-07-14', $now);
        $this->assertSame(1, $first['confirmed'], 'Bestätigung gilt für den ersten Termin der Serie weiter');
        $this->assertSame('Agent ' . $this->agentId, $first['items'][0]['confirmed_by_name']);
        $this->assertSame(0, $this->calendar()->checklist($series, '2026-07-21', $now)['confirmed']);
    }

    public function testSeriesChecklistIsConfirmedPerOccurrence(): void
    {
        $series = $this->calendar()->create([
            'title' => 'Wochencheck', 'date' => '2026-11-10', 'start_time' => '09:00', 'recurrence' => 'weekly',
            'checklist_submitted' => '1', 'checklist' => [['label' => 'Logs gesichtet']],
        ]);
        $id = (int) $series['id'];
        $item = (int) $this->calendar()->checklistItems($series)[0]['id'];
        $now = new \DateTimeImmutable('2026-11-17 12:00:00', new \DateTimeZone('UTC'));

        $this->calendar()->confirmChecklistItem($id, $item, '2026-11-17', true, $now);
        $this->assertSame(1, $this->calendar()->checklist($series, '2026-11-17', $now)['confirmed']);
        $this->assertSame(0, $this->calendar()->checklist($series, '2026-11-10', $now)['confirmed'], 'Gilt nur für das aufgerufene Vorkommen');
        $this->assertTrue($this->calendar()->checklist($series, '2026-11-24', $now)['is_future']);
        $this->assertThrows(ValidationException::class, fn () => $this->calendar()->confirmChecklistItem($id, $item, '2026-11-24', true, $now));
        $this->assertThrows(ValidationException::class, fn () => $this->calendar()->confirmChecklistItem($id, $item, '2026-11-18', true, $now));

        $counts = [];
        foreach ($this->calendar()->eventsIn(['start' => '2026-11-09', 'end' => '2026-11-25']) as $e) {
            $counts[$e['occurrence']] = [$e['checklist_confirmed'], $e['checklist_total']];
        }
        $this->assertSame(['2026-11-10' => [0, 1], '2026-11-17' => [1, 1], '2026-11-24' => [0, 1]], $counts);

        // Ein abweichender Einzeltermin behält die Bestätigung seines Termintags
        $child = $this->calendar()->update($id, [
            'title' => 'Wochencheck', 'date' => '2026-11-17', 'start_time' => '15:00', 'occurrence' => '2026-11-17',
        ], HelpdeskCalendarService::SCOPE_OCCURRENCE);
        $this->assertSame(1, $this->calendar()->checklist($child, '', $now)['confirmed']);
    }

    public function testCreateFromTicketLinksTicketAndDefaultsTitle(): void
    {
        $ticket = $this->createTicket('Umzug Arbeitsplatz', 'Bitte am 20.11.2026 um 14 Uhr den PC umziehen.');

        $event = $this->calendar()->createFromTicket((int) $ticket['id'], ['date' => '2026-11-20', 'start_time' => '14:00']);

        $this->assertSame((int) $ticket['id'], (int) $event['ticket_id']);
        $this->assertSame($ticket['number'] . ' · Umzug Arbeitsplatz', $event['title']);
        $this->assertSame($ticket['number'], $event['ticket_number']);
        $this->assertCount(1, $this->calendar()->forTicket((int) $ticket['id']));
    }

    public function testTodayReturnsEventsOfLocalDay(): void
    {
        $tz = $this->calendar()->timezone();
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable($today, $tz))->modify('+1 day')->format('Y-m-d');
        $a = $this->calendar()->create(['title' => 'Heute', 'date' => $today, 'start_time' => '23:00', 'end_time' => '23:30']);
        $this->calendar()->create(['title' => 'Morgen', 'date' => $tomorrow, 'start_time' => '00:30']);

        $titles = array_map(static fn (array $e): string => (string) $e['title'], $this->calendar()->today());

        $this->assertContains('Heute', $titles);
        $this->assertFalse(in_array('Morgen', $titles, true));
        $this->assertSame((int) $a['id'], (int) $this->calendar()->today()[array_search('Heute', $titles, true)]['id']);
    }

    public function testSuggestionsUseAiEndpointWithTicketReferenceDate(): void
    {
        $transport = new class implements AiTransportInterface {
            /** @var list<array<string,mixed>> */
            public array $payloads = [];

            public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
            {
                $this->payloads[] = (array) json_decode((string) $body, true);
                $content = '{"termine":[{"datum":"2026-11-20","beginn":"14:00","beschreibung":"Umzug","textstelle":"am 20.11. um 14 Uhr"},{"datum":"2026-11-23","beginn":null,"beschreibung":"Rückruf","textstelle":"Montag darauf"}]}';

                return ['status' => 200, 'body' => (string) json_encode(['choices' => [['message' => ['content' => $content]]]]), 'error' => null];
            }
        };
        $settings = $this->c->get(SettingsService::class);
        $settings->set('helpdesk.ai.enabled', '1');
        $settings->set('helpdesk.ai.url', 'http://ki.test/v1');
        $settings->set('helpdesk.ai.model', 'test-model');
        $ai = new AiSettingsService($settings, $this->c->get(Config::class), $this->c->get(Secret::class), $this->c->get(AuditLogService::class), $this->c->get(CurrentUser::class), $transport);
        $service = new CalendarDateSuggestionService($ai, $transport, 'Europe/Berlin');

        $result = $service->suggest(['subject' => 'Umzug', 'description' => 'Bitte am 20.11. um 14 Uhr, Rückruf am Montag darauf.', 'created_at' => '2026-11-10 08:00:00']);

        $this->assertTrue($result['available']);
        $this->assertCount(2, $result['suggestions']);
        $this->assertStringContains('Bitte das zutreffende Datum wählen', $result['message']);
        $last = end($transport->payloads);
        $this->assertSame('test-model', $last['model'] ?? null);
        $this->assertStringContains('Dienstag, 2026-11-10', (string) ($last['messages'][1]['content'] ?? ''));

        $settings->set('helpdesk.ai.enabled', '0');
        $off = $service->suggest(['subject' => 'x', 'description' => 'y']);
        $this->assertFalse($off['available']);
        $this->assertSame([], $off['suggestions']);
    }

    /** @return array<string,mixed> */
    private function createTicket(string $subject, string $description): array
    {
        $employee = $this->c->get(EmployeeRepository::class)->create([
            'first_name' => 'Max', 'last_name' => 'Melder', 'display_name' => 'Max Melder',
            'email' => 'max-cal@example.test', 'department' => 'Vertrieb', 'source' => 'manual', 'is_active' => 1,
        ]);
        $type = $this->c->get(TicketMasterDataRepository::class)->typeByCode('incident');

        return $this->c->get(TicketService::class)->create([
            'subject' => $subject,
            'description' => $description,
            'ticket_type_id' => $type['id'],
            'requester_employee_id' => $employee,
            'impact' => 1,
            'urgency' => 2,
        ], 'web');
    }
}
