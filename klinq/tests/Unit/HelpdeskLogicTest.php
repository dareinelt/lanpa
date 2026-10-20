<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Security\Permissions;
use App\Services\Helpdesk\KnowledgeBaseService;
use App\Services\Helpdesk\TicketNumberService;
use App\Services\Helpdesk\TicketPriorityMatrix;
use App\Services\Helpdesk\TicketRuleEvaluator;
use App\Services\Helpdesk\TicketSlaService;
use App\Services\Helpdesk\TicketWorkflowService;
use Tests\Support\TestCase;

/** Reine Fachlogik des Help Desks ohne Datenbank. */
final class HelpdeskLogicTest extends TestCase
{
    // ------------------------------------------------------------------ Ticketnummern

    public function testTicketNumberFormatAndValidation(): void
    {
        $this->assertSame('HD-2026-000042', TicketNumberService::format('HD', '2026', 42));
        $this->assertSame('HD-2026-1234567', TicketNumberService::format('HD', '2026', 1234567), 'Mehr als sechs Stellen bleiben erhalten');
        $this->assertTrue(TicketNumberService::isValid('HD-2026-000001'));
        $this->assertTrue(TicketNumberService::isValid('IT2-2026-000001'));
        $this->assertFalse(TicketNumberService::isValid('hd-2026-000001'));
        $this->assertFalse(TicketNumberService::isValid('HD-26-000001'));
        $this->assertFalse(TicketNumberService::isValid('HD-2026-1'));
    }

    public function testTicketNumberNormalize(): void
    {
        $this->assertSame('HD-2026-000012', TicketNumberService::normalize(' hd-2026-12 '));
        $this->assertSame('HD-2026-000012', TicketNumberService::normalize('HD-2026-000012'));
        $this->assertNull(TicketNumberService::normalize('12'));
        $this->assertNull(TicketNumberService::normalize('HD-2026'));
        $this->assertNull(TicketNumberService::normalize(''));
    }

    // ------------------------------------------------------------------ Prioritätsmatrix

    public function testPriorityMatrix(): void
    {
        $this->assertSame(1, TicketPriorityMatrix::level(1, 1));
        $this->assertSame(2, TicketPriorityMatrix::level(1, 2));
        $this->assertSame(2, TicketPriorityMatrix::level(2, 1));
        $this->assertSame(3, TicketPriorityMatrix::level(2, 2));
        $this->assertSame(3, TicketPriorityMatrix::level(3, 1));
        $this->assertSame(4, TicketPriorityMatrix::level(3, 3));
        $this->assertSame(1, TicketPriorityMatrix::level(0, -5), 'Werte werden auf 1–3 begrenzt');
        $this->assertSame(4, TicketPriorityMatrix::level(9, 9));
    }

    // ------------------------------------------------------------------ Workflow

    public function testWorkflowTransitions(): void
    {
        $w = new TicketWorkflowService();
        $this->assertTrue($w->canTransition('new', 'in_progress'));
        $this->assertTrue($w->canTransition('in_progress', 'waiting_user'));
        $this->assertTrue($w->canTransition('resolved', 'closed'));
        $this->assertTrue($w->canTransition('closed', 'in_progress'));
        $this->assertFalse($w->canTransition('new', 'resolved'), 'Lösung erst aus der Bearbeitung');
        $this->assertFalse($w->canTransition('closed', 'waiting_user'));
        $this->assertFalse($w->canTransition('cancelled', 'in_progress'));
        $this->assertSame([], $w->allowedTransitions('cancelled'));
        $this->assertSame([], $w->allowedTransitions('unbekannt'));
    }

