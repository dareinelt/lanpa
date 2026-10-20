<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Logger;
use App\Repositories\AdSyncRunRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Services\Ad\AdUserMapper;
use App\Services\Ad\EmployeeSyncService;
use App\Services\Ldap\FakeLdapClient;
use Tests\Support\DatabaseTestCase;

final class EmployeeSyncIntegrationTest extends DatabaseTestCase
{
    private const G1 = '11111111-1111-1111-1111-111111111111';
    private const G2 = '22222222-2222-2222-2222-222222222222';

    /** @param array<int,array<string,mixed>> $entries */
    private function service(array $entries): EmployeeSyncService
    {
        $config = $this->c->get(Config::class)->with([
            'ldap.enabled' => true,
            'ldap.bind_dn' => '',
            'ldap.base_dn' => 'DC=test',
            'ldap.user_filter' => '(objectClass=user)',
        ]);

        return new EmployeeSyncService(
            $config,
            new FakeLdapClient('', $entries),
            $this->c->get(AdUserMapper::class),
            $this->c->get(EmployeeRepository::class),
            $this->c->get(AdSyncRunRepository::class),
            $this->c->get(LocationRepository::class),
            $this->c->get(CostCenterRepository::class),
            $this->c->get(Logger::class)
        );
    }

    /** @return array<string,mixed> */
    private function entry(string $guid, string $user, string $last, int $uac = 512): array
    {
        return ['dn' => "CN={$user}", 'objectGUID' => $guid, 'sAMAccountName' => $user, 'givenName' => 'Test', 'sn' => $last, 'mail' => "{$user}@test.local", 'userAccountControl' => $uac];
    }

    public function testFullLifecycleNeverDeletes(): void
    {
        $employees = $this->c->get(EmployeeRepository::class);
        $runs = $this->c->get(AdSyncRunRepository::class);

        $result = $this->service([$this->entry(self::G1, 'u1', 'Eins'), $this->entry(self::G2, 'u2', 'Zwei')])->run('test');
        $this->assertSame('success', $result['status']);
        $this->assertSame(2, (int) $result['created_count']);
        $u1 = $employees->findByGuid(self::G1);
        $this->assertSame('ad', $u1['source']);
        $u1Id = (int) $u1['id'];

        // Umbenennung im AD → gleiche Zeile aktualisiert (Identität über GUID, nicht Benutzername)
        $result = $this->service([$this->entry(self::G1, 'u1-neu', 'Eins-Neu'), $this->entry(self::G2, 'u2', 'Zwei')])->run('test');
        $this->assertSame(1, (int) $result['updated_count']);
        $this->assertSame($u1Id, (int) $employees->findByGuid(self::G1)['id']);
        $this->assertSame('u1-neu', $employees->findByGuid(self::G1)['username']);

        // u2 im AD deaktiviert, u1 verschwunden → beide inaktiv, keiner gelöscht
        $result = $this->service([$this->entry(self::G2, 'u2', 'Zwei', 514)])->run('test');
        $this->assertSame(2, (int) $result['deactivated_count']);
        $this->assertSame(0, (int) $employees->findByGuid(self::G1)['is_active']);
        $this->assertSame(0, (int) $employees->findByGuid(self::G2)['is_active']);
        $this->assertNotNull($employees->find($u1Id));

        // u1 kehrt zurück → reaktiviert
        $result = $this->service([$this->entry(self::G1, 'u1-neu', 'Eins-Neu'), $this->entry(self::G2, 'u2', 'Zwei', 514)])->run('test');
        $this->assertSame(1, (int) $result['reactivated_count']);
        $this->assertSame(1, (int) $employees->findByGuid(self::G1)['is_active']);
        $this->assertNull($employees->findByGuid(self::G1)['deactivated_at']);

        $this->assertCount(4, array_filter($runs->latest(), static fn (array $r): bool => $r['triggered_by'] === 'test'));
    }

    public function testDryRunLeavesNoTraceExceptRunLog(): void
    {
        $employees = $this->c->get(EmployeeRepository::class);
        $result = $this->service([$this->entry(self::G1, 'dry', 'Lauf')])->run('test', true);
        $this->assertSame('success', $result['status']);
        $this->assertSame(1, (int) $result['created_count']);
        $this->assertNull($employees->findByGuid(self::G1));
        $this->assertStringContains('Testlauf', (string) $result['message']);
    }

    public function testManualEmployeeWithSameUsernameIsAdopted(): void
    {
        $employees = $this->c->get(EmployeeRepository::class);
        $id = $employees->create(['first_name' => 'Manuell', 'last_name' => 'Angelegt', 'display_name' => 'Manuell Angelegt', 'username' => 'madopt', 'source' => 'manual', 'is_active' => 1]);
        $result = $this->service([$this->entry(self::G1, 'madopt', 'Angelegt')])->run('test');
        $this->assertSame(0, (int) $result['created_count']);
        $row = $employees->find($id);
        $this->assertSame(self::G1, $row['ad_object_guid']);
        $this->assertSame('ad', $row['source']);
    }
}
