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

    public function testPortalUserCannotWrite(): void
    {
        $this->loginAs($this->agentId, 'portal');
        $this->assertThrows(ForbiddenException::class, fn () => $this->calendar()->create(['title' => 'X', 'date' => '2026-07-14', 'start_time' => '09:00']));
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