    public function testWorkflowPermissionsAndFlags(): void
    {
        $w = new TicketWorkflowService();
        $this->assertSame('helpdesk.update', $w->requiredPermission('new', 'in_progress'));
        $this->assertSame('helpdesk.close', $w->requiredPermission('in_progress', 'resolved'));
        $this->assertSame('helpdesk.close', $w->requiredPermission('resolved', 'closed'));
        $this->assertSame('helpdesk.close', $w->requiredPermission('open', 'cancelled'));
        $this->assertSame('helpdesk.reopen', $w->requiredPermission('closed', 'in_progress'));
        $this->assertSame('helpdesk.reopen', $w->requiredPermission('resolved', 'in_progress'));

        $this->assertTrue($w->isReopen('resolved', 'in_progress'));
        $this->assertFalse($w->isReopen('waiting_user', 'in_progress'));
        $this->assertTrue($w->isFinal('closed'));
        $this->assertTrue($w->isFinal('cancelled'));
        $this->assertFalse($w->isFinal('resolved'));
        $this->assertTrue($w->isResolvedOrFinal('resolved'));
        $this->assertTrue($w->requiresResolution('resolved'));
        $this->assertFalse($w->requiresResolution('closed'));
        $this->assertTrue($w->requiresReason('cancelled'));
        $this->assertSame('reopened', $w->eventFor('closed', 'in_progress'));
        $this->assertSame('resolved', $w->eventFor('in_progress', 'resolved'));
        $this->assertSame('closed', $w->eventFor('resolved', 'closed'));
    }

    // ------------------------------------------------------------------ SLA

    private function sla(): TicketSlaService
    {
        return new TicketSlaService('Europe/Berlin', 75, 90);
    }

    public function testSlaDueDatesCalendar(): void
    {
        $sla = ['business_hours_only' => 0, 'response_minutes' => 15, 'resolution_minutes' => 240];
        $start = new \DateTimeImmutable('2026-03-10 10:00:00', new \DateTimeZone('UTC'));
        $due = $this->sla()->dueDates($sla, $start);
        $this->assertSame('2026-03-10 10:15:00', $due['response_due_at']);
        $this->assertSame('2026-03-10 14:00:00', $due['resolution_due_at']);
    }

    public function testSlaDueDatesBusinessHoursSkipNightAndWeekend(): void
    {
        // Geschäftszeit Mo–Fr 08:00–17:00 Europe/Berlin (März = MEZ = UTC+1)
        $sla = ['business_hours_only' => 1, 'business_days' => '1,2,3,4,5', 'business_start' => '08:00:00', 'business_end' => '17:00:00', 'response_minutes' => 120, 'resolution_minutes' => 600];
        // Freitag, 13.03.2026 15:00 UTC = 16:00 Ortszeit → nur noch 60 min verfügbar
        $start = new \DateTimeImmutable('2026-03-13 15:00:00', new \DateTimeZone('UTC'));
        $due = $this->sla()->dueDates($sla, $start);
        // 60 min Freitag + 60 min Montag ab 08:00 → Montag 09:00 Ortszeit = 08:00 UTC
        $this->assertSame('2026-03-16 08:00:00', $due['response_due_at']);
        // 600 min = 60 (Fr) + 540 (Mo, ganzer Tag) → Montag 17:00 Ortszeit = 16:00 UTC
        $this->assertSame('2026-03-16 16:00:00', $due['resolution_due_at']);

        // Anlage am Wochenende beginnt am Montag 08:00
        $weekend = new \DateTimeImmutable('2026-03-14 12:00:00', new \DateTimeZone('UTC'));
        $due = $this->sla()->dueDates($sla, $weekend);
        $this->assertSame('2026-03-16 09:00:00', $due['response_due_at'], 'Montag 08:00 + 120 min = 10:00 Ortszeit');

        // Anlage vor Geschäftsbeginn startet um 08:00
        $early = new \DateTimeImmutable('2026-03-10 05:30:00', new \DateTimeZone('UTC')); // 06:30 Ortszeit
        $due = $this->sla()->dueDates($sla, $early);
        $this->assertSame('2026-03-10 09:00:00', $due['response_due_at']);
    }

