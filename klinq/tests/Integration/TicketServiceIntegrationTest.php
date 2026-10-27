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

        $pauseUntil = date('Y-m-d H:i', strtotime('+2 days'));
        $ticket = $this->service()->changeStatus($id, 'waiting_feedback', 'Bitte Screenshot senden', null, null, null, false, $pauseUntil);
        $this->assertSame('waiting_feedback', $ticket['status_code']);
        $this->assertNotNull($ticket['sla_paused_at'], 'SLA pausiert im Wartestatus');
        $this->assertNotNull($ticket['paused_until'], 'Pausenende ist gespeichert');

        $ticket = $this->service()->changeStatus($id, 'in_progress');
        $this->assertNull($ticket['sla_paused_at'], 'SLA läuft wieder');
        $this->assertNull($ticket['paused_until'], 'Pausenende wird beim Fortsetzen geleert');

        // resolved ohne Lösung → Fehler
        $this->assertThrows(ValidationException::class, fn () => $this->service()->changeStatus($id, 'resolved'));

        $ticket = $this->service()->changeStatus($id, 'resolved', null, 'BIOS zurückgesetzt, Gerät startet wieder.');
        $this->assertSame('resolved', $ticket['status_code']);
        $this->assertNotNull($ticket['resolved_at']);
        $this->assertSame('met', $ticket['sla_resolution_state']);

        $ticket = $this->service()->changeStatus($id, 'closed');
        $this->assertSame('closed', $ticket['status_code']);
        $this->assertNotNull($ticket['closed_at']);

        // Ungültiger Übergang closed → waiting_feedback
        $this->assertThrows(ValidationException::class, fn () => $this->service()->changeStatus($id, 'waiting_feedback'), 'nicht erlaubt');

        // Wiedereröffnung zählt hoch
        $ticket = $this->service()->changeStatus($id, 'in_progress', 'Problem tritt erneut auf');
        $this->assertSame('in_progress', $ticket['status_code']);
        $this->assertSame(1, (int) $ticket['reopen_count']);
        $this->assertNull($ticket['resolved_at']);
    }

    public function testCustomPauseReasonFromStatusCatalog(): void
    {
        $masterData = $this->c->get(TicketMasterDataRepository::class);
        $statusId = $masterData->createIn('ticket_statuses', [
            'code' => 'waiting_approval',
            'name' => 'Warten auf Freigabe',
            'category' => 'pending',
            'color' => 'neutral',
            'pauses_sla' => 1,
            'is_active' => 1,
            'sort_order' => 75,
        ]);

        $ticket = $this->createTicket();
        $id = (int) $ticket['id'];
        $this->service()->changeStatus($id, 'in_progress');

        $transitions = $this->service()->availableTransitions($this->service()->get($id));
        $pause = null;
        foreach ($transitions as $transition) {
            if ($transition['code'] === 'waiting_approval') {
                $pause = $transition;
            }
        }
        $this->assertNotNull($pause, 'Katalogstatus erscheint als Pausengrund');
        $this->assertSame(1, (int) $pause['is_pause']);
        $this->assertSame(1, (int) $pause['requires_pause_until']);

        $ticket = $this->service()->changeStatus($id, 'waiting_approval', null, null, null, null, false, date('Y-m-d H:i', strtotime('+2 days')));
        $this->assertSame('waiting_approval', $ticket['status_code']);
        $this->assertNotNull($ticket['paused_until']);

        $ticket = $this->service()->changeStatus($id, 'in_progress');
        $this->assertSame('in_progress', $ticket['status_code']);
        $this->assertNull($ticket['paused_until']);

        $masterData->updateIn('ticket_statuses', $statusId, ['is_active' => 0]);
        $codes = array_column($this->service()->availableTransitions($this->service()->get($id)), 'code');
        $this->assertFalse(in_array('waiting_approval', $codes, true), 'Deaktivierte Pausengründe verschwinden aus der Auswahl');
    }

    public function testCancelRequiresPermissionAndReason(): void
    {
        $ticket = $this->createTicket();
        $id = (int) $ticket['id'];

        // Agenten stornieren standardmäßig nicht – erst die Berechtigungsgruppe gibt frei.
        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->changeStatus($id, 'cancelled', null, null, 'Doppelt gemeldet'));

        $this->loginAs($this->agentId, 'helpdesk_agent', ['ticket_stornieren']);
        $this->assertThrows(ValidationException::class, fn () => $this->service()->changeStatus($id, 'cancelled'));
        $ticket = $this->service()->changeStatus($id, 'cancelled', null, null, 'Doppelt gemeldet');
        $this->assertSame('cancelled', $ticket['status_code']);
    }

    public function testDeleteRequiresPermission(): void
    {
        $ticket = $this->createTicket();
        $id = (int) $ticket['id'];

        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->delete($id));

        $this->loginAs($this->agentId, 'helpdesk_agent', ['ticket_loeschen']);
        $this->service()->delete($id);
        $this->assertNull($this->tickets()->find($id));
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

        $this->service()->changeStatus($id, 'waiting_feedback', null, null, null, null, false, date('Y-m-d H:i', strtotime('+1 day')));

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

    // ------------------------------------------------------------------ Störungsformular (öffentlich)

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function createQuickReport(array $overrides = []): array
    {
        $type = $this->c->get(TicketMasterDataRepository::class)->typeByCode('incident');

        return $this->service()->create(array_merge([
            'subject' => 'Drucker im 2. OG defekt',
            'description' => 'Der Drucker meldet Papierstau, obwohl kein Papier eingeklemmt ist.',
            'ticket_type_id' => $type['id'],
            'reporter' => [
                'employee_id' => $this->requesterEmployeeId,
                'display_name' => 'Max Melder',
                'username' => 'mmelder',
                'host' => 'PC-01',
            ],
        ], $overrides), 'form');
    }

    public function testQuickReportUsesChosenAffectedEmployee(): void
    {
        $ticket = $this->createQuickReport(['affected_employee_id' => $this->otherEmployeeId]);

        $this->assertSame('form', $ticket['source']);
        $this->assertSame($this->requesterEmployeeId, (int) $ticket['requester_employee_id'], 'Melder bleibt der erkannte Anwender');
        $this->assertSame($this->otherEmployeeId, (int) $ticket['affected_employee_id'], 'Auswahl aus „Zusätzlicher Benutzer“');
    }

    public function testQuickReportFallsBackToReporterWithoutAffectedEmployee(): void
    {
        $ticket = $this->createQuickReport();

        $this->assertSame($this->requesterEmployeeId, (int) $ticket['requester_employee_id']);
        $this->assertSame($this->requesterEmployeeId, (int) $ticket['affected_employee_id'], 'ohne Auswahl ist der Melder betroffen');
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

    public function testMergeStoresReasonAndNote(): void
    {
        $source = $this->createTicket(['subject' => 'Duplikat']);
        $target = $this->createTicket(['subject' => 'Original']);

        $result = $this->c->get(TicketMergeService::class)->merge((int) $source['id'], (string) $target['number'], [
            'reason_code' => 'same_topic',
            'reason_note' => '  gleiche   Störung  ',
        ]);

        $sourceAfter = $this->service()->get((int) $source['id']);
        $this->assertSame('Thema identisch – gleiche Störung (' . $target['number'] . ')', $sourceAfter['close_reason']);

        $comment = $this->pdo->query('SELECT body FROM ticket_comments WHERE ticket_id = ' . (int) $target['id'] . " AND type = 'internal' ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringContains('Grund: Thema identisch – gleiche Störung', (string) ($comment['body'] ?? ''));
        $this->assertNull($result['merge_mail'], 'Ohne Auftrag zur Information geht keine E-Mail raus.');
    }

    public function testMergeRejectsUnknownReason(): void
    {
        $source = $this->createTicket(['subject' => 'Duplikat']);
        $target = $this->createTicket(['subject' => 'Original']);

        $this->assertThrows(ValidationException::class, fn () => $this->c->get(TicketMergeService::class)->merge((int) $source['id'], (string) $target['number'], ['reason_code' => 'unbekannt']));

        $this->assertSame('new', $this->service()->get((int) $source['id'])['status_code'], 'Das Quellticket bleibt unverändert.');
    }

    public function testMergeIntoClosedTargetKeepsTargetClosed(): void
    {
        $source = $this->createTicket(['subject' => 'Duplikat']);
        $target = $this->createTicket(['subject' => 'Original']);
        $targetId = (int) $target['id'];
        $this->service()->changeStatus($targetId, 'in_progress');
        $this->service()->changeStatus($targetId, 'resolved', null, 'Gerät getauscht.');
        $this->service()->changeStatus($targetId, 'closed');
        $this->service()->addComment((int) $source['id'], 'Kommentar am Duplikat', 'public');

        $result = $this->c->get(TicketMergeService::class)->merge((int) $source['id'], (string) $target['number']);

        $this->assertSame($targetId, (int) $result['id']);
        $this->assertSame('closed', $this->service()->get($targetId)['status_code'], 'Das geschlossene Zielticket bleibt geschlossen.');
        $this->assertSame($targetId, (int) $this->service()->get((int) $source['id'])['merged_into_ticket_id']);
        $this->assertSame(1, $this->c->get(TicketCommentRepository::class)->countForTicket($targetId, 'public'));
    }

    public function testMergeRejectsCancelledTarget(): void
    {
        $source = $this->createTicket(['subject' => 'Duplikat']);
        $target = $this->createTicket(['subject' => 'Verworfen']);
        $this->loginAs($this->agentId, 'helpdesk_agent', ['ticket_stornieren']);
        $this->service()->changeStatus((int) $target['id'], 'cancelled', null, null, 'Nicht benötigt');

        $this->assertThrows(
            ValidationException::class,
            fn () => $this->c->get(TicketMergeService::class)->merge((int) $source['id'], (string) $target['number']),
        );

        $this->assertSame('new', $this->service()->get((int) $source['id'])['status_code'], 'Das Quellticket bleibt unverändert.');
    }

    public function testMergeComposeDataListsReasons(): void
    {
        $source = $this->createTicket();

        $compose = $this->c->get(TicketMergeService::class)->composeData($this->service()->get((int) $source['id']));

        $this->assertSame('duplicate', $compose['default_reason']);
        $this->assertCount(count(TicketMergeService::REASONS), $compose['reasons']);
        $this->assertSame('Duplikat', $compose['reasons'][0]['name']);
        $this->assertSame('max@example.test', $compose['requester']['email']);
        $this->assertStringContains('{{ziel}}', $compose['body_html']);
    }

    public function testMergeManyMergesAllSourcesIntoChosenTarget(): void
    {
        $sourceA = $this->createTicket(['subject' => 'Duplikat A']);
        $sourceB = $this->createTicket(['subject' => 'Duplikat B']);
        $target = $this->createTicket(['subject' => 'Original']);
        $this->service()->addComment((int) $sourceA['id'], 'Kommentar A', 'public');
        $this->service()->addComment((int) $sourceB['id'], 'Kommentar B', 'public');

        $result = $this->c->get(TicketMergeService::class)->mergeMany([
            (int) $sourceA['id'],
            (int) $sourceB['id'],
            (int) $target['id'],
            (int) $sourceA['id'],
        ], (string) $target['number']);

        $this->assertSame((int) $target['id'], (int) $result['target']['id']);
        $this->assertSame([], $result['errors']);
        $this->assertSame([$sourceA['number'], $sourceB['number']], array_column($result['merged'], 'number'));

        foreach ([$sourceA, $sourceB] as $source) {
            $after = $this->service()->get((int) $source['id']);
            $this->assertSame((int) $target['id'], (int) $after['merged_into_ticket_id']);
            $this->assertSame('cancelled', $after['status_code']);
        }
        $this->assertSame(2, $this->c->get(TicketCommentRepository::class)->countForTicket((int) $target['id'], 'public'));
        $this->assertSame('new', $this->service()->get((int) $target['id'])['status_code'], 'Das Zielticket bleibt offen.');
    }

    public function testMergeManyMergesSingleSourceIntoChosenTarget(): void
    {
        $source = $this->createTicket(['subject' => 'Duplikat']);
        $target = $this->createTicket(['subject' => 'Original']);

        $result = $this->c->get(TicketMergeService::class)->mergeMany(
            [(int) $source['id'], (int) $target['id']],
            (string) $target['number'],
        );

        $this->assertSame([], $result['errors']);
        $this->assertSame([$source['number']], array_column($result['merged'], 'number'));
        $this->assertSame((int) $target['id'], (int) $this->service()->get((int) $source['id'])['merged_into_ticket_id']);
        $this->assertSame('new', $this->service()->get((int) $target['id'])['status_code'], 'Das Zielticket bleibt offen.');
    }

    public function testMergeManyRequiresAnotherTicketBesidesTarget(): void
    {
        $target = $this->createTicket();

        $this->assertThrows(
            ValidationException::class,
            fn () => $this->c->get(TicketMergeService::class)->mergeMany([(int) $target['id']], (string) $target['number']),
        );
        $this->assertSame('new', $this->service()->get((int) $target['id'])['status_code']);
    }

    public function testMergeManyKeepsGoingWhenOneSourceFails(): void
    {
        $source = $this->createTicket(['subject' => 'Duplikat']);
        $target = $this->createTicket(['subject' => 'Original']);

        $result = $this->c->get(TicketMergeService::class)->mergeMany([(int) $source['id'], 999999], (string) $target['number']);

        $this->assertSame([$source['number']], array_column($result['merged'], 'number'));
        $this->assertCount(1, $result['errors']);
        $this->assertStringContains('#999999', $result['errors'][0]);
        $this->assertSame((int) $target['id'], (int) $this->service()->get((int) $source['id'])['merged_into_ticket_id']);
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

    public function testSuggestForMergeListsClosedButNotCancelledTickets(): void
    {
        $same = $this->createTicket(['subject' => 'Drucker streikt']);
        $other = $this->createTicket(['subject' => 'Drucker streikt (fremd)', 'requester_employee_id' => $this->otherEmployeeId]);
        $closed = $this->createTicket(['subject' => 'Drucker erledigt']);
        $cancelled = $this->createTicket(['subject' => 'Drucker verworfen']);
        $this->loginAs($this->agentId, 'helpdesk_agent', ['ticket_stornieren']);
        $this->service()->changeStatus((int) $cancelled['id'], 'cancelled', null, null, 'Testfall');
        $this->service()->changeStatus((int) $closed['id'], 'in_progress');
        $this->service()->changeStatus((int) $closed['id'], 'resolved', null, 'Drucker getauscht.');
        $this->service()->changeStatus((int) $closed['id'], 'closed');

        // Ohne Suchbegriff nur Tickets derselben meldenden Person
        $rows = $this->tickets()->suggestForMerge('', 10, 0, $this->requesterEmployeeId, null, 'max@example.test');
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $this->assertContains((int) $same['id'], $ids);
        $this->assertContains((int) $closed['id'], $ids, 'Geschlossene Tickets sind als Ziel wählbar.');
        $this->assertFalse(in_array((int) $other['id'], $ids, true), 'Fremde Melder erscheinen nicht ohne Suchbegriff.');
        $this->assertFalse(in_array((int) $cancelled['id'], $ids, true), 'Stornierte Tickets werden nicht vorgeschlagen.');

        // Mit Suchbegriff kommen fremde Treffer hinzu, gleiche Melder und offene Tickets stehen vorn
        $rows = $this->tickets()->suggestForMerge('Drucker', 10, 0, $this->requesterEmployeeId, null, 'max@example.test');
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $this->assertContains((int) $other['id'], $ids);
        $this->assertSame((int) $same['id'], $ids[0], 'Offene Tickets derselben Person stehen oben.');
        $this->assertSame((int) $closed['id'], $ids[1], 'Geschlossene Tickets folgen nach den offenen.');
        $flags = array_column($rows, 'same_requester', 'id');
        $this->assertSame(1, (int) $flags[$same['id']]);
        $this->assertSame(0, (int) $flags[$other['id']]);

        // Das Quellticket selbst wird ausgeschlossen
        $rows = $this->tickets()->suggestForMerge('Drucker', 10, (int) $same['id'], $this->requesterEmployeeId, null, 'max@example.test');
        $this->assertFalse(in_array((int) $same['id'], array_map(static fn (array $r): int => (int) $r['id'], $rows), true));
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
