<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\TicketTemplateRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\HelpdeskAdminService;
use App\Services\Helpdesk\TicketService;
use Tests\Support\DatabaseTestCase;

/**
 * Vorlagenumfang (`ticket_templates.is_shared`) und die Berechtigungsgruppe „vorlagen“:
 * persönliche Vorlagen gehören ihrem Ersteller, freigegebene allen Agenten, die
 * Help-Desk-Administration behält die volle Verwaltung.
 */
final class TicketTemplateScopeIntegrationTest extends DatabaseTestCase
{
    private int $ownerId;
    private int $otherId;
    private int $leadId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerId = $this->createUser('tpl-owner', 'helpdesk_agent');
        $this->otherId = $this->createUser('tpl-other', 'helpdesk_agent');
        $this->leadId = $this->createUser('tpl-lead', 'helpdesk_lead');
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
            'display_name' => 'Benutzer ' . $userId,
            'role' => $role,
            'groups' => $groups,
        ]);
    }

    private function templates(): TicketTemplateRepository
    {
        return $this->c->get(TicketTemplateRepository::class);
    }

    /** @param array<string,mixed> $overrides */
    private function template(string $name, int $createdBy, bool $shared, bool $portalVisible = false, bool $active = true): int
    {
        return $this->templates()->create([
            'name' => $name,
            'subject' => 'Betreff ' . $name,
            'created_by' => $createdBy,
            'is_shared' => $shared ? 1 : 0,
            'is_portal_visible' => $portalVisible ? 1 : 0,
            'is_active' => $active ? 1 : 0,
            'sort_order' => 0,
        ]);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function testPersonalTemplatesStayWithTheirCreator(): void
    {
        $shared = $this->template('Geteilt', $this->ownerId, true);
        $personal = $this->template('Persönlich Besitzer', $this->ownerId, false);
        $foreign = $this->template('Persönlich Andere', $this->otherId, false);

        $ownerView = $this->ids($this->templates()->all(true, false, $this->ownerId));
        $this->assertContains($shared, $ownerView, 'Freigegebene Vorlagen sieht jeder Agent');
        $this->assertContains($personal, $ownerView, 'Der Ersteller sieht seine persönliche Vorlage');
        $this->assertFalse(in_array($foreign, $ownerView, true), 'Fremde persönliche Vorlagen bleiben verborgen');

        $otherView = $this->ids($this->templates()->all(true, false, $this->otherId));
        $this->assertContains($shared, $otherView);
        $this->assertContains($foreign, $otherView);
        $this->assertFalse(in_array($personal, $otherView, true));

        $adminView = $this->ids($this->templates()->all(true, false, null));
        $this->assertContains($personal, $adminView, 'Ohne Betrachter (Administration) bleiben alle Vorlagen sichtbar');
        $this->assertContains($foreign, $adminView);
    }

    public function testPortalFilterKeepsPersonalTemplatesWithTheirCreator(): void
    {
        $portalShared = $this->template('Portal freigegeben', $this->ownerId, true, true);
        $sharedInternal = $this->template('Nur intern geteilt', $this->ownerId, true, false);
        $personal = $this->template('Persönlich im Portal', $this->ownerId, false, false);

        $ownerView = $this->ids($this->templates()->all(true, true, $this->ownerId));
        $this->assertContains($portalShared, $ownerView);
        $this->assertContains($personal, $ownerView, 'Der Ersteller nutzt seine Vorlage auch im Portal');
        $this->assertFalse(in_array($sharedInternal, $ownerView, true), 'Ohne Portal-Freigabe bleibt die Vorlage im Agentenbereich');

        $otherView = $this->ids($this->templates()->all(true, true, $this->otherId));
        $this->assertContains($portalShared, $otherView);
        $this->assertFalse(in_array($personal, $otherView, true), 'Persönliche Vorlagen erreichen fremde Portalnutzer nicht');
    }

    public function testPrefillRespectsTemplateScope(): void
    {
        $shared = $this->template('Geteilt', $this->ownerId, true);
        $personal = $this->template('Persönlich', $this->ownerId, false);
        $inactive = $this->template('Inaktiv', $this->ownerId, true, false, false);

        $this->loginAs($this->ownerId, 'helpdesk_agent');
        $service = $this->c->get(TicketService::class);
        $this->assertSame('Betreff Persönlich', $service->prefillFromTemplate($personal, false)['subject'] ?? null);
        $this->assertSame([], $service->prefillFromTemplate($inactive, false), 'Inaktive Vorlagen belegen nichts vor');

        $this->loginAs($this->otherId, 'helpdesk_agent');
        $service = $this->c->get(TicketService::class);
        $this->assertSame([], $service->prefillFromTemplate($personal, false), 'Fremde persönliche Vorlagen sind nicht nutzbar');
        $this->assertSame('Betreff Geteilt', $service->prefillFromTemplate($shared, false)['subject'] ?? null);
        $this->assertSame([], $service->prefillFromTemplate($shared, true), 'Im Portal gilt nur die Portal-Freigabe oder eine eigene Vorlage');

        $this->loginAs($this->leadId, 'helpdesk_lead');
        $service = $this->c->get(TicketService::class);
        $this->assertSame('Betreff Persönlich', $service->prefillFromTemplate($personal, false)['subject'] ?? null, 'Die Leitung nutzt alle Vorlagen');
    }

    public function testTemplateGroupCreatesOwnTemplatesWithoutPortalRelease(): void
    {
        $this->loginAs($this->ownerId, 'portal', ['vorlagen']);
        $service = $this->c->get(HelpdeskAdminService::class);

        $id = $service->saveTemplate(null, [
            'name' => 'Eigene Vorlage',
            'is_shared' => '1',
            'is_portal_visible' => '1',
            'is_active' => '1',
        ]);

        $row = $this->templates()->find($id) ?? [];
        $this->assertSame($this->ownerId, (int) ($row['created_by'] ?? 0), 'Die Vorlage gehört dem Ersteller');
        $this->assertSame(1, (int) ($row['is_shared'] ?? 0), 'Für alle Agenten freigegeben');
        $this->assertSame(0, (int) ($row['is_portal_visible'] ?? 1), 'Die Gruppe „vorlagen“ veröffentlicht nicht ins Portal');
    }

    public function testTemplateGroupCannotEditForeignPersonalTemplates(): void
    {
        $personal = $this->template('Persönlich', $this->ownerId, false);

        $this->loginAs($this->otherId, 'portal', ['vorlagen']);
        $service = $this->c->get(HelpdeskAdminService::class);

        $this->assertThrows(
            NotFoundException::class,
            fn () => $service->saveTemplate($personal, ['name' => 'Umbenannt', 'is_active' => '1']),
            'Vorlage nicht gefunden'
        );
        $this->assertSame('Persönlich', $this->templates()->find($personal)['name'] ?? null, 'Die fremde Vorlage bleibt unverändert');

        $this->loginAs($this->leadId, 'helpdesk_lead');
        $service = $this->c->get(HelpdeskAdminService::class);
        $service->saveTemplate($personal, ['name' => 'Von der Leitung umbenannt', 'is_active' => '1', 'is_shared' => '0']);
        $this->assertSame('Von der Leitung umbenannt', $this->templates()->find($personal)['name'] ?? null, 'Die Leitung verwaltet alle Vorlagen');
    }

    public function testPortalPrefillMatchesThePortalList(): void
    {
        $sharedInternal = $this->template('Geteilt intern', $this->ownerId, true, false);
        $personal = $this->template('Persönlich intern', $this->ownerId, false, false);

        $this->loginAs($this->ownerId, 'helpdesk_agent');
        $service = $this->c->get(TicketService::class);
        $this->assertSame([], $service->prefillFromTemplate($sharedInternal, true), 'Eigene freigegebene Vorlage ohne Portal-Freigabe bleibt im Agentenbereich');
        $this->assertSame('Betreff Persönlich intern', $service->prefillFromTemplate($personal, true)['subject'] ?? null, 'Eigene persönliche Vorlage gilt auch im Portal');
    }

    public function testTemplateNamesAreUniqueOnlyWithinTheVisibleScope(): void
    {
        $this->template('Fremd persönlich', $this->otherId, false);
        $this->template('Fremd für Freigabe', $this->otherId, false);
        $this->template('Geteilt belegt', $this->otherId, true);

        $this->loginAs($this->ownerId, 'portal', ['vorlagen']);
        $service = $this->c->get(HelpdeskAdminService::class);

        $personal = $service->saveTemplate(null, ['name' => 'Fremd persönlich', 'is_shared' => '0', 'is_active' => '1']);
        $this->assertSame(0, (int) ($this->templates()->find($personal)['is_shared'] ?? 1), 'Fremde persönliche Namen blockieren nicht');
        $shared = $service->saveTemplate(null, ['name' => 'Fremd für Freigabe', 'is_shared' => '1', 'is_active' => '1']);
        $this->assertSame(1, (int) ($this->templates()->find($shared)['is_shared'] ?? 0), 'Auch eine Freigabe darf einen fremden persönlichen Namen tragen');

        $this->assertThrows(
            ValidationException::class,
            fn () => $service->saveTemplate(null, ['name' => 'Geteilt belegt', 'is_shared' => '0', 'is_active' => '1']),
            'bereits vergeben'
        );
        $this->assertThrows(
            ValidationException::class,
            fn () => $service->saveTemplate(null, ['name' => 'Fremd persönlich', 'is_shared' => '0', 'is_active' => '1']),
            'bereits vergeben'
        );
    }

    public function testEditingKeepsPortalReleaseOfAManagedTemplate(): void
    {
        $id = $this->template('Portalvorlage', $this->ownerId, true, true);

        $this->loginAs($this->ownerId, 'portal', ['vorlagen']);
        $service = $this->c->get(HelpdeskAdminService::class);
        $service->saveTemplate($id, ['name' => 'Portalvorlage neu', 'is_shared' => '0', 'is_portal_visible' => '0', 'is_active' => '1']);

        $row = $this->templates()->find($id) ?? [];
        $this->assertSame('Portalvorlage neu', $row['name'] ?? null);
        $this->assertSame(0, (int) ($row['is_shared'] ?? 1));
        $this->assertSame(1, (int) ($row['is_portal_visible'] ?? 0), 'Eine bestehende Portal-Freigabe bleibt erhalten');
    }
}