    public function testSlaEvaluateStates(): void
    {
        $s = $this->sla();
        $started = '2026-03-10 10:00:00';
        $due = '2026-03-10 12:00:00';
        $at = static fn (string $t): \DateTimeImmutable => new \DateTimeImmutable($t, new \DateTimeZone('UTC'));

        $this->assertSame('none', $s->evaluate(null, null, $at('2026-03-10 10:30:00'), $started, 75));
        $this->assertSame('ok', $s->evaluate($due, null, $at('2026-03-10 10:30:00'), $started, 75));
        $this->assertSame('warning', $s->evaluate($due, null, $at('2026-03-10 11:40:00'), $started, 75), '83 % verbraucht');
        $this->assertSame('breached', $s->evaluate($due, null, $at('2026-03-10 12:01:00'), $started, 75));
        $this->assertSame('met', $s->evaluate($due, '2026-03-10 11:00:00', $at('2026-03-11 00:00:00'), $started, 75));
        $this->assertSame('breached', $s->evaluate($due, '2026-03-10 13:00:00', $at('2026-03-11 00:00:00'), $started, 75), 'Zu spät erledigt');

        $this->assertSame(50, $s->consumedPercent($started, $due, $at('2026-03-10 11:00:00')));
        $this->assertSame(150, $s->consumedPercent($started, $due, $at('2026-03-10 13:00:00')));
        $this->assertNull($s->consumedPercent($started, null, $at('2026-03-10 13:00:00')));
        $this->assertSame(60, $s->remainingMinutes($due, $at('2026-03-10 11:00:00')));
        $this->assertSame(-30, $s->remainingMinutes($due, $at('2026-03-10 12:30:00')));
        $this->assertSame('2026-03-10 12:45:00', $s->shiftDue($due, 45));
        $this->assertSame($due, $s->shiftDue($due, 0));
    }

    public function testSlaThresholdsAndHumanRemaining(): void
    {
        $s = $this->sla();
        $this->assertSame(75, $s->warningPercent([]));
        $this->assertSame(60, $s->warningPercent(['warning_percent' => 60]));
        $this->assertSame(90, $s->escalationPercent(['escalation_percent' => 0]));
        $this->assertSame('–', TicketSlaService::humanRemaining(null));
        $this->assertSame('noch 0 min', TicketSlaService::humanRemaining(0));
        $this->assertSame('noch 2 h 15 min', TicketSlaService::humanRemaining(135));
        $this->assertSame('noch 1 T 1 h', TicketSlaService::humanRemaining(1500));
        $this->assertSame('überfällig seit 40 min', TicketSlaService::humanRemaining(-40));
    }

    // ------------------------------------------------------------------ Regeln

    public function testRuleEvaluatorOperators(): void
    {
        $e = new TicketRuleEvaluator();
        $ctx = ['subject' => 'VPN bricht ab', 'priority_level' => '3', 'group_name' => '', 'tag' => ['vpn', 'netzwerk'], 'source' => 'portal'];

        $this->assertTrue($e->matches([], $ctx), 'Ohne Bedingungen passt alles');
        $this->assertTrue($e->matches(['all' => [['field' => 'subject', 'op' => 'contains', 'value' => 'vpn']]], $ctx));
        $this->assertTrue($e->matches(['all' => [['field' => 'subject', 'op' => 'starts_with', 'value' => 'VPN']]], $ctx));
        $this->assertFalse($e->matches(['all' => [['field' => 'subject', 'op' => 'eq', 'value' => 'VPN']]], $ctx));
        $this->assertTrue($e->matches(['all' => [['field' => 'priority_level', 'op' => 'gt', 'value' => 2]]], $ctx));
        $this->assertFalse($e->matches(['all' => [['field' => 'priority_level', 'op' => 'lt', 'value' => 2]]], $ctx));
        $this->assertTrue($e->matches(['all' => [['field' => 'group_name', 'op' => 'empty']]], $ctx));
        $this->assertTrue($e->matches(['all' => [['field' => 'source', 'op' => 'in', 'value' => ['portal', 'email']]]], $ctx));
        $this->assertTrue($e->matches(['all' => [['field' => 'source', 'op' => 'not_in', 'value' => ['web']]]], $ctx));
        $this->assertTrue($e->matches(['all' => [['field' => 'tag', 'op' => 'eq', 'value' => 'Netzwerk']]], $ctx), 'Listenfeld: ein Element passt');
        $this->assertFalse($e->matches(['all' => [['field' => 'tag', 'op' => 'eq', 'value' => 'drucker']]], $ctx));
        $this->assertTrue($e->matches(['all' => [['field' => 'tag', 'op' => 'not_empty']]], $ctx));

        // all UND any
        $this->assertTrue($e->matches(['all' => [['field' => 'source', 'op' => 'eq', 'value' => 'portal']], 'any' => [['field' => 'subject', 'op' => 'contains', 'value' => 'drucker'], ['field' => 'subject', 'op' => 'contains', 'value' => 'vpn']]], $ctx));
        $this->assertFalse($e->matches(['any' => [['field' => 'subject', 'op' => 'contains', 'value' => 'drucker']]], $ctx));
        $this->assertFalse($e->matches(['all' => 'kaputt'], $ctx));
    }

