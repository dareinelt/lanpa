<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\EmployeeRepository;
use App\Repositories\TicketCommentRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRelationRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketTagRepository;
use App\Repositories\TicketWorklogRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\TicketMergeService;
use App\Services\Helpdesk\TicketService;
use Tests\Support\DatabaseTestCase;

/** Kernabläufe des Ticketsystems gegen die Test-Datenbank (Anlage, Workflow, SLA, Zuweisung, Kommentare, Merge). */
final class TicketServiceIntegrationTest extends DatabaseTestCase
{
    private int $agentId;
    private int $agent2Id;
    private int $portalUserId;
    private int $requesterEmployeeId;
    private int $otherEmployeeId;

    protected function setUp(): void
    {
        parent::setUp();
        $employees = $this->c->get(EmployeeRepository::class);
        $this->requesterEmployeeId = $employees->create(['first_name' => 'Max', 'last_name' => 'Melder', 'display_name' => 'Max Melder', 'email' => 'max@example.test', 'department' => 'Vertrieb', 'source' => 'manual', 'is_active' => 1]);
        $this->otherEmployeeId = $employees->create(['first_name' => 'Olga', 'last_name' => 'Other', 'display_name' => 'Olga Other', 'email' => 'olga@example.test', 'source' => 'manual', 'is_active' => 1]);
        $this->agentId = $this->createUser('hd-agent', 'helpdesk_agent', null);
        $this->agent2Id = $this->createUser('hd-agent2', 'helpdesk_agent', null);
        $this->portalUserId = $this->createUser('portal-user', 'lager', $this->requesterEmployeeId);
        $this->loginAs($this->agentId, 'helpdesk_agent');
    }

