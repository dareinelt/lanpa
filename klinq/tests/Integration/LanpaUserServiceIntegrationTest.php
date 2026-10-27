<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\EmployeeRepository;
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
}