    public function testRuleEvaluatorValidateDefinition(): void
    {
        $e = new TicketRuleEvaluator();
        $this->assertSame([], $e->validateDefinition(['all' => [['field' => 'subject', 'op' => 'contains', 'value' => 'x']]], ['set_group' => 'Network']));
        $errors = $e->validateDefinition(['all' => [['field' => 'foo', 'op' => 'eq']]], ['set_group' => 'Network']);
        $this->assertCount(1, $errors);
        $this->assertStringContains('foo', $errors[0]);
        $errors = $e->validateDefinition(['all' => [['field' => 'subject', 'op' => 'like']]], []);
        $this->assertCount(2, $errors);
        $this->assertCount(1, $e->validateDefinition([], ['explode' => 1]));
        $this->assertCount(2, $e->validateDefinition('x', 'y'));
    }

    // ------------------------------------------------------------------ Wissensdatenbank

    public function testKnowledgeSlugify(): void
    {
        $this->assertSame('vpn-bricht-ab-split-tunneling', KnowledgeBaseService::slugify('VPN bricht ab – Split-Tunneling'));
        $this->assertSame('druecker-stoerung-ss', KnowledgeBaseService::slugify('Drücker Störung ß'));
        $this->assertSame('', KnowledgeBaseService::slugify('---'));
        $this->assertTrue(mb_strlen(KnowledgeBaseService::slugify(str_repeat('a', 300))) <= 190);
    }

    // ------------------------------------------------------------------ Berechtigungen

    public function testHelpdeskRolesAndPermissions(): void
    {
        $p = new Permissions(new Config(dirname(__DIR__, 2) . '/config'));

        foreach (['helpdesk.view', 'helpdesk.create', 'helpdesk.close', 'helpdesk.merge', 'knowledgebase.manage', 'portal.create'] as $perm) {
            $this->assertTrue($p->roleHas('helpdesk_agent', $perm), "Agent braucht {$perm}");
        }
        $this->assertFalse($p->roleHas('helpdesk_agent', 'helpdesk.admin'));
        $this->assertFalse($p->roleHas('helpdesk_agent', 'helpdesk.sla'));
        $this->assertFalse($p->roleHas('helpdesk_agent', 'helpdesk.export'));
        $this->assertFalse($p->roleHas('helpdesk_agent', 'assets.manage'), 'Agent verwaltet keine Assets');

        $this->assertTrue($p->roleHas('helpdesk_lead', 'helpdesk.sla'));
        $this->assertTrue($p->roleHas('helpdesk_lead', 'helpdesk.escalate'));
        $this->assertFalse($p->roleHas('helpdesk_lead', 'helpdesk.admin'));
        $this->assertTrue($p->roleHas('helpdesk_admin', 'helpdesk.admin'));
        $this->assertTrue($p->roleHas('admin', 'helpdesk.admin'));

        // Fachbereichsrollen: Portal ja, Agentenbereich nein
        foreach (['assetmanagement', 'lager', 'einkauf'] as $role) {
            $this->assertTrue($p->roleHas($role, 'portal.create'), "{$role} darf Tickets im Portal anlegen");
            $this->assertTrue($p->roleHas($role, 'knowledgebase.view'));
            $this->assertFalse($p->roleHas($role, 'helpdesk.view'), "{$role} sieht keine fremden Tickets");
        }
        $this->assertTrue($p->roleHas('readonly', 'portal.view'));
        $this->assertFalse($p->roleHas('readonly', 'helpdesk.view'));
    }
}
