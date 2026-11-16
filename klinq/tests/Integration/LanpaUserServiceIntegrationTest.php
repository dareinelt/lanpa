<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\EmployeeRepository;
use App\Repositories\UserRepository;
use App\Services\Lanpa\LanpaUserService;
use Tests\Support\DatabaseTestCase;

/** Intranet-Anmeldung: Benutzer anlegen/abgleichen und mit dem Mitarbeiterdatensatz verknuepfen. */
final class LanpaUserServiceIntegrationTest extends DatabaseTestCase
{
    private function service(): LanpaUserService
    {
        return $this->c->get(LanpaUserService::class);
    }

    private function employees(): EmployeeRepository
    {
        return $this->c->get(EmployeeRepository::class);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function claims(array $overrides = []): array
    {
        return array_merge([
            'sub' => 'KHWF\\Dreinelt',
            'name' => 'Daniel-Andre Reinelt',
            'email' => 'dreinelt@example.test',
            'role' => 'lager',
            'permission_groups' => [],
        ], $overrides);
    }

    private function createEmployee(array $data): int
    {
        return $this->employees()->create(array_merge([
            'first_name' => 'Daniel-Andre',
            'last_name' => 'Reinelt',
            'display_name' => 'Reinelt, Daniel-Andre',
            'source' => 'manual',
            'is_active' => 1,
        ], $data));
    }

    public function testProvisionCreatesUserAndLinksEmployeeByUsername(): void
    {
        $employeeId = $this->createEmployee(['username' => 'dreinelt', 'email' => 'andere@example.test']);

        $user = $this->service()->provision($this->claims());

        $this->assertNotNull($user);
        $this->assertSame('dreinelt', $user['username'], 'Anmeldename wird normalisiert');
        $this->assertSame('ldap', $user['auth_source']);
        $this->assertSame($employeeId, (int) $user['employee_id'], 'Mitarbeiter ueber den Anmeldenamen verknuepft');
    }

    public function testProvisionLinksEmployeeByEmailFallback(): void
    {
        $employeeId = $this->createEmployee(['email' => 'DReinelt@example.test']);

        $user = $this->service()->provision($this->claims());

        $this->assertNotNull($user);
        $this->assertSame($employeeId, (int) $user['employee_id'], 'Rueckfall auf die E-Mail-Adresse');
    }

    public function testProvisionUpdatesExistingUserAndKeepsEmployeeLink(): void
    {
        $employeeId = $this->createEmployee(['username' => 'dreinelt']);
        $first = $this->service()->provision($this->claims());
        $this->assertNotNull($first);

        // Zweite Anmeldung ohne Treffer im Mitarbeiterbestand: Verknuepfung bleibt bestehen.
        $second = $this->service()->provision($this->claims([
            'name' => 'Reinelt, Daniel-Andre',
            'email' => 'unbekannt@example.test',
        ]));

        $this->assertNotNull($second);
        $this->assertSame((int) $first['id'], (int) $second['id'], 'Kein zweiter Benutzer');
        $this->assertSame('Reinelt, Daniel-Andre', $second['display_name'], 'Anzeigename wird abgeglichen');
        $this->assertSame($employeeId, (int) $second['employee_id']);
    }

    public function testProvisionWithoutEmployeeRecordLeavesLinkEmpty(): void
    {
        $user = $this->service()->provision($this->claims(['sub' => 'ohne-mitarbeiter']));

        $this->assertNotNull($user);
        $this->assertNull($user['employee_id']);
    }

    public function testProvisionFallsBackToPortalRoleWithoutKlinqPermission(): void
    {
        // Unbekannte oder fehlende Rolle: kein Zugriff auf die Assetverwaltung, nur das
        // eigene Help-Desk-Portal.
        foreach (['unbekannt', ''] as $role) {
            $user = $this->service()->provision($this->claims(['sub' => 'ohne-rolle-' . ($role === '' ? 'leer' : $role), 'role' => $role]));

            $this->assertNotNull($user);
            $this->assertSame(LanpaUserService::FALLBACK_ROLE, $user['role'], 'Rueckfallrolle ohne KLINQ-Berechtigung');
            $this->assertSame('portal', $user['role']);
        }
    }

    public function testProvisionRecreatesMissingConfiguredPermissionGroup(): void
    {
        // Fehlt der Gruppendatensatz (Seeder nicht gelaufen), darf das Recht aus der AD-Gruppe
        // nicht verloren gehen.
        $this->pdo->exec("DELETE FROM permission_groups WHERE name = 'aufgabenverteilung'");

        $user = $this->service()->provision($this->claims([
            'sub' => 'agent-aufgaben',
            'role' => 'helpdesk_agent',
            'permission_groups' => ['aufgabenverteilung', 'gibt_es_nicht'],
        ]));

        $this->assertNotNull($user);
        $names = array_column($this->c->get(UserRepository::class)->permissionGroups(), 'name');
        $this->assertTrue(in_array('aufgabenverteilung', $names, true), 'Gruppe wird angelegt');
        $this->assertFalse(in_array('gibt_es_nicht', $names, true), 'Unbekannte Gruppen werden nicht angelegt');

        $assigned = $this->pdo->prepare(
            'SELECT COUNT(*) FROM user_permission_groups ug JOIN permission_groups g ON g.id = ug.group_id'
            . ' WHERE ug.user_id = ? AND g.name = ?'
        );
        $assigned->execute([(int) $user['id'], 'aufgabenverteilung']);
        $this->assertSame(1, (int) $assigned->fetchColumn(), 'Benutzer erhaelt die Gruppe');
    }

    public function testLocalAdminRoleAndManualGroupsSurviveSubsequentSsoProvisioning(): void
    {
        $users = $this->c->get(UserRepository::class);
        $userService = $this->c->get(\App\Services\UserService::class);
        $currentUser = $this->c->get(\App\Security\CurrentUser::class);
        $hasher = $this->c->get(\App\Security\PasswordHasher::class);

        $rootId = $users->create([
            'username' => 'root-sso-test',
            'display_name' => 'Root Admin',
            'password_hash' => $hasher->hash('Sicher12345!'),
            'role_id' => $users->roleId('admin'),
            'auth_source' => 'local',
            'is_active' => 1,
        ]);
        $currentUser->login(['id' => $rootId, 'username' => 'root-sso-test', 'display_name' => 'Root Admin', 'role' => 'admin']);

        // 1. Agent meldet sich über Intranet-SSO mit Rolle helpdesk_agent und Gruppe statistik an
        $agent = $this->service()->provision($this->claims([
            'sub' => 'KHWF\\hd.agent',
            'name' => 'Helpdesk Agent',
            'role' => 'helpdesk_agent',
            'permission_groups' => ['statistik'],
        ]));
        $this->assertNotNull($agent);
        $agentId = (int) $agent['id'];
        $this->assertSame('helpdesk_agent', $agent['role']);
        $this->assertSame(['statistik'], $agent['groups']);

        $groupsByName = array_column($users->permissionGroups(), 'id', 'name');
        $this->assertSame([(int) $groupsByName['statistik']], $users->ssoGroupIdsForUser($agentId), 'SSO-Gruppe ist als SSO-Quelle erkennbar');

        // 2. Lokal in KLINQ wird Rolle „Administrator“ und zusätzlich Gruppe „lieferanten“ gesetzt
        $userService->update($agentId, $agent, [
            'display_name' => 'Helpdesk Agent',
            'email' => 'dreinelt@example.test',
            'role' => 'admin',
            'is_active' => '1',
            'groups' => [(string) $groupsByName['statistik'], (string) $groupsByName['lieferanten']],
        ]);
        $updated = $users->find($agentId);
        $this->assertSame('admin', $updated['role']);
        $this->assertSame(1, (int) $updated['role_override']);

        // 3. Erneuter Einstieg über Intranet-SSO (liefert weiterhin helpdesk_agent und nur noch ticketaufruf):
        //    lokal gesetzte Rolle „admin“ und manuell gesetzter Haken „lieferanten“ schlagen die SSO-Gruppen!
        $reentered = $this->service()->provision($this->claims([
            'sub' => 'KHWF\\hd.agent',
            'name' => 'Helpdesk Agent',
            'role' => 'helpdesk_agent',
            'permission_groups' => ['ticketaufruf'],
        ]));
        $this->assertNotNull($reentered);
        $this->assertSame('admin', $reentered['role'], 'Lokal vergebene Administratorrolle bleibt erhalten');
        $this->assertSame('helpdesk_agent', $reentered['sso_role'], 'Intranet-SSO-Rolle bleibt nachvollziehbar');
        $this->assertSame(['lieferanten', 'ticketaufruf'], $reentered['groups'], 'Manueller Haken bleibt erhalten, SSO-Gruppen werden abgeglichen');

        $sessionUser = new \App\Security\CurrentUser($this->c->get(\App\Security\Permissions::class));
        $sessionUser->login($reentered);
        $this->assertTrue($sessionUser->can('suppliers.manage'), 'Agent mit lokaler Admin-Rolle darf Lieferanten anlegen');

        // 4. Setzt ein anderer Admin die Rolle wieder auf die Intranet-SSO-Rolle zurück, greift künftig wieder SSO
        $currentUser->login(['id' => $rootId, 'username' => 'root-sso-test', 'display_name' => 'Root Admin', 'role' => 'admin']);
        $userService->update($agentId, $reentered, [
            'display_name' => 'Helpdesk Agent',
            'email' => 'dreinelt@example.test',
            'role' => 'helpdesk_agent',
            'is_active' => '1',
            'groups' => [(string) $groupsByName['ticketaufruf'], (string) $groupsByName['lieferanten']],
        ]);
        $reset = $users->find($agentId);
        $this->assertSame(0, (int) $reset['role_override'], 'Rückstellung auf SSO-Rolle hebt lokalen Override auf');
        $this->assertSame(['lieferanten', 'ticketaufruf'], $reset['groups']);

        $promotedBySso = $this->service()->provision($this->claims([
            'sub' => 'KHWF\\hd.agent',
            'name' => 'Helpdesk Agent',
            'role' => 'helpdesk_lead',
            'permission_groups' => [],
        ]));
        $this->assertSame('helpdesk_lead', $promotedBySso['role'], 'Nach Rückstellung übernimmt das Konto wieder Rollenänderungen aus SSO');
        $this->assertSame(['lieferanten'], $promotedBySso['groups'], 'Manuell gesetzter Haken „lieferanten“ bleibt weiterhin bestehen');

        $sessionUser->login($promotedBySso);
        $this->assertTrue($sessionUser->can('suppliers.manage'), 'Agent darf allein über Gruppe „lieferanten“ Lieferanten anlegen');
        $this->assertFalse($sessionUser->can('orders.manage'), 'Gruppe „lieferanten“ gewährt keine Bestellverwaltung');
    }

    public function testDeactivatedUserIsNotReactivatedBySsoProvisioning(): void
    {
        $users = $this->c->get(UserRepository::class);
        $user = $this->service()->provision($this->claims(['sub' => 'gesperrt.user', 'role' => 'helpdesk_agent']));
        $this->assertNotNull($user);

        $users->update((int) $user['id'], ['is_active' => 0]);

        $afterSso = $this->service()->provision($this->claims(['sub' => 'gesperrt.user', 'role' => 'helpdesk_agent']));
        $this->assertNotNull($afterSso);
        $this->assertSame(0, (int) $afterSso['is_active'], 'Deaktiviertes Konto wird durch SSO nicht reaktiviert');
    }
}
