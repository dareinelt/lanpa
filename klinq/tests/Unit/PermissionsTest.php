<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Env;
use App\Security\CurrentUser;
use App\Security\Permissions;
use App\Exceptions\ForbiddenException;
use Tests\Support\TestCase;

final class PermissionsTest extends TestCase
{
    private Permissions $permissions;

    protected function setUp(): void
    {
        $this->permissions = new Permissions(new Config(dirname(__DIR__, 2) . '/config'));
    }

    public function testAdminHasEverything(): void
    {
        $this->assertTrue($this->permissions->roleHas('admin', 'settings.manage'));
        $this->assertTrue($this->permissions->roleHas('admin', 'assets.manage'));
    }

    public function testReadonlyHasOnlyViewPermissions(): void
    {
        foreach ($this->permissions->forRole('readonly') as $permission) {
            $this->assertTrue(str_ends_with($permission, '.view'), "readonly darf {$permission} nicht haben");
        }
        $this->assertFalse($this->permissions->roleHas('readonly', 'assets.manage'));
        $this->assertFalse($this->permissions->roleHas('readonly', 'audit.view'), 'Audit-Log enthält Benutzer-/IP-Daten');
        $this->assertFalse($this->permissions->roleHas('lager', 'audit.view'));
        $this->assertTrue($this->permissions->roleHas('assetmanagement', 'audit.view'));
    }

    public function testPortalRoleHasNoAssetPermissions(): void
    {
        $this->assertTrue($this->permissions->roleExists('portal'));
        $this->assertSame(
            ['portal.view', 'portal.create', 'knowledgebase.view'],
            $this->permissions->forRole('portal'),
            'Die Rolle "portal" darf ausschließlich den eigenen Help-Desk-Bereich öffnen'
        );
        $this->assertFalse($this->permissions->roleHas('portal', 'dashboard.view'));
        $this->assertFalse($this->permissions->roleHas('portal', 'assets.view'));
        $this->assertFalse($this->permissions->roleHas('portal', 'helpdesk.view'));
    }

    public function testLagerCanCheckoutButNotManageSettings(): void    {
        $this->assertTrue($this->permissions->roleHas('lager', 'movements.checkout'));
        $this->assertFalse($this->permissions->roleHas('lager', 'settings.manage'));
    }

    public function testEinkaufManagesOrdersOnly(): void
    {
        $this->assertTrue($this->permissions->roleHas('einkauf', 'orders.manage'));
        $this->assertFalse($this->permissions->roleHas('einkauf', 'movements.checkout'));
    }

    public function testUnknownRoleHasNoPermissions(): void
    {
        $this->assertSame([], $this->permissions->forRole('does-not-exist'));
        $this->assertFalse($this->permissions->roleExists('does-not-exist'));
    }

    public function testCurrentUserRequireThrowsForbidden(): void
    {
        $user = new CurrentUser($this->permissions);
        $this->assertFalse($user->isAuthenticated());
        $user->login(['id' => 1, 'username' => 'ro', 'display_name' => 'RO', 'role' => 'readonly']);
        $this->assertTrue($user->can('assets.view'));
        $this->assertThrows(ForbiddenException::class, static fn () => $user->require('assets.manage'));
        $user->logout();
        $this->assertFalse($user->isAuthenticated());
    }

    // ------------------------------------------------------------------ Berechtigungsgruppen

    public function testGroupGrantsPermissionInAdditionToRole(): void
    {
        $this->assertTrue($this->permissions->groupExists('statistik'));
        $this->assertTrue($this->permissions->groupHas('statistik', 'helpdesk.reports'));
        $this->assertFalse($this->permissions->roleHas('helpdesk_agent', 'helpdesk.reports'), 'Ticket-Berichte werden nur über die Gruppe „Statistik“ vergeben');

        $this->assertFalse($this->permissions->hasEffective('helpdesk_agent', [], 'helpdesk.reports'));
        $this->assertTrue($this->permissions->hasEffective('helpdesk_agent', ['statistik'], 'helpdesk.reports'));
        // Admins haben das Recht bereits über die Rolle, unabhängig von Gruppen.
        $this->assertTrue($this->permissions->hasEffective('admin', [], 'helpdesk.reports'));
    }

    public function testCurrentUserCanCombinesRoleAndGroups(): void
    {
        $user = new CurrentUser($this->permissions);
        $user->login(['id' => 2, 'username' => 'agent', 'display_name' => 'Agent', 'role' => 'helpdesk_agent']);
        $this->assertFalse($user->can('helpdesk.reports'));

        $user->login(['id' => 2, 'username' => 'agent', 'display_name' => 'Agent', 'role' => 'helpdesk_agent', 'groups' => ['statistik']]);
        $this->assertTrue($user->can('helpdesk.reports'));
        $this->assertSame(['statistik'], $user->groups());
    }

    public function testDeleteAndCancelRequireDedicatedGroups(): void
    {
        $this->assertTrue($this->permissions->groupExists('ticket_loeschen'));
        $this->assertTrue($this->permissions->groupExists('ticket_stornieren'));
        $this->assertTrue($this->permissions->groupHas('ticket_loeschen', 'helpdesk.delete'));
        $this->assertTrue($this->permissions->groupHas('ticket_stornieren', 'helpdesk.cancel'));
        $this->assertFalse($this->permissions->groupHas('ticket_loeschen', 'helpdesk.cancel'));
        $this->assertFalse($this->permissions->groupHas('ticket_stornieren', 'helpdesk.delete'));

        foreach (['helpdesk_agent', 'helpdesk_lead', 'helpdesk_admin'] as $role) {
            $this->assertFalse($this->permissions->roleHas($role, 'helpdesk.delete'), $role . ' löscht standardmäßig keine Tickets');
            $this->assertFalse($this->permissions->roleHas($role, 'helpdesk.cancel'), $role . ' storniert standardmäßig keine Tickets');
        }
        $this->assertTrue($this->permissions->roleHas('admin', 'helpdesk.delete'));
        $this->assertTrue($this->permissions->roleHas('admin', 'helpdesk.cancel'));

        $user = new CurrentUser($this->permissions);
        $user->login(['id' => 3, 'username' => 'agent', 'display_name' => 'Agent', 'role' => 'helpdesk_agent']);
        $this->assertFalse($user->can('helpdesk.delete'));
        $this->assertFalse($user->can('helpdesk.cancel'));

        $user->login(['id' => 3, 'username' => 'agent', 'display_name' => 'Agent', 'role' => 'helpdesk_agent', 'groups' => ['ticket_loeschen']]);
        $this->assertTrue($user->can('helpdesk.delete'));
        $this->assertFalse($user->can('helpdesk.cancel'), 'Die Gruppen sind getrennt');

        $user->login(['id' => 3, 'username' => 'agent', 'display_name' => 'Agent', 'role' => 'helpdesk_agent', 'groups' => ['ticket_stornieren']]);
        $this->assertTrue($user->can('helpdesk.cancel'));
        $this->assertFalse($user->can('helpdesk.delete'));
    }
}
