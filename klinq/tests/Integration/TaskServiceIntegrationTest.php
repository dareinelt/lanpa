<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\EmployeeRepository;
use App\Repositories\TaskCategoryRepository;
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

    private function categories(): TaskCategoryRepository
    {
        return $this->c->get(TaskCategoryRepository::class);
    }

    /** ID einer Kategorie aus dem Katalog; legt sie an, falls der Seeder (noch) nicht lief. */
    private function categoryId(string $name): int
    {
        $category = $this->categories()->findByName($name);
        if ($category === null) {
            return $this->categories()->create(['name' => $name, 'sort_order' => 0, 'is_active' => 1]);
        }

        return (int) $category['id'];
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
        // Die Umwandlung schließt das Ticket; nach dem Wiederöffnen greift die Dublettenprüfung.
        $this->c->get(TicketService::class)->changeStatus((int) $ticket['id'], 'in_progress', null, null, null, null, true, null, false);

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

        $done = $this->service()->changeStatus((int) $task['id'], ['status' => 'done']);

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

    public function testConvertWithCategoryShowsCategoryOnTask(): void
    {
        $categoryId = $this->categoryId('Unifi');

        $task = $this->service()->convert((int) $this->createTicket()['id'], ['category_id' => $categoryId]);

        $this->assertSame($categoryId, (int) $task['category_id']);
        $this->assertSame('Unifi', $task['category_name']);
        $this->assertContains('created', array_column($this->service()->events((int) $task['id']), 'type'));
    }

    public function testConvertWithoutCategoryLeavesCategoryEmpty(): void
    {
        $task = $this->service()->convert((int) $this->createTicket()['id'], []);

        $this->assertNull($task['category_id']);
        $this->assertNull($task['category_name']);
    }

    public function testConvertRejectsUnknownCategory(): void
    {
        $this->assertThrows(ValidationException::class, function (): void {
            $this->service()->convert((int) $this->createTicket()['id'], ['category_id' => 999999]);
        }, 'Kategorie aus dem Katalog');
    }

    public function testChangeCategoryRecordsEventAndCanBeRemoved(): void
    {
        $task = $this->service()->convert((int) $this->createTicket()['id'], []);
        $this->service()->claim((int) $task['id']);

        $changed = $this->service()->changeCategory((int) $task['id'], ['category_id' => $this->categoryId('Arbeitsplatz')]);
        $this->assertSame('Arbeitsplatz', $changed['category_name']);
        $events = $this->service()->events((int) $task['id']);
        $this->assertContains('category_changed', array_column($events, 'type'));
        $this->assertSame('Arbeitsplatz', end($events)['new_value']);

        $cleared = $this->service()->changeCategory((int) $task['id'], ['category_id' => '']);
        $this->assertNull($cleared['category_id']);
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

    public function testCommentsAreListedChronologically(): void
    {
        $task = $this->service()->convert((int) $this->createTicket()['id'], []);
        $this->service()->claim((int) $task['id']);

        $this->service()->addComment((int) $task['id'], ['body' => 'Erster Eintrag']);
        $this->service()->addComment((int) $task['id'], ['body' => 'Zweiter Eintrag']);
        $this->loginAs($this->agent2Id, 'helpdesk_agent');
        $this->service()->addComment((int) $task['id'], ['body' => 'Dritter Eintrag']);

        $comments = $this->service()->comments((int) $task['id']);

        $this->assertCount(3, $comments);
        $this->assertSame(['Erster Eintrag', 'Zweiter Eintrag', 'Dritter Eintrag'], array_column($comments, 'body'));
        $this->assertSame($this->agentId, (int) $comments[0]['author_user_id']);
        $this->assertSame($this->agent2Id, (int) $comments[2]['author_user_id']);
    }

    public function testCommentRequiresText(): void
    {
        $task = $this->service()->convert((int) $this->createTicket()['id'], []);

        $this->assertThrows(ValidationException::class, function () use ($task): void {
            $this->service()->addComment((int) $task['id'], ['body' => '   ']);
        });
        $this->assertSame([], $this->service()->comments((int) $task['id']));
    }

    public function testCommentRequiresTaskPermission(): void
    {
        $task = $this->service()->convert((int) $this->createTicket()['id'], []);
        $this->loginAs($this->createUser('portal-user', 'employee'), 'employee');

        $this->assertThrows(ForbiddenException::class, function () use ($task): void {
            $this->service()->addComment((int) $task['id'], ['body' => 'Unbefugt']);
        });
    }

    public function testCommentsCanBePinnedAndUnpinned(): void
    {
        $task = $this->service()->convert((int) $this->createTicket()['id'], []);
        $this->service()->addComment((int) $task['id'], ['body' => 'Erster Eintrag']);
        $this->service()->addComment((int) $task['id'], ['body' => 'Zweiter Eintrag']);
        $comments = $this->service()->comments((int) $task['id']);
        $secondId = (int) $comments[1]['id'];

        $this->service()->setCommentPinned((int) $task['id'], $secondId, true);

        $pinned = $this->service()->comments((int) $task['id']);
        $this->assertSame(['Zweiter Eintrag', 'Erster Eintrag'], array_column($pinned, 'body'));
        $this->assertSame(1, (int) $pinned[0]['is_pinned']);
        $this->assertTrue($pinned[0]['pinned_at'] !== null, 'Anheftung muss einen Zeitstempel erhalten');
        $this->assertSame(0, (int) $pinned[1]['is_pinned']);

        $this->service()->setCommentPinned((int) $task['id'], $secondId, false);

        $unpinned = $this->service()->comments((int) $task['id']);
        $this->assertSame(['Erster Eintrag', 'Zweiter Eintrag'], array_column($unpinned, 'body'));
        $this->assertSame(0, (int) $unpinned[1]['is_pinned']);
        $this->assertTrue($unpinned[1]['pinned_at'] === null, 'Gelöste Anheftung darf keinen Zeitstempel behalten');
    }

    public function testPinningRequiresTaskPermission(): void
    {
        $task = $this->service()->convert((int) $this->createTicket()['id'], []);
        $this->service()->addComment((int) $task['id'], ['body' => 'Erster Eintrag']);
        $commentId = (int) $this->service()->comments((int) $task['id'])[0]['id'];
        $this->loginAs($this->createUser('portal-user', 'employee'), 'employee');

        $this->assertThrows(ForbiddenException::class, function () use ($task, $commentId): void {
            $this->service()->setCommentPinned((int) $task['id'], $commentId, true);
        });
    }

    public function testPinningRejectsForeignComment(): void
    {
        $first = $this->service()->convert((int) $this->createTicket()['id'], []);
        $second = $this->service()->convert((int) $this->createTicket()['id'], []);
        $this->service()->addComment((int) $first['id'], ['body' => 'Gehört zur ersten Aufgabe']);
        $foreignId = (int) $this->service()->comments((int) $first['id'])[0]['id'];

        $this->assertThrows(ValidationException::class, function () use ($second, $foreignId): void {
            $this->service()->setCommentPinned((int) $second['id'], $foreignId, true);
        });
        $this->assertSame(0, (int) $this->service()->comments((int) $first['id'])[0]['is_pinned']);
    }

    public function testTaskNumbersUseTheirOwnSequence(): void
    {
        $first = $this->service()->convert((int) $this->createTicket()['id'], []);
        $second = $this->service()->convert((int) $this->createTicket()['id'], []);

        $this->assertStringContains('AUF-', (string) $first['number']);
        $this->assertTrue($first['number'] !== $second['number'], 'Aufgabennummern müssen eindeutig sein');
    }
}