    private function createUser(string $username, string $role, ?int $employeeId): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO users (username, display_name, email, role_id, auth_source, employee_id, is_active) SELECT ?, ?, ?, id, 'local', ?, 1 FROM roles WHERE name = ?");
        $stmt->execute([$username, ucfirst($username), $username . '@example.test', $employeeId, $role]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<int,string> $groups */
    private function loginAs(int $userId, string $role, array $groups = []): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => $userId, 'username' => 'u' . $userId, 'display_name' => 'User ' . $userId, 'role' => $role, 'groups' => $groups]);
    }

    private function service(): TicketService
    {
        return $this->c->get(TicketService::class);
    }

    private function tickets(): TicketRepository
    {
        return $this->c->get(TicketRepository::class);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function createTicket(array $overrides = [], string $source = 'web'): array
    {
        $type = $this->c->get(TicketMasterDataRepository::class)->typeByCode('incident');

        return $this->service()->create(array_merge([
            'subject' => 'Notebook startet nicht',
            'description' => 'Nach dem Update bleibt der Bildschirm schwarz.',
            'ticket_type_id' => $type['id'],
            'requester_employee_id' => $this->requesterEmployeeId,
            'impact' => 1,
            'urgency' => 2,
        ], $overrides), $source);
    }

    // ------------------------------------------------------------------ Anlage

    public function testCreateAssignsNumberPrioritySlaAndEvents(): void
    {
        $ticket = $this->createTicket(['tags' => 'Notebook, Boot']);

        $this->assertMatches('/^HD-\d{4}-\d{6}$/', (string) $ticket['number']);
        $this->assertSame('new', $ticket['status_code']);
        $this->assertSame('normal', $ticket['priority_code'], 'Matrix Impact 1 × Dringlichkeit 2 → Stufe 2');
        $this->assertNotNull($ticket['sla_id'], 'Standard-SLA zugeordnet');
        $this->assertNotNull($ticket['response_due_at']);
        $this->assertNotNull($ticket['resolution_due_at']);
        $this->assertTrue($ticket['resolution_due_at'] > $ticket['response_due_at']);
        $this->assertSame('Max Melder', $ticket['requester_name']);
        $this->assertSame('boot,notebook', implode(',', array_map('strtolower', explode(',', (string) $ticket['tag_names']))));

        $events = $this->service()->timeline((int) $ticket['id']);
        $this->assertTrue(count($events) >= 1);
        $this->assertSame('created', $events[0]['type']);

        // Zweites Ticket → laufende Nummer +1
        $second = $this->createTicket();
        $this->assertSame((int) substr((string) $ticket['number'], -6) + 1, (int) substr((string) $second['number'], -6));
    }

    public function testCreateValidatesRequiredFields(): void
    {
        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->create(['subject' => 'x'], 'web'));
        $errors = $e->errors();
        $this->assertTrue(isset($errors['subject']));
        $this->assertTrue(isset($errors['description']));
    }

    public function testCriticalPriorityGetsCriticalSla(): void
    {
        $ticket = $this->createTicket(['impact' => 3, 'urgency' => 3]);
        $this->assertSame('critical', $ticket['priority_code']);
        $this->assertSame('Kritisch', $ticket['sla_name']);
        // 15 Minuten Reaktionszeit (24/7-SLA) ab Anlage
        $diff = (strtotime((string) $ticket['response_due_at']) - strtotime((string) $ticket['created_at'])) / 60;
        $this->assertEquals(15, $diff);
    }

    // ------------------------------------------------------------------ Workflow

    public function testStatusWorkflowWithSlaPauseAndResolution(): void
    {
        $ticket = $this->createTicket();
        $id = (int) $ticket['id'];

        $ticket = $this->service()->changeStatus($id, 'in_progress');
        $this->assertSame('in_progress', $ticket['status_code']);

        $ticket = $this->service()->changeStatus($id, 'waiting_user', 'Bitte Screenshot senden');
        $this->assertSame('waiting_user', $ticket['status_code']);
        $this->assertNotNull($ticket['sla_paused_at'], 'SLA pausiert im Wartestatus');

        $ticket = $this->service()->changeStatus($id, 'in_progress');
        $this->assertNull($ticket['sla_paused_at'], 'SLA läuft wieder');

        // resolved ohne Lösung → Fehler
        $this->assertThrows(ValidationException::class, fn () => $this->service()->changeStatus($id, 'resolved'));

        $ticket = $this->service()->changeStatus($id, 'resolved', null, 'BIOS zurückgesetzt, Gerät startet wieder.');
        $this->assertSame('resolved', $ticket['status_code']);
        $this->assertNotNull($ticket['resolved_at']);
        $this->assertSame('met', $ticket['sla_resolution_state']);

        $ticket = $this->service()->changeStatus($id, 'closed');
        $this->assertSame('closed', $ticket['status_code']);
        $this->assertNotNull($ticket['closed_at']);

        // Ungültiger Übergang closed → waiting_user
        $this->assertThrows(ValidationException::class, fn () => $this->service()->changeStatus($id, 'waiting_user'), 'nicht erlaubt');

        // Wiedereröffnung zählt hoch
        $ticket = $this->service()->changeStatus($id, 'in_progress', 'Problem tritt erneut auf');
        $this->assertSame('in_progress', $ticket['status_code']);
        $this->assertSame(1, (int) $ticket['reopen_count']);
        $this->assertNull($ticket['resolved_at']);
    }

    public function testCancelRequiresReason(): void
    {
        $ticket = $this->createTicket();
        $this->assertThrows(ValidationException::class, fn () => $this->service()->changeStatus((int) $ticket['id'], 'cancelled'));
        $ticket = $this->service()->changeStatus((int) $ticket['id'], 'cancelled', null, null, 'Doppelt gemeldet');
        $this->assertSame('cancelled', $ticket['status_code']);
    }

    public function testOptimisticLockingDetectsConcurrentUpdate(): void
    {
        $ticket = $this->createTicket();
        $id = (int) $ticket['id'];
        $version = (int) $ticket['version'];
        $this->service()->update($id, ['subject' => 'Geändert A', 'description' => $ticket['description'], 'ticket_type_id' => $ticket['ticket_type_id']], $version);
        $this->assertThrows(ConflictException::class, fn () => $this->service()->update($id, ['subject' => 'Geändert B', 'description' => $ticket['description'], 'ticket_type_id' => $ticket['ticket_type_id']], $version));
    }

    // ------------------------------------------------------------------ Zuweisung

    public function testAssignAndTakeOverSetStatusOpen(): void
    {
        $ticket = $this->createTicket();
        $id = (int) $ticket['id'];
        $group = $this->c->get(TicketMasterDataRepository::class)->groupByName('Client Support');

        $ticket = $this->service()->assign($id, $this->agent2Id, (int) $group['id']);
        $this->assertSame($this->agent2Id, (int) $ticket['assignee_user_id']);
        $this->assertSame('Client Support', $ticket['group_name']);
        $this->assertSame('open', $ticket['status_code'], 'Zuweisung hebt „neu“ auf „offen“');

        $ticket = $this->service()->takeOver($id);
        $this->assertSame($this->agentId, (int) $ticket['assignee_user_id']);

        $this->assertThrows(ValidationException::class, fn () => $this->service()->assign($id, 999999, null));
    }

    // ------------------------------------------------------------------ Kommentare

    public function testCommentsVisibilityAndAutoStatusChange(): void
    {
        $ticket = $this->createTicket();
        $id = (int) $ticket['id'];
        $this->service()->changeStatus($id, 'in_progress');

        $this->service()->addComment($id, 'Interne Notiz: Gerät ist bekannt.', 'internal');
        $comment = $this->service()->addComment($id, 'Wir prüfen das.', 'public');
        $this->assertSame('public', $comment['type']);
        $ticket = $this->service()->get($id);
        $this->assertNotNull($ticket['first_response_at'], 'Erste öffentliche Agentenantwort setzt first_response_at');
        $this->assertSame('met', $ticket['sla_response_state']);

        $this->service()->changeStatus($id, 'waiting_user');

        // Portalnutzer sieht nur öffentliche Kommentare, Antwort setzt Status zurück
        $this->loginAs($this->portalUserId, 'lager');
        $visible = $this->service()->comments($this->service()->getVisible($id));
        $this->assertCount(1, $visible);
        $this->service()->addComment($id, 'Screenshot anbei.', 'public', 'portal');
        $ticket = $this->service()->get($id);
        $this->assertSame('in_progress', $ticket['status_code']);

        $this->loginAs($this->agentId, 'helpdesk_agent');
        $this->assertCount(3, $this->service()->comments($this->service()->get($id)));
        $this->assertSame(2, $this->c->get(TicketCommentRepository::class)->countForTicket($id, 'public'));
    }

    // ------------------------------------------------------------------ Portal-Sichtbarkeit

    public function testPortalUserSeesOnlyOwnTickets(): void
    {
        $own = $this->createTicket();
        $foreign = $this->createTicket(['requester_employee_id' => $this->otherEmployeeId]);

        $this->loginAs($this->portalUserId, 'lager');
        $this->assertTrue($this->service()->canView($this->service()->get((int) $own['id'])));
        $this->assertFalse($this->service()->canView($this->service()->get((int) $foreign['id'])));
        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->getVisible((int) $foreign['id']));

        $mine = $this->tickets()->search(['visible_user_id' => $this->portalUserId, 'view' => 'portal_open'], 'created_at', 'desc', 50, 0);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $mine);
        $this->assertContains((int) $own['id'], $ids);
        $this->assertFalse(in_array((int) $foreign['id'], $ids, true));

        // Portalnutzer darf kein Ticket schließen
        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->changeStatus((int) $own['id'], 'cancelled', null, null, 'x'));
    }

    public function testPortalCreateIgnoresAgentOnlyFields(): void
    {
        $this->loginAs($this->portalUserId, 'lager');
        $ticket = $this->service()->create([
            'subject' => 'Drucker druckt nicht',
            'description' => 'Fehlermeldung Papierstau, aber kein Papier eingeklemmt.',
            'impact' => 1,
            'urgency' => 1,
            'assignee_user_id' => $this->agentId,
            'priority_id' => 4,
        ], 'portal');
        $this->assertSame('portal', $ticket['source']);
        $this->assertSame($this->requesterEmployeeId, (int) $ticket['requester_employee_id'], 'Melder aus Benutzerkonto');
        $this->assertSame($this->portalUserId, (int) $ticket['requester_user_id']);
        $this->assertNull($ticket['assignee_user_id'], 'Bearbeiter nicht vom Portal setzbar');
        $this->assertSame('low', $ticket['priority_code'], 'Priorität aus Matrix, nicht aus Eingabe');
    }

    // ------------------------------------------------------------------ Tags / Worklog / Beziehungen

    public function testTagsWorklogAndRelations(): void
    {
        $a = $this->createTicket();
        $b = $this->createTicket(['subject' => 'Zweites Notebook']);
        $idA = (int) $a['id'];

        $this->service()->setTags($idA, 'vip, hardware, VIP');
        $names = array_map(static fn (array $t): string => (string) $t['name'], $this->c->get(TicketTagRepository::class)->forTicket($idA));
        $this->assertCount(2, $names);

        $log = $this->service()->addWorklog($idA, ['minutes' => 45, 'activity' => 'analysis', 'note' => 'Logs geprüft']);
        $this->assertSame(45, (int) $log['minutes']);
        $this->assertSame(45, $this->c->get(TicketWorklogRepository::class)->totalMinutes($idA));
        $this->assertThrows(ValidationException::class, fn () => $this->service()->addWorklog($idA, ['minutes' => 0, 'activity' => 'analysis']));

        $this->service()->addRelation($idA, (string) $b['number'], 'related');
        $relations = $this->c->get(TicketRelationRepository::class)->forTicket($idA);
        $this->assertCount(1, $relations);
        $this->assertThrows(ValidationException::class, fn () => $this->service()->addRelation($idA, (string) $a['number'], 'related'));
    }

    // ------------------------------------------------------------------ Zusammenführen

    public function testMergeMovesCommentsAndCancelsSource(): void
    {
        $source = $this->createTicket(['subject' => 'Duplikat']);
        $target = $this->createTicket(['subject' => 'Original']);
        $this->service()->addComment((int) $source['id'], 'Kommentar am Duplikat', 'public');

        $result = $this->c->get(TicketMergeService::class)->merge((int) $source['id'], (string) $target['number']);
        $this->assertSame((int) $target['id'], (int) $result['id']);

        $sourceAfter = $this->service()->get((int) $source['id']);
        $this->assertSame((int) $target['id'], (int) $sourceAfter['merged_into_ticket_id']);
        $this->assertSame('cancelled', $sourceAfter['status_code'], 'Quelle wird als „Storniert“ abgeschlossen');
        $this->assertSame(1, $this->c->get(TicketCommentRepository::class)->countForTicket((int) $target['id'], 'public'));

        // Zusammengeführtes Ticket ist in Listen ausgeblendet
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->tickets()->search(['view' => 'all'], 'created_at', 'desc', 100, 0));
        $this->assertFalse(in_array((int) $source['id'], $ids, true));
        $this->assertThrows(ValidationException::class, fn () => $this->service()->addComment((int) $source['id'], 'geht nicht mehr', 'public'), 'schreibgeschützt');
    }

    // ------------------------------------------------------------------ Suche / Ansichten

    public function testViewCountsAndSearch(): void
    {
        $t = $this->createTicket(['subject' => 'Einzigartiger Suchbegriff Xylophon']);
        $this->service()->assign((int) $t['id'], $this->agentId, null);

        $counts = $this->tickets()->viewCounts($this->agentId);
        $this->assertTrue($counts['mine_open'] >= 1);
        $this->assertTrue($counts['open'] >= 1);

        $found = $this->tickets()->search(['q' => 'Xylophon'], 'created_at', 'desc', 10, 0);
        $this->assertCount(1, $found);
        $this->assertSame($t['number'], $found[0]['number']);
        $this->assertCount(1, $this->tickets()->quickSearch((string) $t['number'], 5));
    }

    // ------------------------------------------------------------------ Ticketaufruf (viewed)

    public function testRecordViewAddsViewedEventForAgentsOnly(): void
    {
        $ticket = $this->createTicket();
        $id = (int) $ticket['id'];

        // Portalnutzer löst keinen „viewed“-Eintrag aus
        $this->loginAs($this->portalUserId, 'lager');
        $this->service()->recordView($id);
        $this->assertSame([], $this->viewedEvents($id));

        // Agent löst den Eintrag aus
        $this->loginAs($this->agentId, 'helpdesk_agent');
        $this->service()->recordView($id);
        $this->assertCount(1, $this->viewedEvents($id));
    }

    public function testViewedEventsRequireTicketaufrufGroupExceptAdmin(): void
    {
        $ticket = $this->createTicket();
        $id = (int) $ticket['id'];
        $this->service()->recordView($id);

        // Help-Desk-Agent ohne Gruppe „ticketaufruf“ sieht den Aufruf nicht
        $this->loginAs($this->agentId, 'helpdesk_agent');
        $this->assertSame([], $this->viewedEventsIn($this->service()->timelineVisible($id)));

        // Mit Gruppe „ticketaufruf“ wird der Aufruf sichtbar
        $this->loginAs($this->agentId, 'helpdesk_agent', ['ticketaufruf']);
        $this->assertCount(1, $this->viewedEventsIn($this->service()->timelineVisible($id)));

        // Admin sieht den Aufruf unabhängig von Gruppen
        $this->loginAs($this->agentId, 'admin');
        $this->assertCount(1, $this->viewedEventsIn($this->service()->timelineVisible($id)));
    }

    /** @return array<int,array<string,mixed>> */
    private function viewedEvents(int $ticketId): array
    {
        return $this->viewedEventsIn($this->service()->timeline($ticketId));
    }

    /** @param array<int,array<string,mixed>> $events @return array<int,array<string,mixed>> */
    private function viewedEventsIn(array $events): array
    {
        return array_values(array_filter($events, static fn (array $e): bool => ($e['type'] ?? null) === 'viewed'));
    }
}
