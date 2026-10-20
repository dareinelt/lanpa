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

    public function testLagerCanCheckoutButNotManageSettings(): void
    {
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
}
