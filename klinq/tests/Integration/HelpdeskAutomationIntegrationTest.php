<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\EmployeeRepository;
use App\Repositories\KnowledgeBaseRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketRuleRepository;
use App\Repositories\TicketSlaRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\HelpdeskAdminService;
use App\Services\Helpdesk\HelpdeskSchedulerService;
use App\Services\Helpdesk\KnowledgeBaseService;
use App\Services\Helpdesk\TicketReportService;
use App\Services\Helpdesk\TicketService;
use Tests\Support\DatabaseTestCase;

/** Automatisierung (Regeln, SLA-Scheduler), Wissensdatenbank, Berichte und Administration. */
final class HelpdeskAutomationIntegrationTest extends DatabaseTestCase
{
    private int $adminId;
    private int $employeeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->employeeId = $this->c->get(EmployeeRepository::class)->create(['first_name' => 'Rita', 'last_name' => 'Requester', 'display_name' => 'Rita Requester', 'email' => 'rita@example.test', 'source' => 'manual', 'is_active' => 1]);
        $stmt = $this->pdo->prepare("INSERT INTO users (username, display_name, email, role_id, auth_source, is_active) SELECT 'hd-admin', 'HD Admin', 'admin@example.test', id, 'local', 1 FROM roles WHERE name = 'helpdesk_admin'");
        $stmt->execute();
        $this->adminId = (int) $this->pdo->lastInsertId();
        $this->login($this->adminId, 'helpdesk_admin');
    }

    /** @param array<int,string> $groups */
    private function login(int $userId, string $role, array $groups = []): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => $userId, 'username' => 'u' . $userId, 'display_name' => 'User ' . $userId, 'role' => $role, 'groups' => $groups]);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function createTicket(array $overrides = []): array
    {
        $type = $this->c->get(TicketMasterDataRepository::class)->typeByCode('incident');

        return $this->c->get(TicketService::class)->create(array_merge([
            'subject' => 'VPN-Verbindung bricht ab',
            'description' => 'Seit heute Morgen trennt sich der VPN-Tunnel alle fünf Minuten.',
            'ticket_type_id' => $type['id'],
            'requester_employee_id' => $this->employeeId,
            'impact' => 2,
            'urgency' => 2,
        ], $overrides), 'web');
    }

    // ------------------------------------------------------------------ Regeln

    public function testRuleRoutesTicketOnCreate(): void
    {
        $admin = $this->c->get(HelpdeskAdminService::class);
        $ruleId = $admin->saveRule(null, [
            'name' => 'Test: VPN-Routing',
            'trigger_event' => 'created',
            'conditions' => json_encode(['all' => [['field' => 'subject', 'op' => 'contains', 'value' => 'VPN']]]),
            'actions' => json_encode(['set_group' => 'Network', 'set_priority' => 'high', 'add_tags' => ['vpn']]),
            'is_active' => '1',
        ]);
        $this->assertTrue($ruleId > 0);

        $ticket = $this->createTicket();
        $this->assertSame('Network', $ticket['group_name']);
        $this->assertSame('high', $ticket['priority_code']);
        $this->assertStringContains('vpn', strtolower((string) $ticket['tag_names']));

        $events = array_map(static fn (array $e): string => (string) $e['type'], $this->c->get(TicketService::class)->timeline((int) $ticket['id']));
        $this->assertContains('rule_applied', $events);

        // Nicht passendes Ticket bleibt unverändert
        $other = $this->createTicket(['subject' => 'Maus defekt']);
        $this->assertNull($other['group_id']);
        $this->assertFalse(str_contains(strtolower((string) $other['tag_names']), 'vpn'));
    }

    public function testRuleValidationRejectsUnknownFieldsAndActions(): void
    {
        $admin = $this->c->get(HelpdeskAdminService::class);
        $this->assertThrows(ValidationException::class, fn () => $admin->saveRule(null, [
            'name' => 'Kaputt', 'trigger_event' => 'created',
            'conditions' => json_encode(['all' => [['field' => 'foo', 'op' => 'eq', 'value' => 1]]]),
            'actions' => json_encode(['set_group' => 'Network']),
        ]));
        $this->assertThrows(ValidationException::class, fn () => $admin->saveRule(null, [
            'name' => 'Kaputt2', 'trigger_event' => 'created', 'conditions' => '{}', 'actions' => json_encode(['explode' => true]),
        ]));
        $this->assertThrows(ValidationException::class, fn () => $admin->saveRule(null, [
            'name' => 'Kaputt3', 'trigger_event' => 'created', 'conditions' => 'kein json', 'actions' => '{}',
        ]));
    }

    // ------------------------------------------------------------------ SLA-Scheduler

    public function testSchedulerMarksWarningBreachAndEscalates(): void
    {
        $ticket = $this->createTicket(['impact' => 3, 'urgency' => 3]); // Kritisch: 15 min / 240 min, 24/7
        $id = (int) $ticket['id'];
        $created = new \DateTimeImmutable((string) $ticket['created_at'], new \DateTimeZone('UTC'));
        $scheduler = $this->c->get(HelpdeskSchedulerService::class);
        $repo = $this->c->get(TicketRepository::class);

        // Nach 10 Minuten: Reaktionszeit zu 66 % verbraucht → Warnung (Schwelle 75 %? Standard 80 %) – prüfen: 13 Minuten = 86 %
        $summary = $scheduler->run($created->modify('+13 minutes'));
        $this->assertTrue($summary['checked'] >= 1);
        $fresh = $repo->find($id);
        $this->assertSame('warning', $fresh['sla_response_state']);
        $this->assertSame('ok', $fresh['sla_resolution_state']);

        // Nach 20 Minuten: Reaktionszeit verletzt
        $scheduler->run($created->modify('+20 minutes'));
        $fresh = $repo->find($id);
        $this->assertSame('breached', $fresh['sla_response_state']);

        // Nach 5 Stunden: Lösungszeit verletzt, Eskalationsstufe 2
        $scheduler->run($created->modify('+5 hours'));
        $fresh = $repo->find($id);
        $this->assertSame('breached', $fresh['sla_resolution_state']);
        $this->assertSame(2, (int) $fresh['escalation_level']);
        $this->assertNotNull($fresh['escalated_at']);

        $events = array_map(static fn (array $e): string => (string) $e['type'], $this->c->get(TicketService::class)->timeline($id));
        $this->assertContains('sla_warning', $events);
        $this->assertContains('sla_breached', $events);
        $this->assertContains('escalated', $events);
    }

    public function testSchedulerAutoClosesResolvedTickets(): void
    {
        $service = $this->c->get(TicketService::class);
        $ticket = $this->createTicket();
        $id = (int) $ticket['id'];
        $service->changeStatus($id, 'in_progress');
        $service->changeStatus($id, 'resolved', null, 'Neuer VPN-Client installiert.');

        $scheduler = $this->c->get(HelpdeskSchedulerService::class);
        $scheduler->run(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->assertSame('resolved', $service->get($id)['status_code'], 'Noch innerhalb der Frist');

        $summary = $scheduler->run((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+30 days'));
        $this->assertTrue($summary['auto_closed'] >= 1);
        $this->assertSame('closed', $service->get($id)['status_code']);
    }

    // ------------------------------------------------------------------ Wissensdatenbank

    public function testKnowledgeArticleFromTicketAndVisibility(): void
    {
        $service = $this->c->get(TicketService::class);
        $kb = $this->c->get(KnowledgeBaseService::class);
        $ticket = $this->createTicket();
        $id = (int) $ticket['id'];
        $service->changeStatus($id, 'in_progress');
        $service->changeStatus($id, 'resolved', null, 'Split-Tunneling deaktiviert.');

        $prefill = $kb->prefillFromTicket($service->get($id));
        $this->assertStringContains('VPN', (string) $prefill['title']);
        $this->assertStringContains('Split-Tunneling', (string) $prefill['body']);

        $article = $kb->create(['title' => 'VPN bricht ab – Split-Tunneling', 'body' => $prefill['body'], 'status' => 'draft', 'visibility' => 'internal', 'tags' => 'vpn'], $id);
        $this->assertSame('vpn-bricht-ab-split-tunneling', $article['slug']);
        $this->assertSame($id, (int) $article['source_ticket_id']);
        $this->assertSame((int) $article['id'], (int) $service->get($id)['knowledge_article_id']);
        $this->assertNull($article['published_at']);

        $article = $kb->update((int) $article['id'], ['title' => $article['title'], 'body' => $article['body'], 'status' => 'published', 'visibility' => 'public', 'tags' => 'vpn, netzwerk']);
        $this->assertNotNull($article['published_at']);

        $linked = $kb->articlesForTicket($id);
        $this->assertCount(1, $linked);

        // Portalnutzer: veröffentlicht + öffentlich sichtbar, Entwürfe nicht
        $draft = $kb->create(['title' => 'Interner Entwurf', 'body' => 'Nur für Agenten', 'status' => 'draft', 'visibility' => 'internal']);
        $this->login($this->adminId + 100000, 'readonly');
        $this->assertTrue($kb->isVisible($article));
        $this->assertFalse($kb->isVisible($draft));
        $this->assertThrows(ForbiddenException::class, fn () => $kb->getVisible((int) $draft['id']));
        $this->assertThrows(ForbiddenException::class, fn () => $kb->create(['title' => 'Verboten', 'body' => 'x', 'status' => 'draft', 'visibility' => 'internal']));

        $rows = $this->c->get(KnowledgeBaseRepository::class)->search($kb->restrictFilters(['q' => 'VPN']), 'updated_at', 'desc', 20, 0);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $this->assertContains((int) $article['id'], $ids);
        $this->assertFalse(in_array((int) $draft['id'], $ids, true));
    }

    // ------------------------------------------------------------------ Berichte

    public function testReportOverviewAndCsvExport(): void
    {
        $service = $this->c->get(TicketService::class);
        $a = $this->createTicket();
        $b = $this->createTicket(['subject' => 'Drucker offline']);
        $service->changeStatus((int) $a['id'], 'in_progress');
        $service->changeStatus((int) $a['id'], 'resolved', null, 'Neustart.');

        // Ticket-Berichte erfordern die Berechtigungsgruppe „Statistik“ (nicht mehr Bestandteil
        // der Help-Desk-Rollen); ausgenommen sind Admins.
        $this->login($this->adminId, 'helpdesk_admin', ['statistik']);

        $reports = $this->c->get(TicketReportService::class);
        $from = gmdate('Y-m-d', strtotime('-1 day'));
        $to = gmdate('Y-m-d', strtotime('+1 day'));
        $overview = $reports->overview($from, $to);
        $created = array_sum(array_map(static fn (array $d): int => (int) $d['created_count'], $overview['per_day']));
        $this->assertTrue($created >= 2, 'Mindestens zwei angelegte Tickets im Zeitraum');
        $this->assertTrue((int) $overview['sla']['total'] >= 2);
        $this->assertTrue((int) $overview['sla']['resolution_met'] >= 1);
        $this->assertTrue(count($overview['by_status']) >= 2);

        $csv = $reports->exportCsv($from, $to);
        $this->assertStringContains(';', $csv);
        $this->assertStringContains("\n", $csv);

        $list = $reports->exportListCsv(['q' => 'Drucker offline'], 'created_at', 'desc');
        $this->assertStringContains((string) $b['number'], $list);
        $this->assertFalse(str_contains($list, (string) $a['number']));
    }

    public function testReportsRequireStatisticsGroupExceptGlobalAdmin(): void
    {
        $reports = $this->c->get(TicketReportService::class);
        $from = gmdate('Y-m-d', strtotime('-1 day'));
        $to = gmdate('Y-m-d', strtotime('+1 day'));

        // Help-Desk-Administrator ohne Gruppe „Statistik“ darf die Ticket-Berichte nicht sehen.
        $this->login($this->adminId, 'helpdesk_admin');
        $this->assertThrows(ForbiddenException::class, fn () => $reports->overview($from, $to));

        // Mit zugeordneter Gruppe „Statistik“ funktioniert der Zugriff.
        $this->login($this->adminId, 'helpdesk_admin', ['statistik']);
        $reports->overview($from, $to);

        // Der globale Administrator (Rolle "admin") hat unabhängig von Gruppen Zugriff.
        $this->login($this->adminId, 'admin');
        $reports->overview($from, $to);
    }

    // ------------------------------------------------------------------ Administration

    public function testAdminMasterDataAndSlaManagement(): void
    {
        $admin = $this->c->get(HelpdeskAdminService::class);
        $master = $this->c->get(TicketMasterDataRepository::class);

        $groupId = $admin->saveSimple('groups', null, ['name' => 'Security', 'description' => 'IT-Sicherheit', 'email' => 'sec@example.test', 'is_active' => '1', 'members' => [$this->adminId], 'leads' => [$this->adminId]]);
        $members = $master->groupMembers($groupId);
        $this->assertCount(1, $members);
        $this->assertSame(1, (int) $members[0]['is_lead']);
        $this->assertThrows(ValidationException::class, fn () => $admin->saveSimple('groups', null, ['name' => 'Security']), 'vergeben');

        $catId = $admin->saveCategory(null, ['name' => 'Sicherheit', 'parent_id' => '', 'is_active' => '1']);
        $subId = $admin->saveCategory(null, ['name' => 'Phishing', 'parent_id' => (string) $catId, 'default_group_id' => (string) $groupId, 'is_active' => '1']);
        $this->assertThrows(ValidationException::class, fn () => $admin->saveCategory($catId, ['name' => 'Sicherheit', 'parent_id' => (string) $catId]), 'sich selbst');
        $this->assertThrows(ValidationException::class, fn () => $admin->saveCategory(null, ['name' => 'Dritte Ebene', 'parent_id' => (string) $subId]), 'zwei Ebenen');

        // Kategorie mit Standardgruppe steuert die Zuordnung neuer Tickets
        $ticket = $this->createTicket(['category_id' => $catId, 'subcategory_id' => $subId]);
        $this->assertSame('Security', $ticket['group_name']);

        $slaId = $admin->saveSla(null, ['name' => 'Security-SLA', 'priority_id' => '', 'category_id' => (string) $catId, 'response_minutes' => '30', 'resolution_minutes' => '600', 'business_hours_only' => '1', 'business_start' => '08:00', 'business_end' => '17:00', 'business_days' => ['1', '2', '3', '4', '5'], 'warning_percent' => '70', 'escalation_percent' => '90', 'is_active' => '1']);
        $sla = $this->c->get(TicketSlaRepository::class)->find($slaId);
        $this->assertSame('08:00:00', $sla['business_start']);
        $this->assertThrows(ValidationException::class, fn () => $admin->saveSla(null, ['name' => 'Falsch', 'response_minutes' => '600', 'resolution_minutes' => '30']));

        $this->assertThrows(ForbiddenException::class, function () use ($admin): void {
            $this->login($this->adminId + 1, 'helpdesk_agent');
            $admin->saveSimple('types', null, ['name' => 'X', 'code' => 'x']);
        });
    }

    public function testFeatureRulesDeleteAndTagCleanup(): void
    {
        $admin = $this->c->get(HelpdeskAdminService::class);
        $ruleId = $admin->saveRule(null, ['name' => 'Temp', 'trigger_event' => 'updated', 'conditions' => '{}', 'actions' => json_encode(['add_tags' => ['x']])]);
        $admin->deleteRule($ruleId);
        $this->assertNull($this->c->get(TicketRuleRepository::class)->find($ruleId));

        $tagId = $admin->saveTag(null, ['name' => 'Wegwerf', 'color' => 'warning']);
        $admin->deleteTag($tagId);
        $this->assertThrows(ValidationException::class, fn () => $admin->saveTag(null, ['name' => '', 'color' => 'warning']));
    }
}
