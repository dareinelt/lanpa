<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\EmployeeRepository;
use App\Repositories\TaskRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\TaskService;
use App\Services\Helpdesk\TicketService;
use Tests\Support\DatabaseTestCase;

/**
 * Aufgaben im Help Desk gegen die Test-Datenbank: Umwandlung aus einem Ticket (mit Schließung),
 * Annahme durch einen Agenten, Zuweisung über die Aufgabenverteilung und Statuswechsel.
 */
final class TaskServiceIntegrationTest extends DatabaseTestCase
{
    private int $agentId;
    private int $agent2Id;
    private int $requesterEmployeeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requesterEmployeeId = $this->c->get(EmployeeRepository::class)->create([
            'first_name' => 'Max', 'last_name' => 'Melder', 'display_name' => 'Max Melder',
            'email' => 'max@example.test', 'department' => 'Vertrieb', 'source' => 'manual', 'is_active' => 1,
        ]);
        $this->agentId = $this->createUser('hd-agent', 'helpdesk_agent');
        $this->agent2Id = $this->createUser('hd-agent2', 'helpdesk_agent');
        $this->loginAs($this->agentId, 'helpdesk_agent');
    }

    private function createUser(string $username, string $role): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO users (username, display_name, email, role_id, auth_source, is_active) SELECT ?, ?, ?, id, 'local', 1 FROM roles WHERE name = ?");
        $stmt->execute([$username, ucfirst($username), $username . '@example.test', $role]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<int,string> $groups */
    private function loginAs(int $userId, string $role, array $groups = []): void
    {
        $this->c->get(CurrentUser::class)->login([
            'id' => $userId,
            'username' => 'u' . $userId,
            'display_name' => 'Agent ' . $userId,
            'role' => $role,
            'groups' => $groups,
        ]);
    }

    private function service(): TaskService
    {
        return $this->c->get(TaskService::class);
    }

    private function tasks(): TaskRepository
    {
        return $this->c->get(TaskRepository::class);
    }

    /** @return array<string,mixed> */
    private function createTicket(): array
    {
        $type = $this->c->get(TicketMasterDataRepository::class)->typeByCode('incident');

        return $this->c->get(TicketService::class)->create([
            'subject' => 'Notebook startet nicht',
            'description' => 'Nach dem Update bleibt der Bildschirm schwarz.',
            'ticket_type_id' => $type['id'],
            'requester_employee_id' => $this->requesterEmployeeId,
            'impact' => 1,
            'urgency' => 2,
        ], 'web');
    }

    public function testConvertClosesTicketAndLinksTask(): void
    {
        $ticket = $this->createTicket();

        $task = $this->service()->convert((int) $ticket['id'], []);

        $this->assertMatches('/^AUF-\d{4}-\d{6}$/', (string) $task['number']);
        $this->assertSame('open', $task['status']);
        $this->assertSame((int) $ticket['id'], (int) $task['source_ticket_id']);
        $this->assertSame('Notebook startet nicht', $task['subject']);

        $closed = $this->c->get(TicketService::class)->get((int) $ticket['id']);
        $this->assertSame('closed', $closed['status_code']);
        $this->assertNotNull($this->tasks()->findByTicket((int) $ticket['id']));
    }

    public function testConvertRejectsSecondTaskForSameTicket(): void
    {
        $ticket = $this->createTicket();
        $this->service()->convert((int) $ticket['id'], []);

        $this->assertThrows(ValidationException::class, function () use ($ticket): void {
            $this->service()->convert((int) $ticket['id'], []);
        }, 'bereits eine Aufgabe');
    }

    public function testConvertRejectsClosedTicket(): void
    {
        $ticket = $this->createTicket();
        $this->c->get(TicketService::class)->changeStatus((int) $ticket['id'], 'closed', null, 'Erledigt.', null, null, true, null, false);

        $this->assertThrows(ValidationException::class, function () use ($ticket): void {
            $this->service()->convert((int) $ticket['id'], []);
        }, 'Geschlossene');
    }

    public function testClaimAssignsAgentAndAppearsInMine(): void
    {
        $task = $this->service()->convert((int) $this->createTicket()['id'], []);

        $claimed = $this->service()->claim((int) $task['id']);

        $this->assertSame($this->agentId, (int) $claimed['assignee_user_id']);
        $this->assertSame('in_progress', $claimed['status']);
        $this->assertNotNull($claimed['claimed_at']);
        $this->assertSame(1, $this->tasks()->openCountFor($this->agentId));
        $this->assertSame(0, $this->tasks()->openCountFor($this->agent2Id));
    }

    public function testClaimRejectsTaskAssignedToAnotherAgent(): void
    {
        $task = $this->service()->convert((int) $this->createTicket()['id'], []);
        $this->service()->claim((int) $task['id']);

        $this->loginAs($this->agent2Id, 'helpdesk_agent');

        $this->assertThrows(ValidationException::class, function () use ($task): void {
            $this->service()->claim((int) $task['id']);
        }, 'bereits einem Agenten zugewiesen');
    }

    public function testAssignRequiresAssignmentGroup(): void
    {
        $task = $this->service()->convert((int) $this->createTicket()['id'], []);

        $this->assertThrows(ForbiddenException::class, function () use ($task): void {
            $this->service()->assign((int) $task['id'], $this->agent2Id);
        });

        $this->loginAs($this->agentId, 'helpdesk_agent', ['aufgabenverteilung']);
        $assigned = $this->service()->assign((int) $task['id'], $this->agent2Id);

        $this->assertSame($this->agent2Id, (int) $assigned['assignee_user_id']);
        $this->assertSame($this->agentId, (int) $assigned['assigned_by_user_id']);
        $this->assertSame('in_progress', $assigned['status']);
        $this->assertSame(1, $this->tasks()->openCountFor($this->agent2Id));
    }

    public function testUnassignPutsTaskBackIntoAllTasks(): void
    {
        $task = $this->service()->convert((int) $this->createTicket()['id'], []);
        $this->loginAs($this->agentId, 'helpdesk_agent', ['aufgabenverteilung']);
        $this->service()->assign((int) $task['id'], $this->agent2Id);

        $unassigned = $this->service()->assign((int) $task['id'], null);

        $this->assertNull($unassigned['assignee_user_id']);
        $this->assertSame('open', $unassigned['status']);
        $this->assertSame(0, $this->tasks()->openCountFor($this->agent2Id));
    }

    public function testStatusChangeRecordsCompletion(): void
    {
        $task = $this->service()->convert((int) $this->createTicket()['id'], []);
        $this->service()->claim((int) $task['id']);

        $done = $this->service()->changeStatus((int) $task['id'], ['status' => 'done', 'note' => 'Gerät getauscht']);

        $this->assertSame('done', $done['status']);
        $this->assertNotNull($done['completed_at']);
        $this->assertSame($this->agentId, (int) $done['completed_by']);
        $this->assertSame(0, $this->tasks()->openCountFor($this->agentId));
    }

    public function testStatusChangeRequiresAssigneeOrAssignmentGroup(): void
    {
        $task = $this->service()->convert((int) $this->createTicket()['id'], []);
        $this->service()->claim((int) $task['id']);

        $this->loginAs($this->agent2Id, 'helpdesk_agent');

        $this->assertThrows(ForbiddenException::class, function () use ($task): void {
            $this->service()->changeStatus((int) $task['id'], ['status' => 'done']);
        });
    }

    public function testEventsDocumentTheLifecycle(): void
    {
        $task = $this->service()->convert((int) $this->createTicket()['id'], []);
        $this->service()->claim((int) $task['id']);
        $this->service()->changeStatus((int) $task['id'], ['status' => 'done']);

        $types = array_column($this->service()->events((int) $task['id']), 'type');

        $this->assertContains('created', $types);
        $this->assertContains('converted_from_ticket', $types);
        $this->assertContains('claimed', $types);
        $this->assertContains('status_changed', $types);
    }

    public function testTaskNumbersUseTheirOwnSequence(): void
    {
        $first = $this->service()->convert((int) $this->createTicket()['id'], []);
        $second = $this->service()->convert((int) $this->createTicket()['id'], []);

        $this->assertStringContains('AUF-', (string) $first['number']);
        $this->assertTrue($first['number'] !== $second['number'], 'Aufgabennummern müssen eindeutig sein');
    }
}
